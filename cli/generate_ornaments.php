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
 * Generates the bundled ornament library from the plugin's own drawing engine.
 *
 * WHY GENERATE RATHER THAN COLLECT
 *
 * The alternative was harvesting public domain artwork from museum and clip-art
 * archives. That works, and cli/import_pd_assets.php still does it, but it
 * carries a permanent provenance burden: every asset needs its licence checked,
 * recorded and defended, and one mistake in a thousand files is a legal problem
 * rather than a bug.
 *
 * Artwork this plugin draws itself has none of that. It is our own work, so it
 * is GPL v3 like the rest of the plugin, it can be bundled without a licence
 * conversation, and it recolours to any institution's brand because it is
 * described as geometry rather than fixed as pixels.
 *
 * The output is ordinary SVG on disk, so it behaves exactly like imported
 * artwork from the picker's point of view.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\ornament;
use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\art\svg_painter;
use mod_certificatepro\local\asset\pd_library;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'dry-run' => false, 'force' => false],
    ['h' => 'help', 'n' => 'dry-run', 'f' => 'force']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln(
        "
    Generate the bundled ornament library.

    Draws every ornament with the plugin's own engine and writes it to
    mod/certificatepro/assets/ornament/, then rebuilds pd_manifest.json.

    Everything written is the plugin's own work under GPL v3. No third party
    artwork is fetched, so there is no licence to check and nothing to credit.

    Options:
      -h, --help     Print this help.
      -n, --dry-run  List what would be written without writing anything.
      -f, --force    Overwrite assets marked hand_curated in the manifest.

    Usage:
      php mod/certificatepro/cli/generate_ornaments.php
    "
    );
    exit(0);
}

// Drawn in a single ink so every ornament recolours cleanly to a brand palette.
// A two-colour ornament looks richer in the picker and then fights whatever
// colour the institution actually uses.
const CPO_INK = '#1f1f1f';

/**
 * A palette whose every role is the same ink.
 *
 * The ornaments are monochrome by design, so a role-based palette would only
 * introduce tints that cannot be recoloured later.
 *
 * @return array<string, string> the palette
 */
function cpo_palette(): array {
    $roles = ['paper', 'primary', 'accent', 'ink', 'rule', 'tint', 'deep', 'soft', 'edge'];
    $palette = array_fill_keys($roles, CPO_INK);
    $palette['paper'] = '#ffffff';

    return $palette;
}

/**
 * Record one ornament and return its SVG.
 *
 * @param callable $draw receives the canvas
 * @param float $w width in millimetres
 * @param float $h height in millimetres
 * @param int $seed deterministic seed
 * @return string the SVG markup
 */
function cpo_draw(callable $draw, float $w, float $h, int $seed): string {
    $c = new canvas($w, $h, cpo_palette(), $seed);
    $draw($c, $w, $h);

    return (new svg_painter('o'))->render($c->ops(), $w, $h, false);
}

/**
 * The full catalogue of ornaments to generate.
 *
 * @return array[] each entry describes one asset
 */
