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
 * A date, written in whichever format and language the design calls for.

Unlike a plain call to userdate, the format, the language and the timezone are
all fixed by the design rather than inherited from whoever happens to be
looking, so the same certificate reads the same to everyone.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class date extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'date';
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
        return 'fa-calendar-day';
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
     * The dates a certificate can show.
     *
     * @return array<string, string>
     */
    public static function sources(): array {
        return [
            'issued' => get_string('date_issued', 'mod_certificatepro'),
            'completion' => get_string('date_completion', 'mod_certificatepro'),
            'enrolment' => get_string('date_enrolment', 'mod_certificatepro'),
            'coursestart' => get_string('date_coursestart', 'mod_certificatepro'),
            'courseend' => get_string('date_courseend', 'mod_certificatepro'),
            'expiry' => get_string('date_expiry', 'mod_certificatepro'),
            'today' => get_string('date_today', 'mod_certificatepro'),
        ];
    }

    /**
     * The built in date formats, plus a free text option.
     *
     * @return array<string, string>
     */
    public static function formats(): array {
        $sample = 1755216000;
        $patterns = [
            '%d %B %Y',
            '%B %d, %Y',
            '%d/%m/%Y',
            '%m/%d/%Y',
            '%Y-%m-%d',
            '%d.%m.%Y',
            '%A, %d %B %Y',
            '%B %Y',
            '%Y',
        ];
        $formats = [];
        foreach ($patterns as $pattern) {
            $formats[$pattern] = userdate($sample, $pattern, 99, false, false);
        }
        $formats['ordinal'] = get_string('date_ordinal', 'mod_certificatepro');
        $formats['custom'] = get_string('date_custom', 'mod_certificatepro');
        return $formats;
    }

    /**
     * Add the date source, format, language and timezone choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'select',
            'datesource',
            get_string('datesource', 'mod_certificatepro'),
            self::sources()
        );
        $mform->setDefault('datesource', 'issued');

        $mform->addElement(
            'select',
            'dateformat',
            get_string('dateformat', 'mod_certificatepro'),
            self::formats()
        );
        $mform->setDefault('dateformat', '%d %B %Y');

        $mform->addElement(
            'text',
            'datecustom',
            get_string('date_custom', 'mod_certificatepro'),
            ['size' => 30]
        );
        $mform->setType('datecustom', PARAM_TEXT);
        $mform->hideIf('datecustom', 'dateformat', 'neq', 'custom');
        $mform->addHelpButton('datecustom', 'date_custom', 'mod_certificatepro');

        $languages = ['' => get_string('datelanguagedefault', 'mod_certificatepro')]
            + get_string_manager()->get_list_of_translations();
        $mform->addElement(
            'select',
            'datelanguage',
            get_string('datelanguage', 'mod_certificatepro'),
            $languages
        );

        $timezones = ['99' => get_string('datetimezoneserver', 'mod_certificatepro')]
            + \core_date::get_list_of_timezones();
        $mform->addElement(
            'select',
            'datetimezone',
            get_string('datetimezone', 'mod_certificatepro'),
            $timezones
        );
        $mform->setDefault('datetimezone', '99');

        $mform->addElement(
            'select',
            'datenumerals',
            get_string('datenumerals', 'mod_certificatepro'),
            [
                'default' => get_string('datenumerals_default', 'mod_certificatepro'),
                'western' => get_string('datenumerals_western', 'mod_certificatepro'),
                'arabic' => get_string('datenumerals_arabic', 'mod_certificatepro'),
                'devanagari' => get_string('datenumerals_devanagari', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('datenumerals', 'default');

        $mform->addElement(
            'text',
            'dateprefix',
            get_string('dateprefix', 'mod_certificatepro'),
            ['size' => 40]
        );
        $mform->setType('dateprefix', PARAM_TEXT);
    }

    /**
     * Persist the date settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'datesource' => $data->datesource ?? 'issued',
            'dateformat' => $data->dateformat ?? '%d %B %Y',
            'datecustom' => (string) ($data->datecustom ?? ''),
            'datelanguage' => (string) ($data->datelanguage ?? ''),
            'datetimezone' => (string) ($data->datetimezone ?? '99'),
            'datenumerals' => $data->datenumerals ?? 'default',
            'dateprefix' => (string) ($data->dateprefix ?? ''),
        ];
    }

    /**
     * The formatted date.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $timestamp = $this->timestamp($ctx);
        if ($timestamp === null) {
            return '';
        }

        $formatted = \mod_certificatepro\local\date_helper::format(
            $timestamp,
            (string) $this->config('dateformat', '%d %B %Y'),
            (string) $this->config('datecustom', ''),
            (string) $this->config('datelanguage', ''),
            (string) $this->config('datetimezone', '99'),
            (string) $this->config('datenumerals', 'default')
        );

        $prefix = trim((string) $this->config('dateprefix', ''));

        return $prefix !== '' ? $prefix . ' ' . $formatted : $formatted;
    }

    /**
     * The timestamp the chosen source resolves to.
     *
     * @param render_context $ctx the render context
     * @return int|null null when the source has no value for this recipient
     */
    protected function timestamp(render_context $ctx): ?int {
        return \mod_certificatepro\local\date_helper::resolve(
            (string) $this->config('datesource', 'issued'),
            $ctx
        );
    }}
