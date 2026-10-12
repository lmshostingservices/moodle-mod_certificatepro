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

namespace mod_certificatepro\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_certificatepro\local\issue_service;

/**
 * The services a learner, a mobile app or an external portal calls.
 *
 * Verification is deliberately usable without logging in, because the person
 * checking a certificate is usually an employer or a registrar who has no
 * account on the site. It answers only whether a code is genuine and what it
 * attests to, never anything else about the holder.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_api extends external_api {
    /**
     * Parameters for listing a user's certificates.
     *
     * @return external_function_parameters
     */
    public static function get_certificates_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'The user to list, or 0 for the current user',
                VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Limit to one course, or 0 for all',
                VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List the certificates a user has been issued.
     *
     * @param int $userid the user to list, or 0 for the current user
     * @param int $courseid limit to one course, or 0 for all
     * @return array
     */
    public static function get_certificates(int $userid = 0, int $courseid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_certificates_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        $targetid = $params['userid'] > 0 ? $params['userid'] : (int) $USER->id;

        self::validate_context(\context_user::instance($targetid));

        if ($targetid !== (int) $USER->id) {
            require_capability('moodle/user:viewalldetails', \context_user::instance($targetid));
        }

        $conditions = ['userid' => $targetid];
        $where = 'i.userid = :userid';
        if ($params['courseid'] > 0) {
            $where .= ' AND c.course = :courseid';
            $conditions['courseid'] = $params['courseid'];
        }

        $sql = "SELECT i.*, c.name AS activityname, c.course AS courseid, co.fullname AS coursename
                  FROM {certificatepro_issues} i
                  JOIN {certificatepro} c ON c.id = i.certificateproid
                  JOIN {course} co ON co.id = c.course
                 WHERE {$where}
              ORDER BY i.timecreated DESC";

        $records = $DB->get_records_sql($sql, $conditions);

        $certificates = [];
        foreach ($records as $record) {
            if (!empty($record->cycleid) && class_exists('\\local_recertify\\cycle_service')) {
                $cycle = $DB->get_record('local_recertify_cycles', ['id' => $record->cycleid]);
                if ($cycle) {
                    $record->activityname = $cycle->name;
                    $record->coursename = $cycle->name;
                }
            }
            $certificates[] = [
                'id' => (int) $record->id,
                'code' => $record->code,
                'serial' => (string) ($record->serial ?? ''),
                'name' => format_string($record->activityname),
                'courseid' => (int) $record->courseid,
                'coursename' => format_string($record->coursename),
                'timecreated' => (int) $record->timecreated,
                'expires' => (int) $record->expires,
                'status' => issue_service::status($record),
                'verifyurl' => (new \moodle_url('/mod/certificatepro/verify.php',
                    ['code' => $record->code]))->out(false),
            ];
        }

        return ['certificates' => $certificates];
    }

    /**
     * What listing certificates returns.
     *
     * @return external_single_structure
     */
    public static function get_certificates_returns(): external_single_structure {
        return new external_single_structure([
            'certificates' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'The issue id'),
                    'code' => new external_value(PARAM_TEXT, 'The verification code'),
                    'serial' => new external_value(PARAM_TEXT, 'The serial number, or empty'),
                    'name' => new external_value(PARAM_TEXT, 'The activity name'),
                    'courseid' => new external_value(PARAM_INT, 'The course id'),
                    'coursename' => new external_value(PARAM_TEXT, 'The course name'),
                    'timecreated' => new external_value(PARAM_INT, 'When it was issued'),
                    'expires' => new external_value(PARAM_INT, 'When it expires, or zero'),
                    'status' => new external_value(PARAM_ALPHA, 'valid, expired or revoked'),
                    'verifyurl' => new external_value(PARAM_URL, 'The public verification address'),
                ])
            ),
        ]);
    }

    /**
     * Parameters for verifying a code.
     *
     * @return external_function_parameters
     */
    public static function verify_code_parameters(): external_function_parameters {
        return new external_function_parameters([
            'code' => new external_value(PARAM_TEXT, 'The code as printed or scanned'),
        ]);
    }

    /**
     * Check a verification code.
     *
     * @param string $code the code as printed or scanned
     * @return array
     */
    public static function verify_code(string $code): array {
        global $DB;

        $params = self::validate_parameters(self::verify_code_parameters(), ['code' => $code]);

        self::validate_context(\context_system::instance());

        $notfound = [
            'found' => false,
            'status' => 'unknown',
            'recipient' => '',
            'coursename' => '',
            'activityname' => '',
            'timeissued' => 0,
            'expires' => 0,
            'serial' => '',
        ];

        if (!issue_service::code_looks_valid($params['code'])) {
            return $notfound;
        }

        $issue = issue_service::find_by_code($params['code']);
        if ($issue === null) {
            return $notfound;
        }

        $sql = "SELECT c.*, co.fullname AS coursefullname, co.id AS courseid
                  FROM {certificatepro} c
                  JOIN {course} co ON co.id = c.course
                 WHERE c.id = :id";
        $instance = $DB->get_record_sql($sql, ['id' => $issue->certificateproid]);

        if (!$instance || empty($instance->verifyany)) {
            // The activity has verification switched off for anonymous callers.
            return $notfound;
        }

        $user = $DB->get_record('user', ['id' => $issue->userid], '*', IGNORE_MISSING);

        \mod_certificatepro\event\certificate_verified::create([
            'objectid' => $issue->id,
            'context' => \context_system::instance(),
        ])->trigger();

        return [
            'found' => true,
            'status' => issue_service::status($issue),
            'recipient' => $user ? fullname($user) : '',
            'coursename' => format_string($instance->coursefullname),
            'activityname' => format_string($instance->name),
            'timeissued' => (int) $issue->timecreated,
            'expires' => (int) $issue->expires,
            'serial' => (string) ($issue->serial ?? ''),
        ];
    }

    /**
     * What verifying a code returns.
     *
     * @return external_single_structure
     */
    public static function verify_code_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether the code is genuine'),
            'status' => new external_value(PARAM_ALPHA, 'valid, expired, revoked or unknown'),
            'recipient' => new external_value(PARAM_TEXT, 'Who it was awarded to'),
            'coursename' => new external_value(PARAM_TEXT, 'The course it was awarded for'),
            'activityname' => new external_value(PARAM_TEXT, 'The certificate name'),
            'timeissued' => new external_value(PARAM_INT, 'When it was issued'),
            'expires' => new external_value(PARAM_INT, 'When it expires, or zero'),
            'serial' => new external_value(PARAM_TEXT, 'The serial number, or empty'),
        ]);
    }
}