function cpo_catalogue(): array {
    $land = [297.0, 210.0];
    $square = [60.0, 60.0];
    $strip = [160.0, 22.0];

    $items = [];

    // ---------------------------------------------------------------- Borders.
    $items[] = ['id' => 'border-guilloche', 'role' => 'border', 'style' => 'engraved',
        'title' => 'Guilloche frame', 'size' => $land, 'seed' => 11,
        'draw' => function (canvas $c) {
            ornament::guilloche_frame($c, 0.030, 7.0, 3, CPO_INK, CPO_INK, 0.85);
            $c->stroke(CPO_INK, 1.5);
            $c->rect(0.020, 0.028, 0.960, 0.944, 'D');
            $c->stroke(CPO_INK, 0.4);
            $c->rect(0.046, 0.064, 0.908, 0.872, 'D');
        }];

    $items[] = ['id' => 'border-double-rule', 'role' => 'border', 'style' => 'classical',
        'title' => 'Double rule with corner marks', 'size' => $land, 'seed' => 12,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 2.2);
            $c->rect(0.028, 0.038, 0.944, 0.924, 'D');
            $c->stroke(CPO_INK, 0.5);
            $c->rect(0.042, 0.058, 0.916, 0.884, 'D');
            // A lozenge at each corner, the plainest way to stop two rules
            // meeting in a bare mitre.
            foreach ([[0.035, 0.048], [0.965, 0.048], [0.035, 0.952], [0.965, 0.952]] as $p) {
                $d = 2.6;
                $x = $c->x($p[0]);
                $y = $c->y($p[1]);
                $c->fill(CPO_INK);
                $c->poly_mm([$x, $y - $d, $x + $d, $y, $x, $y + $d, $x - $d, $y], 'F');
            }
        }];

    $items[] = ['id' => 'border-greek-key', 'role' => 'border', 'style' => 'classical',
        'title' => 'Greek key band', 'size' => $land, 'seed' => 13,
        'draw' => function (canvas $c) {
            cpo_greek_key($c, 0.040, 6.5);
            $c->stroke(CPO_INK, 1.2);
            $c->rect(0.028, 0.038, 0.944, 0.924, 'D');
        }];

    $items[] = ['id' => 'border-scalloped', 'role' => 'border', 'style' => 'ceremonial',
        'title' => 'Scalloped frame', 'size' => $land, 'seed' => 14,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 1.0);
            ornament::scalloped_rect($c, 0.030, 0.042, 0.940, 0.916, 4.0, 'D');
            $c->stroke(CPO_INK, 0.45);
            $c->rect(0.056, 0.078, 0.888, 0.844, 'D');
        }];

    $items[] = ['id' => 'border-rope', 'role' => 'border', 'style' => 'nautical',
        'title' => 'Rope moulding frame', 'size' => $land, 'seed' => 15,
        'draw' => function (canvas $c) {
            cpo_rope_frame($c, 0.036, 0.050);
            $c->stroke(CPO_INK, 0.5);
            $c->rect(0.060, 0.084, 0.880, 0.832, 'D');
        }];

    $items[] = ['id' => 'border-bead-reel', 'role' => 'border', 'style' => 'classical',
        'title' => 'Bead and reel frame', 'size' => $land, 'seed' => 16,
        'draw' => function (canvas $c) {
            cpo_bead_reel_frame($c, 0.038, 0.052);
            $c->stroke(CPO_INK, 1.4);
            $c->rect(0.024, 0.032, 0.952, 0.936, 'D');
        }];

    $items[] = ['id' => 'border-chain', 'role' => 'border', 'style' => 'engraved',
        'title' => 'Interlocking chain frame', 'size' => $land, 'seed' => 17,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.7);
            ornament::chain($c, 0.042, 0.058, 0.958, 0.058, 3.0);
            ornament::chain($c, 0.042, 0.942, 0.958, 0.942, 3.0);
            ornament::chain($c, 0.042, 0.058, 0.042, 0.942, 3.0);
            ornament::chain($c, 0.958, 0.058, 0.958, 0.942, 3.0);
            $c->stroke(CPO_INK, 1.3);
            $c->rect(0.022, 0.030, 0.956, 0.940, 'D');
        }];

    $items[] = ['id' => 'border-hairline', 'role' => 'border', 'style' => 'modern',
        'title' => 'Hairline frame with rules', 'size' => $land, 'seed' => 18,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.45);
            $c->rect(0.036, 0.050, 0.928, 0.900, 'D');
            $c->stroke(CPO_INK, 2.4);
            // Short heavy segments at the mid-point of each side: a modern way
            // to weight a frame without thickening the whole rule.
            $c->line(0.42, 0.050, 0.58, 0.050);
            $c->line(0.42, 0.950, 0.58, 0.950);
            $c->line(0.036, 0.42, 0.036, 0.58);
            $c->line(0.964, 0.42, 0.964, 0.58);
        }];

    // ---------------------------------------------------------------- Corners.
    $items[] = ['id' => 'corner-scroll', 'role' => 'corner', 'style' => 'baroque',
        'title' => 'Acanthus scroll corner', 'size' => $square, 'seed' => 21,
        'draw' => fn(canvas $c) => cpo_scroll_corner($c)];

    $items[] = ['id' => 'corner-fleuron', 'role' => 'corner', 'style' => 'classical',
        'title' => 'Fleuron corner', 'size' => $square, 'seed' => 22,
        'draw' => fn(canvas $c) => cpo_fleuron($c, 0.5, 0.5, 20.0)];

    $items[] = ['id' => 'corner-bracket', 'role' => 'corner', 'style' => 'modern',
        'title' => 'Rule bracket corner', 'size' => $square, 'seed' => 23,
        'draw' => function (canvas $c) {
            foreach ([[2.4, 0.10], [0.6, 0.20], [0.6, 0.26]] as [$width, $inset]) {
                $c->stroke(CPO_INK, $width);
                $c->line($inset, 0.86, $inset, $inset);
                $c->line($inset, $inset, 0.86, $inset);
            }
        }];

    $items[] = ['id' => 'corner-rosette', 'role' => 'corner', 'style' => 'engraved',
        'title' => 'Rose engine corner', 'size' => $square, 'seed' => 24,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.35);
            ornament::rosette($c, 0.5, 0.5, 22.0, 7, 3, 0.62, 0.0);
        }];

    // --------------------------------------------------------------- Dividers.
    $items[] = ['id' => 'divider-diamond', 'role' => 'divider', 'style' => 'classical',
        'title' => 'Rule with centre diamond', 'size' => $strip, 'seed' => 31,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.7);
            $c->line(0.02, 0.5, 0.42, 0.5);
            $c->line(0.58, 0.5, 0.98, 0.5);
            $d = 3.2;
            $x = $c->x(0.5);
            $y = $c->y(0.5);
            $c->fill(CPO_INK);
            $c->poly_mm([$x, $y - $d, $x + $d, $y, $x, $y + $d, $x - $d, $y], 'F');
            $c->stroke(CPO_INK, 0.7);
            $c->circle(0.455, 0.5, 1.5, 0.0, 360.0, 'D');
            $c->circle(0.545, 0.5, 1.5, 0.0, 360.0, 'D');
        }];

    $items[] = ['id' => 'divider-fleuron', 'role' => 'divider', 'style' => 'classical',
        'title' => 'Fleuron divider', 'size' => $strip, 'seed' => 32,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.6);
            $c->line(0.03, 0.5, 0.40, 0.5);
            $c->line(0.60, 0.5, 0.97, 0.5);
            cpo_fleuron($c, 0.5, 0.5, 8.0);
        }];

    $items[] = ['id' => 'divider-guilloche', 'role' => 'divider', 'style' => 'engraved',
        'title' => 'Guilloche strand', 'size' => $strip, 'seed' => 33,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.35);
            ornament::strand_band($c, false, 0.03, 0.97, 0.5, 5.0, 3, CPO_INK, CPO_INK, 0.9);
        }];

    $items[] = ['id' => 'divider-laurel', 'role' => 'divider', 'style' => 'ceremonial',
        'title' => 'Laurel spray divider', 'size' => $strip, 'seed' => 34,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.6);
            $c->line(0.06, 0.5, 0.94, 0.5);
            $c->fill(CPO_INK);
            for ($i = 0; $i < 9; $i++) {
                $t = 0.5 + ($i - 4) * 0.045;
                $len = 3.4 * (1.0 - abs($i - 4) / 6.0);
                $c->ellipse_mm($c->x($t), $c->y(0.5) - 1.6, $len, $len * 0.34, -32.0, 0.0, 360.0, 'F');
                $c->ellipse_mm($c->x($t), $c->y(0.5) + 1.6, $len, $len * 0.34, 32.0, 0.0, 360.0, 'F');
            }
        }];

    // --------------------------------------------------------------- Patterns.
    $items[] = ['id' => 'pattern-engine-turned', 'role' => 'pattern', 'style' => 'security',
        'title' => 'Engine-turned field', 'size' => $square, 'seed' => 41,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.22);
            ornament::rosette_family($c, 0.5, 0.5, 26.0, 5, 3, 3, 0.55);
        }];

    $items[] = ['id' => 'pattern-lattice', 'role' => 'pattern', 'style' => 'geometric',
        'title' => 'Diagonal lattice', 'size' => $square, 'seed' => 42,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.3);
            for ($i = -10; $i <= 20; $i++) {
                $o = $i * 0.10;
                $c->line($o, 0.0, $o + 1.0, 1.0);
                $c->line($o + 1.0, 0.0, $o, 1.0);
            }
        }];

    $items[] = ['id' => 'pattern-bead-grid', 'role' => 'pattern', 'style' => 'geometric',
        'title' => 'Bead grid', 'size' => $square, 'seed' => 43,
        'draw' => function (canvas $c) {
            $c->fill(CPO_INK);
            for ($r = 0; $r < 12; $r++) {
                for ($k = 0; $k < 12; $k++) {
                    $offset = ($r % 2) * 0.04;
                    $c->circle(0.04 + $k * 0.08 + $offset, 0.04 + $r * 0.08, 0.55, 0.0, 360.0, 'F');
                }
            }
        }];

    $items[] = ['id' => 'pattern-chevron', 'role' => 'pattern', 'style' => 'modern',
        'title' => 'Chevron field', 'size' => $square, 'seed' => 44,
        'draw' => function (canvas $c) {
            $c->stroke(CPO_INK, 0.45);
            for ($r = 0; $r < 16; $r++) {
                $y = $r * 0.0665;
                for ($k = 0; $k < 8; $k++) {
                    $x = $k * 0.125;
                    $c->polyline([$x, $y + 0.03, $x + 0.0625, $y, $x + 0.125, $y + 0.03], 'D');
                }
            }
        }];

    // ------------------------------------------------------------------ Seals.
    foreach ([
        ['seal-ring-laurel', seal_engine::EDGE_SCALLOP, seal_engine::MARK_CREST, 'Scalloped laurel seal'],
        ['seal-ring-plain', seal_engine::EDGE_DOUBLE, seal_engine::MARK_STAR, 'Double rule star seal'],
        ['seal-ring-cog', seal_engine::EDGE_COG, seal_engine::MARK_BOOK, 'Cog edge book seal'],
        ['seal-ring-rope', seal_engine::EDGE_ROPE, seal_engine::MARK_TORCH, 'Rope edge torch seal'],
    ] as $index => [$id, $edge, $mark, $title]) {
        $items[] = ['id' => $id, 'role' => 'seal', 'style' => 'ceremonial',
            'title' => $title, 'size' => $square, 'seed' => 51 + $index,
            'draw' => function (canvas $c) use ($edge, $mark) {
                seal_engine::draw(
                    $c,
                    0.5,
                    0.5,
                    27.0,
                    [
                        'edge' => $edge, 'mark' => $mark, 'ring' => seal_engine::RING_NONE,
                        'ink' => CPO_INK, 'metal' => '#ffffff', 'innerkeyline' => true,
                    ]
                );
            }];
    }

    return $items;
}

