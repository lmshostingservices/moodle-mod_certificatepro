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

use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\credential\badge_service;
use mod_certificatepro\local\credential\open_badge;
use mod_certificatepro\local\credential\svg_baker;
use mod_certificatepro\local\issue_service;

/**
 * Tests for the badge a certificate is issued alongside.
 *
 * The badge is the only artefact here that a third party parses, so the two
 * things that matter are that it is well formed SVG and that the credential
 * baked into it comes back out unchanged. The third thing that matters is
 * subtler: the badge has to be the seal the design actually carries, or the
 * learner ends up holding two marks that do not look like each other.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(badge_service::class)]
final class badge_service_test extends \advanced_testcase {

    /**
     * A course, a learner and an issued certificate built from a starter.
     *
     * @param string $starter the starter design to build from
     * @return array{course: \stdClass, user: \stdClass, instance: \stdClass, issue: \stdClass}
     */
    protected function issued(string $starter = 'classic'): array {
        global $DB, $PAGE;

        // Creating an activity is supposed to be a pure back end operation, and
        // the module generator throws if the theme is initialised while one is
        // created. Building a design reads the theme's brand colour, so it is
        // initialised here first — see the note on brand::primary_colour().
        $PAGE->initialise_theme_and_output();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Renée', 'lastname' => 'Ó Súilleabháin',
        ]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $module = $this->getDataGenerator()->create_module('certificatepro', [
            'course' => $course->id,
            'name' => 'Certificate of Achievement',
            'starter' => $starter,
        ]);

        $instance = $DB->get_record('certificatepro', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('certificatepro', $instance->id, $course->id,
            false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $issue = issue_service::issue($instance, $course, $context, $user);

        return ['course' => $course, 'user' => $user, 'instance' => $instance, 'issue' => $issue];
    }

    /**
     * The unbaked artwork is well formed SVG and carries no credential.
     *
     * @return void
     */
    public function test_the_artwork_is_well_formed_svg(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();
        $svg = badge_service::artwork($fixture['issue'], $fixture['course'], $fixture['instance']);

        $this->assertStringStartsWith('<svg', ltrim($svg));

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $this->assertSame('svg', $doc->documentElement->localName);
        $this->assertFalse(svg_baker::is_baked($svg));
    }

    /**
     * A baked badge parses, and carries exactly one credential where the specification puts it.
     *
     * @return void
     */
    public function test_the_badge_is_well_formed_and_carries_one_credential(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();
        $svg = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg), 'the baked badge is not well formed XML');

        $this->assertTrue(svg_baker::is_baked($svg));
        $this->assertSame(1, substr_count($svg, '<' . svg_baker::PREFIX . ':' . svg_baker::ELEMENT));

        // Section 5.3 puts the credential directly after the root tag.
        $this->assertMatchesRegularExpression(
            '/<svg[^>]*>\s*<' . svg_baker::PREFIX . ':' . svg_baker::ELEMENT . '/', $svg);
        $this->assertStringContainsString(
            'xmlns:' . svg_baker::PREFIX . '="' . svg_baker::NAMESPACE_URI . '"', $svg);
    }

    /**
     * What is baked in comes back out unchanged, and validates.
     *
     * @return void
     */
    public function test_the_baked_credential_round_trips_and_validates(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();
        $svg = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $extracted = svg_baker::extract($svg);
        $built = open_badge::build($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $this->assertSame($built, $extracted);
        $this->assertSame([], open_badge::validate($extracted));
        $this->assertStringContainsString($fixture['issue']->code, $extracted['id']);
    }

    /**
     * Stripping a badge leaves the drawing untouched.
     *
     * @return void
     */
    public function test_stripping_the_badge_gives_back_the_artwork(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();
        $artwork = badge_service::artwork($fixture['issue'], $fixture['course'],
            $fixture['instance']);
        $badge = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $stripped = svg_baker::strip($badge);
        $this->assertFalse(svg_baker::is_baked($stripped));

        // Baking declares a namespace on the root; nothing else about the
        // drawing may change.
        $undeclared = str_replace(
            ' xmlns:' . svg_baker::PREFIX . '="' . svg_baker::NAMESPACE_URI . '"', '', $stripped);
        $this->assertSame($artwork, $undeclared);
    }

    /**
     * The badge is the design's own seal, not a generic one.
     *
     * @return void
     */
    public function test_the_badge_is_the_seal_the_design_carries(): void {
        $this->resetAfterTest();

        // The ceremonial starter uses a scalloped edge and a crest, where the
        // classic one uses a double edge and a monogram. If the badge ignored
        // the design the two would come out identical.
        $classic = $this->issued('classic');
        $ceremonial = $this->issued('ceremonial');

        $classicart = badge_service::artwork($classic['issue'], $classic['course'],
            $classic['instance']);
        $ceremonialart = badge_service::artwork($ceremonial['issue'], $ceremonial['course'],
            $ceremonial['instance']);

        $this->assertNotSame($classicart, $ceremonialart,
            'two designs with different seals produced the same badge');

        // The ring lettering the starter sets has to be there.
        $toptext = get_string('startertext_sealtop', 'mod_certificatepro');
        $this->assertStringContainsString(\core_text::substr($toptext, 0, 1), $classicart);
    }

    /**
     * Issuing freezes the seal settings alongside the resolved values.
     *
     * @return void
     */
    public function test_the_seal_is_frozen_into_the_snapshot_at_issue(): void {
        $this->resetAfterTest();

        $fixture = $this->issued('ceremonial');

        $snapshot = json_decode((string) $fixture['issue']->snapshot, true);
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey(badge_service::SNAPSHOT_KEY, $snapshot);
        $this->assertSame(seal_engine::EDGE_SCALLOP,
            $snapshot[badge_service::SNAPSHOT_KEY]['sealedge']);
    }

    /**
     * An issued badge does not follow a later design edit.
     *
     * @return void
     */
    public function test_an_edited_design_does_not_change_an_issued_badge(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->issued('classic');
        $before = badge_service::artwork($fixture['issue'], $fixture['course'],
            $fixture['instance']);

        // Change the design's seal after the certificate was issued.
        $seal = $DB->get_record_sql(
            'SELECT e.* FROM {certificatepro_elements} e
               JOIN {certificatepro_pages} p ON p.id = e.pageid
              WHERE p.templateid = ? AND e.element = ?',
            [$fixture['instance']->templateid, 'seal'], IGNORE_MULTIPLE);
        $this->assertNotEmpty($seal);

        $data = json_decode($seal->data, true);
        $data['sealedge'] = seal_engine::EDGE_STARBURST;
        $data['toptext'] = 'SOMETHING ENTIRELY DIFFERENT';
        $DB->set_field('certificatepro_elements', 'data', json_encode($data), ['id' => $seal->id]);

        $reissued = $DB->get_record('certificatepro_issues', ['id' => $fixture['issue']->id]);
        $after = badge_service::artwork($reissued, $fixture['course'], $fixture['instance']);

        $this->assertSame($before, $after, 'an issued badge followed a design edit');
    }

    /**
     * A design with no seal still produces a badge.
     *
     * @return void
     */
    public function test_a_design_with_no_seal_falls_back_to_the_site(): void {
        $this->resetAfterTest();

        // The blank starter ships no elements at all.
        $fixture = $this->issued('blank');
        $svg = badge_service::artwork($fixture['issue'], $fixture['course'],
            $fixture['instance']);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));

        // The fallback is built from the site name, so it still letters a ring.
        $this->assertStringContainsString('<text', $svg);
    }

    /**
     * The same issue always produces the same badge bytes.
     *
     * @return void
     */
    public function test_the_badge_is_stable_for_the_same_issue(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();

        $first = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);
        $second = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $this->assertSame($first, $second);
    }

    /**
     * Baking over a baked badge replaces rather than duplicates.
     *
     * @return void
     */
    public function test_rebaking_a_badge_never_leaves_two_credentials(): void {
        $this->resetAfterTest();

        $fixture = $this->issued();
        $svg = badge_service::badge($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $credential = open_badge::build($fixture['issue'], $fixture['instance'],
            $fixture['course'], $fixture['user']);

        $rebaked = svg_baker::bake($svg, $credential);

        $this->assertSame(1,
            substr_count($rebaked, '<' . svg_baker::PREFIX . ':' . svg_baker::ELEMENT));
        $this->assertSame($svg, $rebaked);
    }

    /**
     * The download filename names the code and cannot escape its directory.
     *
     * @return void
     */
    public function test_the_filename_is_safe_and_names_the_code(): void {
        $this->resetAfterTest();

        $name = badge_service::filename((object) ['code' => 'CP-4K7M-9XQ2-P3TR']);

        $this->assertSame('badge-CP-4K7M-9XQ2-P3TR.svg', $name);
        $this->assertStringEndsWith('.svg', badge_service::filename(new \stdClass()));
        $this->assertStringNotContainsString('/',
            badge_service::filename((object) ['code' => '../../etc/passwd']));
    }
}
