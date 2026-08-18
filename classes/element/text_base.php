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

        $pdf->writeHTMLCell(
            $width,
            0,
            $left,
            $top,
            $html,
            0,
            1,
            false,
            true,
            $this->get_alignment(),
            true
        );

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
        $colour = $this->resolve_colour($ctx);
        $size = $this->resolve_size($ctx);
        [$family] = $this->resolve_font($ctx);

        $styles = [
            'color:' . $colour,
            'font-size:' . $size . 'pt',
            'font-family:' . font_manager::css_font_stack($family),
            'letter-spacing:' . (float) $this->style('tracking', 0.0) . 'pt',
            'line-height:' . (float) $this->style('lineheight', 1.25),
            'text-align:' . $this->css_align(),
            'opacity:' . (float) $this->style('opacity', 1.0),
        ];
        if ($this->style('bold', false)) {
            $styles[] = 'font-weight:700';
        }
        if ($this->style('italic', false)) {
            $styles[] = 'font-style:italic';
        }
        if ($this->style('underline', false)) {
            $styles[] = 'text-decoration:underline';
        }

        $body = $this->allows_html() ? $this->clean_html($text) : nl2br(s($text), false);

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