/**
 * A Greek key meander running round the page just inside the trim.
 *
 * @param canvas $c the canvas
 * @param float $inset distance from the trim as a page fraction
 * @param float $bandmm band depth in millimetres
 * @return void
 */
function cpo_greek_key(canvas $c, float $inset, float $bandmm): void {
    $c->stroke(CPO_INK, 0.9);

    $left = $c->x($inset);
    $right = $c->x(1.0 - $inset);
    $top = $c->y($inset);
    $bottom = $c->y(1.0 - $inset);
    $unit = $bandmm;

    foreach ([['h', $top, 1.0], ['h', $bottom, -1.0]] as [$axis, $fixed, $dir]) {
        unset($axis);
        $x = $left;
        while ($x + $unit * 4 <= $right) {
            $c->polyline_mm(
                [
                    $x, $fixed + $dir * $unit,
                    $x, $fixed,
                    $x + $unit * 3, $fixed,
                    $x + $unit * 3, $fixed + $dir * $unit * 0.66,
                    $x + $unit, $fixed + $dir * $unit * 0.66,
                    $x + $unit, $fixed + $dir * $unit * 0.33,
                ],
                'D'
            );
            $x += $unit * 4;
        }
    }

    foreach ([['v', $left, 1.0], ['v', $right, -1.0]] as [$axis, $fixed, $dir]) {
        unset($axis);
        $y = $top;
        while ($y + $unit * 4 <= $bottom) {
            $c->polyline_mm(
                [
                    $fixed + $dir * $unit, $y,
                    $fixed, $y,
                    $fixed, $y + $unit * 3,
                    $fixed + $dir * $unit * 0.66, $y + $unit * 3,
                    $fixed + $dir * $unit * 0.66, $y + $unit,
                    $fixed + $dir * $unit * 0.33, $y + $unit,
                ],
                'D'
            );
            $y += $unit * 4;
        }
    }
}

