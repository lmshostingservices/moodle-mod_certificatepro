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
 * End to end verification of every element, against a real database.
 *
 * Builds a course, a learner and a certificate, places one of every element
 * type, renders a real PDF, issues a real certificate and checks the whole
 * chain: storage, retrieval, JSON round trip, snapshot immutability,
 * verification, revocation and geometry.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');

use mod_certificatepro\element\registry;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\issue_service;
use mod_certificatepro\local\render\pdf_renderer;
use mod_certificatepro\local\starter_templates;
use mod_certificatepro\local\zone\zone_library;
use mod_certificatepro\template;

$pass = 0;
$fail = 0;
$failures = [];

/**
 * Record a check.
 *
 * @param bool $ok whether the check passed
 * @param string $message what was checked
 * @param string $detail extra context shown on failure
 * @return void
 */
function check(bool $ok, string $message, string $detail = ''): void {
    global $pass, $fail, $failures;

    if ($ok) {
        $pass++;
        cli_writeln("  ok   " . $message);
    } else {
        $fail++;
        $failures[] = $message . ($detail !== '' ? ' -- ' . $detail : '');
        cli_writeln("  FAIL " . $message . ($detail !== '' ? '  [' . $detail . ']' : ''));
    }
}

/**
 * Print a section heading.
 *
 * @param string $title the heading
 * @return void
 */
function section(string $title): void {
    cli_writeln('');
    cli_writeln('=== ' . $title . ' ===');
}

// ---------------------------------------------------------------- fixtures.

section('Fixtures');

// Run as the site administrator so the module API permits activity creation.
\core\session\manager::set_user(get_admin());

$category = core_course_category::get_default();

// Every run makes its own users, so the name has to be unique to the run. A
// short random number was not: two runs a few thousand apart collided and the
// second died on a duplicate username before it had checked anything.
$runid = uniqid('', false);

$course = create_course((object) [
    'fullname' => 'Introduction to Workplace Safety',
    'shortname' => 'WS-101-' . $runid,
    'category' => $category->id,
    'startdate' => strtotime('2026-01-06'),
    'enddate' => strtotime('2026-06-30'),
    'enablecompletion' => 1,
    'summary' => '<ul><li>Hazard identification</li><li>Reporting</li></ul>',
    'summaryformat' => FORMAT_HTML,
]);
check($course && $course->id > 0, 'course created', 'id ' . ($course->id ?? 0));

$learner = (object) [
    'username' => 'learner' . $runid,
    'password' => 'Learn3r!pass',
    'firstname' => 'Aleksandr',
    'lastname' => 'Ривера',
    'email' => 'learner' . $runid . '@example.com',
    'idnumber' => 'EMP-4471',
    'city' => 'Bangkok',
    'country' => 'TH',
    'institution' => 'LMS Labs Academy',
    'department' => 'Operations',
    'confirmed' => 1,
    'mnethostid' => $CFG->mnet_localhost_id,
];
$learner->id = user_create_user($learner, false, false);
$learner = $DB->get_record('user', ['id' => $learner->id]);
check($learner->id > 0, 'learner created with a non Latin surname', $learner->lastname);

$teacher = (object) [
    'username' => 'teacher' . $runid,
    'password' => 'Te4ch3r!pass',
    'firstname' => 'Morgan',
    'lastname' => 'Hale',
    'email' => 'teacher' . $runid . '@example.com',
    'confirmed' => 1,
    'mnethostid' => $CFG->mnet_localhost_id,
];
$teacher->id = user_create_user($teacher, false, false);
$teacher = $DB->get_record('user', ['id' => $teacher->id]);

$studentrole = $DB->get_record('role', ['shortname' => 'student']);
$teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
$manual->enrol_user($instance, $learner->id, $studentrole->id, strtotime('2026-01-10'));
$manual->enrol_user($instance, $teacher->id, $teacherrole->id);
check($DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $learner->id]),
    'learner enrolled');

$group = $DB->insert_record('groups', (object) [
    'courseid' => $course->id, 'name' => 'Cohort A', 'timecreated' => time(), 'timemodified' => time(),
]);
$DB->insert_record('groups_members', (object) [
    'groupid' => $group, 'userid' => $learner->id, 'timeadded' => time(),
]);

