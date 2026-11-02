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

namespace mod_certificatepro\local\art;

/**
 * Modernised security backgrounds.
 *
 * The heritage of banknote and share-certificate printing, redrawn with
 * contemporary spacing and restraint. These are real rose-engine curves with
 * proper phase relationships, not a lattice of sine waves pretending to be
 * guilloche.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_security {
    /**
     * Lathe Ribbon: woven bands of engine-turned line along opposite edges.
     *
     * The phase enters the second harmonic doubled and the third tripled, so the
     * strands change shape as they advance rather than merely translating. That
     * is the difference between a lathe and a stack of sine waves.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_lathe_ribbon(canvas $c): void {
        ornament::tinted_ground($c, 0.8, 0.5, 0.5);

        $b = 0.085 * $c->s();
        $f1 = 3 + $c->variant(2);
        $f2 = 7 + $c->rnd_int(3);
        $f3 = 11 + $c->rnd_int(3);
        $psi = 2 * M_PI * $c->rnd();

        $a1 = 0.44 * $b;
        $a2 = 0.27 * $b;
        $a3 = 0.13 * $b;

        $dark = $c->colour('primarydark');
        $primary = $c->colour('primary');
        $rule = $c->colour('rule');
        $strands = 7;

        foreach ([[0.072, 1.0, -1.0], [0.945, -1.0, 1.0]] as $bandindex => $band) {
            $m = $band[0];
            $sign = $band[1];
            $a2sign = $band[2];

            for ($k = 0; $k < $strands; $k++) {
                $phi = $sign * 2 * M_PI * $k / $strands;
                $brand = ($k === 2 || $k === 5);

                $c->alpha($brand ? 0.30 : ($k % 2 === 0 ? 0.32 : 0.22));
                $c->stroke($brand ? $primary : $dark, 0.28);

                $pts = [];
                for ($i = 0; $i <= 320; $i++) {
                    $t = $i / 320;
                    $o = $a1 * sin(2 * M_PI * $f1 * $t + $phi)
                        + $a2sign * $a2 * sin(2 * M_PI * $f2 * $t + 2 * $phi + $psi)
                        + $a3 * sin(2 * M_PI * $f3 * $t + 3 * $phi);
                    $pts[] = $t;
                    $pts[] = $m + $o / $c->h();
                }
                $c->polyline($pts, 'D');
            }

            // One bounding hairline, on the page-outer side only. Bounding both
            // sides is what turns engine turning into a Victorian frame.
            $c->alpha(0.40);
            $c->stroke($rule, 0.40);
            $edge = $bandindex === 0 ? $m - 0.55 * $b / $c->h() : $m + 0.55 * $b / $c->h();
            $c->line(0.0, $edge, 1.0, $edge);
        }
    }

    /**
     * Relief Ruling: evenly spaced rulings whose thickness swells and thins.
     *
     * A stroked path cannot vary in width, so each ruling is a filled ribbon
     * instead. Tone is carried by line width rather than by colour value, which
     * is why this one reproduces identically in black, in CMYK and on a
     * photocopier.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_relief_ruling(canvas $c): void {
        ornament::tinted_ground($c, 0.6, 0.5, 0.45);

        $pitch = 1.15;
        $wmin = 0.085;
        $wmax = 0.26;

        $k = [];
        for ($i = 0; $i < 6; $i++) {
            $k[] = 0.6 + 1.9 * $c->rnd();
        }
        $ph = [2 * M_PI * $c->rnd(), 2 * M_PI * $c->rnd(), 2 * M_PI * $c->rnd()];

        $field = function (float $u, float $v) use ($k, $ph): float {
            $m = 0.55 * sin(2 * M_PI * ($k[0] * $u + $k[1] * $v) + $ph[0])
                + 0.30 * sin(2 * M_PI * ($k[2] * $u - $k[3] * $v) + $ph[1])
                + 0.15 * sin(2 * M_PI * ($k[4] * $u + $k[5] * $v) + $ph[2]);
            return 0.5 + 0.5 * $m;
        };

        $dark = $c->colour('primarydark');
        $count = (int) floor(0.96 * $c->h() / $pitch);

        $c->alpha(0.30);
        $c->fill($dark);

        for ($i = 0; $i < $count; $i++) {
            $vy = (0.02 * $c->h() + $i * $pitch) / $c->h();

            $top = [];
            $bottom = [];
            // Sampled finely across the page and dissolved over a long ramp: at
            // a coarse sample count the rulings ended on a visibly stepped
            // rectangle rather than fading out.
            for ($j = 0; $j < 80; $j++) {
                $u = $j / 79;
                // A very long ramp. A short one left the clearing reading as a
                // white blob punched out of a texture rather than as relief.
                $gate = background_library::quiet_gate($u, $vy, 1.25, 1.60);
                $feather = min(1.0, min($u, 1 - $u) / 0.05)
                    * min(1.0, min($vy, 1 - $vy) / 0.05);
                $h = 0.5 * ($wmin + ($wmax - $wmin) * $field($u, $vy)) * $gate * $feather;

                if ($h < 0.5 * $wmin) {
                    // The ribbon opens out rather than tapering to a hairline.
                    if (count($top) >= 3) {
                        self::ribbon($c, $top, $bottom);
                    }
                    $top = [];
                    $bottom = [];
                    continue;
                }

                $top[] = [$u, $vy - $h / $c->h()];
                $bottom[] = [$u, $vy + $h / $c->h()];
            }
            if (count($top) >= 3) {
                self::ribbon($c, $top, $bottom);
            }
        }
    }

    /**
     * Emit a variable-width ribbon as one filled polygon.
     *
     * @param canvas $c the canvas
     * @param array[] $top the upper edge points
     * @param array[] $bottom the lower edge points
     * @return void
     */
    protected static function ribbon(canvas $c, array $top, array $bottom): void {
        $pts = [];
        foreach ($top as $p) {
            $pts[] = $p[0];
            $pts[] = $p[1];
        }
        foreach (array_reverse($bottom) as $p) {
            $pts[] = $p[0];
            $pts[] = $p[1];
        }
        $c->poly($pts, 'F');
    }

    /**
     * Counter Rosette: a tinted plate with a rosette engraved out of it in white.
     *
     * Drawing the traces in paper over the tint means they read as line cut into
     * the plate, and where the rosette crosses untinted sheet they simply
     * vanish — which produces the half-in, half-out crop for free, with no
     * clipping logic at all.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_counter_rosette(canvas $c): void {
        $primary = $c->colour('primary');
        $paper = $c->colour('paper');
        $dark = $c->colour('primarydark');
        $flip = $c->variant(2) === 1;

        $mu = function (float $u) use ($flip): float {
            return $flip ? 1.0 - $u : $u;
        };

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        // The L-shaped plate. Its vertical arm would otherwise put a sixteen
        // percent tint under the start of the text, so that arm is a gradient
        // that has fallen to almost nothing by the time it reaches the band.
        $c->alpha(0.16);
        $c->fill($primary);
        $c->rect(0.0, 0.0, 1.0, 0.235, 'F');

        $c->linear_gradient(
            $mu(0.0),
            0.235,
            0.30,
            0.765,
            $primary,
            $paper,
            $flip ? [0.0, 0.0, 1.0, 0.0] : [1.0, 0.0, 0.0, 0.0]
        );

        // Soften the plate's inner edge so it dissolves into the sheet.
        $c->linear_gradient(0.0, 0.235, 1.0, 0.045, $primary, $paper, [0.0, 0.0, 0.0, 1.0]);

        // The rosette, engraved in white over the plate.
        $p = 9 + 2 * $c->variant(3);
        $q = 2;
        $bigr = 0.30 * $c->s();
        $r = $bigr * $q / $p;
        $d = 0.78 * $r;
        $cu = $mu(0.30);
        $cv = 0.235;

        $c->alpha(0.85);
        $c->stroke($paper, 0.85);
        for ($k = 0; $k < 11; $k++) {
            self::plain_trochoid(
                $c,
                $cu,
                $cv,
                $bigr + $r,
                $r,
                $d * (1 + 0.028 * $k),
                $q,
                $p,
                $k * 2 * M_PI / ($p * 11)
            );
        }

        // The tight triple rule at the middle of a real lathe rosette.
        foreach ([[0.10, 0.85], [0.104, 0.28], [0.122, 0.28]] as $ring) {
            $c->stroke($paper, $ring[1]);
            $c->circle($cu, $cv, $ring[0] * $c->s(), 0, 360, 'D');
        }

        $c->restore();

        $c->alpha(0.50);
        $c->stroke($dark, 0.34);
        $inset = 0.018 * $c->s();
        $c->line(0.0, $inset / $c->h(), 1.0, $inset / $c->h());
    }

    /**
     * Sample a trochoid with no quiet-zone gate.
     *
     * Used where the drawing colour is the paper itself, so the curve adds no
     * ink and needs no gate.
     *
     * @param canvas $c the canvas
     * @param float $cu centre as a width fraction
     * @param float $cv centre as a height fraction
     * @param float $a the sum radius in millimetres
     * @param float $r the rolling radius in millimetres
     * @param float $d the pen offset in millimetres
     * @param int $q turns to close the curve
     * @param int $p lobes
     * @param float $phase rotation in radians
     * @return void
     */
    protected static function plain_trochoid(canvas $c, float $cu, float $cv, float $a, float $r,
            float $d, int $q, int $p, float $phase): void {
        $steps = max(220, 20 * $p * $q);
        $ratio = $a / max(0.0001, $r);
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = ($i / $steps) * 2 * M_PI * $q + $phase;
            $pts[] = $cu + ($a * cos($t) - $d * cos($ratio * $t)) / $c->w();
            $pts[] = $cv + ($a * sin($t) - $d * sin($ratio * $t)) / $c->h();
        }
        $c->polyline($pts, 'D');
    }
}
