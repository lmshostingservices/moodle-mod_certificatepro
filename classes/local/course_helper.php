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
 * Answers questions about a course that several elements need.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_helper {
    /**
     * The teachers in a course, keyed by user id.
     *
     * @param \context $context a module or course context
     * @return \stdClass[]
     */
    public static function teachers(\context $context): array {
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return [];
        }

        $users = get_enrolled_users(
            $coursecontext,
            'moodle/course:update',
            0,
            'u.*',
            'u.lastname ASC, u.firstname ASC'
        );

        $teachers = [];
        foreach ($users as $user) {
            $teachers[(int) $user->id] = $user;
        }

        return $teachers;
    }

    /**
     * A selector menu of the teachers in a course.
     *
     * @param \context $context a module or course context
     * @return array<int, string>
     */
    public static function teacher_menu(\context $context): array {
        $menu = [0 => get_string('choosedots')];
        foreach (self::teachers($context) as $id => $user) {
            $menu[$id] = fullname($user);
        }
        return $menu;
    }

    /**
     * How long a user has spent in a course, in seconds.
     *
     * Derived from the spread of their logged activity rather than from a
     * single session, and capped per gap so an abandoned browser tab does not
     * count as a week of study.
     *
     * @param int $courseid the course to measure
     * @param int $userid the user to measure
     * @return int seconds
     */
    public static function time_in_course(int $courseid, int $userid): int {
        global $DB;

        $sql = "SELECT id, timecreated
                  FROM {logstore_standard_log}
                 WHERE userid = :userid AND courseid = :courseid
              ORDER BY timecreated ASC";

        try {
            $records = $DB->get_records_sql(
                $sql,
                ['userid' => $userid, 'courseid' => $courseid]
            );
        } catch (\dml_exception $e) {
            // The standard log store may not be installed.
            return 0;
        }

        if (count($records) < 2) {
            return 0;
        }

        $maxgap = 2 * HOURSECS;
        $total = 0;
        $previous = null;

        foreach ($records as $record) {
            $timecreated = (int) $record->timecreated;
            if ($previous !== null) {
                $gap = $timecreated - $previous;
                if ($gap > 0 && $gap < $maxgap) {
                    $total += $gap;
                }
            }
            $previous = $timecreated;
        }

        return $total;
    }}
