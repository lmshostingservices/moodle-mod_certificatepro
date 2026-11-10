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

use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\art\svg_painter;

/**
 * Tests for the seal engine.
 *
 * The engine's contract is combinatorial: any edge, with any ring layout, with
 * any centre mark, has to produce a seal. A single combination that throws or
 * that draws nothing costs a learner their certificate's seal, and there are
 * enough combinations that nobody is going to click through them all. So every
 * one of them is drawn here.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(seal_engine::class)]
final class seal_engine_test extends \advanced_testcase {

    /**
     * A canvas with a predictable measurer.
     *
     * @param float $size the square size in millimetres
     * @return canvas
     */
    protected function canvas(float $size = 34.0): canvas {
        $c = new canvas($size, $size, [
            'primary' => '#0f6cbf', 'primarydark' => '#0a4a83', 'accent' => '#b08432',
            'ink' => '#1d2125', 'muted' => '#6b7377', 'paper' => '#ffffff',
        ], 11);

        $c->set_measurer(function (string $text, string $family, string $style, float $sizept): float {
            return \core_text::strlen($text) * $sizept * canvas::PT_TO_MM * 0.5;
        });

        return $c;
    }

    /**
     * A fully populated specification, so no branch is skipped for want of text.
     *
     * @param array $overrides values to replace
     * @return array the specification
     */
    protected function spec(array $overrides = []): array {
        return $overrides + [
            'top' => 'INSTITUTE OF LEARNING',
            'bottom' => 'EST 1998',
            'centre' => 'IL',
            'year' => '1998',
            'motto' => 'Per aspera ad astra',
            'ink' => '#0f6cbf',
            'metal' => '#b08432',
            'paper' => '#ffffff',
            'family' => 'helvetica',
        ];
    }

    /**
     * Every edge, ring layout and centre mark the engine offers.
     *
     * @return \Generator<string, array{0: string, 1: string, 2: string}>
     */
    public static function combination_provider(): \Generator {
        foreach (seal_engine::edges() as $edge) {
            foreach (seal_engine::ring_layouts() as $ring) {
                foreach (seal_engine::marks() as $mark) {
                    yield "$edge / $ring / $mark" => [$edge, $ring, $mark];
                }
            }
        }
    }

    /**
     * Every edge, ring and mark combination draws a seal and paints valid SVG.
     *
     * @param string $edge the edge treatment
     * @param string $ring the ring layout
     * @param string $mark the centre mark
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('combination_provider')]
    public function test_every_combination_draws_something(string $edge, string $ring,
            string $mark): void {
        $this->resetAfterTest();

        $c = $this->canvas();

        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'edge' => $edge,
            'ring' => $ring,
            'mark' => $mark,
        ]));

        $ops = $c->ops();
        $this->assertNotEmpty($ops, "$edge / $ring / $mark recorded nothing");

        // Something must actually mark the page, not merely set a colour.
        $drawing = array_filter($ops, function ($op) {
            return !in_array($op[0], ['save', 'restore', 'fill', 'stroke', 'alpha'], true);
        });
        $this->assertNotEmpty($drawing, "$edge / $ring / $mark set state but drew nothing");

        // And the whole recording has to survive being painted.
        $svg = (new svg_painter('t'))->render($ops, 34.0, 34.0);
        $this->assertStringContainsString('<svg', $svg);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg), "$edge / $ring / $mark painted invalid XML");
    }

    /**
     * Clearing every string still leaves a seal rather than a crash.
     *
     * @param string $edge the edge treatment
     * @param string $ring the ring layout
     * @param string $mark the centre mark
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('combination_provider')]
    public function test_every_combination_survives_empty_text(string $edge, string $ring,
            string $mark): void {
        $this->resetAfterTest();

        $c = $this->canvas();

        // An author who clears every string must still get a seal, not a crash
        // and not a blank square.
        seal_engine::draw($c, 0.5, 0.5, 17.0, [
            'edge' => $edge,
            'ring' => $ring,
            'mark' => $mark,
            'top' => '',
            'bottom' => '',
            'centre' => '',
            'year' => '',
            'motto' => '',
            'ink' => '#0f6cbf',
            'metal' => '#b08432',
            'family' => 'helvetica',
        ]);

        $this->assertNotEmpty($c->ops(), "$edge / $ring / $mark recorded nothing with no text");
    }

    /**
     * The default specification is complete.
     *
     * @return void
     */
    public function test_defaults_fill_in_every_key_the_engine_reads(): void {
        $this->resetAfterTest();

        $defaults = seal_engine::defaults();

        foreach (['edge', 'ring', 'mark', 'top', 'bottom', 'centre', 'year', 'motto',
                'ink', 'paper', 'metal', 'family', 'divider', 'innerkeyline', 'weight'] as $key) {
            $this->assertArrayHasKey($key, $defaults);
        }

        $this->assertSame(seal_engine::EDGE_DOUBLE, $defaults['edge']);
        $this->assertSame(seal_engine::RING_BOTH, $defaults['ring']);
        $this->assertSame(seal_engine::MARK_MONOGRAM, $defaults['mark']);
    }

    /**
     * Defaults fill gaps rather than replacing choices.
     *
     * @return void
     */
    public function test_defaults_do_not_overwrite_what_the_caller_supplied(): void {
        $this->resetAfterTest();

        $defaults = seal_engine::defaults(['edge' => seal_engine::EDGE_ROPE, 'top' => 'X']);

        $this->assertSame(seal_engine::EDGE_ROPE, $defaults['edge']);
        $this->assertSame('X', $defaults['top']);
        $this->assertSame(seal_engine::RING_BOTH, $defaults['ring']);
    }

    /**
     * An unrecognised option falls back instead of throwing.
     *
     * @return void
     */
    public function test_an_unknown_edge_or_mark_falls_back_rather_than_failing(): void {
        $this->resetAfterTest();

        $c = $this->canvas();

        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'edge' => 'no-such-edge',
            'ring' => 'no-such-ring',
            'mark' => 'no-such-mark',
        ]));

        $this->assertNotEmpty($c->ops());
    }

    /**
     * Below the printable floor the engine declines to draw.
     *
     * @return void
     */
    public function test_a_seal_too_small_to_print_draws_nothing(): void {
        $this->resetAfterTest();

        $c = $this->canvas(1.0);
        seal_engine::draw($c, 0.5, 0.5, 0.4, $this->spec());

        $this->assertSame([], $c->ops());
    }

    /**
     * The none layout letters nothing.
     *
     * @return void
     */
    public function test_ring_none_sets_no_lettering(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_NONE,
            'mark' => seal_engine::MARK_NONE,
        ]));

        foreach ($c->ops() as $op) {
            $this->assertNotSame('glyphs', $op[0]);
            $this->assertNotSame('text', $op[0]);
        }
    }

    /**
     * The both layout sets a top and a bottom arc with dividers between them.
     *
     * @return void
     */
    public function test_ring_both_sets_two_arcs_and_a_pair_of_dividers(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_BOTH,
            'mark' => seal_engine::MARK_NONE,
            'divider' => true,
        ]));

        $runs = array_values(array_filter($c->ops(), fn($op) => $op[0] === 'glyphs'));
        $this->assertCount(2, $runs);

        // The top arc sits above the centre and the bottom arc below it.
        $this->assertLessThan(17.0, $runs[0][1][0][2]);
        $this->assertGreaterThan(17.0, $runs[1][1][0][2]);

        // Two diamonds mark where the arcs meet.
        $polys = array_values(array_filter($c->ops(), fn($op) => $op[0] === 'poly'));
        $this->assertCount(2, $polys);
    }

    /**
     * A full ring reaches every quadrant instead of leaving half the band bare.
     *
     * @return void
     */
    public function test_ring_full_closes_the_band(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_FULL,
            'mark' => seal_engine::MARK_NONE,
            'top' => 'CERTIFIED',
        ]));

        $runs = array_values(array_filter($c->ops(), fn($op) => $op[0] === 'glyphs'));
        $this->assertCount(1, $runs);

        $glyphs = $runs[0][1];

        // A full ring has to reach all four quadrants. A phrase repeated a
        // fixed number of times leaves a bare half on a wide ring, which is the
        // one thing this layout exists to avoid.
        $quadrants = [];
        foreach ($glyphs as $glyph) {
            $dx = $glyph[1] - 17.0;
            $dy = $glyph[2] - 17.0;
            $quadrants[($dx >= 0 ? 'r' : 'l') . ($dy >= 0 ? 'b' : 't')] = true;
        }

        $this->assertCount(4, $quadrants,
            'a full ring left part of the band empty: ' . implode(',', array_keys($quadrants)));
    }

    /**
     * The top layout sets one arc and needs no junction marks.
     *
     * @return void
     */
    public function test_ring_top_sets_one_arc_and_no_dividers(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_TOP,
            'mark' => seal_engine::MARK_NONE,
        ]));

        $runs = array_values(array_filter($c->ops(), fn($op) => $op[0] === 'glyphs'));
        $this->assertCount(1, $runs);
        $this->assertSame([], array_values(array_filter($c->ops(), fn($op) => $op[0] === 'poly')));
    }

    /**
     * A long name shrinks to fit its arc.
     *
     * @return void
     */
    public function test_a_long_institution_name_is_set_smaller_not_wrapped_round(): void {
        $this->resetAfterTest();

        $short = $this->canvas();
        seal_engine::draw($short, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_TOP,
            'mark' => seal_engine::MARK_NONE,
            'top' => 'ACME',
        ]));

        $long = $this->canvas();
        seal_engine::draw($long, 0.5, 0.5, 17.0, $this->spec([
            'ring' => seal_engine::RING_TOP,
            'mark' => seal_engine::MARK_NONE,
            'top' => 'THE ROYAL AND ANCIENT INSTITUTE OF ADVANCED LEARNING AND RESEARCH',
        ]));

        $shortrun = array_values(array_filter($short->ops(), fn($op) => $op[0] === 'glyphs'))[0];
        $longrun = array_values(array_filter($long->ops(), fn($op) => $op[0] === 'glyphs'))[0];

        $this->assertLessThan($shortrun[4], $longrun[4], 'a long name was not set smaller');
        $this->assertCount(65, $longrun[1]);
    }

    /**
     * The same specification always draws the same seal.
     *
     * @return void
     */
    public function test_the_same_specification_always_draws_the_same_seal(): void {
        $this->resetAfterTest();

        $first = $this->canvas();
        $second = $this->canvas();

        $spec = $this->spec(['edge' => seal_engine::EDGE_ROPE, 'mark' => seal_engine::MARK_CREST]);

        seal_engine::draw($first, 0.5, 0.5, 17.0, $spec);
        seal_engine::draw($second, 0.5, 0.5, 17.0, $spec);

        $this->assertEquals($first->ops(), $second->ops());
    }

    /**
     * No edge treatment pushes lettering outside the seal box.
     *
     * @return void
     */
    public function test_a_seal_stays_inside_its_box(): void {
        $this->resetAfterTest();

        foreach (seal_engine::edges() as $edge) {
            $c = $this->canvas(34.0);
            seal_engine::draw($c, 0.5, 0.5, 17.0, $this->spec(['edge' => $edge]));

            foreach ($c->ops() as $op) {
                if ($op[0] !== 'glyphs') {
                    continue;
                }
                foreach ($op[1] as $glyph) {
                    $this->assertGreaterThanOrEqual(-1.0, $glyph[1], "$edge glyph left the box");
                    $this->assertLessThanOrEqual(35.0, $glyph[1], "$edge glyph left the box");
                    $this->assertGreaterThanOrEqual(-1.0, $glyph[2], "$edge glyph left the box");
                    $this->assertLessThanOrEqual(35.0, $glyph[2], "$edge glyph left the box");
                }
            }
        }
    }

    /**
     * Every option the engine offers has a translated name.
     *
     * @return void
     */
    public function test_every_edge_ring_and_mark_is_named_in_the_language_pack(): void {
        $this->resetAfterTest();

        foreach (seal_engine::edges() as $edge) {
            $this->assertNotEmpty(get_string('sealedge_' . $edge, 'mod_certificatepro'));
        }
        foreach (seal_engine::ring_layouts() as $ring) {
            $this->assertNotEmpty(get_string('sealring_' . $ring, 'mod_certificatepro'));
        }
        foreach (seal_engine::marks() as $mark) {
            $this->assertNotEmpty(get_string('sealmark_' . $mark, 'mod_certificatepro'));
        }
    }
}