// A course grade so the grade element has something real to print.
grade_regrade_final_grades($course->id);
$gradeitem = grade_item::fetch_course_item($course->id);
$gradeitem->update_final_grade($learner->id, 87.5, 'import');
check($gradeitem !== false, 'course grade item created and graded');

// ------------------------------------------------------- activity creation.

section('Activity creation via the real module API');

$moduleinfo = (object) [
    'modulename' => 'certificatepro',
    'course' => $course->id,
    'section' => 0,
    'visible' => 1,
    'name' => 'Course completion certificate',
    'introeditor' => ['text' => '<p>Your certificate.</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
    'starter' => 'classic',
    'requiredtime' => 0,
    'requiregrade' => null,
    'requirecompletion' => 0,
    'autoissue' => 1,
    'verifyany' => 1,
    'deliveryoption' => 'D',
    'usecustomfilename' => 1,
    'customfilenamepattern' => '{fullname} - {coursefullname}',
    'emailstudents' => 0,
    'emailteachers' => 0,
    'emailothers' => '',
    'validityperiod' => 2 * YEARSECS,
    'expirywarndays' => 30,
    'serialprefix' => 'ACME-2026-',
    'serialpadding' => 6,
    'protection' => 'modify,copy',
    'revocationallowed' => 1,
    'timecreated' => time(),
    'timemodified' => time(),
];

$moduleinfo = create_module($moduleinfo);
check($moduleinfo->instance > 0, 'activity created through create_module');

$cert = $DB->get_record('certificatepro', ['id' => $moduleinfo->instance], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('certificatepro', $cert->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

check($cert->templateid > 0, 'a design was created and linked', 'templateid ' . $cert->templateid);
check((int) $cert->validityperiod === 2 * YEARSECS, 'validity period stored');
check($cert->serialprefix === 'ACME-2026-', 'serial prefix stored');
check($cert->protection === 'modify,copy', 'protection flags stored');

$templateobj = template::load((int) $cert->templateid);
$pages = $templateobj->get_pages();
check(count($pages) === 1, 'starter created exactly one page', 'got ' . count($pages));

$page = reset($pages);
check(abs((float) $page->width - 297.0) < 0.001 && abs((float) $page->height - 210.0) < 0.001,
    'page is A4 landscape in millimetres', $page->width . ' x ' . $page->height);

$starterelements = $templateobj->get_elements((int) $page->id);
check(count($starterelements) === 18, 'classic starter placed 18 elements',
    'got ' . count($starterelements));

$docked = 0;
foreach ($starterelements as $element) {
    if (!empty($element->get_record()->zoneid)) {
        $docked++;
    }
}
check($docked >= 17, 'starter elements are docked into named zones', $docked . ' docked');

// -------------------------------------------------- every element type.

section('Every element type: create, store, read back, resolve, render');

$types = registry::types();
check(count($types) === 30, 'registry discovers 30 element types', 'got ' . count($types));

$testpageid = $templateobj->add_page('L', 'A4');
$created = [];

foreach ($types as $type) {
    if ($type === 'unavailable') {
        continue;
    }

    $class = registry::class_for($type);
    $elementid = $templateobj->add_element($testpageid, $type, null);

    if ($elementid <= 0) {
        check(false, "[$type] element inserted");
        continue;
    }

    $record = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);
    $created[$type] = $record;

    // Every column must be present and of the right shape.
    $columnsok = $record->element === $type
        && $record->name !== ''
        && is_numeric($record->posx) && is_numeric($record->posy)
        && is_numeric($record->width) && is_numeric($record->height)
        && is_numeric($record->rotation)
        && in_array($record->refpoint, ['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'], true)
        && in_array($record->alignment, ['L', 'C', 'R', 'J'], true)
        && in_array($record->valignment, ['T', 'M', 'B'], true)
        && $record->timecreated > 0 && $record->timemodified > 0;
    check($columnsok, "[$type] every column stored with a valid value");

    // The style column must be valid JSON that decodes to the declared defaults.
    $style = json_decode($record->style, true);
    check(is_array($style) && $style === $class::default_style(),
        "[$type] style column round trips as JSON");

    // Writing element specific config and reading it back.
    $config = ['probe' => 'värde-测试-قيمة', 'number' => 42, 'flag' => true, 'nested' => ['a' => 1]];
    $DB->set_field('certificatepro_elements', 'data', json_encode($config), ['id' => $elementid]);
    $reloaded = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);
    $element = registry::instantiate($reloaded);
    check($element->config('probe') === 'värde-测试-قيمة' && $element->config('number') === 42
        && $element->config('flag') === true && $element->config('nested') === ['a' => 1],
        "[$type] data column round trips multibyte and nested JSON");

    // Restore the real defaults for the render pass.
    $DB->set_field('certificatepro_elements', 'data', json_encode([]), ['id' => $elementid]);
}

// ------------------------------------------------------- rendering.

section('Rendering every element into a real PDF');

$previewctx = render_context::preview($context, $course, $cert);
$templateobj->configure($previewctx);
$previewctx->set_page_size(297.0, 210.0);

foreach ($types as $type) {
    if (!isset($created[$type])) {
        continue;
    }
    $record = $DB->get_record('certificatepro_elements', ['id' => $created[$type]->id], '*', MUST_EXIST);
    $element = registry::instantiate($record);

    try {
        $value = $element->resolve($previewctx);
        check(true, "[$type] resolve() returns without error",
            is_string($value) ? shorten_text(strip_tags($value), 40) : gettype($value));
    } catch (Throwable $e) {
        check(false, "[$type] resolve() returns without error", $e->getMessage());
    }

    try {
        $html = $element->render_html($previewctx);
        check(is_string($html), "[$type] render_html() returns a string");
    } catch (Throwable $e) {
        check(false, "[$type] render_html() returns a string", $e->getMessage());
    }

    try {
        $exported = $element->export_for_designer($previewctx);
        $keysok = isset($exported['id'], $exported['type'], $exported['posx'], $exported['posy'],
            $exported['width'], $exported['height'], $exported['refpoint'], $exported['style'],
            $exported['data'], $exported['html']);
        check($keysok, "[$type] export_for_designer() carries every key the designer needs");
    } catch (Throwable $e) {
        check(false, "[$type] export_for_designer() carries every key the designer needs",
            $e->getMessage());
    }
}

// Render the whole design, every element on one page.
try {
    $renderer = new pdf_renderer($templateobj);
    $pdf = $renderer->render($previewctx, ['title' => 'Verification render']);
    check(strlen($pdf) > 5000, 'full PDF renders with every element type present',
        strlen($pdf) . ' bytes');
    check(str_starts_with($pdf, '%PDF-'), 'output is a real PDF');
    file_put_contents('/home/claude/verify-allelements.pdf', $pdf);
} catch (Throwable $e) {
    check(false, 'full PDF renders with every element type present', $e->getMessage());
}

// Drop the probe page so the issued certificate uses the starter design alone.
$templateobj->delete_page($testpageid);
check(count($templateobj->get_pages()) === 1, 'probe page removed cleanly');

// ---------------------------------------------------------- issuing.

section('Issuing, snapshotting and immutability');

$unmet = issue_service::unmet_criteria($cert, $learner, $course, $cm);
check(empty($unmet), 'learner meets the criteria', implode('; ', $unmet));

$issue = issue_service::issue($cert, $course, $context, $learner);
check($issue->id > 0, 'certificate issued');
check(preg_match('/^CP-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/',
    $issue->code) === 1, 'verification code well formed', $issue->code);
check(issue_service::code_looks_valid($issue->code), 'verification code passes its own checksum');
check($issue->serial === 'ACME-2026-000001', 'first serial number is ACME-2026-000001',
    (string) $issue->serial);
check((int) $issue->expires > time(), 'expiry date recorded on the issue');
check(!empty($issue->pdfhash) && strlen($issue->pdfhash) === 64, 'PDF hash recorded');

$snapshot = json_decode($issue->snapshot, true);
check(is_array($snapshot) && !empty($snapshot), 'snapshot written as JSON',
    'keys: ' . count(is_array($snapshot) ? $snapshot : []));
check(isset($snapshot['tokens']['user.fullname']), 'snapshot captured the resolved tokens');
check(($snapshot['tokens']['course.fullname'] ?? '') === 'Introduction to Workplace Safety',
    'snapshot captured the course name',
    (string) ($snapshot['tokens']['course.fullname'] ?? ''));

$storedfile = issue_service::get_file($cert, $course, $context, $learner, $issue);
check($storedfile !== null, 'rendered PDF stored in the file API');
check($storedfile && $storedfile->get_filename() === 'Aleksandr Ривера - Introduction to Workplace Safety.pdf',
    'custom filename pattern applied', $storedfile ? $storedfile->get_filename() : '');
check($storedfile && hash('sha256', $storedfile->get_content()) === $issue->pdfhash,
    'stored PDF matches its recorded hash');

// Immutability: change the world, re-render from the snapshot, expect no change.
$before = $storedfile->get_content();
$DB->set_field('course', 'fullname', 'COMPLETELY DIFFERENT COURSE NAME', ['id' => $course->id]);
$DB->set_field('user', 'lastname', 'CHANGEDSURNAME', ['id' => $learner->id]);
$templateobj->update(['name' => 'Renamed design']);

$freshcourse = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
$freshuser = $DB->get_record('user', ['id' => $learner->id], '*', MUST_EXIST);
$freshissue = $DB->get_record('certificatepro_issues', ['id' => $issue->id], '*', MUST_EXIST);

$snapctx = render_context::for_issue($context, $cert, $freshcourse, $freshuser, $freshissue);
check($snapctx->is_snapshot(), 'a stored issue renders in snapshot mode');

$tokens = $snapctx->tokens();
check($tokens['course.fullname'] === 'Introduction to Workplace Safety',
    'course name still reads as it did at issue time', $tokens['course.fullname']);
check($tokens['user.fullname'] !== '' && !str_contains($tokens['user.fullname'], 'CHANGEDSURNAME'),
    'learner name still reads as it did at issue time', $tokens['user.fullname']);

// Restore the world.
$DB->set_field('course', 'fullname', 'Introduction to Workplace Safety', ['id' => $course->id]);
$DB->set_field('user', 'lastname', 'Ривера', ['id' => $learner->id]);

// Issuing twice must not create a second certificate.
$again = issue_service::issue($cert, $course, $context, $learner);
check((int) $again->id === (int) $issue->id, 'issuing twice returns the same certificate');
check($DB->count_records('certificatepro_issues', ['certificateproid' => $cert->id]) === 1,
    'exactly one issue row exists');

// Serial numbers advance.
$second = (object) (array) $learner;
unset($second->id);
$second->username = 'learner2' . $runid;
$second->email = 'learner2' . $runid . '@example.com';
$second->id = user_create_user($second, false, false);
$second = $DB->get_record('user', ['id' => $second->id]);
$manual->enrol_user($instance, $second->id, $studentrole->id);
$issue2 = issue_service::issue($cert, $course, $context, $second);
check($issue2->serial === 'ACME-2026-000002', 'second serial is ACME-2026-000002',
    (string) $issue2->serial);
check($issue2->code !== $issue->code, 'the two certificates have different codes');

// ---------------------------------------------------------- verification.

section('Verification and revocation');

$found = issue_service::find_by_code($issue->code);
check($found !== null && (int) $found->id === (int) $issue->id, 'code looks up its issue');

$scrambled = strtolower(str_replace('-', ' ', $issue->code));
check(issue_service::normalise_code($scrambled) === $issue->code,
    'lowercase, space separated input still resolves', $scrambled);

check(issue_service::find_by_code('CP-ZZZZ-ZZZZ-ZZZZ') === null, 'an invented code finds nothing');
check(issue_service::status($found) === 'valid', 'a fresh certificate reads as valid');

issue_service::revoke($found, $context, 'Issued in error during verification testing');
$revoked = $DB->get_record('certificatepro_issues', ['id' => $issue->id], '*', MUST_EXIST);
check((int) $revoked->revoked === 1, 'revocation flag set');
check((int) $revoked->revokedby > 0 || (int) $revoked->timerevoked > 0, 'revocation audited');
check(issue_service::status($revoked) === 'revoked', 'a revoked certificate reads as revoked');
check($DB->record_exists('certificatepro_issues', ['id' => $issue->id]),
    'revoking does not delete the record');

// ------------------------------------------------------------- geometry.

section('Geometry: zone and starter overlap audit');

/**
 * Whether two boxes overlap by more than a tolerance.
 *
 * @param array $a the first box
 * @param array $b the second box
 * @param float $tolerance millimetres of permitted touching
 * @return bool
 */
function overlaps(array $a, array $b, float $tolerance = 0.5): bool {
    return ($a['x'] + $a['w'] - $tolerance) > $b['x']
        && ($b['x'] + $b['w'] - $tolerance) > $a['x']
        && ($a['y'] + $a['h'] - $tolerance) > $b['y']
        && ($b['y'] + $b['h'] - $tolerance) > $a['y'];
}

foreach (['classic', 'ceremonial', 'modern', 'corporate', 'portrait'] as $starter) {
    $built = starter_templates::build($starter, 'Audit ' . $starter, $context);
    $builtpages = $built->get_pages();
    $builtpage = reset($builtpages);

    $boxes = [];
    foreach ($built->get_elements((int) $builtpage->id) as $element) {
        $type = $element::type();
        if (in_array($type, ['border', 'bgimage', 'artwork', 'shape'], true)) {
            continue;
        }
        [$left, $top] = $element->box_origin($element->get_width(), $element->get_height());
        $boxes[] = [
            'name' => $element->get_name() . ' (' . $type . ')',
            'x' => $left, 'y' => $top,
            'w' => $element->get_width(), 'h' => $element->get_height(),
        ];
    }

    $clashes = [];
    $outside = [];
    for ($i = 0; $i < count($boxes); $i++) {
        $box = $boxes[$i];
        if ($box['x'] < -0.5 || $box['y'] < -0.5
                || $box['x'] + $box['w'] > (float) $builtpage->width + 0.5
                || $box['y'] + $box['h'] > (float) $builtpage->height + 0.5) {
            $outside[] = $box['name'];
        }
        for ($j = $i + 1; $j < count($boxes); $j++) {
            if (overlaps($box, $boxes[$j])) {
                $clashes[] = $box['name'] . ' x ' . $boxes[$j]['name'];
            }
        }
    }

    check(empty($clashes), "[$starter] no element overlaps another",
        implode(' | ', array_slice($clashes, 0, 6)));
    check(empty($outside), "[$starter] every element is inside the page",
        implode(' | ', $outside));

    $built->delete();
}

// -------------------------------------------------- shared media library.

section('Shared media library and logo slots');

$pixel = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAGQAAAAyCAIAAAC2rgKmAAAAOklEQVR4nO3BMQEAAADCoPVPbQ0P'
    . 'oAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAvgYxAAABBQ1kQwAAAABJRU5ErkJggg=='
);
$fs = get_file_storage();
$draft = $fs->create_file_from_string([
    'contextid' => context_user::instance(get_admin()->id)->id,
    'component' => 'user', 'filearea' => 'draft',
    'itemid' => file_get_unused_draft_itemid(), 'filepath' => '/',
    'filename' => 'signature.png',
], $pixel);

