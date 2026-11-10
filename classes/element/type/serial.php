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

use mod_certificatepro\element\render_context;
use mod_certificatepro\element\text_base;

/**
 * The certificate's sequential serial number.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class serial extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'serial';
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
        return 'fa-list-ol';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [70.0, 6.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(parent::default_style(), [
            'font' => 'accent',
            'scalerole' => 'code',
            'size' => null,
            'colour' => 'muted',
        ]);
    }

    /**
     * The configuration a newly dropped serial number starts with.
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'label' => get_string('seriallabeldefault', 'mod_certificatepro'),
        ];
    }

    /**
     * Let the author add a label in front of the serial.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('text', 'label',
            get_string('seriallabel', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->setDefault('label', get_string('seriallabeldefault', 'mod_certificatepro'));
    }

    /**
     * Persist the label.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return ['label' => (string) ($data->label ?? '')];
    }

    /**
     * The serial number.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $issue = $ctx->get_issue();
        $serial = (string) ($issue->serial ?? '');
        if ($serial === '') {
            return '';
        }
        $label = trim((string) $this->config('label', ''));
        return $label !== '' ? $label . ' ' . $serial : $serial;
    }}
