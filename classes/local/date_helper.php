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
 * Turns timestamps into the words that appear on a certificate.
 *
 * The important property is determinism. A certificate is a document about a
 * moment in the past, so its date must not change depending on who is reading
 * it, what language they browse in, or what timezone they are sitting in. The
 * design fixes the format, the language and the timezone; nothing here reads
 * the current user's preferences unless the design explicitly asks it to.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class date_helper {
    /**
     * Format a timestamp.
     *
     * @param int $timestamp the moment to format
     * @param string $format a strftime style pattern, or ordinal, or custom
     * @param string $custom the pattern to use when the format is custom
     * @param string $language a language pack code, or empty for the site default
     * @param string $timezone a timezone identifier, or 99 for the server timezone
     * @param string $numerals default, western, arabic or devanagari
     * @return string
     */
    public static function format(int $timestamp, string $format, string $custom = '',
            string $language = '', string $timezone = '99', string $numerals = 'default'): string {
        if ($timestamp <= 0) {
            return '';
        }

        $pattern = $format;
        if ($format === 'custom') {
            $pattern = $custom !== '' ? $custom : '%d %B %Y';
        }

        $resolvedzone = $timezone === '99' || $timezone === '' ? \core_date::get_server_timezone() : $timezone;

        if ($format === 'ordinal') {
            $formatted = self::ordinal_date($timestamp, $resolvedzone, $language);
        } else {
            $formatted = self::localised_date($timestamp, $pattern, $resolvedzone, $language);
        }

        return self::convert_numerals($formatted, $numerals);
    }

    /**
     * Format a timestamp in a fixed language, restoring the caller's language afterwards.
     *
     * @param int $timestamp the moment to format
     * @param string $pattern a strftime style pattern
     * @param string $timezone a timezone identifier
     * @param string $language a language pack code, or empty for the current language
     * @return string
     */
    protected static function localised_date(int $timestamp, string $pattern, string $timezone,
            string $language): string {
        if ($language === '') {
            return userdate($timestamp, $pattern, $timezone, false, false);
        }

        $stringmanager = get_string_manager();
        if (!$stringmanager->translation_exists($language, false)) {
            return userdate($timestamp, $pattern, $timezone, false, false);
        }

        $previous = force_current_language($language);
        try {
            $formatted = userdate($timestamp, $pattern, $timezone, false, false);
        } finally {
            force_current_language($previous);
        }

        return $formatted;
    }

    /**
     * Format a date with an English ordinal day, as in the fourth of July two thousand.
     *
     * @param int $timestamp the moment to format
     * @param string $timezone a timezone identifier
     * @param string $language a language pack code
     * @return string
     */
    protected static function ordinal_date(int $timestamp, string $timezone, string $language): string {
        $day = (int) userdate($timestamp, '%d', $timezone, false, false);
        $suffix = self::ordinal_suffix($day);
        $rest = self::localised_date($timestamp, '%B %Y', $timezone, $language);
        return $day . $suffix . ' ' . $rest;
    }

    /**
     * The English ordinal suffix for a day of the month.
     *
     * @param int $day a day between 1 and 31
     * @return string
     */
    protected static function ordinal_suffix(int $day): string {
        if ($day >= 11 && $day <= 13) {
            return 'th';
        }
        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Rewrite the digits of a string in another numeral system.
     *
     * @param string $text the text to convert
     * @param string $numerals default, western, arabic or devanagari
     * @return string
     */
    public static function convert_numerals(string $text, string $numerals): string {
        $sets = [
            'western' => ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            'arabic' => ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            'devanagari' => ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'],
        ];

        if (!isset($sets[$numerals])) {
            return $text;
        }

        $target = $sets[$numerals];
        $result = $text;

        // Normalise every known set to western digits first, then map outwards.
        foreach (['arabic', 'devanagari'] as $source) {
            $result = str_replace($sets[$source], $sets['western'], $result);
        }

        if ($numerals !== 'western') {
            $result = str_replace($sets['western'], $target, $result);
        }

        return $result;
    }

    /**
     * The timestamp a named date source resolves to for this recipient.
     *
     * @param string $source a key from the date element's source list
     * @param render_context $ctx the render context
     * @return int|null null when the source has no value
     */
    public static function resolve(string $source, render_context $ctx): ?int {
        global $DB;

        $issue = $ctx->get_issue();
        $course = $ctx->get_course();
        $user = $ctx->get_user();

        switch ($source) {
            case 'today':
                return time();

            case 'issued':
                return isset($issue->timecreated) ? (int) $issue->timecreated : time();

            case 'expiry':
                return !empty($issue->expires) ? (int) $issue->expires : null;

            case 'coursestart':
                return $course && !empty($course->startdate) ? (int) $course->startdate : null;

            case 'courseend':
                return $course && !empty($course->enddate) ? (int) $course->enddate : null;

            case 'enrolment':
                if ($course === null || $user === null) {
                    return null;
                }
                $sql = 'SELECT MIN(ue.timestart) AS timestart, MIN(ue.timecreated) AS timecreated
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid = :courseid AND ue.userid = :userid';
                $record = $DB->get_record_sql($sql, ['courseid' => $course->id, 'userid' => $user->id]);
                if (!$record) {
                    return null;
                }
                $timestart = (int) ($record->timestart ?? 0);
                if ($timestart > 0) {
                    return $timestart;
                }
                $created = (int) ($record->timecreated ?? 0);
                return $created > 0 ? $created : null;

            case 'completion':
                if ($course === null || $user === null) {
                    return null;
                }
                $completion = $DB->get_record(
                    'course_completions',
                    ['course' => $course->id, 'userid' => $user->id]
                );
                if ($completion && !empty($completion->timecompleted)) {
                    return (int) $completion->timecompleted;
                }
                return null;

            default:
                return isset($issue->timecreated) ? (int) $issue->timecreated : time();
        }
    }
}