$mediaid = \mod_certificatepro\local\media_library::save($draft,
    'Head of School signature', \mod_certificatepro\local\media_library::KIND_SIGNATURE);
check($mediaid > 0, 'picture saved into the shared library');

$mediarecord = \mod_certificatepro\local\media_library::get($mediaid);
check($mediarecord !== null && (int) $mediarecord->pixelwidth === 100
    && (int) $mediarecord->pixelheight === 50,
    'library recorded the intrinsic pixel size',
    $mediarecord ? $mediarecord->pixelwidth . 'x' . $mediarecord->pixelheight : 'none');

check(\mod_certificatepro\local\media_library::file($mediaid) !== null,
    'library file retrievable');
check(\mod_certificatepro\local\media_library::path($mediaid) !== null,
    'library file copied to a readable path for the PDF');
check(\mod_certificatepro\local\media_library::url($mediaid) !== null,
    'library file has a browser URL');

$menu = \mod_certificatepro\local\media_library::menu(
    \mod_certificatepro\local\media_library::KIND_SIGNATURE);
check(isset($menu[$mediaid]), 'library picture appears in the signature picker');
check(!isset(\mod_certificatepro\local\media_library::menu(
    \mod_certificatepro\local\media_library::KIND_LOGO)[$mediaid]),
    'a signature does not appear in the logo picker');

