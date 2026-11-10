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

namespace mod_certificatepro\element;

use mod_certificatepro\local\brand;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;

/**
 * Shared behaviour for every element that draws words.
 *
 * All the typography lives here: font resolution against the design's pairing,
 * letter spacing, line height, case transforms, alignment, rotation, and the
 * shrink to fit behaviour that stops a long course name from overflowing its
 * box. Subclasses only have to say what the words are.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class text_base extends base {
    /** @var float The smallest point size shrink to fit will go down to. */
    protected const MIN_FIT_SIZE = 5.0;

    /** @var \pdf|null A throwaway document the canvas measures text with. */
    protected static $measurer = null;

    /** @var bool Whether building the measuring document has already failed. */
    protected static bool $measurerfailed = false;

    /**
     * The words this element draws.
     *
     * @param render_context $ctx the render context
     * @return string plain text or simple HTML
     */
    abstract protected function text(render_context $ctx): string;

    /**
     * Resolve to the interpolated, case transformed text.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function resolve(render_context $ctx) {
        $text = $this->text($ctx);
        $text = $ctx->interpolate($text);
        $case = (string) $this->style('case', 'none');
        if ($case !== 'none') {
            $text = $this->transform_case($text, $case);
        }
        return $text;
    }

    /**
     * Apply a case transform.
     *
     * @param string $text the text to transform
     * @param string $case upper, lower or title
     * @return string
     */
    protected function transform_case(string $text, string $case): string {
        return match ($case) {
            'upper' => \core_text::strtoupper($text),
            'lower' => \core_text::strtolower($text),
            'title' => \core_text::strtotitle($text),
            default => $text,
        };
    }

    /**
     * Whether this element's text may contain HTML markup.
     *
     * @return bool
     */
    protected function allows_html(): bool {
        return false;
    }

    /**
     * The concrete TCPDF font family and style for this element.
     *
     * @param render_context $ctx the render context
     * @return array{0: string, 1: string} the family then the style flags
     */
    protected function resolve_font(render_context $ctx): array {
        $role = (string) $this->style('font', 'body');
        $installed = font_manager::installed_families();
        $pairing = typography::resolve($ctx->get_pairing(), $installed);

        if (in_array($role, ['heading', 'body', 'accent'], true)) {
            $family = $pairing[$role]['resolved'];
            $style = (string) $pairing[$role]['style'];
        } else {
            // The author picked an explicit family rather than a semantic role.
            $family = font_manager::sanitise_family($role);
            $style = '';
        }

        if ($this->style('bold', false) && strpos($style, 'B') === false) {
            $style .= 'B';
        }
        if ($this->style('italic', false) && strpos($style, 'I') === false) {
            $style .= 'I';
        }
        if ($this->style('underline', false) && strpos($style, 'U') === false) {
            $style .= 'U';
        }

        return [$family, $style];
    }

    /**
     * The resolved colour for this element as a hex string.
     *
     * @param render_context $ctx the render context
     * @return string a colour in #rrggbb form
     */
    protected function resolve_colour(render_context $ctx): string {
        $value = (string) $this->style('colour', 'ink');
        $palette = $ctx->get_palette();
        if (isset($palette[$value])) {
            return $palette[$value];
        }
        if (brand::is_hex($value)) {
            return brand::normalise_hex($value);
        }
        return $palette['ink'] ?? '#111111';
    }

    /**
     * The point size for this element, resolved against the design's type scale.
     *
     * @param render_context $ctx the render context
     * @return float
     */
    protected function resolve_size(render_context $ctx): float {
        $size = $this->style('size', null);
        if (is_numeric($size)) {
            return (float) $size;
        }
        $role = (string) $this->style('scalerole', 'body');
        return typography::size($ctx->get_pairing(), $role);
    }

    /**
     * Draw the text onto the PDF.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $text = (string) $this->resolve_cached($ctx);
        if (trim(strip_tags($text)) === '' && trim($text) === '') {
            return;
        }

        [$family, $style] = $this->resolve_font($ctx);

        // Emoji have no glyph in any face TCPDF can reach, and asking for one
        // makes TCPDF raise an undefined-index error inside its width lookup.
        // Dropping them prints the name without the decoration, which is far
        // better than losing the whole element to the renderer's guard.
        $text = font_manager::strip_undrawable($text);

        $colour = $this->resolve_colour($ctx);
        [$r, $g, $b] = brand::to_rgb($colour);
        $size = $this->resolve_size($ctx);
        $tracking = (float) $this->style('tracking', 0.0);
        $lineheight = (float) $this->style('lineheight', 1.25);
        $opacity = (float) $this->style('opacity', 1.0);

        $width = $this->get_width();
        if ($width <= 0) {
            $width = max(10.0, $ctx->get_page_width() - 20.0);
        }

        $pdf->SetTextColor($r, $g, $b);
        $pdf->setFontSpacing($this->points_to_mm($tracking));
        $pdf->setCellHeightRatio($lineheight);
        if ($opacity < 1.0) {
            $pdf->SetAlpha(max(0.0, min(1.0, $opacity)));
        }

        $html = $this->allows_html() ? $this->clean_html($text) : nl2br(s($text), false);

        // A recipient's name is not negotiable, and no single face TCPDF ships
        // draws Latin, Arabic and Han at once. Any part of the text the design's
        // own face cannot draw is tagged here with one that can, so a mixed
        // script name comes out complete instead of half missing.
        $html = font_manager::apply_font_runs($pdf, $family, $html);

        // Shrink to fit keeps a long value inside its box instead of overflowing.
        if ($this->style('fit', true)) {
            $size = $this->fit_size($pdf, $family, $style, $size, $html, $width);
        }
        $pdf->SetFont($family, $style, $size);

        $height = $this->get_height();
        $measured = $this->measure($pdf, $html, $width);
        if ($height <= 0) {
            $height = $measured;
        }

        [$left, $top] = $this->box_origin($width, $height);

        // Vertical alignment inside a fixed height box.
        if ($height > $measured) {
            $top += match ($this->get_valignment()) {
                'M' => ($height - $measured) / 2,
                'B' => $height - $measured,
                default => 0.0,
            };
        }

        $rotation = $this->get_rotation();
        if (abs($rotation) > 0.001) {
            $pdf->StartTransform();
            $pdf->Rotate($rotation, $this->get_posx(), $this->get_posy());
        }

        // The write is guarded because a font without a glyph for some character
        // makes TCPDF raise an undefined-index warning, which a site running
        // developer debugging promotes to an exception. The renderer's own guard
        // would then abandon the element, and a certificate that prints without
        // the recipient's name on it is worse than one that prints their name
        // with a character missing. So a failure is retried once with anything
        // outside Latin-1 removed, which every bundled face can draw.
        try {
            $pdf->writeHTMLCell($width, 0, $left, $top, $html, 0, 1, false, true,
                $this->get_alignment(), true);
        } catch (\Throwable $e) {
            $safe = preg_replace('/[^\x{0000}-\x{00FF}]/u', '', $html);
            if ($safe === null || $safe === $html) {
                throw $e;
            }
            debugging('Certificate Pro dropped characters the font "' . $family
                . '" cannot draw: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $pdf->writeHTMLCell($width, 0, $left, $top, $safe, 0, 1, false, true,
                $this->get_alignment(), true);
        }

        if (abs($rotation) > 0.001) {
            $pdf->StopTransform();
        }

        if ($opacity < 1.0) {
            $pdf->SetAlpha(1.0);
        }
        $pdf->setFontSpacing(0);
        $pdf->setCellHeightRatio(1.25);
    }

    /**
     * Measure how tall the text will be at the current font settings.
     *
     * @param \pdf $pdf the PDF being built
     * @param string $html the markup to measure
     * @param float $width the box width in millimetres
     * @return float the height in millimetres
     */
    protected function measure(\pdf $pdf, string $html, float $width): float {
        return (float) $pdf->getStringHeight($width, $html, false, true, '', 0);
    }

    /**
     * Reduce the point size until the text fits the available box.
     *
     * @param \pdf $pdf the PDF being built
     * @param string $family the font family
     * @param string $style the font style flags
     * @param float $size the requested point size
     * @param string $html the markup to fit
     * @param float $width the box width in millimetres
     * @return float the point size that fits
     */
    protected function fit_size(\pdf $pdf, string $family, string $style, float $size,
            string $html, float $width): float {
        $maxheight = $this->get_height();
        $maxlines = (int) $this->style('maxlines', 0);

        if ($maxheight <= 0 && $maxlines <= 0) {
            return $size;
        }

        $current = $size;
        for ($i = 0; $i < 40; $i++) {
            $pdf->SetFont($family, $style, $current);
            $height = $this->measure($pdf, $html, $width);
            $lineheight = (float) $pdf->getStringHeight($width, 'Mg', false, true, '', 0);
            $lines = $lineheight > 0 ? (int) ceil($height / $lineheight) : 1;

            $toohigh = $maxheight > 0 && $height > $maxheight;
            $toomany = $maxlines > 0 && $lines > $maxlines;

            if (!$toohigh && !$toomany) {
                return $current;
            }
            if ($current <= self::MIN_FIT_SIZE) {
                return self::MIN_FIT_SIZE;
            }
            $current = max(self::MIN_FIT_SIZE, $current - 0.5);
        }

        return $current;
    }

    /**
     * Convert a point measurement to millimetres.
     *
     * @param float $points the value in points
     * @return float the value in millimetres
     */
    protected function points_to_mm(float $points): float {
        return $points * 25.4 / 72.0;
    }

    /**
     * A document used only for measuring, shared by every element in the request.
     *
     * The canvas has no PDF of its own, so shrink to fit had nothing to measure
     * against and simply did not happen there: a long course name was sliced by
     * the overflow rule on screen and set complete in the print. Measuring with
     * the same engine the print uses is the only way the two can agree on the
     * point size, and one shared document costs a few milliseconds for the whole
     * page rather than one document per element.
     *
     * @return \pdf|null the document, or null when one cannot be built
     */
    protected static function measurement_pdf(): ?\pdf {
        global $CFG;

        if (self::$measurer !== null || self::$measurerfailed) {
            return self::$measurer;
        }

        try {
            require_once($CFG->libdir . '/pdflib.php');

            $pdf = new \pdf();
            $pdf->SetPrintHeader(false);
            $pdf->SetPrintFooter(false);
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->SetMargins(0, 0, 0, true);
            $pdf->SetCellPadding(0);
            $pdf->AddPage('L', [297.0, 210.0]);

            self::$measurer = $pdf;
        } catch (\Throwable $e) {
            // Losing the measurement only costs the canvas its shrink to fit,
            // so it must never cost the author the designer.
            self::$measurerfailed = true;
            debugging('Certificate Pro could not build a text measuring document: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return self::$measurer;
    }

    /**
     * The point size the PDF will actually set this text in.
     *
     * @param render_context $ctx the render context
     * @param string $family the font family
     * @param string $style the font style flags
     * @param float $size the requested point size
     * @param string $html the markup that has to fit
     * @param float $width the box width in millimetres
     * @return float the point size, shrunk to fit when the design asks for it
     */
    protected function fitted_size(render_context $ctx, string $family, string $style,
            float $size, string $html, float $width): float {
        if (!$this->style('fit', true)) {
            return $size;
        }

        $pdf = self::measurement_pdf();
        if ($pdf === null) {
            return $size;
        }

        try {
            // The same settings the print applies before it fits, because both
            // tracking and line height change how much fits in the box.
            $pdf->setFontSpacing($this->points_to_mm((float) $this->style('tracking', 0.0)));
            $pdf->setCellHeightRatio((float) $this->style('lineheight', 1.25));
            $fitted = $this->fit_size($pdf, $family, $style, $size, $html, $width);
            $pdf->setFontSpacing(0);
            $pdf->setCellHeightRatio(1.25);
            return $fitted;
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not fit text for the canvas: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return $size;
        }
    }

    /**
     * Strip everything from author supplied HTML that a PDF cannot draw safely.
     *
     * @param string $html the markup to clean
     * @return string
     */
    protected function clean_html(string $html): string {
        $allowed = '<p><br><b><strong><i><em><u><span><ul><ol><li><h1><h2><h3><h4>'
            . '<small><sup><sub><table><tr><td><th><tbody><thead><hr><div><font>';
        $html = strip_tags($html, $allowed);
        // Remove any event handler or script protocol that survived stripping.
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]*/i', '', $html) ?? $html;
        return $html;
    }

    /**
     * Produce the designer canvas markup for this element.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $text = (string) $this->resolve($ctx);

        // An element with nothing to say draws nothing, on paper and on screen,
        // which in the designer means an element the author cannot find or
        // select. Name it instead, the way an image with no file is named.
        if (trim(strip_tags($text)) === '' && trim($text) === '') {
            return \html_writer::div(
                \html_writer::tag('span', static::display_name()),
                'certificatepro-element-placeholder'
            );
        }

        $colour = $this->resolve_colour($ctx);
        $size = $this->resolve_size($ctx);
        [$family, $fontstyle] = $this->resolve_font($ctx);
        $tracking = (float) $this->style('tracking', 0.0);

        $body = $this->allows_html() ? $this->clean_html($text) : nl2br(s($text), false);

        $width = $this->get_width();
        if ($width <= 0) {
            $width = max(10.0, $ctx->get_page_width() - 20.0);
        }
        $size = $this->fitted_size($ctx, $family, $fontstyle, $size, $body, $width);

        $styles = [
            'color:' . $colour,
            // The absolute value first as the fallback a browser without
            // container query units keeps, then the value that tracks the zoom.
            'font-size:' . $size . 'pt',
            'font-size:' . $this->canvas_points($ctx, $size),
            'font-family:' . font_manager::css_font_stack($family),
            'letter-spacing:' . $tracking . 'pt',
            'letter-spacing:' . $this->canvas_points($ctx, $tracking),
            'line-height:' . (float) $this->style('lineheight', 1.25),
            'text-align:' . $this->css_align(),
            'opacity:' . (float) $this->style('opacity', 1.0),
            // The print aligns the text inside a fixed height box by the
            // vertical alignment; the canvas centred everything regardless.
            'margin-top:' . ($this->get_valignment() === 'T' ? '0' : 'auto'),
            'margin-bottom:' . ($this->get_valignment() === 'B' ? '0' : 'auto'),
        ];
        // The weight and slope come from the resolved font rather than from the
        // style flags alone, because a pairing can carry them: the heading role
        // of most pairings is bold, and reading only the author's own bold flag
        // set every heading on the canvas in the regular weight the print never
        // used.
        if (strpos($fontstyle, 'B') !== false) {
            $styles[] = 'font-weight:700';
        } else {
            $styles[] = 'font-weight:400';
        }
        if (strpos($fontstyle, 'I') !== false) {
            $styles[] = 'font-style:italic';
        }
        if (strpos($fontstyle, 'U') !== false) {
            $styles[] = 'text-decoration:underline';
        }

        return \html_writer::div($body, 'certificatepro-element-text', [
            'style' => implode(';', $styles),
        ]);
    }

    /**
     * Translate the stored alignment flag into a CSS value.
     *
     * @return string
     */
    protected function css_align(): string {
        return match ($this->get_alignment()) {
            'C' => 'center',
            'R' => 'right',
            'J' => 'justify',
            default => 'left',
        };
    }
}
