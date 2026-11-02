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
 * Replays a recorded {@see canvas} as SVG for the designer preview.
 *
 * This is the browser twin of {@see tcpdf_painter}. Both consume the same
 * operation list, both use {@see mesh_math} to subdivide a gradient mesh, and
 * both follow TCPDF's arc convention, in which an angle is measured
 * counter-clockwise from the positive x axis with y increasing downward.
 * Keeping the two in lockstep is what makes the preview trustworthy.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class svg_painter {
    /** @var float Millimetres in one typographic point. */
    protected const PT_TO_MM = 0.3527777777778;

    /** @var string[] The emitted body markup. */
    protected $body = [];

    /** @var string[] The emitted defs markup. */
    protected $defs = [];

    /** @var array The current graphics state. */
    protected $state;

    /** @var array[] The saved graphics states. */
    protected $stack = [];

    /** @var int[] Groups opened per save frame, so restore closes the right number. */
    protected $groups = [];

    /** @var int Counter for unique def identifiers. */
    protected $uid = 0;

    /** @var string Prefix for def identifiers, unique per render. */
    protected $prefix;

    /**
     * Create a painter.
     *
     * @param string $prefix a prefix making def ids unique when several SVGs share a page
     */
    public function __construct(string $prefix = 'cpa') {
        $this->prefix = preg_replace('/[^a-z0-9]/i', '', $prefix) ?: 'cpa';
        $this->state = [
            'alpha' => 1.0,
            'fill' => '#000000',
            'stroke' => '#000000',
            'width' => 0.2,
            'dash' => '',
        ];
    }

    /**
     * Render an operation list as a complete SVG document.
     *
     * @param array[] $ops the operations from canvas::ops()
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @param bool $stretch true to fill the container regardless of its shape,
     *      false to preserve the aspect ratio. A page background wants true —
     *      it is drawn for that exact page. A standalone ornament wants false,
     *      or dropping it into a box of a different shape shears the artwork.
     * @return string the SVG markup
     */
    public function render(array $ops, float $width, float $height,
            bool $stretch = true): string {
        $this->body = [];
        $this->defs = [];
        $this->stack = [];
        $this->groups = [];

        foreach ($ops as $op) {
            $this->apply($op);
        }

        // Close anything a style left open.
        while ($this->groups) {
            $open = (int) array_pop($this->groups);
            for ($i = 0; $i < $open; $i++) {
                $this->body[] = '</g>';
            }
        }

        $defs = $this->defs ? '<defs>' . implode('', $this->defs) . '</defs>' : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" '
            . 'viewBox="0 0 ' . self::n($width) . ' ' . self::n($height) . '" '
            . 'width="100%" height="100%" preserveAspectRatio="'
            . ($stretch ? 'none' : 'xMidYMid meet') . '" '
            . 'shape-rendering="geometricPrecision">'
            . $defs . implode('', $this->body) . '</svg>';
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
                $this->stack[] = $this->state;
                $this->groups[] = 0;
                break;

            case 'restore':
                if ($this->stack) {
                    $this->state = array_pop($this->stack);
                    $open = (int) array_pop($this->groups);
                    for ($i = 0; $i < $open; $i++) {
                        $this->body[] = '</g>';
                    }
                }
                break;

            case 'clip':
                $id = $this->next_id();
                $this->defs[] = '<clipPath id="' . $id . '"><rect x="' . self::n($op[1])
                    . '" y="' . self::n($op[2]) . '" width="' . self::n($op[3])
                    . '" height="' . self::n($op[4]) . '"/></clipPath>';
                $this->body[] = '<g clip-path="url(#' . $id . ')">';
                $this->bump_group();
                break;

            case 'rotate':
                // TCPDF rotates counter-clockwise; SVG rotates clockwise.
                $this->body[] = '<g transform="rotate(
                    ' . self::n(-1 * (float) $op[1]) . ' '
                        . self::n($op[2]) . ' ' . self::n($op[3]) . '
                )">';
                $this->bump_group();
                break;

            case 'alpha':
                $this->state['alpha'] = (float) $op[1];
                break;

            case 'fill':
                $this->state['fill'] = (string) $op[1];
                break;

            case 'stroke':
                $this->state['stroke'] = (string) $op[1];
                $this->state['width'] = max(0.02, (float) $op[2] * self::PT_TO_MM);
                $dash = [];
                foreach ((array) $op[3] as $d) {
                    $dash[] = self::n((float) $d * self::PT_TO_MM);
                }
                $this->state['dash'] = implode(',', $dash);
                break;

            case 'rect':
                $this->body[] = '<rect x="' . self::n($op[1]) . '" y="' . self::n($op[2])
                    . '" width="' . self::n($op[3]) . '" height="' . self::n($op[4]) . '"'
                    . $this->paint_attrs((string) $op[5]) . '/>';
                break;

            case 'circle':
                $this->arc(
                    (float) $op[1],
                    (float) $op[2],
                    (float) $op[3],
                    (float) $op[3],
                    0.0,
                    (float) $op[4],
                    (float) $op[5],
                    (string) $op[6]
                );
                break;

            case 'ellipse':
                $this->arc(
                    (float) $op[1],
                    (float) $op[2],
                    (float) $op[3],
                    (float) $op[4],
                    (float) $op[5],
                    (float) $op[6],
                    (float) $op[7],
                    (string) $op[8]
                );
                break;

            case 'line':
                $this->body[] = '<line x1="' . self::n($op[1]) . '" y1="' . self::n($op[2])
                    . '" x2="' . self::n($op[3]) . '" y2="' . self::n($op[4]) . '"'
                    . $this->paint_attrs('D') . '/>';
                break;

            case 'poly':
                $this->body[] = '<polygon points="' . $this->points((array) $op[1]) . '"'
                    . $this->paint_attrs((string) $op[2]) . '/>';
                break;

            case 'path':
                $this->body[] = '<path d="' . $this->path_data((array) $op[1]) . '"'
                    . $this->paint_attrs((string) $op[2]) . '/>';
                break;

            case 'lgrad':
                $this->gradient_rect('linear', $op);
                break;

            case 'rgrad':
                $this->gradient_rect('radial', $op);
                break;

            case 'mesh':
                $this->mesh((array) $op[1], (array) $op[2], (int) $op[3]);
                break;

            case 'text':
                $this->body[] = '<text x="' . self::n($op[1]) . '" y="' . self::n($op[2]) . '"'
                    . $this->text_attrs((string) $op[4], (string) $op[5], (float) $op[6])
                    . '>' . self::esc((string) $op[3]) . '</text>';
                break;

            case 'glyphs':
                $this->glyphs((array) $op[1], (string) $op[2], (string) $op[3], (float) $op[4]);
                break;
        }
    }

    /**
     * Emit positioned, rotated glyphs.
     *
     * Every coordinate and angle was computed by the canvas, so this does no
     * layout of its own — which is precisely why the preview cannot drift from
     * the PDF. It also means we never touch textPath, whose bottom-arc
     * direction and textLength support differ across browsers.
     *
     * @param array $glyphs each entry [char, xmm, ymm, rotation in degrees]
     * @param string $family font family
     * @param string $style TCPDF style string
     * @param float $sizept size in points
     * @return void
     */
    protected function glyphs(array $glyphs, string $family, string $style, float $sizept): void {
        $attrs = $this->text_attrs($family, $style, $sizept);

        foreach ($glyphs as $glyph) {
            $char = (string) ($glyph[0] ?? '');
            if ($char === '' || $char === ' ') {
                continue;
            }
            $x = (float) $glyph[1];
            $y = (float) $glyph[2];
            // The canvas records TCPDF's counter-clockwise convention; SVG
            // rotates clockwise, so negate exactly as the rotate op does.
            $rot = -1 * (float) ($glyph[3] ?? 0.0);

            $transform = abs($rot) < 0.001 ? ''
                : ' transform="rotate(' . self::n($rot) . ' ' . self::n($x) . ' ' . self::n($y) . ')"';

            $this->body[] = '<text x="' . self::n($x) . '" y="' . self::n($y) . '"'
                . $attrs . $transform . '>' . self::esc($char) . '</text>';
        }
    }

    /**
     * Presentation attributes shared by every text op.
     *
     * Text takes the current fill colour, matching the PDF painter.
     *
     * @param string $family font family
     * @param string $style TCPDF style string, B and I are recognised
     * @param float $sizept size in points
     * @return string the attribute string
     */
    protected function text_attrs(string $family, string $style, float $sizept): string {
        $attrs = ' font-family="' . self::esc($family) . '"'
            . ' font-size="' . self::n($sizept * self::PT_TO_MM) . '"'
            . ' fill="' . self::esc($this->state['fill']) . '"'
            . ' stroke="none"';

        if (stripos($style, 'B') !== false) {
            $attrs .= ' font-weight="bold"';
        }
        if (stripos($style, 'I') !== false) {
            $attrs .= ' font-style="italic"';
        }
        if ($this->state['alpha'] < 1.0) {
            $attrs .= ' fill-opacity="' . self::n($this->state['alpha']) . '"';
        }

        return $attrs;
    }

    /**
     * Escape a value for use in markup.
     *
     * @param string $value raw value
     * @return string escaped value
     */
    protected static function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Record that a group was opened inside the current save frame.
     *
     * @return void
     */
    protected function bump_group(): void {
        if (!$this->groups) {
            $this->groups[] = 0;
            $this->stack[] = $this->state;
        }
        $this->groups[count($this->groups) - 1]++;
    }

    /**
     * A unique identifier for a def.
     *
     * @return string
     */
    protected function next_id(): string {
        return $this->prefix . (++$this->uid);
    }

    /**
     * Build the fill and stroke attributes for the current state.
     *
     * @param string $mode F, D or FD
     * @return string
     */
    protected function paint_attrs(string $mode): string {
        $mode = strtoupper($mode);
        $fills = ($mode === 'F' || $mode === 'FD' || $mode === 'DF');
        $strokes = ($mode === 'D' || $mode === 'FD' || $mode === 'DF');
        $alpha = self::n($this->state['alpha']);

        $out = $fills
            ? ' fill="' . $this->state['fill'] . '" fill-opacity="' . $alpha . '"'
            : ' fill="none"';

        if ($strokes) {
            $out .= ' stroke="' . $this->state['stroke'] . '" stroke-opacity="' . $alpha . '"'
                . ' stroke-width="' . self::n($this->state['width']) . '"';
            if ($this->state['dash'] !== '') {
                $out .= ' stroke-dasharray="' . $this->state['dash'] . '"';
            }
        }

        return $out;
    }

    /**
     * Emit an arc, ellipse or full circle.
     *
     * Angles follow TCPDF: counter-clockwise from the positive x axis, with the
     * y axis pointing down the page, so a point at angle t is at
     * (cx + rx cos t, cy - ry sin t).
     *
     * @param float $cx centre x in millimetres
     * @param float $cy centre y in millimetres
     * @param float $rx horizontal radius
     * @param float $ry vertical radius
     * @param float $rot rotation of the ellipse in degrees
     * @param float $from start angle in degrees
     * @param float $to end angle in degrees
     * @param string $mode F, D or FD
     * @return void
     */
    protected function arc(float $cx, float $cy, float $rx, float $ry, float $rot, float $from,
            float $to, string $mode): void {
        $sweep = abs($to - $from);
        $transform = abs($rot) > 0.0001
            ? ' transform="rotate(' . self::n(-$rot) . ' ' . self::n($cx) . ' ' . self::n($cy) . ')"'
            : '';

        if ($sweep >= 359.99) {
            $this->body[] = '<ellipse cx="' . self::n($cx) . '" cy="' . self::n($cy)
                . '" rx="' . self::n($rx) . '" ry="' . self::n($ry) . '"' . $transform
                . $this->paint_attrs($mode) . '/>';
            return;
        }

        $a0 = deg2rad($from);
        $a1 = deg2rad($to);
        $x0 = $cx + $rx * cos($a0);
        $y0 = $cy - $ry * sin($a0);
        $x1 = $cx + $rx * cos($a1);
        $y1 = $cy - $ry * sin($a1);
        $large = ($sweep > 180) ? 1 : 0;
        // Counter-clockwise in a y-down space is a zero sweep flag in SVG.
        $sweepflag = ($to > $from) ? 0 : 1;

        $d = 'M ' . self::n($x0) . ' ' . self::n($y0)
            . ' A ' . self::n($rx) . ' ' . self::n($ry) . ' 0 ' . $large . ' ' . $sweepflag
            . ' ' . self::n($x1) . ' ' . self::n($y1);
        if (strtoupper($mode) !== 'D') {
            $d .= ' Z';
        }

        $this->body[] = '<path d="' . $d . '"' . $transform . $this->paint_attrs($mode) . '/>';
    }

    /**
     * Format a flat millimetre coordinate list as SVG polygon points.
     *
     * @param float[] $mm x1, y1, x2, y2, ...
     * @return string
     */
    protected function points(array $mm): string {
        $out = [];
        $count = count($mm);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $out[] = self::n($mm[$i]) . ',' . self::n($mm[$i + 1]);
        }
        return implode(' ', $out);
    }

    /**
     * Format path segments as SVG path data.
     *
     * @param array[] $segments the segments in millimetres
     * @return string
     */
    protected function path_data(array $segments): string {
        $out = [];
        foreach ($segments as $seg) {
            $cmd = (string) ($seg[0] ?? '');
            if ($cmd === 'Z') {
                $out[] = 'Z';
                continue;
            }
            $piece = $cmd;
            $count = count($seg);
            for ($i = 1; $i < $count; $i++) {
                $piece .= ' ' . self::n((float) $seg[$i]);
            }
            $out[] = $piece;
        }
        return implode(' ', $out);
    }

    /**
     * Emit a rectangle filled with a gradient.
     *
     * @param string $type linear or radial
     * @param array $op the operation
     * @return void
     */
    protected function gradient_rect(string $type, array $op): void {
        $id = $this->next_id();
        $from = (string) $op[5];
        $to = (string) $op[6];
        $coords = (array) $op[7];

        if ($type === 'linear') {
            $x1 = (float) ($coords[0] ?? 0);
            $y1 = (float) ($coords[1] ?? 0);
            $x2 = (float) ($coords[2] ?? 1);
            $y2 = (float) ($coords[3] ?? 0);
            $this->defs[] = '<linearGradient id="' . $id . '" x1="' . self::n($x1) . '" y1="'
                . self::n($y1) . '" x2="' . self::n($x2) . '" y2="' . self::n($y2) . '">'
                . '<stop offset="0" stop-color="' . $from . '"/>'
                . '<stop offset="1" stop-color="' . $to . '"/></linearGradient>';
        } else {
            $fx = (float) ($coords[0] ?? 0.5);
            $fy = (float) ($coords[1] ?? 0.5);
            $cx = (float) ($coords[3] ?? 0.5);
            $cy = (float) ($coords[4] ?? 0.5);
            $r = (float) ($coords[5] ?? 0.5);
            $this->defs[] = '<radialGradient id="' . $id . '" cx="' . self::n($cx) . '" cy="'
                . self::n($cy) . '" r="' . self::n($r) . '" fx="' . self::n($fx) . '" fy="'
                . self::n($fy) . '">'
                . '<stop offset="0" stop-color="' . $from . '"/>'
                . '<stop offset="1" stop-color="' . $to . '"/></radialGradient>';
        }

        $this->body[] = '<rect x="' . self::n($op[1]) . '" y="' . self::n($op[2])
            . '" width="' . self::n($op[3]) . '" height="' . self::n($op[4])
            . '" fill="url(#' . $id . ')" fill-opacity="' . self::n($this->state['alpha']) . '"/>';
    }

    /**
     * Fill a quad with a bilinear blend by subdividing it.
     *
     * @param float[] $quad the four corners in millimetres
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
        $alpha = self::n($this->state['alpha']);

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
                $c = mesh_math::colour($rgb, ($s0 + $s1) / 2, ($t0 + $t1) / 2);
                $hex = sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);

                $pts = [];
                foreach ($cell as $p) {
                    $pts[] = self::n($p[0]) . ',' . self::n($p[1]);
                }

                // The matching hairline stroke closes the seams between cells,
                // exactly as the TCPDF painter does.
                $this->body[] = '<polygon points="' . implode(' ', $pts) . '" fill="' . $hex
                    . '" fill-opacity="' . $alpha . '" stroke="' . $hex . '" stroke-opacity="'
                    . $alpha . '" stroke-width="0.06"/>';
            }
        }
    }

    /**
     * Format a number compactly for SVG output.
     *
     * @param float $value the number
     * @return string
     */
    protected static function n($value): string {
        $out = number_format((float) $value, 3, '.', '');
        $out = rtrim(rtrim($out, '0'), '.');
        return $out === '' || $out === '-' ? '0' : $out;
    }
}