// Sizing: the drawn size must keep the picture's own proportions.
[$w, $h] = \mod_certificatepro\local\media_library::fit(100, 50, 60.0, 60.0, 'contain');
check(abs($w - 60.0) < 0.01 && abs($h - 30.0) < 0.01,
    'contain fits a 2:1 picture into a square box without distortion', "{$w} x {$h}");

[$w, $h] = \mod_certificatepro\local\media_library::fit(100, 50, 60.0, 60.0, 'cover');
check(abs($w - 120.0) < 0.01 && abs($h - 60.0) < 0.01,
    'cover fills a square box from a 2:1 picture', "{$w} x {$h}");

[$w, $h] = \mod_certificatepro\local\media_library::fit(100, 50, 60.0, 60.0, 'stretch');
check(abs($w - 60.0) < 0.01 && abs($h - 60.0) < 0.01, 'stretch honours the box exactly');

[$w, $h] = \mod_certificatepro\local\media_library::fit(100, 50, 80.0, 0.0, 'contain');
check(abs($h - 40.0) < 0.01, 'a width only box derives the right height', (string) $h);

[$w, $h] = \mod_certificatepro\local\media_library::fit(0, 0, 40.0, 20.0, 'contain');
check(abs($w - 40.0) < 0.01 && abs($h - 20.0) < 0.01,
    'an unmeasurable picture falls back to the box');

