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

namespace mod_certificatepro;

defined('MOODLE_INTERNAL') || die();

use core_files\archive_writer;
use mod_certificatepro\local\issue_service;
use mod_certificatepro\local\render\zip_packager;

/**
 * Tests for packing a course's issued certificates into one zip.
 *
 * Three things decide whether this feature is safe to ship. Every certificate
 * has to come out under a name a filesystem will actually take and no two may
 * collide, or the teacher unpacks fewer files than the zip claims and never
 * learns which learner went missing. Withdrawn certificates must not leak into
 * an archive that reads as a set of valid awards. And what comes out has to be
 * the document as issued, not a fresh render against a design that has since
 * been edited.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(zip_packager::class)]
final class zip_packager_test extends \advanced_testcase {

    /**
     * A course, an activity and a certificate issued to each named learner.
     *
     * @param array $names firstname and lastname pairs to enrol and issue to
     * @param array $settings activity settings to override
     * @return array{course: \stdClass, instance: \stdClass, context: \context,
     *      cm: \stdClass, users: \stdClass[], issues: \stdClass[]}
     */
    protected function issued_course(array $names, array $settings = []): array {
        global $DB, $PAGE;

        $this->resetAfterTest();

        // Building a design reads the theme's brand colour, and the module
        // generator refuses to run once the theme has been initialised behind
        // its back, so it is initialised here first.
        $PAGE->initialise_theme_and_output();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'ZIP-101']);

        $module = $this->getDataGenerator()->create_module('certificatepro', $settings + [
            'course' => $course->id,
            'name' => 'Certificate of Achievement',
            'starter' => 'classic',
        ]);

        $instance = $DB->get_record('certificatepro', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('certificatepro', $instance->id, $course->id,
            false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $users = [];
        $issues = [];
        foreach ($names as $name) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => $name[0], 'lastname' => $name[1],
            ]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $users[] = $user;
            $issues[] = issue_service::issue($instance, $course, $context, $user);
        }

        return ['course' => $course, 'instance' => $instance, 'context' => $context,
            'cm' => $cm, 'users' => $users, 'issues' => $issues];
    }

    /**
     * Names no filesystem or zip reader will take are reduced to ones they will.
     *
     * @param string $input the proposed name
     * @param string $expected what should survive
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_name_provider')]
    public function test_an_unsafe_name_is_reduced_to_a_safe_one(string $input,
            string $expected): void {
        $this->assertSame($expected, zip_packager::safe_stem($input));
    }

    /**
     * Names that a filesystem, a zip reader or Windows would reject.
     *
     * @return array[]
     */
    public static function unsafe_name_provider(): array {
        return [
            'plain' => ['Ada Lovelace', 'Ada Lovelace'],
            'forward slash makes a directory' => ['a/b', 'ab'],
            'back slash makes a directory too' => ['a\\b', 'ab'],
            'colon' => ['a:b', 'ab'],
            // clean_filename() leaves these, and Windows rejects them outright.
            'wildcards' => ['a*b?c', 'abc'],
            'angle brackets and pipe' => ['a<b>c|d', 'abcd'],
            'double quote' => ['say "hello"', 'say hello'],
            'newline from a pasted field' => ["Ada\nLovelace", 'Ada Lovelace'],
            'tab' => ["Ada\tLovelace", 'Ada Lovelace'],
            'null byte' => ["Ada\0Lovelace", 'Ada Lovelace'],
            'traversal' => ['../../etc/passwd', 'etcpasswd'],
            'dot run collapses' => ['a....b', 'a.b'],
            'trailing dot is dropped by windows' => ['Ada.', 'Ada'],
            'trailing space is dropped by windows' => ['Ada ', 'Ada'],
            'leading space' => [' Ada', 'Ada'],
            'runs of space collapse' => ['Ada    Lovelace', 'Ada Lovelace'],
            'reserved device name' => ['con', 'con_'],
            'reserved device name in caps' => ['COM1', 'COM1_'],
            'a reserved word inside a name is fine' => ['con man', 'con man'],
            'non latin survives' => ['Ривера', 'Ривера'],
            'cjk survives' => ['李雷', '李雷'],
            'accents survive' => ['Ünlü Öztürk', 'Ünlü Öztürk'],
            'nothing usable' => ['///', ''],
            'empty' => ['', ''],
            'only dots' => ['...', ''],
        ];
    }

    /**
     * A long name is cut on a character boundary, not through one.
     *
     * @return void
     */
    public function test_a_long_name_is_cut_without_breaking_a_character(): void {
        $stem = zip_packager::safe_stem(str_repeat('Ривера', 60));

        $this->assertSame(zip_packager::MAX_STEM, \core_text::strlen($stem));
        // A cut through a multibyte sequence leaves bytes no decoder accepts.
        $this->assertTrue((bool) preg_match('//u', $stem), 'the cut produced invalid UTF-8');
        $this->assertSame($stem, \core_text::entities_to_utf8(\core_text::utf8_to_entities($stem)));
    }

    /**
     * A name in a script that spends three bytes a character still fits a filesystem.
     *
     * @return void
     */
    public function test_a_long_name_fits_the_byte_limit_as_well_as_the_character_limit(): void {
        $stem = zip_packager::safe_stem(str_repeat('雷', 200));

        // The character count alone would have let 300 bytes through, and most
        // filesystems count bytes.
        $this->assertLessThanOrEqual(zip_packager::MAX_BYTES, strlen($stem));
        $this->assertLessThanOrEqual(zip_packager::MAX_STEM, \core_text::strlen($stem));
        $this->assertGreaterThan(0, \core_text::strlen($stem));
        $this->assertTrue((bool) preg_match('//u', $stem), 'the cut produced invalid UTF-8');
    }

    /**
     * Two learners sharing a long CJK name still get whole, distinct codes.
     *
     * @return void
     */
    public function test_a_shared_cjk_name_keeps_its_whole_code_within_the_byte_limit(): void {
        $long = str_repeat('雷', 90);
        $fixture = $this->issued_course([[$long, '李'], [$long, '李']]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertCount(2, array_unique($names));
        foreach ($rows as $row) {
            $this->assertStringContainsString((string) $row->code, $names[$row->id]);
            $this->assertLessThanOrEqual(zip_packager::MAX_BYTES + 4, strlen($names[$row->id]));
        }
    }

    /**
     * Whatever comes out is left alone by the archive writer's own sanitiser.
     *
     * The writer runs every name through clean_param(PARAM_PATH). If that
     * rewrote a name after this class had proved the set unique, two entries
     * could silently become one.
     *
     * @param string $input the proposed name
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_name_provider')]
    public function test_a_safe_name_survives_the_archive_writers_sanitiser(string $input): void {
        $stem = zip_packager::safe_stem($input);

        if ($stem === '') {
            $this->assertSame('', $stem);
            return;
        }

        $this->assertSame($stem . '.pdf', clean_param($stem . '.pdf', PARAM_PATH));
    }

    /**
     * Learners who share a name each get the code printed on their certificate.
     *
     * @return void
     */
    public function test_learners_sharing_a_name_get_distinct_files(): void {
        $fixture = $this->issued_course([
            ['John', 'Smith'], ['John', 'Smith'], ['John', 'Smith'], ['Ada', 'Lovelace'],
        ]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertCount(4, $names);
        $this->assertCount(4, array_unique(array_map('core_text::strtolower', $names)));

        // Every one of the three carries its code, not just the second and
        // third: a plain "John Smith.pdf" beside two suffixed ones tells the
        // teacher nothing about which John Smith the plain one belongs to.
        foreach ($rows as $row) {
            if (fullname($row->user) === 'John Smith') {
                $this->assertStringContainsString((string) $row->code, $names[$row->id]);
            } else {
                $this->assertStringNotContainsString('CP-', $names[$row->id]);
            }
        }
    }

    /**
     * The code that tells two long names apart is never the part that is cut off.
     *
     * @return void
     */
    public function test_a_shared_long_name_keeps_its_whole_code(): void {
        $long = str_repeat('Wolfeschlegelstein', 5);
        $fixture = $this->issued_course([[$long, 'Hausen'], [$long, 'Hausen']]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertCount(2, array_unique($names));
        foreach ($rows as $row) {
            $this->assertStringContainsString((string) $row->code, $names[$row->id]);
            $this->assertLessThanOrEqual(zip_packager::MAX_STEM + 4,
                \core_text::strlen($names[$row->id]));
        }
    }

    /**
     * A name made only of characters no filesystem takes still produces a file.
     *
     * @return void
     */
    public function test_a_name_with_nothing_usable_falls_back_to_the_code(): void {
        $fixture = $this->issued_course(
            [['///', '???']],
            ['usecustomfilename' => 1, 'customfilenamepattern' => '{fullname}']
        );

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);
        $row = reset($rows);

        $this->assertNotSame('.pdf', $names[$row->id]);
        $this->assertStringContainsString(str_replace('-', '', (string) $row->code),
            str_replace('-', '', $names[$row->id]));
    }

    /**
     * A custom pattern that resolves the same for everybody still yields unique files.
     *
     * @return void
     */
    public function test_a_constant_custom_pattern_still_yields_unique_names(): void {
        $fixture = $this->issued_course(
            [['Ada', 'Lovelace'], ['Alan', 'Turing'], ['Grace', 'Hopper']],
            ['usecustomfilename' => 1, 'customfilenamepattern' => '{courseshortname}']
        );

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertCount(3, array_unique(array_map('core_text::strtolower', $names)));
        foreach ($names as $name) {
            $this->assertStringStartsWith('ZIP-101', $name);
        }
    }

    /**
     * The custom filename setting is honoured rather than worked around.
     *
     * @return void
     */
    public function test_the_activitys_custom_filename_pattern_is_used(): void {
        $fixture = $this->issued_course(
            [['Ada', 'Lovelace']],
            ['usecustomfilename' => 1, 'customfilenamepattern' => 'Award for {firstname} {lastname}']
        );

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertSame('Award for Ada Lovelace.pdf', reset($names));
    }

    /**
     * Revoked certificates are left out unless the teacher asks, and expired ones are not.
     *
     * @return void
     */
    public function test_revoked_are_excluded_by_default_and_expired_are_not(): void {
        global $DB;

        $fixture = $this->issued_course([
            ['Ada', 'Lovelace'], ['Alan', 'Turing'], ['Grace', 'Hopper'],
        ]);

        issue_service::revoke($fixture['issues'][0], $fixture['context'], 'Awarded in error');
        $DB->set_field('certificatepro_issues', 'expires', time() - DAYSECS,
            ['id' => $fixture['issues'][1]->id]);

        $id = (int) $fixture['instance']->id;

        $this->assertCount(2, issue_service::download_set($id, false, true));
        $this->assertCount(3, issue_service::download_set($id, true, true));
        $this->assertCount(1, issue_service::download_set($id, false, false));
        $this->assertCount(2, issue_service::download_set($id, true, false));
    }

    /**
     * An included revoked certificate is marked, so it cannot pass as one that stands.
     *
     * @return void
     */
    public function test_an_included_revoked_certificate_is_named_as_revoked(): void {
        $fixture = $this->issued_course([['Ada', 'Lovelace'], ['Alan', 'Turing']]);
        issue_service::revoke($fixture['issues'][0], $fixture['context'], 'Awarded in error');

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $this->assertStringContainsString(get_string('revokedprefix', 'mod_certificatepro'),
            $names[$fixture['issues'][0]->id]);
        $this->assertStringNotContainsString(get_string('revokedprefix', 'mod_certificatepro'),
            $names[$fixture['issues'][1]->id]);
    }

    /**
     * A certificate belonging to a deleted user never leaves the site in bulk.
     *
     * @return void
     */
    public function test_a_deleted_users_certificate_is_not_exported(): void {
        $fixture = $this->issued_course([['Ada', 'Lovelace'], ['Alan', 'Turing']]);

        delete_user($fixture['users'][0]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);

        $this->assertCount(1, $rows);
        $this->assertSame((int) $fixture['users'][1]->id, (int) reset($rows)->userid);
    }

    /**
     * The set carries only what naming and file lookup need, in the report's order.
     *
     * @return void
     */
    public function test_the_set_is_lean_and_ordered_by_name(): void {
        $fixture = $this->issued_course([
            ['Ada', 'Zeta'], ['Alan', 'Alpha'], ['Grace', 'Mu'],
        ]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $surnames = array_map(static function ($row) {
            return $row->user->lastname;
        }, array_values($rows));

        $this->assertSame(['Alpha', 'Mu', 'Zeta'], $surnames);

        // The whole point of the lean row is that a two thousand learner course
        // does not load two thousand full user records beside the streaming.
        $row = reset($rows);
        $this->assertObjectNotHasProperty('snapshot', $row);
        $this->assertObjectNotHasProperty('email', $row->user);
        $this->assertObjectHasProperty('firstname', $row->user);
    }

    /**
     * The zip holds exactly the certificates asked for, each valid and naming its learner.
     *
     * @return void
     */
    public function test_the_archive_holds_a_valid_pdf_per_learner(): void {
        $fixture = $this->issued_course([
            ['Ada', 'Lovelace'], ['Renée', 'Ó Súilleabháin'], ['李', '雷'],
        ]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $path = $this->write_archive($fixture, $rows);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $this->assertSame(3, $zip->numFiles);

        foreach ($names as $name) {
            $contents = $zip->getFromName($name);
            $this->assertNotFalse($contents, "the archive has no entry called $name");
            $this->assertStringStartsWith('%PDF-', $contents);
            $this->assertGreaterThan(20000, strlen($contents),
                "the entry $name is too small to be a rendered certificate");
        }

        $zip->close();
    }

    /**
     * The bytes in the archive are the bytes stored at issue, hash for hash.
     *
     * @return void
     */
    public function test_the_archive_carries_the_document_as_issued(): void {
        global $DB;

        $fixture = $this->issued_course([['Ada', 'Lovelace']]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);
        $row = reset($rows);

        // Rename the course. A live render would print the new name; a replay
        // of the snapshot cannot.
        $DB->set_field('course', 'fullname', 'Renamed After Issue',
            ['id' => $fixture['course']->id]);

        $path = $this->write_archive($fixture, $rows);
        $zip = new \ZipArchive();
        $zip->open($path);
        $contents = $zip->getFromName($names[$row->id]);
        $zip->close();

        $issue = $DB->get_record('certificatepro_issues', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame($issue->pdfhash, hash('sha256', $contents),
            'the archived certificate is not the one that was issued');
    }

    /**
     * A certificate whose stored PDF has gone is redrawn from its snapshot, not from today.
     *
     * @return void
     */
    public function test_a_missing_pdf_is_redrawn_from_the_snapshot(): void {
        global $DB;

        $fixture = $this->issued_course([['Ada', 'Lovelace']]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $row = reset($rows);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);

        $snapshot = $DB->get_field('certificatepro_issues', 'snapshot', ['id' => $row->id]);
        $this->assertNotEmpty($snapshot, 'the fixture did not store a snapshot to replay');

        // Lose the stored file, then move the world on underneath it.
        get_file_storage()->delete_area_files($fixture['context']->id, 'mod_certificatepro',
            issue_service::FILEAREA, $row->id);
        $DB->set_field('course', 'fullname', 'Renamed After Issue',
            ['id' => $fixture['course']->id]);

        $path = $this->write_archive($fixture, $rows);
        $zip = new \ZipArchive();
        $zip->open($path);
        $contents = $zip->getFromName($names[$row->id]);
        $zip->close();

        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(20000, strlen($contents));

        // The replay is drawn from the snapshot, so the snapshot is unchanged
        // and the certificate still stands for what it said on the day.
        $this->assertSame($snapshot,
            $DB->get_field('certificatepro_issues', 'snapshot', ['id' => $row->id]));
    }

    /**
     * A certificate that can neither be read nor redrawn leaves a note, not a gap.
     *
     * @return void
     */
    public function test_an_unrecoverable_certificate_leaves_a_note_in_its_place(): void {
        global $DB;

        $fixture = $this->issued_course([['Ada', 'Lovelace'], ['Alan', 'Turing']]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $names = zip_packager::plan_names($rows, $fixture['instance'], $fixture['course']);
        $row = reset($rows);

        // Lose the stored file and the design it was drawn from, so there is
        // nothing left to serve and nothing left to redraw with.
        get_file_storage()->delete_area_files($fixture['context']->id, 'mod_certificatepro',
            issue_service::FILEAREA, $row->id);
        $DB->delete_records('certificatepro_templates',
            ['id' => (int) $fixture['instance']->templateid]);

        $path = $this->write_archive($fixture, $rows);

        // The failed redraw calls debugging(), which on a site with developer
        // debugging on writes to output. Mid-stream that would land inside the
        // zip and no reader could open the result, which is why packing buffers
        // the redraw. Here it only has to be acknowledged.
        $this->assertDebuggingCalled();

        $zip = new \ZipArchive();
        $zip->open($path);

        // The other learner's certificate is untouched: one unreadable file
        // must not cost the teacher the rest of the course.
        $this->assertSame(2, $zip->numFiles);
        $this->assertFalse($zip->getFromName($names[$row->id]));

        $note = $zip->getFromName(pathinfo($names[$row->id], PATHINFO_FILENAME) . '.txt');
        $this->assertNotFalse($note, 'nothing was put in place of the unreadable certificate');
        $this->assertStringContainsString((string) $row->code, $note);

        $zip->close();
    }

    /**
     * Counting stored certificates is what decides the time limit, so it has to be right.
     *
     * @return void
     */
    public function test_stored_certificates_are_counted(): void {
        $fixture = $this->issued_course([
            ['Ada', 'Lovelace'], ['Alan', 'Turing'], ['Grace', 'Hopper'],
        ]);

        $rows = issue_service::download_set((int) $fixture['instance']->id, true, true);
        $packager = new zip_packager($fixture['instance'], $fixture['course'],
            $fixture['context']);

        $this->assertSame(3, $packager->count_stored($rows));

        $row = reset($rows);
        get_file_storage()->delete_area_files($fixture['context']->id, 'mod_certificatepro',
            issue_service::FILEAREA, $row->id);

        $this->assertSame(2, $packager->count_stored($rows));
        $this->assertSame(0, $packager->count_stored([]));
    }

    /**
     * The time limit is budgeted from what the work costs, not from a round number.
     *
     * @return void
     */
    public function test_the_time_budget_grows_with_the_work(): void {
        // Copying stored bytes is cheap; redrawing is not, and the budget has
        // to say so or a course that lost its files times out halfway through.
        $this->assertGreaterThan(zip_packager::time_budget(2000, 0),
            zip_packager::time_budget(0, 2000));
        $this->assertGreaterThan(MINSECS, zip_packager::time_budget(2000, 0));
        $this->assertGreaterThanOrEqual(MINSECS, zip_packager::time_budget(0, 0));
    }

    /**
     * The archive is named after the course and the activity, safely.
     *
     * @return void
     */
    public function test_the_archive_is_named_after_the_course_and_activity(): void {
        $instance = (object) ['name' => 'Certificate: of/Achievement'];
        $course = (object) ['shortname' => 'ZIP*101'];

        $this->assertSame('ZIP101 - Certificate ofAchievement.zip',
            zip_packager::zip_filename($instance, $course));

        $this->assertSame('certificates.zip',
            zip_packager::zip_filename((object) ['name' => '///'], (object) ['shortname' => '']));
    }

    /**
     * Pack a set into a file rather than down the wire, so a test can read it.
     *
     * @param array $fixture the fixture from issued_course()
     * @param array $rows the set to pack
     * @return string the path to the written zip
     */
    protected function write_archive(array $fixture, array $rows): string {
        $writer = archive_writer::get_file_writer('certificates.zip', archive_writer::ZIP_WRITER);

        $packager = new zip_packager($fixture['instance'], $fixture['course'],
            $fixture['context']);
        $packager->pack($writer, $rows);
        $writer->finish();

        return $writer->get_path_to_zip();
    }
}
