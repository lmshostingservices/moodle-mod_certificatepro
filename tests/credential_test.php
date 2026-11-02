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

use mod_certificatepro\local\credential\open_badge;
use mod_certificatepro\local\credential\svg_baker;

/**
 * Tests for building Open Badges 3.0 credentials and baking them into SVG.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(svg_baker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(open_badge::class)]
final class credential_test extends \advanced_testcase {
    /**
     * A credential whose values exercise the awkward parts of XML.
     *
     * The description carries a CDATA terminator, an angle bracket and a
     * quotation mark, because those are what break a naive baker.
     *
     * @return array a credential shaped document
     */
    protected function sample_credential(): array {
        return [
            '@context' => [
                open_badge::CONTEXT_CREDENTIALS,
                open_badge::CONTEXT_OPEN_BADGES,
            ],
            'id' => 'https://example.com/mod/certificatepro/verify.php?code=CP-4K7M-9XQ2-P3TR',
            'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
            'issuer' => [
                'id' => 'https://example.com',
                'type' => ['Profile'],
                'name' => 'École Élan',
                'url' => 'https://example.com',
            ],
            'validFrom' => '2026-01-01T00:00:00Z',
            'credentialSubject' => [
                'id' => 'mailto:learner@example.com',
                'type' => ['AchievementSubject'],
                'achievement' => [
                    'id' => 'https://example.com/mod/certificatepro/view.php?id=42',
                    'type' => ['Achievement'],
                    'name' => 'Advanced Welding',
                    'description' => 'Indexing a[b[c]]> where 5 < 6 and "quoted" holds',
                    'criteria' => ['narrative' => 'Completed <Advanced Welding> at 80% or better'],
                ],
            ],
        ];
    }

    /**
     * Artwork with an XML declaration, as an exported drawing normally arrives.
     *
     * @return string SVG markup
     */
    protected function sample_svg(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">' . "\n"
            . '<title>Seal</title><circle cx="256" cy="256" r="200" fill="#0f6cbf"/>' . "\n"
            . '</svg>';
    }

    /**
     * Whether markup parses as XML, checked without disturbing libxml's state.
     *
     * @param string $xml the markup to parse
     * @return bool true when the document is well formed
     */
    protected function is_well_formed(string $xml): bool {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (bool) $loaded;
    }

    /**
     * A credential baked in comes back out unchanged.
     *
     * @return void
     */
    public function test_bake_and_extract_round_trip(): void {
        $this->resetAfterTest();

        $credential = $this->sample_credential();
        $svg = $this->sample_svg();

        $this->assertFalse(svg_baker::is_baked($svg));

        $baked = svg_baker::bake($svg, $credential);

        $this->assertTrue(svg_baker::is_baked($baked));
        $this->assertSame($credential, svg_baker::extract($baked));
        $this->assertTrue($this->is_well_formed($baked));

        // The namespace is declared on the root and the element follows it.
        $this->assertStringContainsString(
            'xmlns:openbadges="' . svg_baker::NAMESPACE_URI . '"',
            $baked
        );
        $this->assertMatchesRegularExpression('/<svg[^>]*>\s*<openbadges:credential>/', $baked);

        // The drawing itself is untouched.
        $this->assertStringContainsString(
            '<circle cx="256" cy="256" r="200" fill="#0f6cbf"/>',
            $baked
        );
        $this->assertSame(0, strpos($baked, '<?xml version="1.0" encoding="UTF-8"?>'));
    }

    /**
     * A credential given as JSON rather than as an array bakes just the same.
     *
     * @return void
     */
    public function test_bake_accepts_json(): void {
        $this->resetAfterTest();

        $credential = $this->sample_credential();
        $json = json_encode($credential, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $baked = svg_baker::bake($this->sample_svg(), $json);

        $this->assertSame($credential, svg_baker::extract($baked));
        $this->assertSame($json, svg_baker::extract_json($baked));
    }

    /**
     * Artwork with no XML declaration bakes without gaining one.
     *
     * @return void
     */
    public function test_bake_without_xml_declaration(): void {
        $this->resetAfterTest();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
            . '<rect width="100" height="100"/></svg>';

        $baked = svg_baker::bake($svg, $this->sample_credential());

        $this->assertSame(0, strpos($baked, '<svg'));
        $this->assertTrue($this->is_well_formed($baked));
        $this->assertSame($this->sample_credential(), svg_baker::extract($baked));
    }

    /**
     * A root element with no children is given a body to bake into.
     *
     * @return void
     */
    public function test_bake_into_self_closing_root(): void {
        $this->resetAfterTest();

        $baked = svg_baker::bake(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',
            $this->sample_credential()
        );

        $this->assertTrue($this->is_well_formed($baked));
        $this->assertSame($this->sample_credential(), svg_baker::extract($baked));
    }

    /**
     * A credential containing ]]> does not end its own CDATA section.
     *
     * @return void
     */
    public function test_cdata_terminator_is_escaped(): void {
        $this->resetAfterTest();

        $credential = $this->sample_credential();
        $awkward = $credential['credentialSubject']['achievement']['description'];

        $this->assertStringContainsString(']]>', $awkward);

        $baked = svg_baker::bake($this->sample_svg(), $credential);

        // The section is closed and reopened around the terminator.
        $this->assertStringContainsString(']]]]><![CDATA[>', $baked);
        $this->assertTrue($this->is_well_formed($baked));

        // A real XML parser sees one element holding the original text.
        $document = new \DOMDocument();
        $document->loadXML($baked);
        $nodes = $document->getElementsByTagNameNS(svg_baker::NAMESPACE_URI, 'credential');

        $this->assertSame(1, $nodes->length);
        $this->assertSame($credential, json_decode(trim($nodes->item(0)->textContent), true));

        $extracted = svg_baker::extract($baked);
        $this->assertSame($awkward, $extracted['credentialSubject']['achievement']['description']);
    }

    /**
     * Baking twice replaces the credential, because only one may be present.
     *
     * @return void
     */
    public function test_double_bake_replaces_rather_than_duplicates(): void {
        $this->resetAfterTest();

        $first = $this->sample_credential();
        $second = $first;
        $second['id'] = 'https://example.com/mod/certificatepro/verify.php?code=CP-ZZZZ-9999-0000';

        $once = svg_baker::bake($this->sample_svg(), $first);
        $twice = svg_baker::bake($once, $second);

        $this->assertSame(1, substr_count($twice, '<openbadges:credential'));
        $this->assertSame(1, substr_count($twice, '</openbadges:credential>'));
        $this->assertSame(1, substr_count($twice, 'xmlns:openbadges='));
        $this->assertSame($second['id'], svg_baker::extract($twice)['id']);
        $this->assertTrue($this->is_well_formed($twice));

        // A third pass is a no-op, so repeated issuing cannot grow the file.
        $this->assertSame($twice, svg_baker::bake($twice, $second));
    }

    /**
     * A document baked elsewhere, under another prefix, is replaced not doubled.
     *
     * @return void
     */
    public function test_rebaking_a_foreign_prefix(): void {
        $this->resetAfterTest();

        $prebaked = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:ob="' . svg_baker::NAMESPACE_URI
            . '"><ob:credential><![CDATA[{"id":"old"}]]></ob:credential>'
            . '<rect width="1" height="1"/></svg>';

        $this->assertTrue(svg_baker::is_baked($prebaked));
        $this->assertSame(['id' => 'old'], svg_baker::extract($prebaked));

        $rebaked = svg_baker::bake($prebaked, $this->sample_credential());

        $this->assertStringNotContainsString('<ob:credential', $rebaked);
        $this->assertSame(1, substr_count($rebaked, svg_baker::NAMESPACE_URI));
        $this->assertSame($this->sample_credential(), svg_baker::extract($rebaked));
        $this->assertTrue($this->is_well_formed($rebaked));
    }

    /**
     * A signed credential goes into the verify attribute with an empty body.
     *
     * @return void
     */
    public function test_bake_jwt(): void {
        $this->resetAfterTest();

        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.c2lnbmF0dXJl';

        $baked = svg_baker::bake_jwt($this->sample_svg(), $jwt);

        $this->assertStringContainsString('<openbadges:credential verify="' . $jwt . '">', $baked);
        $this->assertSame($jwt, svg_baker::extract_jwt($baked));
        $this->assertNull(svg_baker::extract($baked));
        $this->assertTrue($this->is_well_formed($baked));

        // Rebaking with an embedded proof clears the token.
        $replaced = svg_baker::bake($baked, $this->sample_credential());
        $this->assertStringNotContainsString($jwt, $replaced);
        $this->assertSame(1, substr_count($replaced, '<openbadges:credential'));
    }

    /**
     * Stripping leaves a document that reports itself as unbaked.
     *
     * @return void
     */
    public function test_strip(): void {
        $this->resetAfterTest();

        $baked = svg_baker::bake($this->sample_svg(), $this->sample_credential());
        $stripped = svg_baker::strip($baked);

        $this->assertFalse(svg_baker::is_baked($stripped));
        $this->assertNull(svg_baker::extract($stripped));
        $this->assertTrue($this->is_well_formed($stripped));
    }

    /**
     * Markup that is not SVG is refused rather than half baked.
     *
     * @return void
     */
    public function test_bake_rejects_markup_that_is_not_svg(): void {
        $this->resetAfterTest();

        $this->assertFalse(svg_baker::is_baked('not markup at all'));
        $this->assertNull(svg_baker::extract('not markup at all'));

        $this->expectException(\moodle_exception::class);
        svg_baker::bake('not markup at all', $this->sample_credential());
    }

    /**
     * A credential that is neither an array nor JSON is refused.
     *
     * @return void
     */
    public function test_bake_rejects_a_credential_that_is_not_json(): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        svg_baker::bake('<svg xmlns="http://www.w3.org/2000/svg"><g/></svg>', 'this is not json');
    }

    /**
     * A built credential carries every property Open Badges 3.0 requires.
     *
     * @return void
     */
    public function test_build_produces_a_conformant_credential(): void {
        global $CFG;

        $this->resetAfterTest();

        [$issue, $instance, $course, $user] = $this->fixture();

        $credential = open_badge::build($issue, $instance, $course, $user);

        $this->assertSame([], open_badge::validate($credential));

        // The envelope, per the data model and the badge specification.
        $this->assertSame(open_badge::CONTEXT_CREDENTIALS, $credential['@context'][0]);
        $this->assertContains(open_badge::CONTEXT_OPEN_BADGES, $credential['@context']);
        $this->assertSame(['VerifiableCredential', 'OpenBadgeCredential'], $credential['type']);

        // The credential names itself by its public verification page.
        $this->assertStringStartsWith(
            $CFG->wwwroot . '/mod/certificatepro/verify.php',
            $credential['id']
        );
        $this->assertStringContainsString('code=' . $issue->code, $credential['id']);

        // The issuer is this site.
        $this->assertSame($CFG->wwwroot, $credential['issuer']['id']);
        $this->assertSame($CFG->wwwroot, $credential['issuer']['url']);
        $this->assertSame(['Profile'], $credential['issuer']['type']);
        $this->assertNotEmpty($credential['issuer']['name']);

        // Times are UTC, in the form the data model expects.
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', (int) $issue->timecreated),
            $credential['validFrom']
        );
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', (int) $issue->expires),
            $credential['validUntil']
        );

        // The subject and the achievement.
        $subject = $credential['credentialSubject'];
        $this->assertSame(['AchievementSubject'], $subject['type']);
        $this->assertSame('mailto:' . $user->email, $subject['id']);
        $this->assertSame($user->email, $subject['identifier'][0]['identityHash']);
        $this->assertSame('emailAddress', $subject['identifier'][0]['identityType']);

        $achievement = $subject['achievement'];
        $this->assertSame(['Achievement'], $achievement['type']);
        $this->assertSame($instance->name, $achievement['name']);
        $this->assertStringContainsString('Weld anything', $achievement['description']);
        $this->assertNotEmpty($achievement['id']);
        $this->assertNotEmpty($achievement['criteria']['narrative']);
    }

    /**
     * A certificate that never expires carries no expiry.
     *
     * @return void
     */
    public function test_build_omits_expiry_when_there_is_none(): void {
        $this->resetAfterTest();

        [$issue, $instance, $course, $user] = $this->fixture();
        $issue->expires = 0;

        $credential = open_badge::build($issue, $instance, $course, $user);

        $this->assertArrayNotHasKey('validUntil', $credential);
        $this->assertSame([], open_badge::validate($credential));
    }

    /**
     * Validation names what is wrong rather than merely failing.
     *
     * @return void
     */
    public function test_validate_reports_problems(): void {
        $this->resetAfterTest();

        $this->assertNotEmpty(open_badge::validate([]));

        $credential = $this->sample_credential();
        unset($credential['credentialSubject']['achievement']['criteria']);
        $credential['type'] = ['VerifiableCredential'];
        $credential['@context'] = ['https://example.com/context.json'];

        $problems = open_badge::validate($credential);

        $this->assertContains('type must include OpenBadgeCredential', $problems);
        $this->assertContains('achievement is missing criteria', $problems);
        $this->assertContains(
            '@context must start with ' . open_badge::CONTEXT_CREDENTIALS,
            $problems
        );
    }

    /**
     * The two halves meet: a built credential bakes and comes back whole.
     *
     * @return void
     */
    public function test_built_credential_survives_baking(): void {
        $this->resetAfterTest();

        [$issue, $instance, $course, $user] = $this->fixture();

        $credential = open_badge::build($issue, $instance, $course, $user);
        $baked = svg_baker::bake($this->sample_svg(), $credential);
        $extracted = svg_baker::extract($baked);

        $this->assertSame($credential, $extracted);
        $this->assertSame([], open_badge::validate($extracted));
        $this->assertTrue($this->is_well_formed($baked));
    }

    /**
     * A course, a recipient, an activity instance and an issue to build from.
     *
     * The activity and the issue are plain records rather than a real course
     * module, because the credential is assembled from stored values and the
     * builder falls back to the course when a module is not there to point at.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     */
    protected function fixture(): array {
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['fullname' => 'Welding & Fabrication']);
        $user = $generator->create_user(['email' => 'learner@example.com']);

        $instance = (object) [
            'id' => 12345,
            'course' => $course->id,
            'name' => 'Advanced Welding',
            'intro' => '<p>Weld anything, <em>safely</em>.</p>',
            'introformat' => FORMAT_HTML,
        ];

        $issue = (object) [
            'id' => 999,
            'certificateproid' => $instance->id,
            'userid' => $user->id,
            'code' => 'CP-4K7M-9XQ2-P3TR',
            'timecreated' => 1767225600,
            'expires' => 1798761600,
        ];

        return [$issue, $instance, $course, $user];
    }
}