// An element pointed at the library must draw the library picture.
// reset() takes its array by reference, so the return value of get_pages()
// has to be given a name of its own before it can be handed over.
$sigpages = $templateobj->get_pages();
$sigpage = reset($sigpages);
$sigid = $templateobj->add_element((int) $sigpage->id, 'signature', 'signatureleft');
$DB->set_field('certificatepro_elements', 'data',
    json_encode(['mediaid' => $mediaid, 'namesource' => 'custom', 'signaturename' => 'M. Hale',
        'signaturerole' => 'Head of School', 'showrule' => 1, 'keepaspect' => 1],
        JSON_PRESERVE_ZERO_FRACTION),
    ['id' => $sigid]);

$sigelement = registry::instantiate(
    $DB->get_record('certificatepro_elements', ['id' => $sigid], '*', MUST_EXIST));
$sigctx = render_context::preview($context, $course, $cert);
$templateobj->configure($sigctx);
$sigctx->set_page_size(297.0, 210.0);
check(str_contains((string) $sigelement->render_html($sigctx), 'pluginfile.php'),
    'an element pointed at the library renders the library picture');

check(\mod_certificatepro\local\media_library::usage_count($mediaid) === 1,
    'the library reports how many elements use a picture',
    (string) \mod_certificatepro\local\media_library::usage_count($mediaid));

