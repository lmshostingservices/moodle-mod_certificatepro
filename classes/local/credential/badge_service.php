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

namespace mod_certificatepro\local\credential;

use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\art\svg_painter;
use mod_certificatepro\local\brand;

/**
 * Produces the verifiable badge image for an issued certificate.
 *
 * WHY A BADGE AS WELL AS A PDF
 *
 * Open Badges 3.0 reached Final Release in 2026, and its baking section is
 * unambiguous: the image "MUST be in either PNG or SVG format in order to
 * support baking". PDF is not mentioned anywhere in the specification, and the
 * W3C's rendering work removed its PDF render suite entirely in March 2026.
 *
 * So the PDF cannot carry a verifiable credential — not because of any
 * limitation in this plugin, but because no standard defines how it would. An
 * SVG can. Emitting one alongside the PDF costs almost nothing, because the
 * designer canvas already renders through {@see svg_painter}, and it makes a
 * certificate issued here a valid Open Badges 3.0 carrier. Moodle core supports
 * Open Badges 2.1 at best and bakes into PNG only, so this is a gap core itself
 * does not fill.
 *
 * WHY THE SEAL IS THE BADGE
 *
 * A badge is displayed square and small — on a profile, in a backpack, on a
 * professional network. A landscape certificate reduced to that size is
 * illegible. The seal is already the institution's mark, already square, and
 * already designed to survive reproduction at 19mm, so it is the right artefact
 * to carry the credential.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badge_service {
    /** @var float The badge canvas size in millimetres. */
    const SIZE = 34.0;

    /** @var string The snapshot key the issued design's seal settings are frozen under. */
    const SNAPSHOT_KEY = 'sealdata';

    /**
     * The baked badge for an issue.
     *
     * @param \stdClass $issue the issue record
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \stdClass $user the recipient
     * @return string SVG markup with the credential embedded
     */
    public static function badge(\stdClass $issue, \stdClass $instance, \stdClass $course,
            \stdClass $user): string {
        $svg = self::artwork($issue, $course, $instance);
        $credential = open_badge::build($issue, $instance, $course, $user);

        return svg_baker::bake($svg, $credential);
    }

    /**
     * The badge artwork, before the credential is baked in.
     *
     * Uses the seal the design actually carries where there is one, so the
     * badge and the certificate are visibly the same mark. Falls back to a seal
     * built from the site's name and colours, because a badge with no artwork
     * is worse than a badge with generic artwork.
     *
     * @param \stdClass $issue the issue record
     * @param \stdClass $course the course
     * @param \stdClass|null $instance the activity instance, which names the design
     * @return string SVG markup
     */
    public static function artwork(\stdClass $issue, \stdClass $course,
            ?\stdClass $instance = null): string {
        $spec = self::spec_from_snapshot($issue)
            ?? self::spec_from_design($instance)
            ?? self::spec_from_site($course);

        $palette = brand::palette(self::site_colour());
        $c = new canvas(self::SIZE, self::SIZE, $palette, self::seed($issue));
        seal_engine::draw($c, 0.5, 0.5, self::SIZE / 2.0, $spec);

        return (new svg_painter('badge'))->render($c->ops(), self::SIZE, self::SIZE, false);
    }

    /**
     * Recover the seal specification from the issue's frozen snapshot.
     *
     * The snapshot is what makes a reissued badge match the certificate the
     * learner was originally given, even after the design has been edited.
     *
     * @param \stdClass $issue the issue record
     * @return array|null the specification, or null when the design had no seal
     */
    protected static function spec_from_snapshot(\stdClass $issue): ?array {
        if (empty($issue->snapshot)) {
            return null;
        }

        $snapshot = json_decode((string) $issue->snapshot, true);
        if (!is_array($snapshot)) {
            return null;
        }

        // The snapshot is a flat map of tokens and resolved element values —
        // it has never carried an 'elements' list, so reading one always failed
        // and every badge silently fell back to the generic site seal.
        // {@see issue_service} freezes the seal's own settings under this key.
        $data = $snapshot[self::SNAPSHOT_KEY] ?? null;

        return is_array($data) ? self::spec_from_data($data) : null;
    }

    /**
     * The seal specification from the design the activity currently carries.
     *
     * Used for issues minted before the seal was frozen into the snapshot. It
     * follows the live design, so an edited design does change these badges —
     * which is still closer to the certificate the learner holds than a seal
     * built from nothing but the site name.
     *
     * @param \stdClass|null $instance the activity instance
     * @return array|null the specification, or null when the design has no seal
     */
    protected static function spec_from_design(?\stdClass $instance): ?array {
        global $DB;

        if ($instance === null || empty($instance->templateid)) {
            return null;
        }

        $record = $DB->get_record_sql(
            'SELECT e.data
               FROM {certificatepro_elements} e
               JOIN {certificatepro_pages} p ON p.id = e.pageid
              WHERE p.templateid = :templateid AND e.element = :element
           ORDER BY p.sequence, e.zindex, e.id',
            ['templateid' => (int) $instance->templateid, 'element' => 'seal'],
            IGNORE_MULTIPLE
        );

        if (!$record) {
            return null;
        }

        $data = json_decode((string) $record->data, true);

        return is_array($data) ? self::spec_from_data($data) : null;
    }

    /**
     * Turn a seal element's stored settings into an engine specification.
     *
     * @param array $data the seal element's data
     * @return array the specification
     */
    protected static function spec_from_data(array $data): array {
        return seal_engine::defaults(
            [
                'edge' => (string) ($data['sealedge'] ?? seal_engine::EDGE_DOUBLE),
                'ring' => (string) ($data['sealring'] ?? seal_engine::RING_BOTH),
                'mark' => (string) ($data['sealmark'] ?? seal_engine::MARK_MONOGRAM),
                'top' => (string) ($data['toptext'] ?? ''),
                'bottom' => (string) ($data['bottomtext'] ?? ''),
                'centre' => (string) ($data['centretext'] ?? ''),
                'year' => (string) ($data['yeartext'] ?? ''),
                'motto' => (string) ($data['mottotext'] ?? ''),
                'ink' => self::hex($data['colour'] ?? '', self::site_colour()),
                'metal' => self::hex($data['metal'] ?? '', '#b08432'),
            ]
        );
    }

    /**
     * The first seal element's stored settings on a design.
     *
     * Called when an issue is minted so the badge is frozen alongside the PDF,
     * rather than following a design the learner's certificate no longer shows.
     *
     * @param int $templateid the design
     * @return array|null the decoded data, or null when there is no seal
     */
    public static function seal_data_for_template(int $templateid): ?array {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT e.data
               FROM {certificatepro_elements} e
               JOIN {certificatepro_pages} p ON p.id = e.pageid
              WHERE p.templateid = :templateid AND e.element = :element
           ORDER BY p.sequence, e.zindex, e.id',
            ['templateid' => $templateid, 'element' => 'seal'],
            IGNORE_MULTIPLE
        );

        if (!$record) {
            return null;
        }

        $data = json_decode((string) $record->data, true);

        return is_array($data) ? $data : null;
    }

    /**
     * A seal built from the site, for designs that carry none.
     *
     * @param \stdClass $course the course
     * @return array the specification
     */
    protected static function spec_from_site(\stdClass $course): array {
        global $SITE;

        $name = format_string($SITE->fullname ?? '', true, ['context' => \context_system::instance()]);

        return seal_engine::defaults(
            [
                'edge' => seal_engine::EDGE_DOUBLE,
                'ring' => seal_engine::RING_BOTH,
                'mark' => seal_engine::MARK_MONOGRAM,
                'top' => \core_text::strtoupper($name),
                'bottom' => \core_text::strtoupper(format_string($course->shortname ?? '', true)),
                'centre' => seal_engine::initials($name),
                'ink' => self::site_colour(),
            ]
        );
    }


    /**
     * Resolve a stored colour value to a hex string.
     *
     * @param mixed $value a palette role, a hex string, or nothing
     * @param string $fallback the colour to use when it resolves to neither
     * @return string a #rrggbb string
     */
    protected static function hex($value, string $fallback): string {
        $value = (string) $value;
        if ($value === '') {
            return $fallback;
        }
        if (brand::is_hex($value)) {
            return brand::normalise_hex($value);
        }

        $palette = brand::palette(self::site_colour());
        return $palette[$value] ?? $fallback;
    }

    /**
     * The site's brand colour.
     *
     * @return string a #rrggbb string
     */
    protected static function site_colour(): string {
        $configured = (string) get_config('mod_certificatepro', 'brandcolour');
        if ($configured !== '' && brand::is_hex($configured)) {
            return brand::normalise_hex($configured);
        }

        return '#0f6cbf';
    }

    /**
     * A stable seed, so a badge redrawn next year is the badge issued today.
     *
     * @param \stdClass $issue the issue record
     * @return int the seed
     */
    protected static function seed(\stdClass $issue): int {
        return (int) abs(crc32((string) ($issue->code ?? 'certificatepro'))) ?: 1;
    }

    /**
     * The filename a downloaded badge should carry.
     *
     * @param \stdClass $issue the issue record
     * @return string the filename
     */
    public static function filename(\stdClass $issue): string {
        return clean_filename('badge-' . ($issue->code ?? 'certificate') . '.svg');
    }
}
