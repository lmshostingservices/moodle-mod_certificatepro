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

namespace mod_certificatepro\local;

use mod_certificatepro\element\render_context;

/**
 * Reads standard and custom fields from users and courses.
 *
 * Custom fields frequently hold formatted text, so values are passed through
 * format_text rather than format_string when the design allows markup. Running
 * a rich text field through format_string is what flattens a bulleted syllabus
 * into one unreadable line.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_helper {
    /** @var string[] Profile fields a certificate may print. */
    protected const USER_FIELDS = [
        'firstname', 'lastname', 'fullname', 'email', 'idnumber', 'institution',
        'department', 'address', 'city', 'country', 'phone1', 'phone2',
        'description', 'firstnamephonetic', 'lastnamephonetic', 'middlename',
        'alternatename',
    ];

    /** @var string[] Course fields a certificate may print. */
    protected const COURSE_FIELDS = [
        'fullname', 'shortname', 'idnumber', 'summary', 'startdate', 'enddate',
    ];

    /**
     * A selector menu of every user field a certificate can print.
     *
     * @return array<string, string>
     */
    public static function user_field_menu(): array {
        global $DB;

        $menu = ['' => get_string('choosedots')];

        foreach (self::USER_FIELDS as $field) {
            $menu[$field] = self::user_field_label($field);
        }

        $custom = $DB->get_records('user_info_field', null, 'sortorder ASC', 'id, shortname, name');
        foreach ($custom as $field) {
            $menu['profile_' . $field->shortname] = format_string($field->name);
        }

        return $menu;
    }

    /**
     * A human readable label for a standard user field.
     *
     * @param string $field the field name
     * @return string
     */
    protected static function user_field_label(string $field): string {
        $mapped = [
            'fullname' => 'fullname',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'email' => 'email',
            'idnumber' => 'idnumber',
            'institution' => 'institution',
            'department' => 'department',
            'address' => 'address',
            'city' => 'city',
            'country' => 'country',
            'phone1' => 'phone1',
            'phone2' => 'phone2',
            'description' => 'description',
            'middlename' => 'middlename',
            'alternatename' => 'alternatename',
            'firstnamephonetic' => 'firstnamephonetic',
            'lastnamephonetic' => 'lastnamephonetic',
        ];

        $key = $mapped[$field] ?? null;
        if ($key !== null && get_string_manager()->string_exists($key, 'moodle')) {
            return get_string($key);
        }

        return ucfirst($field);
    }

    /**
     * The value of a user field for this recipient.
     *
     * @param string $field the field name, prefixed with profile_ for custom fields
     * @param render_context $ctx the render context
     * @param bool $allowhtml whether formatted text should be preserved
     * @return string
     */
    public static function user_field_value(string $field, render_context $ctx,
            bool $allowhtml = false): string {
        global $CFG, $DB;

        $user = $ctx->get_user();
        if ($user === null || $field === '') {
            return $ctx->is_preview() ? get_string('sample_fieldvalue', 'mod_certificatepro') : '';
        }

        $context = $ctx->get_context();

        if (str_starts_with($field, 'profile_')) {
            require_once($CFG->dirroot . '/user/profile/lib.php');

            $shortname = substr($field, 8);
            $definition = $DB->get_record('user_info_field', ['shortname' => $shortname]);
            if (!$definition) {
                return '';
            }
            $data = $DB->get_record('user_info_data',
                ['userid' => $user->id, 'fieldid' => $definition->id]);
            if (!$data) {
                return '';
            }
            return self::present((string) $data->data, (int) ($data->dataformat ?? FORMAT_MOODLE),
                $context, $allowhtml);
        }

        if ($field === 'fullname') {
            return fullname($user);
        }

        if ($field === 'country' && !empty($user->country)) {
            $countries = get_string_manager()->get_list_of_countries();
            return (string) ($countries[$user->country] ?? $user->country);
        }

        if (!property_exists($user, $field)) {
            return '';
        }

        $value = (string) $user->{$field};
        $format = $field === 'description' ? (int) ($user->descriptionformat ?? FORMAT_MOODLE) : FORMAT_PLAIN;

        return self::present($value, $format, $context, $allowhtml);
    }

    /**
     * A selector menu of every course field a certificate can print.
     *
     * @return array<string, string>
     */
    public static function course_field_menu(): array {
        global $CFG;

        $menu = ['' => get_string('choosedots')];

        foreach (self::COURSE_FIELDS as $field) {
            $menu[$field] = self::course_field_label($field);
        }

        require_once($CFG->dirroot . '/lib/filelib.php');

        $handler = \core_course\customfield\course_handler::create();
        foreach ($handler->get_fields() as $field) {
            $menu['custom_' . $field->get('shortname')] = format_string($field->get('name'));
        }

        return $menu;
    }

    /**
     * A human readable label for a standard course field.
     *
     * @param string $field the field name
     * @return string
     */
    protected static function course_field_label(string $field): string {
        return match ($field) {
            'fullname' => get_string('fullnamecourse'),
            'shortname' => get_string('shortnamecourse'),
            'idnumber' => get_string('idnumbercourse'),
            'summary' => get_string('coursesummary'),
            'startdate' => get_string('startdate'),
            'enddate' => get_string('enddate'),
            default => ucfirst($field),
        };
    }

    /**
     * The value of a course field.
     *
     * @param string $field the field name, prefixed with custom_ for custom fields
     * @param render_context $ctx the render context
     * @param bool $allowhtml whether formatted text should be preserved
     * @return string
     */
    public static function course_field_value(string $field, render_context $ctx,
            bool $allowhtml = true): string {
        $course = $ctx->get_course();
        if ($course === null || $field === '') {
            return $ctx->is_preview() ? get_string('sample_fieldvalue', 'mod_certificatepro') : '';
        }

        $context = $ctx->get_context();

        if (str_starts_with($field, 'custom_')) {
            $shortname = substr($field, 7);
            $handler = \core_course\customfield\course_handler::create();
            $data = $handler->get_instance_data((int) $course->id, true);
            foreach ($data as $datum) {
                if ($datum->get_field()->get('shortname') === $shortname) {
                    $value = $datum->export_value();
                    return $value === null ? '' : self::present((string) $value, FORMAT_HTML,
                        $context, $allowhtml);
                }
            }
            return '';
        }

        if (in_array($field, ['startdate', 'enddate'], true)) {
            $timestamp = (int) ($course->{$field} ?? 0);
            return $timestamp > 0
                ? userdate($timestamp, get_string('strftimedate', 'langconfig'))
                : '';
        }

        if (!property_exists($course, $field)) {
            return '';
        }

        $value = (string) $course->{$field};
        $format = $field === 'summary' ? (int) ($course->summaryformat ?? FORMAT_HTML) : FORMAT_PLAIN;

        return self::present($value, $format, $context, $allowhtml);
    }

    /**
     * Prepare a stored value for printing, keeping or discarding its markup.
     *
     * @param string $value the raw stored value
     * @param int $format the Moodle text format it was stored in
     * @param \context $context the context to format against
     * @param bool $allowhtml whether formatted text should be preserved
     * @return string
     */
    protected static function present(string $value, int $format, \context $context,
            bool $allowhtml): string {
        if ($value === '') {
            return '';
        }

        if ($allowhtml) {
            return format_text($value, $format, ['context' => $context, 'para' => false]);
        }

        return format_string(html_to_text($value, 0, false), true, ['context' => $context]);
    }
}
