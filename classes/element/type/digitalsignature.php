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
use mod_certificatepro\element\image_base;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;

/**
 * Applies a cryptographic signature to the finished PDF, so a reader can
confirm the file has not been altered since the site issued it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digitalsignature extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'digitalsignature';
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
        return 'fa-file-shield';
    }

    /**
     * Only one cryptographic signature is applied per document.
     *
     * @return bool
     */
    public static function allows_multiple(): bool {
        return false;
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [40.0, 14.0];
    }

    /**
     * Add the certificate details shown to a reader.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('static', 'certificatehint', '',
            get_string('digitalsignaturehint', 'mod_certificatepro'));

        $mform->addElement('text', 'signername',
            get_string('signername', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('signername', PARAM_TEXT);

        $mform->addElement('text', 'reason',
            get_string('signreason', 'mod_certificatepro'), ['size' => 50]);
        $mform->setType('reason', PARAM_TEXT);

        $mform->addElement('text', 'location',
            get_string('signlocation', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('location', PARAM_TEXT);

        $mform->addElement('text', 'contact',
            get_string('signcontact', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('contact', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'showvisual',
            get_string('signshowvisual', 'mod_certificatepro'));
        $mform->setDefault('showvisual', 1);
    }

    /**
     * Persist the signature details.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'signername' => (string) ($data->signername ?? ''),
            'reason' => (string) ($data->reason ?? ''),
            'location' => (string) ($data->location ?? ''),
            'contact' => (string) ($data->contact ?? ''),
            'showvisual' => !empty($data->showvisual) ? 1 : 0,
        ];
    }

    /**
     * The signature has no printed value.
     *
     * @param render_context $ctx the render context
     * @return null
     */
    public function resolve(render_context $ctx) {
        return null;
    }

    /**
     * Apply the signature to the document and draw its visible marker.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $certificate = trim((string) get_config('mod_certificatepro', 'signingcertificate'));
        $privatekey = trim((string) get_config('mod_certificatepro', 'signingprivatekey'));
        if ($certificate === '' || $privatekey === '') {
            return;
        }

        $password = (string) get_config('mod_certificatepro', 'signingpassword');

        try {
            $pdf->setSignature(
                $certificate,
                $privatekey,
                $password,
                '',
                2,
                [
                    'Name' => (string) $this->config('signername', ''),
                    'Location' => (string) $this->config('location', ''),
                    'Reason' => (string) $this->config('reason', ''),
                    'ContactInfo' => (string) $this->config('contact', ''),
                ]
            );

            if ($this->config('showvisual', 1)) {
                $width = max(10.0, $this->get_width());
                $height = max(6.0, $this->get_height());
                [$left, $top] = $this->box_origin($width, $height);
                $pdf->setSignatureAppearance($left, $top, $width, $height);
            }
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not sign the document: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        return \html_writer::div(
            \html_writer::tag('span', get_string('element_digitalsignature', 'mod_certificatepro')),
            'certificatepro-element-placeholder certificatepro-element-signed'
        );
    }}
