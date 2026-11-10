<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_certificatepro\local\art;

/**
 * Replays a recorded {@see canvas} onto a TCPDF page using native primitives.
 *
 * Nothing here goes through TCPDF's SVG parser. That parser silently degrades
 * gradients and cannot set text on a path, which is what made the first version
 * of the award seal print as a hard diagonal split with no lettering. Drawing
 * natively means a gradient is a real PDF shading dictionary and the artwork
 * stays vector all the way to the page.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tcpdf_painter {
    /** @var float Millimetres in one typographic point. */
    protected const PT_TO_MM = 0.3527777777778;

    /** @var \TCPDF The document being drawn into. */
    protected $pdf;

    /** @var float Horizontal offset in millimetres. */
    protected $ox = 0.0;

    /** @var float Vertical offset in millimetres. */
    protected $oy = 0.0;

    /** @var array The current stroke style, reapplied after TCPDF resets it. */
    protected $linestyle = ['width' => 0.2, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0,
        'color' => [0, 0, 0]];

    /** @var int[] The current fill colour. */
    protected $fillrgb = [0, 0, 0];

    /** @var int Number of transform frames opened, so they can all be closed. */
    protected $open = 0;

    /**
     * Create a painter.
     *
     * @param \TCPDF $pdf the document
     * @param float $ox horizontal offset in millimetres
     * @param float $oy vertical offset in millimetres
     */
    public function __construct($pdf, float $ox = 0.0, float $oy = 0.0) {
        $this->pdf = $pdf;
        $this->ox = $ox;
        $this->oy = $oy;
    }

    /**
     * Replay an operation list.
     *
     * The whole run is wrapped in a transform frame and the graphics state is
     * restored afterwards, so a background can never leak a clip, an alpha or a
     * colour into the elements drawn on top of it.
     *
     * @param array[] $ops the operations from canvas::ops()
     * @return void
     */
    public function paint(array $ops): void {
        $this->pdf->StartTransform();
        $this->open = 1;

        foreach ($ops as $op) {
            $this->apply($op);
        }

        // Close anything a style left open, then the outer frame.
        while ($this->open > 0) {
            $this->pdf->StopTransform();
            $this->open--;
        }
        $this->pdf->SetAlpha(1.0);
    }

    /**
     * Apply one operation.
     *
     * @param array $op the operation
     * @return void
     */
    protected function apply(array $op): void {
        $kind = (string) ($op[0] ?? '');

        switch ($kind) {
            case 'save':
                $this->pdf->StartTransform();
                $this->open++;
                break;

            case 'restore':
                if ($this->open > 1) {
                    $this->pdf->StopTransform();
                    $this->open--;
                }
                break;

            case 'clip':
                // A rectangle drawn with the CNZ style becomes the clipping path.
                $this->pdf->Rect($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4], 'CNZ');
                break;

            case 'alpha':
                $this->pdf->SetAlpha((float) $op[1]);
                break;

            case 'rotate':
                $this->pdf->Rotate((float) $op[1], $this->ox + $op[2], $this->oy + $op[3]);
                break;

            case 'fill':
                $this->fillrgb = canvas::to_rgb((string) $op[1]);
                $this->pdf->SetFillColor($this->fillrgb[0], $this->fillrgb[1], $this->fillrgb[2]);
                break;

            case 'stroke':
                $this->set_stroke((string) $op[1], (float) $op[2], (array) $op[3]);
                break;

            case 'rect':
                $this->pdf->Rect($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4],
                    $this->mode((string) $op[5]), $this->border_style(), $this->fillrgb);
                break;

            case 'circle':
                $this->pdf->Circle($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4], $op[5],
                    $this->mode((string) $op[6]), $this->linestyle, $this->fillrgb, 12);
                break;

            case 'ellipse':
                $this->pdf->Ellipse($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4], $op[5],
                    $op[6], $op[7], $this->mode((string) $op[8]), $this->linestyle, $this->fillrgb, 12);
                break;

            case 'line':
                $this->pdf->Line($this->ox + $op[1], $this->oy + $op[2], $this->ox + $op[3],
                    $this->oy + $op[4], $this->linestyle);
                break;

            case 'poly':
                $this->polygon((array) $op[1], (string) $op[2]);
                break;

            case 'path':
                $this->draw_path((array) $op[1], (string) $op[2]);
                break;

            case 'lgrad':
                $this->pdf->LinearGradient($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4],
                    canvas::to_rgb((string) $op[5]), canvas::to_rgb((string) $op[6]),
                    self::linear_coords((array) $op[7]));
                break;

            case 'rgrad':
                $this->pdf->RadialGradient($this->ox + $op[1], $this->oy + $op[2], $op[3], $op[4],
                    canvas::to_rgb((string) $op[5]), canvas::to_rgb((string) $op[6]),
                    self::radial_coords((array) $op[7]));
                break;

            case 'mesh':
                $this->mesh((array) $op[1], (array) $op[2], (int) $op[3]);
                break;

            case 'text':
                $this->draw_text((float) $op[1], (float) $op[2], (string) $op[3],
                    (string) $op[4], (string) $op[5], (float) $op[6]);
                break;

            case 'glyphs':
                $this->draw_glyphs((array) $op[1], (string) $op[2], (string) $op[3], (float) $op[4]);
                break;
        }
    }

    /**
     * Draw a run of text from its left edge at a baseline.
     *
     * The canvas has already resolved the anchor into a left edge, so nothing
     * is measured here — that is what keeps this identical to the preview.
     *
     * @param float $xmm left edge in millimetres
     * @param float $ymm baseline in millimetres
     * @param string $text the run
     * @param string $family font family
     * @param string $style TCPDF style string
     * @param float $sizept size in points
     * @return void
     */
    protected function draw_text(float $xmm, float $ymm, string $text, string $family,
            string $style, float $sizept): void {
        $this->apply_text_state($family, $style, $sizept);
        $this->pdf->Text($this->ox + $xmm, $this->oy + $ymm, $text,
            0, false, true, 0, 0, '', false, '', 0, true, 'L', 'M', true);
    }

    /**
     * Draw positioned, rotated glyphs.
     *
     * @param array $glyphs each entry [char, xmm, ymm, rotation in degrees]
     * @param string $family font family
     * @param string $style TCPDF style string
     * @param float $sizept size in points
     * @return void
     */
    protected function draw_glyphs(array $glyphs, string $family, string $style,
            float $sizept): void {
        $this->apply_text_state($family, $style, $sizept);

        foreach ($glyphs as $glyph) {
            $char = (string) ($glyph[0] ?? '');
            if ($char === '' || $char === ' ') {
                continue;
            }
            $x = $this->ox + (float) $glyph[1];
            $y = $this->oy + (float) $glyph[2];
            $rot = (float) ($glyph[3] ?? 0.0);

            if (abs($rot) < 0.001) {
                $this->pdf->Text($x, $y, $char,
                    0, false, true, 0, 0, '', false, '', 0, true, 'L', 'M', true);
                continue;
            }

            // The same call as the unrotated branch, and for the same reason:
            // calign 'L' makes the recorded y the glyph's baseline. Left to
            // TCPDF's default the y would be the top of the cell instead, which
            // pushes a top arc's lettering inwards and a bottom arc's outwards
            // by a whole ascent, and disagrees with the SVG painter, where a
            // text element's y is always the baseline.
            $this->pdf->StartTransform();
            $this->pdf->Rotate($rot, $x, $y);
            $this->pdf->Text($x, $y, $char,
                0, false, true, 0, 0, '', false, '', 0, true, 'L', 'M', true);
            $this->pdf->StopTransform();
        }
    }

    /**
     * Set the font and colour a text op will draw with.
     *
     * Text takes the current fill colour, so a style sets ink once and it
     * applies to shapes and lettering alike.
     *
     * @param string $family font family
     * @param string $style TCPDF style string
     * @param float $sizept size in points
     * @return void
     */
    protected function apply_text_state(string $family, string $style, float $sizept): void {
        $this->pdf->SetFont($family, $style, $sizept);
        $this->pdf->SetTextColor($this->fillrgb[0], $this->fillrgb[1], $this->fillrgb[2]);
        // Text() draws through Cell(), which would otherwise add the document's
        // cell padding to every glyph origin and shift the whole run right.
        $this->pdf->setCellPaddings(0, 0, 0, 0);
    }

    /**
     * Set the stroke colour, width and dash.
     *
     * Widths arrive in points because that is how a designer thinks about a
     * hairline; TCPDF wants the document unit, which is millimetres here.
     *
     * @param string $colour a #rrggbb string
     * @param float $widthpt width in points
     * @param array $dash dash pattern in points
     * @return void
     */
    protected function set_stroke(string $colour, float $widthpt, array $dash): void {
        $rgb = canvas::to_rgb($colour);
        $pattern = 0;
        if ($dash) {
            $parts = [];
            foreach ($dash as $d) {
                $parts[] = round((float) $d * self::PT_TO_MM, 3);
            }
            $pattern = implode(',', $parts);
        }
        $this->linestyle = [
            'width' => max(0.02, $widthpt * self::PT_TO_MM),
            'cap' => 'butt',
            'join' => 'miter',
            'dash' => $pattern,
            'color' => $rgb,
        ];
        $this->pdf->SetLineStyle($this->linestyle);
    }

    /**
     * Turn a linear gradient's canvas coordinates the right way up for PDF.
     *
     * The canvas describes a gradient the way SVG does: inside a unit box whose
     * y runs downwards, so y=0 is the top edge. TCPDF sets up the shading with
     * `w 0 0 h x (pageheight-(y+h)) cm` {@see \TCPDF::Clip()}, which is the PDF
     * convention — y runs upwards, so y=0 is the BOTTOM edge.
     *
     * Handed the canvas numbers unchanged, every gradient with a vertical
     * component therefore printed mirrored against the preview. A wash whose
     * tint sat along the top of the designer canvas sat along the bottom of the
     * paper. It went unnoticed because no background reached this painter until
     * now — they all went through TCPDF's SVG parser, which flattened them to
     * one colour, so there was no orientation left to be wrong — and because
     * the award seal, the only thing that did come through here, draws no
     * gradients.
     *
     * @param float[] $coords x1, y1, x2, y2 in the unit box, y downwards
     * @return float[] the same vector with y measured upwards
     */
    protected static function linear_coords(array $coords): array {
        if (count($coords) < 4) {
            return array_values($coords);
        }
        return [
            (float) $coords[0],
            1.0 - (float) $coords[1],
            (float) $coords[2],
            1.0 - (float) $coords[3],
        ];
    }

    /**
     * Translate radial gradient coordinates into the order TCPDF actually reads.
     *
     * The canvas, like SVG, describes a radial gradient with six values: a focus
     * point, a focus radius, then the centre and radius of the end circle.
     * TCPDF's shading writer consumes only five of them — it emits
     * `/Coords [c0 c1 0 c2 c3 c4]`, hard-coding the inner radius to zero. Handed
     * a six-value array it therefore reads the focus radius as the end circle's
     * x, and the end centre as its y and radius.
     *
     * The visible symptom is a cone: the start point lands on or outside the end
     * circle, and PDF renders that as a hard diagonal edge with an arc across
     * the page rather than as a soft bloom.
     *
     * Both y values are also turned the right way up, for the reason given in
     * {@see linear_coords()}.
     *
     * @param float[] $coords focus x, focus y, focus r, centre x, centre y, radius
     * @return float[] the five values TCPDF expects
     */
    protected static function radial_coords(array $coords): array {
        if (count($coords) < 6) {
            return array_values($coords);
        }
        return [
            (float) $coords[0],
            1.0 - (float) $coords[1],
            (float) $coords[3],
            1.0 - (float) $coords[4],
            (float) $coords[5],
        ];
    }

    /**
     * The current stroke, in the shape TCPDF's shape methods want it.
     *
     * {@see \TCPDF::Rect()} and {@see \TCPDF::Polygon()} accept a line style
     * either as one style for the whole outline, under the key 'all', or as a
     * map of per-side styles. Handed a bare style array they take the second
     * reading: Rect() then strips the stroke operator out of the paint mode and
     * looks for sides named 'L', 'T', 'R' and 'B', finds none among 'width',
     * 'cap', 'join', 'dash' and 'color', and draws no outline at all.
     *
     * The effect was a stroked rectangle that simply did not appear — the
     * double keyline frame of the Deep Field ground among them — while the same
     * op list drew it correctly in the preview. Saying 'all' says what was
     * always meant: one stroke, all the way round.
     *
     * @return array the border style
     */
    protected function border_style(): array {
        return ['all' => $this->linestyle];
    }

    /**
     * Translate a canvas draw mode to a TCPDF style string.
     *
     * @param string $mode F, D or FD
     * @return string
     */
    protected function mode(string $mode): string {
        $mode = strtoupper($mode);
        if ($mode === 'F') {
            return 'F';
        }
        if ($mode === 'FD' || $mode === 'DF') {
            return 'DF';
        }
        return 'D';
    }

    /**
     * Draw a polygon from flat millimetre coordinates.
     *
     * @param float[] $mm x1, y1, x2, y2, ...
     * @param string $mode F, D or FD
     * @param bool $closed whether to close the figure
     * @return void
     */
    protected function polygon(array $mm, string $mode, bool $closed = true): void {
        $points = [];
        $count = count($mm);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $points[] = $this->ox + (float) $mm[$i];
            $points[] = $this->oy + (float) $mm[$i + 1];
        }
        if (count($points) < 4) {
            return;
        }
        $this->pdf->Polygon($points, $this->mode($mode), $this->border_style(), $this->fillrgb,
            $closed);
    }

    /**
     * Draw a path by flattening its curves to a dense polyline.
     *
     * TCPDF has no general path API, and flattening at print resolution is
     * indistinguishable from a true curve while being far more predictable than
     * chaining Polycurve calls.
     *
     * @param array[] $segments the path segments in millimetres
     * @param string $mode F, D or FD
     * @return void
     */
    protected function draw_path(array $segments, string $mode): void {
        foreach (path_flattener::flatten($segments) as $sub) {
            if (count($sub['points']) >= 4) {
                $this->polygon($sub['points'], $mode, $sub['closed']);
            }
        }
    }

    /**
     * Fill a quad with a bilinear colour blend by subdividing it.
     *
     * Each cell is drawn filled and stroked in its own colour, which closes the
     * hairline seams that otherwise show between adjacent cells.
     *
     * @param float[] $quad the four corners in millimetres, clockwise from top left
     * @param string[] $colours the four corner colours
     * @param int $steps subdivision per axis
     * @return void
     */
    protected function mesh(array $quad, array $colours, int $steps): void {
        $rgb = [];
        foreach ($colours as $c) {
            $rgb[] = canvas::to_rgb((string) $c);
        }
        while (count($rgb) < 4) {
            $rgb[] = $rgb[0];
        }

        for ($i = 0; $i < $steps; $i++) {
            for ($j = 0; $j < $steps; $j++) {
                $s0 = $i / $steps;
                $s1 = ($i + 1) / $steps;
                $t0 = $j / $steps;
                $t1 = ($j + 1) / $steps;

                $cell = [
                    mesh_math::point($quad, $s0, $t0),
                    mesh_math::point($quad, $s1, $t0),
                    mesh_math::point($quad, $s1, $t1),
                    mesh_math::point($quad, $s0, $t1),
                ];
                $colour = mesh_math::colour($rgb, ($s0 + $s1) / 2, ($t0 + $t1) / 2);

                $points = [];
                foreach ($cell as $p) {
                    $points[] = $this->ox + $p[0];
                    $points[] = $this->oy + $p[1];
                }

                $style = ['width' => 0.06, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0,
                    'color' => $colour];
                $this->pdf->SetFillColor($colour[0], $colour[1], $colour[2]);
                $this->pdf->Polygon($points, 'DF', ['all' => $style], $colour, true);
            }
        }

        // Leave the fill colour as the style set it.
        $this->pdf->SetFillColor($this->fillrgb[0], $this->fillrgb[1], $this->fillrgb[2]);
        $this->pdf->SetLineStyle($this->linestyle);
    }
}
