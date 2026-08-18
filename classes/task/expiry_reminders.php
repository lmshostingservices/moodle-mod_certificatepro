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

/**
 * Tells learners when a certificate they hold is about to expire.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expiry_reminders extends \core\task\scheduled_task {
    /**
     * The translated task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_expiryreminders', 'mod_certificatepro');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $now = time();

        $sql = "SELECT i.*, c.name AS activityname, c.course AS courseid, c.expirywarndays
                  FROM {certificatepro_issues} i
                  JOIN {certificatepro} c ON c.id = i.certificateproid
                 WHERE i.expires > 0
                   AND i.expirynotified = 0
                   AND i.revoked = 0
                   AND c.expirywarndays > 0
                   AND i.expires > :now
                   AND i.expires <= :window";

        $records = $DB->get_records_sql($sql, [
            'now' => $now,
            'window' => $now + (365 * DAYSECS),
        ]);

        $sent = 0;

        foreach ($records as $record) {
            $threshold = $record->expires - ((int) $record->expirywarndays * DAYSECS);
            if ($now < $threshold) {
                continue;
            }

            $user = $DB->get_record('user', ['id' => $record->userid], '*', IGNORE_MISSING);
            if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $record->courseid], '*', IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            $message = new \core\message\message();
            $message->component = 'mod_certificatepro';
            $message->name = 'expiring';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('expireson', 'mod_certificatepro',
                userdate($record->expires, get_string('strftimedaydate', 'langconfig')));
            $message->fullmessage = $message->subject;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->courseid = $course->id;

            if (message_send($message)) {
                $DB->set_field('certificatepro_issues', 'expirynotified', 1, ['id' => $record->id]);
                $sent++;
            }
        }

        mtrace('Certificate Pro sent ' . $sent . ' expiry reminders.');
    }
}
