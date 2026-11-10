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

/**
 * A QR code that leads to the public verification page.

The code is drawn as vector artwork rather than as a bitmap, so it stays sharp
at any print size and cannot be turned into a black square by a transparency
mishap in the image pipeline.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qrcode extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'qrcode';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_VERIFY;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-qrcode';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [26.0, 26.0];
    }

    /**
     * A code is drawn at min(width, height), so its box may not be stretched.
     *
     * @return bool
     */
    public static function keepsquare(): bool {
        return true;
    }

    /**
     * The configuration a newly dropped code starts with.
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'errorlevel' => 'H',
            'usebrandcolour' => 0,
            'quietzone' => 1,
        ];
    }

    /**
     * Add the error correction and colour choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'errorlevel',
            get_string('qrerrorlevel', 'mod_certificatepro'), [
                'L' => get_string('qrerrorlevel_l', 'mod_certificatepro'),
                'M' => get_string('qrerrorlevel_m', 'mod_certificatepro'),
                'Q' => get_string('qrerrorlevel_q', 'mod_certificatepro'),
                'H' => get_string('qrerrorlevel_h', 'mod_certificatepro'),
            ]);
        $mform->setDefault('errorlevel', 'H');
        $mform->addHelpButton('errorlevel', 'qrerrorlevel', 'mod_certificatepro');

        $mform->addElement('advcheckbox', 'usebrandcolour',
            get_string('qrusebrandcolour', 'mod_certificatepro'));

        $mform->addElement('advcheckbox', 'quietzone',
            get_string('qrquietzone', 'mod_certificatepro'));
        $mform->setDefault('quietzone', 1);
        $mform->addHelpButton('quietzone', 'qrquietzone', 'mod_certificatepro');
    }

    /**
     * Persist the QR settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'errorlevel' => in_array($data->errorlevel ?? 'H', ['L', 'M', 'Q', 'H'], true)
                ? $data->errorlevel : 'H',
            'usebrandcolour' => !empty($data->usebrandcolour) ? 1 : 0,
            'quietzone' => !empty($data->quietzone) ? 1 : 0,
        ];
    }

    /**
     * The address the code points at.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function resolve(render_context $ctx) {
        return $ctx->verify_url();
    }

    /**
     * Draw the code as vector artwork.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $url = (string) $this->resolve_cached($ctx);
        if ($url === '') {
            return;
        }

        $size = min($this->get_width(), $this->get_height());
        if ($size <= 0) {
            $size = 26.0;
        }
        [$left, $top] = $this->box_origin($size, $size);

        $foreground = brand::to_rgb($this->foreground($ctx));

        // A code is only worth having if a phone can read it, and a reader is
        // entitled to expect dark modules on a light field. On a dark ground
        // the code therefore gets its own plate rather than being inverted:
        // inverted codes are read by some scanners and not others, and a
        // certificate's verification code is not the place to find out which.
        $plate = $this->plate($ctx);
        $background = $plate === null ? false : brand::to_rgb($plate);

        $style = [
            'border' => false,
            'vpadding' => $this->config('quietzone', 1) ? 'auto' : 0,
            'hpadding' => $this->config('quietzone', 1) ? 'auto' : 0,
            'fgcolor' => $foreground,
            'bgcolor' => $background,
            'module_width' => 1,
            'module_height' => 1,
        ];

        try {
            $pdf->write2DBarcode(
                $url,
                'QRCODE,' . $this->config('errorlevel', 'H'),
                $left,
                $top,
                $size,
                $size,
                $style,
                'N'
            );
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not draw a QR code: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The side of the square the code is drawn in.
     *
     * @return float millimetres
     */
    protected function drawn_size(): float {
        $size = min($this->get_width(), $this->get_height());
        return $size > 0 ? $size : 26.0;
    }

    /**
     * Produce the designer canvas markup, using TCPDF's dependency free renderer.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        global $CFG;

        // pdflib.php loads the document classes but not the barcode ones, so
        // without this the class below simply does not exist and the canvas
        // showed an empty box where the print showed a code.
        require_once($CFG->libdir . '/pdflib.php');
        require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');

        try {
            $barcode = new \TCPDF2DBarcode(
                $ctx->verify_url(),
                'QRCODE,' . $this->config('errorlevel', 'H')
            );
            $svg = $this->barcode_svg($barcode->getBarcodeArray(), $this->foreground($ctx),
                $this->plate($ctx));
        } catch (\Throwable $e) {
            // Silence here is what hid the missing class in the first place.
            debugging('Certificate Pro could not draw a QR code for the canvas: '
                . $e->getMessage(), DEBUG_DEVELOPER);
            $svg = null;
        }

        if ($svg === null) {
            return \html_writer::div(
                \html_writer::tag('span', static::display_name()),
                'certificatepro-element-placeholder'
            );
        }

        $size = $this->drawn_size();

        // A grid of black squares is worth nothing to a screen reader unless it
        // is announced as a picture with a name, so the box the code is drawn in
        // carries role="img" and the name. The name lives here rather than on
        // the <svg> alone because the wrapper is the element the canvas exposes.
        return \html_writer::div($svg, 'certificatepro-element-qr', [
            'style' => implode(';', $this->canvas_box_style($ctx, $size, $size)),
            'role' => 'img',
            'aria-label' => get_string('qraltext', 'mod_certificatepro'),
        ]);
    }

    /**
     * The colour the modules are drawn in.
     *
     * @param render_context $ctx the render context
     * @return string a CSS colour
     */
    protected function foreground(render_context $ctx): string {
        if (!$this->config('usebrandcolour', 0)) {
            return '#000000';
        }

        // On a dark ground the primarydark role has been lifted off it, which is
        // right for a title and wrong for a code: the modules have to stay dark
        // against the plate drawn behind them.
        $palette = $ctx->needs_light_ink() ? $ctx->get_base_palette() : $ctx->get_palette();

        return $palette['primarydark'] ?? '#000000';
    }

    /**
     * The field the modules are drawn on, or null to leave the page showing.
     *
     * @param render_context $ctx the render context
     * @return string|null a hex colour, or null for no plate
     */
    protected function plate(render_context $ctx): ?string {
        if (!$ctx->needs_light_ink()) {
            return null;
        }

        return $ctx->get_palette()['paper'] ?? '#ffffff';
    }

    /**
     * Turn TCPDF's module grid into SVG.
     *
     * TCPDF's own HTML renderer builds the code out of fixed pixel divs, which
     * cannot follow the designer's zoom and cannot be printed sharply. The same
     * grid as vector artwork matches what write2DBarcode() puts on the page,
     * quiet zone included.
     *
     * @param array|null $code the array from TCPDF2DBarcode::getBarcodeArray()
     * @param string $colour the module colour
     * @param string|null $plate the field behind the modules, or null for none
     * @return string|null the SVG markup, or null when there is no code
     */
    protected function barcode_svg(?array $code, string $colour, ?string $plate = null): ?string {
        if (empty($code['num_rows']) || empty($code['num_cols']) || empty($code['bcode'])) {
            return null;
        }

        $rows = (int) $code['num_rows'];
        $cols = (int) $code['num_cols'];
        // write2DBarcode() reads a quiet zone of 'auto' as four modules a side,
        // and scales the code so the padding is inside the box. Same here.
        $quiet = $this->config('quietzone', 1) ? 4 : 0;

        $rects = [];
        foreach ($code['bcode'] as $r => $row) {
            $run = 0;
            foreach ($row as $c => $module) {
                if ($module) {
                    $run++;
                    continue;
                }
                if ($run > 0) {
                    $rects[] = $this->module_rect($quiet + $c - $run, $quiet + (int) $r, $run);
                    $run = 0;
                }
            }
            if ($run > 0) {
                $rects[] = $this->module_rect($quiet + $cols - $run, $quiet + (int) $r, $run);
            }
        }

        // role="img" plus a <title> is what makes an inline SVG announce itself.
        // The title is referenced by id rather than left to be picked up
        // positionally, because a bare <title> is only used for the accessible
        // name when the reader bothers to look for it.
        $titleid = 'cpro-qr-title-' . ($this->get_id() ?: 0);

        $field = '';
        if ($plate !== null) {
            $field = '<rect x="0" y="0" width="' . ($cols + (2 * $quiet)) . '" height="'
                . ($rows + (2 * $quiet)) . '" fill="' . s($plate) . '"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
            . ($cols + (2 * $quiet)) . ' ' . ($rows + (2 * $quiet)) . '" '
            . 'width="100%" height="100%" preserveAspectRatio="xMidYMid meet" '
            . 'shape-rendering="crispEdges" fill="' . s($colour) . '" '
            . 'role="img" aria-labelledby="' . s($titleid) . '">'
            . '<title id="' . s($titleid) . '">'
            . s(get_string('qraltext', 'mod_certificatepro')) . '</title>'
            . $field . implode('', $rects) . '</svg>';
    }

    /**
     * One horizontal run of dark modules.
     *
     * @param int $x the first column of the run
     * @param int $y the row
     * @param int $length how many modules the run covers
     * @return string the rect markup
     */
    protected function module_rect(int $x, int $y, int $length): string {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $length . '" height="1"/>';
    }}
