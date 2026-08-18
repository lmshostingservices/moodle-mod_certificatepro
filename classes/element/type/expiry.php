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
 * The date the certificate stops being valid.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expiry extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'expiry';
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
        return 'fa-hourglass-half';
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
            'scalerole' => 'meta',
            'size' => null,
        ]);
    }

    /**
     * Add the format and wording choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('text', 'label',
            get_string('expirylabel', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->setDefault('label', get_string('expirylabeldefault', 'mod_certificatepro'));

        $mform->addElement('select', 'dateformat',
            get_string('dateformat', 'mod_certificatepro'), date::formats());
        $mform->setDefault('dateformat', '%d %B %Y');

        $mform->addElement('text', 'nevertext',
            get_string('expirynevertext', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('nevertext', PARAM_TEXT);
        $mform->setDefault('nevertext', get_string('neverexpires', 'mod_certificatepro'));
    }

    /**
     * Persist the expiry settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'label' => (string) ($data->label ?? ''),
            'dateformat' => $data->dateformat ?? '%d %B %Y',
            'nevertext' => (string) ($data->nevertext ?? ''),
        ];
    }

    /**
     * The expiry date, or the wording used when there is none.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $issue = $ctx->get_issue();
        $label = trim((string) $this->config('label', ''));

        if (empty($issue->expires)) {
            $never = trim((string) $this->config('nevertext', ''));
            if ($never === '') {
                return '';
            }
            return $label !== '' ? $label . ' ' . $never : $never;
        }

        $formatted = \mod_certificatepro\local\date_helper::format(
            (int) $issue->expires,
            (string) $this->config('dateformat', '%d %B %Y'),
            '',
            '',
            '99',
            'default'
        );

        return $label !== '' ? $label . ' ' . $formatted : $formatted;
    }}
