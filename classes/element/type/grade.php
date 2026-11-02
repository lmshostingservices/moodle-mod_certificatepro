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
 * A grade, shown as points, a percentage, a letter or a pass or fail word.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'grade';
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
        return 'fa-percent';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [60.0, 8.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(
            parent::default_style(),
            [
                'font' => 'body',
                'scalerole' => 'meta',
                'size' => null,
            ]
        );
    }

    /**
     * Add the grade item, format and decimal choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'select',
            'gradeitem',
            get_string('gradeitem', 'mod_certificatepro'),
            \mod_certificatepro\local\grade_helper::menu($context)
        );

        $mform->addElement(
            'select',
            'gradeformat',
            get_string('gradeformat', 'mod_certificatepro'),
            [
                'percent' => get_string('gradeformat_percent', 'mod_certificatepro'),
                'points' => get_string('gradeformat_points', 'mod_certificatepro'),
                'pointsmax' => get_string('gradeformat_pointsmax', 'mod_certificatepro'),
                'letter' => get_string('gradeformat_letter', 'mod_certificatepro'),
                'passfail' => get_string('gradeformat_passfail', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('gradeformat', 'percent');

        $mform->addElement(
            'select',
            'decimals',
            get_string('gradedecimals', 'mod_certificatepro'),
            [0 => '0', 1 => '1', 2 => '2', 3 => '3']
        );
        $mform->setDefault('decimals', 0);

        $mform->addElement(
            'text',
            'label',
            get_string('gradelabel', 'mod_certificatepro'),
            ['size' => 40]
        );
        $mform->setType('label', PARAM_TEXT);

        $mform->addElement(
            'text',
            'passtext',
            get_string('gradepasstext', 'mod_certificatepro'),
            ['size' => 30]
        );
        $mform->setType('passtext', PARAM_TEXT);
        $mform->setDefault('passtext', get_string('gradepassdefault', 'mod_certificatepro'));
        $mform->hideIf('passtext', 'gradeformat', 'neq', 'passfail');

        $mform->addElement(
            'text',
            'failtext',
            get_string('gradefailtext', 'mod_certificatepro'),
            ['size' => 30]
        );
        $mform->setType('failtext', PARAM_TEXT);
        $mform->setDefault('failtext', get_string('gradefaildefault', 'mod_certificatepro'));
        $mform->hideIf('failtext', 'gradeformat', 'neq', 'passfail');
    }

    /**
     * Persist the grade settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'gradeitem' => (string) ($data->gradeitem ?? ''),
            'gradeformat' => $data->gradeformat ?? 'percent',
            'decimals' => (int) ($data->decimals ?? 0),
            'label' => (string) ($data->label ?? ''),
            'passtext' => (string) ($data->passtext ?? ''),
            'failtext' => (string) ($data->failtext ?? ''),
        ];
    }

    /**
     * The formatted grade.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $value = \mod_certificatepro\local\grade_helper::format(
            (string) $this->config('gradeitem', ''),
            (string) $this->config('gradeformat', 'percent'),
            (int) $this->config('decimals', 0),
            $ctx,
            [
                'pass' => (string) $this->config('passtext', ''),
                'fail' => (string) $this->config('failtext', ''),
            ]
        );

        if ($value === '') {
            return '';
        }

        $label = trim((string) $this->config('label', ''));

        return $label !== '' ? $label . ' ' . $value : $value;
    }}