$templateobj->delete_element($sigid);

// Logo slots.
$slots = \mod_certificatepro\local\brand::logo_slots();
check(count($slots) === 4, 'four named logo slots exist', (string) count($slots));
check($slots[1] !== '' && $slots[4] !== '', 'every slot has a display name');
check(\mod_certificatepro\local\brand::slot_logo_file(1) === null,
    'an empty slot reports no file rather than failing');
check(\mod_certificatepro\local\brand::slot_logo_path(9) === null,
    'an out of range slot is refused');

$logosources = \mod_certificatepro\element\type\themelogo::sources();
check(count($logosources) === 7,
    'the logo element offers theme full, theme compact, four slots and the library',
    implode(',', array_keys($logosources)));

\mod_certificatepro\local\media_library::delete($mediaid);
check(\mod_certificatepro\local\media_library::get($mediaid) === null,
    'library picture removed cleanly');

// ------------------------------------------------------------- privacy.

section('Privacy API');

$contextlist = \mod_certificatepro\privacy\provider::get_contexts_for_userid((int) $second->id);
check($contextlist->count() > 0, 'privacy provider finds the learner context');

$userlist = new \core_privacy\local\request\userlist($context, 'mod_certificatepro');
\mod_certificatepro\privacy\provider::get_users_in_context($userlist);
check(count($userlist->get_userids()) >= 1, 'privacy provider finds users in the context');

$approved = new \core_privacy\local\request\approved_contextlist(
    $second, 'mod_certificatepro', [$context->id]);
try {
    \mod_certificatepro\privacy\provider::export_user_data($approved);
    check(true, 'privacy export runs against the real content writer');
} catch (Throwable $e) {
    check(false, 'privacy export runs against the real content writer', $e->getMessage());
}

