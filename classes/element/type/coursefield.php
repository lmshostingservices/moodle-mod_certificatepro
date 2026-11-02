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
 * Any standard or custom field from the course.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursefield extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'coursefield';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DATA;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-table-list';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [110.0, 10.0];
    }

    /**
     * Custom course fields often hold formatted text.
     *
     * @return bool
     */
    protected function allows_html(): bool {
        return (bool) $this->config('allowhtml', 1);
    }

    /**
     * Add the field chooser.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'select',
            'coursefield',
            get_string('coursefield', 'mod_certificatepro'),
            \mod_certificatepro\local\field_helper::course_field_menu()
        );

        $mform->addElement(
            'advcheckbox',
            'allowhtml',
            get_string('allowhtml', 'mod_certificatepro')
        );
        $mform->setDefault('allowhtml', 1);
        $mform->addHelpButton('allowhtml', 'allowhtml', 'mod_certificatepro');

        $mform->addElement(
            'text',
            'label',
            get_string('fieldlabel', 'mod_certificatepro'),
            ['size' => 40]
        );
        $mform->setType('label', PARAM_TEXT);
    }

    /**
     * Persist the field settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'coursefield' => (string) ($data->coursefield ?? ''),
            'allowhtml' => !empty($data->allowhtml) ? 1 : 0,
            'label' => (string) ($data->label ?? ''),
        ];
    }

    /**
     * The course field value.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $value = \mod_certificatepro\local\field_helper::course_field_value(
            (string) $this->config('coursefield', ''),
            $ctx,
            $this->allows_html()
        );

        if ($value === '') {
            return '';
        }

        $label = trim((string) $this->config('label', ''));

        return $label !== '' ? $label . ' ' . $value : $value;
    }}
