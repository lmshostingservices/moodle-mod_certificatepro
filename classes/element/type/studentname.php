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
 * The name of the person the certificate is awarded to.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class studentname extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'studentname';
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
        return 'fa-user';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [180.0, 20.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(parent::default_style(), [
            'font' => 'heading',
            'scalerole' => 'recipient',
            'size' => null,
            'colour' => 'primarydark',
            'maxlines' => 2,
            'fit' => true,
        ]);
    }

    /**
     * Add the name format chooser.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'nameformat',
            get_string('nameformat', 'mod_certificatepro'), self::name_formats());
        $mform->setDefault('nameformat', 'fullname');
        $mform->addHelpButton('nameformat', 'nameformat', 'mod_certificatepro');
    }

    /**
     * Persist the chosen name format.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return ['nameformat' => $data->nameformat ?? 'fullname'];
    }

    /**
     * The available ways of writing a person's name.
     *
     * @return array<string, string>
     */
    public static function name_formats(): array {
        return [
            'fullname' => get_string('nameformat_full', 'mod_certificatepro'),
            'firstlast' => get_string('nameformat_firstlast', 'mod_certificatepro'),
            'lastfirst' => get_string('nameformat_lastfirst', 'mod_certificatepro'),
            'first' => get_string('nameformat_first', 'mod_certificatepro'),
            'last' => get_string('nameformat_last', 'mod_certificatepro'),
            'initials' => get_string('nameformat_initials', 'mod_certificatepro'),
            'alternate' => get_string('nameformat_alternate', 'mod_certificatepro'),
        ];
    }

    /**
     * The recipient's name in the configured format.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $user = $ctx->get_user();
        if ($user === null) {
            return get_string('sample_fullname', 'mod_certificatepro');
        }

        $first = trim((string) ($user->firstname ?? ''));
        $last = trim((string) ($user->lastname ?? ''));

        switch ($this->config('nameformat', 'fullname')) {
            case 'firstlast':
                return trim($first . ' ' . $last);
            case 'lastfirst':
                return trim($last . ', ' . $first);
            case 'first':
                return $first;
            case 'last':
                return $last;
            case 'initials':
                $initials = '';
                foreach (preg_split('/\s+/', trim($first . ' ' . $last)) ?: [] as $part) {
                    if ($part !== '') {
                        $initials .= \core_text::strtoupper(\core_text::substr($part, 0, 1)) . '.';
                    }
                }
                return $initials;
            case 'alternate':
                $alternate = trim((string) ($user->alternatename ?? ''));
                return $alternate !== '' ? trim($alternate . ' ' . $last) : fullname($user);
            default:
                return fullname($user);
        }
    }}