\mod_certificatepro\privacy\provider::delete_data_for_user($approved);
check(!$DB->record_exists('certificatepro_issues',
    ['certificateproid' => $cert->id, 'userid' => $second->id]),
    'privacy deletion removes the learner issue');

// ------------------------------------------------ the activity settings form.

section('The activity settings form');

// Building this form is the whole of adding the activity to a course, so if it
// throws there is no way to create a certificate at all. It went unnoticed
// because the fault only appears on Moodle 5.x: a duration element with its
// units restricted must also name its default unit, and Moodle 5.0 started
// refusing to build one that does not. Every live test had been on 4.5.
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/certificatepro/mod_form.php');

// The form reads the global $COURSE rather than the course handed to it, which
// in a web request is this course and on the command line is the front page.
global $COURSE;
$previouscourse = $COURSE;
$COURSE = $course;
rebuild_course_cache($course->id, true);

$formerror = '';
$formfields = 0;
try {
    $formcurrent = clone $cert;
    $formcurrent->coursemodule = $cm->id;
    $formcurrent->instance = $cert->id;
    $formcurrent->course = $course->id;

    $settingsform = new mod_certificatepro_mod_form($formcurrent, 1, $cm, $course);
    $formhtml = $settingsform->render();
    $formfields = substr_count($formhtml, '<input') + substr_count($formhtml, '<select');
} catch (\Throwable $e) {
    $formerror = get_class($e) . ': ' . $e->getMessage();
}

$COURSE = $previouscourse;

check($formerror === '', 'the activity settings form builds without throwing', $formerror);
check($formfields > 20, 'the settings form renders its fields', $formfields . ' found');

// ------------------------------------------- serials across a course restore.

section('Serial numbers across a course restore');

// A restored activity arrives with its certificates but without the counter
// that produced their numbers, because the counter is not in the backup. If it
// is not put back, the next certificate issued repeats a serial that is already
// printed on one of them.
$serialinstance = $DB->get_record('certificatepro', ['id' => $cert->id], '*', MUST_EXIST);
$serialinstance->serialprefix = 'VRF-';
$serialinstance->serialpadding = 6;
$DB->update_record('certificatepro', $serialinstance);

$DB->delete_records('certificatepro_serials', ['scope' => 'instance_' . $cert->id]);
$DB->delete_records('certificatepro_issues', ['certificateproid' => $cert->id]);

// Three certificates as a restore would leave them: rows present, counter gone.
foreach ([1, 2, 3] as $n) {
    $DB->insert_record('certificatepro_issues', (object) [
        'certificateproid' => $cert->id,
        'userid' => $learner->id,
        'code' => \mod_certificatepro\local\issue_service::generate_code(),
        'serial' => 'VRF-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
        'serialnumber' => $n,
        'templaterevision' => 0,
        'emailed' => 0,
        'expires' => 0,
        'expirynotified' => 0,
        'revoked' => 0,
        'revokedby' => 0,
        'timerevoked' => 0,
        'issuedby' => 0,
        'timecreated' => time(),
    ]);
}

check(!$DB->record_exists('certificatepro_serials', ['scope' => 'instance_' . $cert->id]),
    'a restored activity starts with no serial counter');

$aligned = \mod_certificatepro\local\issue_service::realign_serial_counter((int) $cert->id);
check($aligned === 3, 'the counter is realigned to the highest restored serial',
    'got ' . $aligned);

[$nextserial, $nextnumber] = \mod_certificatepro\local\issue_service::next_serial(
    'VRF-', 6, 'instance_' . $cert->id);
check($nextnumber === 4, 'the next serial continues past the restored ones',
    'got ' . $nextnumber);
check(!$DB->record_exists('certificatepro_issues',
    ['certificateproid' => $cert->id, 'serial' => $nextserial]),
    'the next serial is not one already issued', $nextserial);

// Realigning twice must not move a counter that is already ahead.
$DB->set_field('certificatepro_serials', 'counter', 99, ['scope' => 'instance_' . $cert->id]);
\mod_certificatepro\local\issue_service::realign_serial_counter((int) $cert->id);
check((int) $DB->get_field('certificatepro_serials', 'counter',
    ['scope' => 'instance_' . $cert->id]) === 99,
    'realigning never moves a counter backwards');

