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
use mod_certificatepro\local\render\zip_packager;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$issueid = optional_param('issueid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$includerevoked = optional_param('includerevoked', 0, PARAM_BOOL);
$includeexpired = optional_param('includeexpired', 1, PARAM_BOOL);

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

// Both hand actions ask first. Neither is reachable by a bare GET: the buttons
// in the table post, the confirmation posts, and the capability is checked again
// at the point the record is written rather than only when the question is put.
if ($action === 'issue' && $userid > 0) {
    require_sesskey();
    require_capability('mod/certificatepro:issue', $context);

    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    if (!$confirm) {
        $continue = new moodle_url($url, [
            'action' => 'issue',
            'userid' => $userid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('issuecertificate', 'mod_certificatepro'));
        echo $OUTPUT->confirm(
            get_string('confirmissue', 'mod_certificatepro', fullname($user)),
            $continue,
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }

    // Asked again here rather than trusted from the request that put the
    // question: the confirmation is a second round trip, and a role may have
    // been withdrawn in between.
    require_capability('mod/certificatepro:issue', $context);
    issue_service::issue($instance, $course, $context, $user, (int) $USER->id);

    redirect($url, get_string('certificateissued', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Everything this activity has issued, as one zip.
//
// The capability carries RISK_PERSONAL because the archive is a pile of named
// learners' documents, so it is treated as an export rather than a link: it is
// checked here, at the point the bytes leave, on the module context, behind a
// sesskey, and only from a POST. Refusing a GET keeps a whole cohort's
// certificates out of browser history, out of the referrer sent to whatever the
// teacher clicks next, and out of any proxy log that records query strings —
// none of which a sesskey on its own prevents. Every certificate that goes in
// is logged against the learner it names, so the export is auditable per person
// rather than as one anonymous bulk action.
if ($action === 'downloadall') {
    require_sesskey();
    require_capability('mod/certificatepro:downloadall', $context);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirect($url, get_string('downloadallpostonly', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $rows = issue_service::download_set((int) $instance->id, (bool) $includerevoked,
        (bool) $includeexpired);

    if (empty($rows)) {
        redirect($url, get_string('downloadallnothing', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $packager = new zip_packager($instance, $course, $context);

    // Raise the limit by what the work actually costs, not by a round number.
    // Packing a stored certificate is milliseconds; only one whose PDF has gone
    // missing has to be drawn again, and that is the case worth budgeting for.
    $stored = $packager->count_stored($rows);
    $missing = count($rows) - $stored;
    \core_php_time_limit::raise(zip_packager::time_budget($stored, $missing));

    // The streaming itself needs no extra memory — each certificate is handed
    // to the archive as a file handle and never held whole — but drawing a
    // missing one again loads a PDF library that wants tens of megabytes, so
    // the headroom is asked for only when there is something to redraw.
    if ($missing > 0) {
        raise_memory_limit(MEMORY_EXTRA);
    }

    foreach ($rows as $row) {
        \mod_certificatepro\event\certificate_downloaded::create([
            'objectid' => $row->id,
            'context' => $context,
            'relateduserid' => $row->userid,
            'other' => ['format' => 'zip', 'bulk' => true],
        ])->trigger();
    }

    $packager->stream($rows);
    exit;
}

if ($action === 'revoke' && $issueid > 0) {
    require_sesskey();
    require_capability('mod/certificatepro:revoke', $context);

    $issue = $DB->get_record('certificatepro_issues',
        ['id' => $issueid, 'certificateproid' => $instance->id], '*', MUST_EXIST);
    $holder = $DB->get_record('user', ['id' => $issue->userid], '*', MUST_EXIST);

    $revokeform = new \mod_certificatepro\form\revoke_form(
        new moodle_url($url, ['action' => 'revoke', 'issueid' => $issueid]),
        ['holder' => fullname($holder)]
    );
    $revokeform->set_data([
        'id' => $cm->id,
        'issueid' => $issueid,
        'action' => 'revoke',
    ]);

    if ($revokeform->is_cancelled()) {
        redirect($url);
    }

    if ($data = $revokeform->get_data()) {
        // Same again: the form is a second round trip, so the capability is
        // checked where the record is written, not only where it was offered.
        require_capability('mod/certificatepro:revoke', $context);
        issue_service::revoke($issue, $context, trim((string) $data->reason));

        redirect($url, get_string('certificaterevoked', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('revokecertificate', 'mod_certificatepro'));
    $revokeform->display();
    echo $OUTPUT->footer();
    exit;
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

if (has_capability('mod/certificatepro:downloadall', $context)) {
    $counts = ['total' => 0, 'revoked' => 0, 'expired' => 0];
    foreach ($issues as $issue) {
        $counts['total']++;
        $status = issue_service::status($issue);
        if ($status === 'revoked' || $status === 'expired') {
            $counts[$status]++;
        }
    }

    echo $PAGE->get_renderer('mod_certificatepro')->render_download_all($cm, $counts);
}

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
