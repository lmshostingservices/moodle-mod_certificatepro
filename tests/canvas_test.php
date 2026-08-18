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

/**
 * Tests for the recording canvas, and in particular for text on an arc.
 *
 * Arc lettering is the one piece of layout that both painters replay rather
 * than compute, so if the canvas gets a glyph's position or rotation wrong,
 * every seal on every certificate is wrong in the same way. These tests pin the
 * properties that make a ring legible: the right number of glyphs, a bottom arc
 * that is not a mirrored top arc, and a long name that shrinks instead of
 * running round the circle and colliding with itself.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(canvas::class)]
final class canvas_test extends \advanced_testcase {

    /**
     * A canvas with a predictable measurer, so widths do not depend on a font.
     *
     * Every character is half an em wide, which is close enough to a real face
     * for layout assertions and exactly reproducible.
     *
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @return canvas
     */
    protected function canvas(float $width = 100.0, float $height = 100.0): canvas {
        $c = new canvas($width, $height, [
            'primary' => '#0f6cbf', 'primarydark' => '#0a4a83', 'accent' => '#b08432',
            'ink' => '#1d2125', 'muted' => '#6b7377', 'paper' => '#ffffff',
        ], 7);

        $c->set_measurer(function (string $text, string $family, string $style, float $sizept): float {
            return \core_text::strlen($text) * $sizept * canvas::PT_TO_MM * 0.5;
        });

        return $c;
    }

    /**
     * The operations of a given kind that a canvas recorded.
     *
     * @param canvas $c the canvas to read
     * @param string $kind the operation name
     * @return array[] the matching operations
     */
    protected function ops_of(canvas $c, string $kind): array {
        return array_values(array_filter($c->ops(), function ($op) use ($kind) {
            return ($op[0] ?? '') === $kind;
        }));
    }

    // ------------------------------------------------------------------
    // Straight text.
    // ------------------------------------------------------------------

    /**
     * An untracked run is recorded once, not glyph by glyph.
     *
     * @return void
     */
    public function test_text_records_a_single_run_when_untracked(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $width = $c->text_mm(10.0, 20.0, 'Hello', ['sizept' => 12.0, 'anchor' => 'L']);

        $runs = $this->ops_of($c, 'text');
        $this->assertCount(1, $runs);
        $this->assertCount(0, $this->ops_of($c, 'glyphs'));

        // [op, left, baseline, text, family, style, sizept].
        $this->assertEqualsWithDelta(10.0, $runs[0][1], 0.0001);
        $this->assertEqualsWithDelta(20.0, $runs[0][2], 0.0001);
        $this->assertSame('Hello', $runs[0][3]);
        $this->assertEqualsWithDelta(5 * 12.0 * canvas::PT_TO_MM * 0.5, $width, 0.0001);
    }

    /**
     * The anchor is resolved in the canvas so neither painter has to measure.
     *
     * @return void
     */
    public function test_text_anchor_resolves_to_a_left_edge(): void {
        $this->resetAfterTest();

        $left = $this->canvas();
        $left->text_mm(50.0, 20.0, 'Hello', ['sizept' => 12.0, 'anchor' => 'L']);

        $centre = $this->canvas();
        $centre->text_mm(50.0, 20.0, 'Hello', ['sizept' => 12.0, 'anchor' => 'C']);

        $right = $this->canvas();
        $right->text_mm(50.0, 20.0, 'Hello', ['sizept' => 12.0, 'anchor' => 'R']);

        $total = 5 * 12.0 * canvas::PT_TO_MM * 0.5;

        $this->assertEqualsWithDelta(50.0, $this->ops_of($left, 'text')[0][1], 0.0001);
        $this->assertEqualsWithDelta(50.0 - $total / 2, $this->ops_of($centre, 'text')[0][1], 0.0001);
        $this->assertEqualsWithDelta(50.0 - $total, $this->ops_of($right, 'text')[0][1], 0.0001);
    }

    /**
     * A tracked run becomes explicit glyphs, so letterspacing cannot diverge.
     *
     * @return void
     */
    public function test_tracked_text_becomes_one_glyph_per_character(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->text_mm(10.0, 20.0, 'ABCD', ['sizept' => 10.0, 'anchor' => 'L', 'tracking' => 0.2]);

        $runs = $this->ops_of($c, 'glyphs');
        $this->assertCount(1, $runs);
        $this->assertCount(0, $this->ops_of($c, 'text'));

        $glyphs = $runs[0][1];
        $this->assertCount(4, $glyphs);

        // Every glyph shares the baseline and none of them is rotated.
        foreach ($glyphs as $glyph) {
            $this->assertEqualsWithDelta(20.0, $glyph[2], 0.0001);
            $this->assertEqualsWithDelta(0.0, $glyph[3], 0.0001);
        }

        // The pen advances by the character width plus the tracking.
        $step = 10.0 * canvas::PT_TO_MM * 0.5 + 0.2 * 10.0 * canvas::PT_TO_MM;
        $this->assertEqualsWithDelta($step, $glyphs[1][1] - $glyphs[0][1], 0.0001);
    }

    /**
     * An empty string draws nothing at all.
     *
     * @return void
     */
    public function test_text_records_nothing_for_an_empty_string(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $this->assertEqualsWithDelta(0.0, $c->text_mm(1.0, 1.0, '', []), 0.0001);
        $this->assertSame([], $c->ops());
    }

    /**
     * An accented name is split by character, not by byte.
     *
     * @return void
     */
    public function test_text_counts_multibyte_characters_once_each(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->text_mm(0.0, 0.0, 'ÉLAN', ['sizept' => 10.0, 'tracking' => 0.2]);

        $this->assertCount(4, $this->ops_of($c, 'glyphs')[0][1]);
    }

    // ------------------------------------------------------------------
    // Text on an arc.
    // ------------------------------------------------------------------

    /**
     * Every character of a ring run is laid out.
     *
     * @return void
     */
    public function test_arc_text_records_one_glyph_per_character(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $result = $c->arc_text(0.5, 0.5, 20.0, 'CERTIFIED', ['sizept' => 6.0]);

        $runs = $this->ops_of($c, 'glyphs');
        $this->assertCount(1, $runs);
        $this->assertCount(9, $runs[0][1]);
        $this->assertGreaterThan(0.0, $result['sweep']);
    }

    /**
     * An empty ring run draws nothing and sweeps nothing.
     *
     * @return void
     */
    public function test_arc_text_records_nothing_for_an_empty_string(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $result = $c->arc_text(0.5, 0.5, 20.0, '', ['sizept' => 6.0]);

        $this->assertSame([], $c->ops());
        $this->assertEqualsWithDelta(0.0, $result['sweep'], 0.0001);
    }

    /**
     * Every glyph sits on the baseline circle it was given.
     *
     * @return void
     */
    public function test_arc_text_places_glyphs_on_the_radius(): void {
        $this->resetAfterTest();

        $radius = 20.0;
        $c = $this->canvas();
        $c->arc_text(0.5, 0.5, $radius, 'ABCDE', ['sizept' => 6.0]);

        // Each recorded coordinate is the glyph's left edge, half an advance
        // back along the tangent, so it sits a little inside the radius. It
        // must never be far off it.
        foreach ($this->ops_of($c, 'glyphs')[0][1] as $glyph) {
            $distance = sqrt(pow($glyph[1] - 50.0, 2) + pow($glyph[2] - 50.0, 2));
            $this->assertEqualsWithDelta($radius, $distance, 1.5,
                'glyph ' . $glyph[0] . ' left the baseline circle');
        }
    }

    /**
     * A top arc runs over the centre, rightwards.
     *
     * @return void
     */
    public function test_top_arc_reads_left_to_right_above_the_centre(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->arc_text(0.5, 0.5, 20.0, 'ABCDE', ['sizept' => 6.0, 'align' => 'top']);
        $glyphs = $this->ops_of($c, 'glyphs')[0][1];

        // Above the centre, and running rightwards.
        foreach ($glyphs as $glyph) {
            $this->assertLessThan(50.0, $glyph[2], 'a top arc glyph fell below the centre');
        }
        $this->assertLessThan($glyphs[4][1], $glyphs[0][1]);
    }

    /**
     * A bottom arc runs under the centre, still rightwards.
     *
     * @return void
     */
    public function test_bottom_arc_reads_left_to_right_below_the_centre(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->arc_text(0.5, 0.5, 20.0, 'ABCDE', ['sizept' => 6.0, 'align' => 'bottom']);
        $glyphs = $this->ops_of($c, 'glyphs')[0][1];

        foreach ($glyphs as $glyph) {
            $this->assertGreaterThan(50.0, $glyph[2], 'a bottom arc glyph rose above the centre');
        }
        // Still left to right, which is what stops the run coming out mirrored.
        $this->assertLessThan($glyphs[4][1], $glyphs[0][1]);
    }

    /**
     * A bottom arc mirrors a top arc rather than copying it.
     *
     * @return void
     */
    public function test_bottom_arc_rotations_differ_from_a_top_arc(): void {
        $this->resetAfterTest();

        $top = $this->canvas();
        $top->arc_text(0.5, 0.5, 20.0, 'ABCDE', ['sizept' => 6.0, 'align' => 'top']);

        $bottom = $this->canvas();
        $bottom->arc_text(0.5, 0.5, 20.0, 'ABCDE', ['sizept' => 6.0, 'align' => 'bottom']);

        $topglyphs = $this->ops_of($top, 'glyphs')[0][1];
        $bottomglyphs = $this->ops_of($bottom, 'glyphs')[0][1];

        $this->assertCount(count($topglyphs), $bottomglyphs);

        // A bottom arc is the mirror of a top arc, not a copy of it: every
        // glyph leans the opposite way by the same amount. That is exactly what
        // keeps the run upright and reading left to right underneath the
        // centre instead of upside down.
        foreach ($topglyphs as $i => $glyph) {
            $this->assertEqualsWithDelta(-$this->normalise($glyph[3]),
                $this->normalise($bottomglyphs[$i][3]), 0.001,
                'glyph ' . $i . ' does not mirror on the bottom arc');
        }

        // And the two runs really are different: the outer glyphs lean, so a
        // mirrored rotation is not the same rotation.
        $this->assertNotEqualsWithDelta($this->normalise($topglyphs[0][3]),
            $this->normalise($bottomglyphs[0][3]), 1.0);
        $this->assertNotEqualsWithDelta($this->normalise($topglyphs[4][3]),
            $this->normalise($bottomglyphs[4][3]), 1.0);
    }

    /**
     * Fold an angle into the half open range (-180, 180].
     *
     * @param float $degrees the angle
     * @return float the equivalent angle
     */
    protected function normalise(float $degrees): float {
        $degrees = fmod($degrees, 360.0);
        if ($degrees > 180.0) {
            $degrees -= 360.0;
        }
        if ($degrees <= -180.0) {
            $degrees += 360.0;
        }
        return $degrees;
    }

    /**
     * A run is centred on its bearing rather than starting from it.
     *
     * @return void
     */
    public function test_arc_text_is_centred_on_the_requested_bearing(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->arc_text(0.5, 0.5, 20.0, 'AAAAA', ['sizept' => 6.0, 'align' => 'top']);
        $glyphs = $this->ops_of($c, 'glyphs')[0][1];

        // Five identical glyphs centred on twelve o'clock: the outer pair sit
        // symmetrically either side of the centre line.
        $lefts = array_column($glyphs, 1);
        $this->assertEqualsWithDelta(50.0, (min($lefts) + max($lefts)) / 2.0, 1.5);
    }

    /**
     * A long name is set smaller instead of running round the circle.
     *
     * @return void
     */
    public function test_autofit_shrinks_a_long_string_to_the_permitted_sweep(): void {
        $this->resetAfterTest();

        $short = $this->canvas();
        $shortresult = $short->arc_text(0.5, 0.5, 40.0, 'ABC',
            ['sizept' => 8.0, 'maxsweep' => 160.0]);

        $long = $this->canvas();
        $longtext = 'THE VERY LONG NAME OF A LARGE AND ANCIENT INSTITUTION OF LEARNING';
        $longresult = $long->arc_text(0.5, 0.5, 40.0, $longtext,
            ['sizept' => 8.0, 'maxsweep' => 160.0]);

        // A short run is left at the size it was asked for.
        $this->assertEqualsWithDelta(8.0, $shortresult['sizept'], 0.0001);
        $this->assertLessThanOrEqual(160.0, $shortresult['sweep']);

        // A long one is set smaller, and fits.
        $this->assertLessThan(8.0, $longresult['sizept']);
        $this->assertLessThanOrEqual(160.0, $longresult['sweep']);
        $this->assertSame(\core_text::strlen($longtext),
            count($this->ops_of($long, 'glyphs')[0][1]));
    }

    /**
     * Autofit stops at its floor rather than shrinking towards nothing.
     *
     * @return void
     */
    public function test_autofit_respects_its_floor(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $result = $c->arc_text(0.5, 0.5, 4.0, str_repeat('W', 400), [
            'sizept' => 9.0,
            'maxsweep' => 100.0,
            'minsizept' => 4.0,
        ]);

        // The run cannot possibly fit, so the size stops at the floor rather
        // than shrinking towards zero.
        $this->assertEqualsWithDelta(4.0, $result['sizept'], 0.0001);
    }

    /**
     * With autofit off the caller gets the size it asked for.
     *
     * @return void
     */
    public function test_autofit_can_be_turned_off(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $result = $c->arc_text(0.5, 0.5, 20.0, str_repeat('M', 60), [
            'sizept' => 9.0,
            'maxsweep' => 90.0,
            'autofit' => false,
        ]);

        $this->assertEqualsWithDelta(9.0, $result['sizept'], 0.0001);
        $this->assertGreaterThan(90.0, $result['sweep']);
    }

    /**
     * The same run occupies less arc on a wider ring.
     *
     * @return void
     */
    public function test_arc_text_sweep_shrinks_as_the_radius_grows(): void {
        $this->resetAfterTest();

        $tight = $this->canvas();
        $tightresult = $tight->arc_text(0.5, 0.5, 10.0, 'CERTIFIED',
            ['sizept' => 5.0, 'autofit' => false]);

        $wide = $this->canvas();
        $wideresult = $wide->arc_text(0.5, 0.5, 30.0, 'CERTIFIED',
            ['sizept' => 5.0, 'autofit' => false]);

        $this->assertGreaterThan($wideresult['sweep'], $tightresult['sweep']);
    }

    // ------------------------------------------------------------------
    // Determinism and geometry helpers.
    // ------------------------------------------------------------------

    /**
     * A seeded canvas is deterministic, so a certificate never redraws differently.
     *
     * @return void
     */
    public function test_the_same_seed_draws_the_same_artwork(): void {
        $this->resetAfterTest();

        $palette = ['primary' => '#0f6cbf', 'ink' => '#000000', 'paper' => '#ffffff'];

        $first = new canvas(50.0, 40.0, $palette, 12345);
        $second = new canvas(50.0, 40.0, $palette, 12345);
        $other = new canvas(50.0, 40.0, $palette, 54321);

        $draw = function (canvas $c): array {
            $values = [];
            for ($i = 0; $i < 8; $i++) {
                $values[] = $c->rnd();
            }
            return $values;
        };

        $this->assertSame($draw($first), $draw($second));
        $this->assertNotSame($draw($first), $draw($other));
    }

    /**
     * The fraction helpers describe the page they were built for.
     *
     * @return void
     */
    public function test_geometry_helpers_report_the_page(): void {
        $this->resetAfterTest();

        $landscape = $this->canvas(297.0, 210.0);
        $this->assertTrue($landscape->is_landscape());
        $this->assertEqualsWithDelta(210.0, $landscape->s(), 0.0001);
        $this->assertEqualsWithDelta(297.0, $landscape->l(), 0.0001);
        $this->assertEqualsWithDelta(148.5, $landscape->x(0.5), 0.0001);
        $this->assertEqualsWithDelta(1.0, $landscape->by_orientation(1.0, 2.0), 0.0001);

        $portrait = $this->canvas(210.0, 297.0);
        $this->assertFalse($portrait->is_landscape());
        $this->assertEqualsWithDelta(2.0, $portrait->by_orientation(1.0, 2.0), 0.0001);
    }

    /**
     * Resetting a canvas empties it.
     *
     * @return void
     */
    public function test_reset_discards_everything_recorded(): void {
        $this->resetAfterTest();

        $c = $this->canvas();
        $c->fill('#ff0000');
        $c->rect_mm(0.0, 0.0, 10.0, 10.0);
        $this->assertNotSame([], $c->ops());

        $c->reset();
        $this->assertSame([], $c->ops());
    }
}
