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
     * Add the error correction and colour choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'select',
            'errorlevel',
            get_string('qrerrorlevel', 'mod_certificatepro'),
            [
                'L' => get_string('qrerrorlevel_l', 'mod_certificatepro'),
                'M' => get_string('qrerrorlevel_m', 'mod_certificatepro'),
                'Q' => get_string('qrerrorlevel_q', 'mod_certificatepro'),
                'H' => get_string('qrerrorlevel_h', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('errorlevel', 'H');
        $mform->addHelpButton('errorlevel', 'qrerrorlevel', 'mod_certificatepro');

        $mform->addElement(
            'advcheckbox',
            'usebrandcolour',
            get_string('qrusebrandcolour', 'mod_certificatepro')
        );

        $mform->addElement(
            'advcheckbox',
            'quietzone',
            get_string('qrquietzone', 'mod_certificatepro')
        );
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

        $foreground = [0, 0, 0];
        if ($this->config('usebrandcolour', 0)) {
            $foreground = brand::to_rgb($ctx->get_palette()['primarydark'] ?? '#000000');
        }

        $style = [
            'border' => false,
            'vpadding' => $this->config('quietzone', 1) ? 'auto' : 0,
            'hpadding' => $this->config('quietzone', 1) ? 'auto' : 0,
            'fgcolor' => $foreground,
            'bgcolor' => false,
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
     * Produce the designer canvas markup, using TCPDF's dependency free renderer.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        try {
            $barcode = new \TCPDF2DBarcode(
                $ctx->verify_url(),
                'QRCODE,' . $this->config('errorlevel', 'H')
            );
            $colour = $this->config('usebrandcolour', 0)
                ? ($ctx->get_palette()['primarydark'] ?? 'black')
                : 'black';
            $html = $barcode->getBarcodeHTML(4, 4, $colour);
        } catch (\Throwable $e) {
            return \html_writer::div('', 'certificatepro-element-placeholder');
        }

        return \html_writer::div($html, 'certificatepro-element-qr');
    }}