/**
 * A rope moulding running round the page.
 *
 * @param canvas $c the canvas
 * @param float $inu horizontal inset as a fraction
 * @param float $inv vertical inset as a fraction
 * @return void
 */
function cpo_rope_frame(canvas $c, float $inu, float $inv): void {
    $c->stroke(CPO_INK, 0.7);

    $left = $c->x($inu);
    $right = $c->x(1.0 - $inu);
    $top = $c->y($inv);
    $bottom = $c->y(1.0 - $inv);
    $step = 4.6;
    $bead = 2.4;

    for ($x = $left; $x <= $right; $x += $step) {
        foreach ([$top, $bottom] as $y) {
            $c->ellipse_mm($x, $y, $bead, $bead * 0.46, 34.0, 0.0, 360.0, 'D');
        }
    }
    for ($y = $top; $y <= $bottom; $y += $step) {
        foreach ([$left, $right] as $x) {
            $c->ellipse_mm($x, $y, $bead, $bead * 0.46, 124.0, 0.0, 360.0, 'D');
        }
    }
}

/**
 * A bead-and-reel moulding running round the page.
 *
 * @param canvas $c the canvas
 * @param float $inu horizontal inset as a fraction
 * @param float $inv vertical inset as a fraction
 * @return void
 */
function cpo_bead_reel_frame(canvas $c, float $inu, float $inv): void {
    $left = $c->x($inu);
    $right = $c->x(1.0 - $inu);
    $top = $c->y($inv);
    $bottom = $c->y(1.0 - $inv);
    $step = 5.0;

    $bead = function (float $x, float $y, bool $round) use ($c) {
        if ($round) {
            $c->fill(CPO_INK);
            $c->circle_mm($x, $y, 1.15, 0.0, 360.0, 'F');
            return;
        }
        $c->stroke(CPO_INK, 1.1);
        $c->line_mm($x, $y - 1.6, $x, $y + 1.6);
    };

    $i = 0;
    for ($x = $left; $x <= $right; $x += $step, $i++) {
        $bead($x, $top, $i % 2 === 0);
        $bead($x, $bottom, $i % 2 === 0);
    }
    $i = 0;
    for ($y = $top; $y <= $bottom; $y += $step, $i++) {
        $bead($left, $y, $i % 2 === 0);
        $bead($right, $y, $i % 2 === 0);
    }
}

