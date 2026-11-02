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

namespace mod_certificatepro\task;

use mod_certificatepro\local\issue_service;

/**
 * Issues certificates to learners who have met every condition.
 *
 * Each learner is judged only on their own criteria. Nothing another learner
 * does, and no email setting, can cause a certificate to be created for
 * somebody who has not earned one.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue_certificates extends \core\task\scheduled_task {
    /** @var int How many certificates to issue in one run. */
    protected const BATCH = 200;

    /**
     * The translated task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_issuecertificates', 'mod_certificatepro');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $instances = $DB->get_records('certificatepro', ['autoissue' => 1]);
        $issued = 0;

        foreach ($instances as $instance) {
            if ($issued >= self::BATCH) {
                mtrace('Certificate Pro reached the batch limit; the rest will follow next run.');
                return;
            }

            $cm = get_coursemodule_from_instance(
                'certificatepro',
                $instance->id,
                $instance->course,
                false,
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $instance->course], '*', IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            $context = \context_module::instance($cm->id);
            $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
            if (!$cminfo->visible || !empty($cminfo->deletioninprogress)) {
                continue;
            }
            // Only active enrolments of people who are meant to receive certificates and can reach the activity.
            $candidates = get_enrolled_users($context, 'mod/certificatepro:receive', 0, 'u.*', null, 0, 0, true);
            $candidates = (new \core_availability\info_module($cminfo))->filter_user_list($candidates);

            foreach ($candidates as $user) {
                if ($issued >= self::BATCH) {
                    break;
                }

                if (issue_service::get_issue((int) $instance->id, (int) $user->id) !== null) {
                    continue;
                }

                if (!empty(issue_service::unmet_criteria($instance, $user, $course, $cm))) {
                    continue;
                }

                try {
                    issue_service::issue($instance, $course, $context, $user);
                    $issued++;
                } catch (\Throwable $e) {
                    mtrace(
                        'Certificate Pro could not issue to user ' . $user->id . ': '
                            . $e->getMessage()
                    );
                }
            }
        }

        mtrace('Certificate Pro issued ' . $issued . ' certificates.');
    }
}
