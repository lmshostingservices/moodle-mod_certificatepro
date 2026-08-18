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

/**
 * The issued certificates report, where a teacher issues and revokes by hand.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_certificatepro\local\issue_service;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$issueid = optional_param('issueid', 0, PARAM_INT);
$reason = optional_param('reason', '', PARAM_TEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'certificatepro');
$instance = $DB->get_record('certificatepro', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/certificatepro:viewreport', $context);

$url = new moodle_url('/mod/certificatepro/report.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $instance);
$PAGE->set_title(get_string('reporttitle', 'mod_certificatepro'));
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'issue' && $userid > 0) {
    require_sesskey();
    require_capability('mod/certificatepro:issue', $context);

    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    issue_service::issue($instance, $course, $context, $user, (int) $USER->id);

    redirect($url, get_string('certificateissued', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'revoke' && $issueid > 0) {
    require_sesskey();
    require_capability('mod/certificatepro:revoke', $context);

    $issue = $DB->get_record('certificatepro_issues',
        ['id' => $issueid, 'certificateproid' => $instance->id], '*', MUST_EXIST);
    issue_service::revoke($issue, $context, $reason);

    redirect($url, get_string('certificaterevoked', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reporttitle', 'mod_certificatepro'));

$participants = get_enrolled_users($context, 'mod/certificatepro:view', 0, 'u.*',
    'u.lastname ASC, u.firstname ASC');

$issues = $DB->get_records('certificatepro_issues', ['certificateproid' => $instance->id],
    '', '*', 0, 0);
$byuser = [];
foreach ($issues as $issue) {
    $byuser[(int) $issue->userid] = $issue;
}

$canissue = has_capability('mod/certificatepro:issue', $context);
$canrevoke = has_capability('mod/certificatepro:revoke', $context);

$table = new html_table();
$table->head = [
    get_string('fullname'),
    get_string('status'),
    get_string('dateissued', 'mod_certificatepro'),
    get_string('verifyserial', 'mod_certificatepro'),
    get_string('codelabeldefault', 'mod_certificatepro'),
    '',
];
$table->attributes['class'] = 'generaltable table-sm';

foreach ($participants as $participant) {
    $issue = $byuser[(int) $participant->id] ?? null;
    $actions = '';

    if ($issue === null && $canissue) {
        $actions = $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'issue', 'userid' => $participant->id,
                'sesskey' => sesskey()]),
            get_string('issuecertificate', 'mod_certificatepro'),
            'post'
        );
    } else if ($issue !== null && $canrevoke && empty($issue->revoked)
            && !empty($instance->revocationallowed)) {
        $actions = $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'revoke', 'issueid' => $issue->id,
                'sesskey' => sesskey()]),
            get_string('revokecertificate', 'mod_certificatepro'),
            'post'
        );
    }

    $table->data[] = [
        fullname($participant),
        $issue
            ? get_string('status_' . issue_service::status($issue), 'mod_certificatepro')
            : get_string('status_notissued', 'mod_certificatepro'),
        $issue ? userdate($issue->timecreated, get_string('strftimedate', 'langconfig')) : '-',
        $issue ? s($issue->serial ?? '-') : '-',
        $issue ? html_writer::tag('code', s($issue->code)) : '-',
        $actions,
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('noissues', 'mod_certificatepro'), 'info');
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
