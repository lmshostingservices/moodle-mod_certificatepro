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
 * The name of a teacher in the course, or of a named signatory.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachername extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'teachername';
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
        return 'fa-chalkboard-user';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [70.0, 8.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(parent::default_style(), [
            'font' => 'body',
            'scalerole' => 'signaturename',
            'size' => null,
        ]);
    }

    /**
     * The configuration a newly dropped teacher name starts with.
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'teachermode' => 'first',
            'teacherid' => 0,
        ];
    }

    /**
     * Choose which teacher to name.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'teachermode',
            get_string('teachermode', 'mod_certificatepro'), [
                'first' => get_string('teachermode_first', 'mod_certificatepro'),
                'all' => get_string('teachermode_all', 'mod_certificatepro'),
                'specific' => get_string('teachermode_specific', 'mod_certificatepro'),
            ]);
        $mform->setDefault('teachermode', 'first');

        $mform->addElement('select', 'teacherid',
            get_string('teacher', 'mod_certificatepro'),
            \mod_certificatepro\local\course_helper::teacher_menu($context));
        $mform->hideIf('teacherid', 'teachermode', 'neq', 'specific');
    }

    /**
     * Persist the chosen teacher.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'teachermode' => $data->teachermode ?? 'first',
            'teacherid' => (int) ($data->teacherid ?? 0),
        ];
    }

    /**
     * The teacher name or names.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $teachers = \mod_certificatepro\local\course_helper::teachers($ctx->get_context());

        switch ($this->config('teachermode', 'first')) {
            case 'all':
                $names = array_map(static fn($t) => fullname($t), $teachers);
                return implode("\n", $names);
            case 'specific':
                $id = (int) $this->config('teacherid', 0);
                return isset($teachers[$id]) ? fullname($teachers[$id]) : '';
            default:
                $first = reset($teachers);
                return $first ? fullname($first) : get_string('sample_teachername', 'mod_certificatepro');
        }
    }}
