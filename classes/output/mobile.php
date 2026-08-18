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

namespace mod_certificatepro\output;

use mod_certificatepro\local\issue_service;

/**
 * What the Moodle app shows for a Certificate Pro activity.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Build the activity view for the app.
     *
     * @param array $args the arguments the app supplied
     * @return array the app response
     */
    public static function mobile_view(array $args): array {
        global $DB, $OUTPUT, $USER;

        $args = (object) $args;
        $cm = get_coursemodule_from_id('certificatepro', $args->cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $instance = $DB->get_record('certificatepro', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        require_login($course, false, $cm);
        require_capability('mod/certificatepro:view', $context);

        $issue = issue_service::get_issue((int) $instance->id, (int) $USER->id);
        $unmet = issue_service::unmet_criteria($instance, $USER, $course, $cm);

        $data = [
            'cmid' => (int) $cm->id,
            'name' => format_string($instance->name),
            'intro' => format_module_intro('certificatepro', $instance, $cm->id),
            'hasissue' => $issue !== null,
            'status' => $issue !== null ? issue_service::status($issue) : 'notissued',
            'code' => $issue !== null ? $issue->code : '',
            'serial' => $issue !== null ? (string) ($issue->serial ?? '') : '',
            'issued' => $issue !== null
                ? userdate($issue->timecreated, get_string('strftimedaydate', 'langconfig'))
                : '',
            'unmet' => array_values($unmet),
            'downloadurl' => (new \moodle_url('/mod/certificatepro/view.php',
                ['id' => $cm->id, 'download' => 1, 'sesskey' => sesskey()]))->out(false),
            'verifyurl' => $issue !== null
                ? (new \moodle_url('/mod/certificatepro/verify.php',
                    ['code' => $issue->code]))->out(false)
                : '',
        ];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_certificatepro/mobile_view', $data),
                ],
            ],
            'javascript' => '',
            'otherdata' => '',
            'files' => [],
        ];
    }
}