// An activity restored without user data has nothing to derive from.
$DB->delete_records('certificatepro_issues', ['certificateproid' => $cert->id]);
$DB->delete_records('certificatepro_serials', ['scope' => 'instance_' . $cert->id]);
check(\mod_certificatepro\local\issue_service::realign_serial_counter((int) $cert->id) === 0,
    'an activity restored without certificates keeps no counter');
check(!$DB->record_exists('certificatepro_serials', ['scope' => 'instance_' . $cert->id]),
    'no counter row is invented for an activity with no certificates');

// ------------------------------------------- faces a publisher does not offer.

section('Fonts: faces the publisher does not offer');

// A family is not deficient for lacking a face that does not exist, and saying
// it is leaves the fonts page asking for work that can never be completed.
$previousunavailable = get_config('mod_certificatepro', 'gfontsunavailablefaces');
set_config('gfontsunavailablefaces', '', 'mod_certificatepro');

$cairo = \mod_certificatepro\local\font\google_fonts::unavailable_faces('Cairo');
check(in_array('I', $cairo, true) && in_array('BI', $cairo, true),
    'Cairo is known to publish no italic', implode(',', $cairo));
check(!in_array('', $cairo, true) && !in_array('B', $cairo, true),
    'Cairo is not thought to be missing its regular or bold');
check(\mod_certificatepro\local\font\google_fonts::unavailable_faces('Lato') === [],
    'a family that publishes all four faces reports none unavailable');
check(\mod_certificatepro\local\font\google_fonts::unavailable_faces('') === [],
    'an empty family name is handled');

foreach (\mod_certificatepro\local\font\google_fonts::preset_status() as $statusrow) {
    if (!array_key_exists('unavailable', $statusrow)) {
        check(false, 'every status row says which faces are unavailable',
            $statusrow['family']);
        break;
    }
}
check(true, 'every status row says which faces are unavailable');

// What an install found outranks the bundled table, in both directions.
set_config('gfontsunavailablefaces', json_encode(['cairo' => []]), 'mod_certificatepro');
check(\mod_certificatepro\local\font\google_fonts::unavailable_faces('Cairo') === [],
    'a family that gains a face upstream repairs its own record');

set_config('gfontsunavailablefaces', json_encode(['lato' => ['BI']]), 'mod_certificatepro');
check(\mod_certificatepro\local\font\google_fonts::unavailable_faces('Lato') === ['BI'],
    'a family outside the bundled table learns from its own install');

set_config('gfontsunavailablefaces', $previousunavailable === false ? '' : $previousunavailable,
    'mod_certificatepro');

// ------------------------------------------------------------- teardown.

section('Deleting the activity');

$remaining = $DB->count_records('certificatepro_issues', ['certificateproid' => $cert->id]);

// Moodle 5.2 deprecated course_delete_module() in favour of the course format
// actions. Both are called here because this script has to run unchanged on the
// versions the plugin supports, and the older ones have no formatactions class.
if (class_exists('\\core_courseformat\\formatactions')
        && method_exists('\\core_courseformat\\local\\cmactions', 'delete')) {
    \core_courseformat\formatactions::cm($cm->course)->delete($cm->id);
} else {
    course_delete_module($cm->id);
}

check(!$DB->record_exists('certificatepro', ['id' => $cert->id]), 'activity row deleted');
check(!$DB->record_exists('certificatepro_issues', ['certificateproid' => $cert->id]),
    'issues deleted with the activity', $remaining . ' existed before');
check(!$DB->record_exists('certificatepro_templates', ['id' => $cert->templateid]),
    'design deleted with the activity');
check(!$DB->record_exists('certificatepro_pages', ['templateid' => $cert->templateid]),
    'pages deleted with the design');

// -------------------------------------------------------------- summary.

cli_writeln('');
cli_writeln(str_repeat('-', 70));
cli_writeln(sprintf('PASSED %d   FAILED %d', $pass, $fail));

if ($fail > 0) {
    cli_writeln('');
    cli_writeln('Failures:');
    foreach ($failures as $failure) {
        cli_writeln('  - ' . $failure);
    }
}

cli_writeln(str_repeat('-', 70));

exit($fail === 0 ? 0 : 1);