/**
 * A four-lobed fleuron.
 *
 * @param canvas $c the canvas
 * @param float $u centre as a width fraction
 * @param float $v centre as a height fraction
 * @param float $rmm overall radius in millimetres
 * @return void
 */
function cpo_fleuron(canvas $c, float $u, float $v, float $rmm): void {
    $cx = $c->x($u);
    $cy = $c->y($v);

    // Four pointed petals, each swept from the centre out to a tip and back.
    // Overlapping ellipses were the obvious construction and read as a blob:
    // a fleuron needs the pinch where each petal leaves the boss.
    $c->fill(CPO_INK);
    for ($i = 0; $i < 4; $i++) {
        $axis = deg2rad($i * 90.0);
        $points = [];
        $steps = 16;
        for ($k = 0; $k <= $steps; $k++) {
            $t = $k / $steps;
            $reach = $rmm * $t;
            $swell = $rmm * 0.30 * sin($t * M_PI) * (1.0 - 0.35 * $t);
            $points[] = $cx + $reach * sin($axis) + $swell * cos($axis);
            $points[] = $cy - $reach * cos($axis) + $swell * sin($axis);
        }
        for ($k = $steps; $k >= 0; $k--) {
            $t = $k / $steps;
            $reach = $rmm * $t;
            $swell = $rmm * 0.30 * sin($t * M_PI) * (1.0 - 0.35 * $t);
            $points[] = $cx + $reach * sin($axis) - $swell * cos($axis);
            $points[] = $cy - $reach * cos($axis) - $swell * sin($axis);
        }
        $c->poly_mm($points, 'F');
    }

    // Smaller leaves on the diagonals, and a boss to hide the junction.
    for ($i = 0; $i < 4; $i++) {
        $axis = deg2rad(45.0 + $i * 90.0);
        $lx = $cx + $rmm * 0.44 * sin($axis);
        $ly = $cy - $rmm * 0.44 * cos($axis);
        $c->ellipse_mm($lx, $ly, $rmm * 0.30, $rmm * 0.11, 45.0 + $i * 90.0, 0.0, 360.0, 'F');
    }

    $c->circle_mm($cx, $cy, $rmm * 0.155, 0.0, 360.0, 'F');
}

