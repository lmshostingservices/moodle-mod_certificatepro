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
 * Shows a learner their certificate, and lets them download it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_certificatepro\local\issue_service;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$badge = optional_param('badge', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'certificatepro');
$instance = $DB->get_record('certificatepro', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/certificatepro:view', $context);

// A cycle issuer has nothing of its own to show: its documents are listed
// against the cycles that earned them, and this page would only offer a course
// award that this activity is forbidden to make. Send the learner where their
// documents actually are. Refusing outright when the ledger is absent is
// deliberate — the alternative is an activity that silently looks ordinary.
if (!empty($instance->cycleonly)) {
    if (!class_exists('\\local_recertify\\cycle_service')) {
        throw new moodle_exception('cycleproviderunavailable', 'mod_certificatepro');
    }

    redirect(new moodle_url('/local/recertify/cycles.php'));
}

$url = new moodle_url('/mod/certificatepro/view.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $instance);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

certificatepro_view($instance, $course, $cm, $context);

$unmet = issue_service::unmet_criteria($instance, $USER, $course, $cm);
$issue = issue_service::get_issue((int) $instance->id, (int) $USER->id);

if ($issue === null && empty($unmet) && !empty($instance->autoissue)) {
    $issue = issue_service::issue($instance, $course, $context, $USER);
}

// The Open Badges 3.0 carrier. Served as a download rather than inline,
// because the value of a baked SVG is that the learner can hand the file to a
// backpack or a verifier — the credential travels inside the image.
if ($badge && $issue !== null && empty($issue->revoked)) {
    require_sesskey();

    $svg = \mod_certificatepro\local\credential\badge_service::badge(
        $issue, $instance, $course, $USER);

    \mod_certificatepro\event\certificate_downloaded::create([
        'objectid' => $issue->id,
        'context' => $context,
        'relateduserid' => $USER->id,
        'other' => ['format' => 'badge'],
    ])->trigger();

    send_file($svg, \mod_certificatepro\local\credential\badge_service::filename($issue),
        0, 0, true, true, 'image/svg+xml');
    exit;
}

if ($download && $issue !== null && empty($issue->revoked)) {
    require_sesskey();

    $file = issue_service::get_file($instance, $course, $context, $USER, $issue);

    if ($file) {
        \mod_certificatepro\event\certificate_downloaded::create([
            'objectid' => $issue->id,
            'context' => $context,
            'relateduserid' => $USER->id,
        ])->trigger();

        send_stored_file($file, 0, 0, $instance->deliveryoption === 'D', [
            'filename' => issue_service::filename($instance, $course, $USER, $issue),
        ]);
        exit;
    }
}

// The description is drawn by the activity header, which set_cm() above has
// already been given the activity record for. Printing it a second time here
// put two copies on the page, both carrying id="intro".
echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_certificatepro');
echo $renderer->render_learner_view($instance, $course, $cm, $context, $issue, $unmet);

echo $OUTPUT->footer();
