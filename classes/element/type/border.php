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

namespace mod_certificatepro\element\type;

use mod_certificatepro\element\base;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\ai\artwork_engine;

/**
 * A decorative frame drawn around the whole page in the site's own colours.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class border extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'border';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DECOR;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-border-all';
    }

    /**
     * Only one frame makes sense per page.
     *
     * @return bool
     */
    public static function allows_multiple(): bool {
        return false;
    }

    /**
     * The default box size in millimetres, replaced by the page size on render.
     *
     * A landscape page is only the starting guess. What the element actually
     * occupies is settled by {@see export_for_designer()}, which reports the
     * real page: stored anything else, a portrait design used to show a frame
     * two thirds of the way down the sheet while the print drew it round the
     * whole page.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [297.0, 210.0];
    }

    /**
     * The configuration a newly dropped frame starts with.
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'borderstyle' => 'classic',
            'inset' => 8.0,
            'thickness' => 0.8,
            'colour' => 'primary',
        ];
    }

    /**
     * A page frame is the size of the page, so there is nothing to resize.
     *
     * The distance the frame stands in from the paper's edge is the inset
     * setting, which is what an author actually wants to change.
     *
     * @return bool
     */
    public static function resizable(): bool {
        return false;
    }

    /**
     * Report the frame to the designer as what it is: the whole page.
     *
     * The print draws the frame from the page dimensions, so a box of any other
     * size is a promise the print will not keep. Rather than let the two drift,
     * the box the canvas draws is replaced with the page itself.
     *
     * @param render_context $ctx the render context
     * @return array
     */
    public function export_for_designer(render_context $ctx): array {
        $data = parent::export_for_designer($ctx);

        $data['posx'] = 0.0;
        $data['posy'] = 0.0;
        $data['width'] = $ctx->get_page_width();
        $data['height'] = $ctx->get_page_height();
        $data['refpoint'] = 'tl';
        $data['rotation'] = 0.0;

        return $data;
    }

    /**
     * The frame styles on offer.
     *
     * @return array<string, string>
     */
    public static function styles(): array {
        return [
            'hairline' => get_string('borderstyle_hairline', 'mod_certificatepro'),
            'double' => get_string('borderstyle_double', 'mod_certificatepro'),
            'classic' => get_string('borderstyle_classic', 'mod_certificatepro'),
            'corners' => get_string('borderstyle_corners', 'mod_certificatepro'),
            'deco' => get_string('borderstyle_deco', 'mod_certificatepro'),
            'band' => get_string('borderstyle_band', 'mod_certificatepro'),
            'guilloche' => get_string('borderstyle_guilloche', 'mod_certificatepro'),
        ];
    }

    /**
     * Add the frame settings.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'borderstyle',
            get_string('borderstyle', 'mod_certificatepro'), self::styles());
        $mform->setDefault('borderstyle', 'classic');

        $mform->addElement('text', 'inset',
            get_string('borderinset', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('inset', PARAM_FLOAT);
        $mform->setDefault('inset', 8);

        $mform->addElement('text', 'thickness',
            get_string('linethickness', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('thickness', PARAM_FLOAT);
        $mform->setDefault('thickness', 0.8);

        $mform->addElement('text', 'colour',
            get_string('elementcolour', 'mod_certificatepro'), ['size' => 12]);
        $mform->setType('colour', PARAM_TEXT);
        $mform->setDefault('colour', 'primary');
        $mform->addHelpButton('colour', 'elementcolour', 'mod_certificatepro');
    }

    /**
     * Persist the frame settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'borderstyle' => array_key_exists($data->borderstyle ?? '', self::styles())
                ? $data->borderstyle : 'classic',
            'inset' => max(0.0, (float) ($data->inset ?? 8)),
            'thickness' => max(0.05, (float) ($data->thickness ?? 0.8)),
            'colour' => (string) ($data->colour ?? 'primary'),
        ];
    }

    /**
     * A frame has no value of its own.
     *
     * @param render_context $ctx the render context
     * @return null
     */
    public function resolve(render_context $ctx) {
        return null;
    }

    /**
     * Draw the frame.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $pagewidth = $ctx->get_page_width();
        $pageheight = $ctx->get_page_height();
        $inset = (float) $this->config('inset', 8);
        $thickness = (float) $this->config('thickness', 0.8);

        $palette = $ctx->get_palette();
        $key = (string) $this->config('colour', 'primary');
        $hex = $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : '#333333');
        [$r, $g, $b] = brand::to_rgb($hex);
        $rgb = [$r, $g, $b];

        $x = $inset;
        $y = $inset;
        $w = $pagewidth - (2 * $inset);
        $h = $pageheight - (2 * $inset);

        if ($w <= 0 || $h <= 0) {
            return;
        }

        $line = static function (float $x1, float $y1, float $x2, float $y2, float $weight)
                use ($pdf, $rgb): void {
            $pdf->Line($x1, $y1, $x2, $y2, ['width' => $weight, 'color' => $rgb]);
        };

        switch ($this->config('borderstyle', 'classic')) {
            case 'hairline':
                $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => $thickness, 'color' => $rgb]]);
                break;

            case 'double':
                $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => $thickness, 'color' => $rgb]]);
                $gap = max(1.5, $thickness * 3);
                $pdf->Rect($x + $gap, $y + $gap, $w - (2 * $gap), $h - (2 * $gap), 'D',
                    ['all' => ['width' => $thickness * 0.5, 'color' => $rgb]]);
                break;

            case 'classic':
                $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => $thickness * 1.6, 'color' => $rgb]]);
                $gap = max(2.0, $thickness * 3.5);
                $pdf->Rect($x + $gap, $y + $gap, $w - (2 * $gap), $h - (2 * $gap), 'D',
                    ['all' => ['width' => $thickness * 0.4, 'color' => $rgb]]);
                // Small squares at each corner of the inner rule.
                $dot = max(1.2, $thickness * 2.4);
                foreach ([[$x + $gap, $y + $gap], [$x + $w - $gap, $y + $gap],
                          [$x + $gap, $y + $h - $gap], [$x + $w - $gap, $y + $h - $gap]] as $corner) {
                    $pdf->Rect($corner[0] - ($dot / 2), $corner[1] - ($dot / 2), $dot, $dot, 'F',
                        [], $rgb);
                }
                break;

            case 'corners':
                $arm = min($w, $h) * 0.14;
                $weight = $thickness * 1.8;
                $line($x, $y, $x + $arm, $y, $weight);
                $line($x, $y, $x, $y + $arm, $weight);
                $line($x + $w - $arm, $y, $x + $w, $y, $weight);
                $line($x + $w, $y, $x + $w, $y + $arm, $weight);
                $line($x, $y + $h - $arm, $x, $y + $h, $weight);
                $line($x, $y + $h, $x + $arm, $y + $h, $weight);
                $line($x + $w - $arm, $y + $h, $x + $w, $y + $h, $weight);
                $line($x + $w, $y + $h - $arm, $x + $w, $y + $h, $weight);
                break;

            case 'deco':
                $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => $thickness, 'color' => $rgb]]);
                $step = max(4.0, min($w, $h) / 28);
                for ($i = 1; $i <= 3; $i++) {
                    $offset = $i * $step * 0.45;
                    $arm = $step * (4 - $i);
                    $weight = $thickness * (0.9 - ($i * 0.2));
                    $line($x + $offset, $y + $offset, $x + $offset + $arm, $y + $offset, $weight);
                    $line($x + $offset, $y + $offset, $x + $offset, $y + $offset + $arm, $weight);
                    $line($x + $w - $offset - $arm, $y + $offset, $x + $w - $offset, $y + $offset, $weight);
                    $line($x + $w - $offset, $y + $offset, $x + $w - $offset, $y + $offset + $arm, $weight);
                    $line($x + $offset, $y + $h - $offset, $x + $offset + $arm, $y + $h - $offset, $weight);
                    $line($x + $offset, $y + $h - $offset - $arm, $x + $offset, $y + $h - $offset, $weight);
                    $line($x + $w - $offset - $arm, $y + $h - $offset, $x + $w - $offset, $y + $h - $offset, $weight);
                    $line($x + $w - $offset, $y + $h - $offset - $arm, $x + $w - $offset, $y + $h - $offset, $weight);
                }
                break;

            case 'band':
                $band = max(4.0, min($w, $h) * 0.035);
                $wash = brand::to_rgb($palette['primarywash'] ?? '#eeeeee');
                $pdf->Rect($x, $y, $w, $band, 'F', [], $rgb);
                $pdf->Rect($x, $y + $h - $band, $w, $band, 'F', [], $rgb);
                $pdf->Rect($x, $y + $band, $w, $band * 0.28, 'F', [], $wash);
                $pdf->Rect($x, $y + $h - $band - ($band * 0.28), $w, $band * 0.28, 'F', [], $wash);
                break;

            case 'guilloche':
                $this->draw_guilloche($pdf, $x, $y, $w, $h, $rgb, $thickness);
                $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => $thickness, 'color' => $rgb]]);
                break;
        }
    }

    /**
     * Draw an interlaced security pattern along the inside of the frame.
     *
     * @param \pdf $pdf the PDF being built
     * @param float $x the frame left edge in millimetres
     * @param float $y the frame top edge in millimetres
     * @param float $w the frame width in millimetres
     * @param float $h the frame height in millimetres
     * @param int[] $rgb the line colour
     * @param float $thickness the line weight in millimetres
     * @return void
     */
    protected function draw_guilloche(\pdf $pdf, float $x, float $y, float $w, float $h,
            array $rgb, float $thickness): void {
        $band = max(3.0, min($w, $h) * 0.022);
        $steps = 240;
        $weight = max(0.08, $thickness * 0.22);

        $wave = static function (float $t, float $length, float $amplitude, float $cycles,
                float $phase): float {
            return $amplitude * sin((2 * M_PI * $cycles * $t) + $phase);
        };

        foreach ([0, 1] as $pass) {
            $phase = $pass * M_PI / 2;
            $cycles = 26 + ($pass * 5);

            // Top and bottom runs.
            $previoustop = null;
            $previousbottom = null;
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $px = $x + ($w * $t);
                $offset = $wave($t, $w, $band / 2, $cycles, $phase);
                $topy = $y + ($band / 2) + $offset;
                $bottomy = $y + $h - ($band / 2) + $offset;
                if ($previoustop !== null) {
                    $pdf->Line($previoustop[0], $previoustop[1], $px, $topy,
                        ['width' => $weight, 'color' => $rgb]);
                    $pdf->Line($previousbottom[0], $previousbottom[1], $px, $bottomy,
                        ['width' => $weight, 'color' => $rgb]);
                }
                $previoustop = [$px, $topy];
                $previousbottom = [$px, $bottomy];
            }

            // Left and right runs.
            $previousleft = null;
            $previousright = null;
            $cycles = 18 + ($pass * 4);
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $py = $y + ($h * $t);
                $offset = $wave($t, $h, $band / 2, $cycles, $phase);
                $leftx = $x + ($band / 2) + $offset;
                $rightx = $x + $w - ($band / 2) + $offset;
                if ($previousleft !== null) {
                    $pdf->Line($previousleft[0], $previousleft[1], $leftx, $py,
                        ['width' => $weight, 'color' => $rgb]);
                    $pdf->Line($previousright[0], $previousright[1], $rightx, $py,
                        ['width' => $weight, 'color' => $rgb]);
                }
                $previousleft = [$leftx, $py];
                $previousright = [$rightx, $py];
            }
        }
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $palette = $ctx->get_palette();
        $key = (string) $this->config('colour', 'primary');
        $hex = $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : '#333333');

        $svg = artwork_engine::border_preview_svg(
            (string) $this->config('borderstyle', 'classic'),
            $hex,
            $palette['primarywash'] ?? '#f2f6fb',
            $ctx->get_page_width(),
            $ctx->get_page_height(),
            (float) $this->config('inset', 8),
            (float) $this->config('thickness', 0.8)
        );

        return \html_writer::div($svg, 'certificatepro-element-border');
    }}