/**
 * A scrolled acanthus corner, drawn as a pair of involute spirals.
 *
 * @param canvas $c the canvas
 * @return void
 */
function cpo_scroll_corner(canvas $c): void {
    $c->stroke(CPO_INK, 0.8);

    foreach ([[0.20, 0.20, 1.0], [0.20, 0.20, -1.0]] as [$u, $v, $dir]) {
        $points = [];
        $turns = 2.6;
        $steps = 90;
        for ($k = 0; $k <= $steps; $k++) {
            $t = $k / $steps;
            $theta = $t * $turns * 2.0 * M_PI;
            $r = 1.4 + $t * 15.0;
            $bx = $c->x($u) + ($dir > 0 ? 22.0 : 2.0);
            $by = $c->y($v) + ($dir > 0 ? 2.0 : 22.0);
            $points[] = $bx + $dir * $r * cos($theta);
            $points[] = $by + $r * sin($theta);
        }
        $c->polyline_mm($points, 'D');
    }

    // The stem the two scrolls spring from.
    $c->stroke(CPO_INK, 1.4);
    $c->polyline([0.12, 0.88, 0.12, 0.30, 0.30, 0.12, 0.88, 0.12], 'D');
}

// -------------------------------------------------------------------- Run.

// Generated artwork lives in its own subdirectory, exactly as each
// imported source does, so the two never collide on disk.
$root = pd_library::asset_root() . '/ornament/certificatepro';
$catalogue = cpo_catalogue();

$existing = [];
foreach (pd_library::manifest()['items'] as $item) {
    $existing[$item['id']] = $item;
}

cli_writeln('Generating ' . count($catalogue) . ' ornaments into ' . $root);

if (!$options['dry-run'] && !is_dir($root)) {
    mkdir($root, $CFG->directorypermissions, true);
}

$items = [];
$written = 0;
$skipped = 0;

foreach ($catalogue as $spec) {
    [$w, $h] = $spec['size'];
    // Relative to assets/ornament/, which is what pd_library::decorate()
    // resolves against.
    $file = 'certificatepro/' . $spec['id'] . '.svg';
    $path = pd_library::asset_root() . '/ornament/' . $file;

    $prior = $existing[$spec['id']] ?? null;
    if ($prior !== null && !empty($prior['hand_curated']) && !$options['force']) {
        cli_writeln('  skip (hand curated)  ' . $spec['id']);
        $items[] = $prior;
        $skipped++;
        continue;
    }

    $svg = cpo_draw($spec['draw'], $w, $h, $spec['seed']);

    if (!$options['dry-run']) {
        file_put_contents($path, $svg);
    }

    $items[] = [
        'id' => $spec['id'],
        'file' => $file,
        'role' => $spec['role'],
        'title' => $spec['title'],
        'style' => $spec['style'],
        'orientation' => $w > $h * 1.15 ? 'landscape' : ($h > $w * 1.15 ? 'portrait' : 'both'),
        'width' => $w,
        'height' => $h,
        'source' => 'certificatepro',
        'source_url' => 'https://lms-labs.com',
        'licence' => 'GPL-3.0-or-later',
        'attribution_required' => false,
        'generated' => true,
        'bytes' => strlen($svg),
    ];

    cli_writeln(sprintf('  %-24s %-8s %6d bytes', $spec['id'], $spec['role'], strlen($svg)));
    $written++;
}

$manifest = [
    'manifest_version' => 1,
    'generator' => 'mod/certificatepro/cli/generate_ornaments.php',
    'roles' => pd_library::roles(),
    'items' => $items,
];

if (!$options['dry-run']) {
    file_put_contents(
        pd_library::manifest_path(),
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    pd_library::reset_cache();
}

cli_writeln('');
cli_writeln(
    $options['dry-run']
        ? 'Dry run. Nothing written.'
        : "Wrote {$written} ornaments, kept {$skipped} hand curated. Manifest rebuilt."
);
