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
 * The public certificate verification page.
 *
 * Reachable without an account, because the person checking a certificate is
 * usually an employer or a registrar who has none. A scanned QR code lands
 * here and stays here: nothing on this page redirects to a login form.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_certificatepro\local\issue_service;

$code = optional_param('code', '', PARAM_TEXT);

// This page is deliberately reachable without an account: the person checking a
// certificate is usually an employer or a registrar who has none, and a scanned
// QR code that lands on a login form is a dead end. Reading forcelogin made every
// QR code useless on a new Moodle 5.x site, where forcing login is the default, so
// like core's public badge page this stays open and a plugin setting decides it
// instead. A site that wants no anonymous verification unticks the setting.
if (!get_config('mod_certificatepro', 'publicverify')) {
    require_login();
}

$PAGE->set_url('/mod/certificatepro/verify.php', $code !== '' ? ['code' => $code] : []);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('verifytitle', 'mod_certificatepro'));
$PAGE->set_heading(get_string('verifytitle', 'mod_certificatepro'));

$result = null;
$issue = null;
$instance = null;
$recipient = null;

if ($code !== '') {
    if (!issue_service::code_looks_valid($code)) {
        $result = 'unknown';
    } else {
        $issue = issue_service::find_by_code($code);

        if ($issue === null) {
            $result = 'unknown';
        } else {
            $sql = 'SELECT c.*, co.fullname AS coursefullname, co.shortname AS courseshortname
                      FROM {certificatepro} c
                      JOIN {course} co ON co.id = c.course
                     WHERE c.id = :id';
            $instance = $DB->get_record_sql($sql, ['id' => $issue->certificateproid]);

            $allowed = $instance && !empty($instance->verifyany);
            if (!$allowed && isloggedin() && !isguestuser()) {
                $allowed = has_capability('mod/certificatepro:verifyallcertificates',
                    context_system::instance());
            }

            if (!$allowed) {
                $result = 'unknown';
                $issue = null;
            } else {
                $result = issue_service::status($issue);

                // Name the award the way the document does. A cycle document is
                // titled by the programme it renews, and the course hosting the
                // issuing activity is an implementation detail nobody checking a
                // certificate should be shown.
                if (!empty($issue->cycleid) && class_exists('\\local_recertify\\cycle_service')) {
                    $cycle = $DB->get_record('local_recertify_cycles', ['id' => $issue->cycleid]);
                    if ($cycle) {
                        $instance->name = $cycle->name;
                        $instance->coursefullname = $cycle->name;
                    }
                }

                $recipient = $DB->get_record('user', ['id' => $issue->userid], '*', IGNORE_MISSING);

                \mod_certificatepro\event\certificate_verified::create([
                    'objectid' => $issue->id,
                    'context' => context_system::instance(),
                ])->trigger();
            }
        }
    }
}

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_certificatepro');
echo $renderer->render_verification($code, $result, $issue, $instance, $recipient);

echo $OUTPUT->footer();
