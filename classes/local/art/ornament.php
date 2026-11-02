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
 * Shared ornamental primitives for the engraved and ceremonial backgrounds.
 *
 * Guilloche, rosettes, scalloped edges and linked chains are the vocabulary
 * every engraved certificate is built from, so they live here once rather than
 * being reinvented inside each style.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ornament {
    /**
     * One hypotrochoid trace — the curve a rose engine actually cuts.
     *
     * A high petal count with a modest pen offset gives engine turning; a low
     * count with a large offset gives a spiky star, which reads as clip art.
     *
     * @param canvas $c the canvas
     * @param float $cu centre as a width fraction
     * @param float $cv centre as a height fraction
     * @param float $bigr the fixed wheel radius in millimetres
     * @param int $p petals
     * @param int $q turns to close the curve
     * @param float $offset pen offset as a fraction of the rolling radius
     * @param float $phase rotation in radians
     * @return void
     */
    public static function rosette(canvas $c, float $cu, float $cv, float $bigr, int $p,
            int $q, float $offset, float $phase): void {
        $r = $bigr * $q / $p;
        $a = $bigr - $r;
        $d = $offset * $r;
        $ratio = $a / max(0.0001, $r);
        $steps = max(300, min(1600, 10 * $p * $q));

        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $t = ($i / $steps) * 2 * M_PI * $q + $phase;
            $pts[] = $cu + ($a * cos($t) + $d * cos($ratio * $t)) / $c->w();
            $pts[] = $cv + ($a * sin($t) - $d * sin($ratio * $t)) / $c->h();
        }
        $c->polyline($pts, 'D');
    }

    /**
     * A family of nested rosette traces, phase-stepped by one petal pitch.
     *
     * @param canvas $c the canvas
     * @param float $cu centre as a width fraction
     * @param float $cv centre as a height fraction
     * @param float $bigr the fixed wheel radius in millimetres
     * @param int $p petals
     * @param int $q turns
     * @param int $traces how many traces in the family
     * @param float $offset base pen offset
     * @return void
     */
    public static function rosette_family(canvas $c, float $cu, float $cv, float $bigr, int $p,
            int $q, int $traces, float $offset = 0.40): void {
        for ($k = 0; $k < $traces; $k++) {
            self::rosette(
                $c,
                $cu,
                $cv,
                $bigr,
                $p,
                $q,
                $offset * (1 + 0.035 * $k),
                $k * 2 * M_PI / ($p * $traces)
            );
        }
    }

    /**
     * A band of engine-turned strands running along one axis.
     *
     * The phase enters the second harmonic doubled and the third tripled, so
     * strands change shape as they advance rather than merely translating.
     * That is what produces the woven crossings of a real lathe.
     *
     * @param canvas $c the canvas
     * @param bool $vertical whether the band runs down the page
     * @param float $from start along the band's axis, as a fraction
     * @param float $to end along the band's axis, as a fraction
     * @param float $centre the band centreline, as a fraction of the other axis
     * @param float $ampmm strand amplitude in millimetres
     * @param int $strands how many strands
     * @param string $colour the main strand colour
     * @param string $brandcolour the colour for the two brand strands
     * @param float $alpha the base alpha
     * @param float $widthpt stroke width in points
     * @return void
     */
    public static function strand_band(canvas $c, bool $vertical, float $from, float $to,
            float $centre, float $ampmm, int $strands, string $colour, string $brandcolour,
            float $alpha, float $widthpt = 0.3): void {
        $f1 = 6.0;
        $f2 = 13.0;
        $f3 = 21.0;
        $across = $vertical ? $c->w() : $c->h();

        for ($k = 0; $k < $strands; $k++) {
            $phi = 2 * M_PI * $k / $strands;
            $brand = ($k === 2 || $k === 5);
            $c->alpha($brand ? $alpha : $alpha * ($k % 2 === 0 ? 1.0 : 0.68));
            $c->stroke($brand ? $brandcolour : $colour, $widthpt);

            $pts = [];
            for ($i = 0; $i <= 260; $i++) {
                $t = $i / 260;
                $o = 0.46 * $ampmm * sin(2 * M_PI * $f1 * $t + $phi)
                    + 0.28 * $ampmm * sin(2 * M_PI * $f2 * $t + 2 * $phi)
                    + 0.14 * $ampmm * sin(2 * M_PI * $f3 * $t + 3 * $phi);
                $along = $from + ($to - $from) * $t;
                $off = $centre + $o / $across;
                $pts[] = $vertical ? $off : $along;
                $pts[] = $vertical ? $along : $off;
            }
            $c->polyline($pts, 'D');
        }
    }

    /**
     * A guilloche band around all four edges of a rectangle.
     *
     * Drawn as four straight bands meeting at the corners, which is how an
     * engraved plate is actually made — the corner devices exist to cover the
     * joins.
     *
     * @param canvas $c the canvas
     * @param float $inset the frame inset in millimetres
     * @param float $bandmm the band depth in millimetres
     * @param int $strands strands per band
     * @param string $colour the main strand colour
     * @param string $brandcolour the brand strand colour
     * @param float $alpha the base alpha
     * @return void
     */
    public static function guilloche_frame(canvas $c, float $inset, float $bandmm, int $strands,
            string $colour, string $brandcolour, float $alpha): void {
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();
        $amp = $bandmm * 0.42;

        self::strand_band(
            $c,
            false,
            $ou,
            1 - $ou,
            ($inset + $bandmm / 2) / $c->h(),
            $amp,
            $strands,
            $colour,
            $brandcolour,
            $alpha
        );
        self::strand_band(
            $c,
            false,
            $ou,
            1 - $ou,
            1 - ($inset + $bandmm / 2) / $c->h(),
            $amp,
            $strands,
            $colour,
            $brandcolour,
            $alpha
        );
        self::strand_band(
            $c,
            true,
            $ov,
            1 - $ov,
            ($inset + $bandmm / 2) / $c->w(),
            $amp,
            $strands,
            $colour,
            $brandcolour,
            $alpha
        );
        self::strand_band(
            $c,
            true,
            $ov,
            1 - $ov,
            1 - ($inset + $bandmm / 2) / $c->w(),
            $amp,
            $strands,
            $colour,
            $brandcolour,
            $alpha
        );
    }

    /**
     * A scalloped rectangle, the silhouette of a pressed wax seal edge.
     *
     * @param canvas $c the canvas
     * @param float $u left edge as a fraction
     * @param float $v top edge as a fraction
     * @param float $uw width as a fraction
     * @param float $vh height as a fraction
     * @param float $radiusmm the scallop radius in millimetres
     * @param string $mode F, D or FD
     * @return void
     */
    public static function scalloped_rect(canvas $c, float $u, float $v, float $uw, float $vh,
            float $radiusmm, string $mode = 'D'): void {
        $pts = [];
        $edges = [
            [$u, $v, $u + $uw, $v],
            [$u + $uw, $v, $u + $uw, $v + $vh],
            [$u + $uw, $v + $vh, $u, $v + $vh],
            [$u, $v + $vh, $u, $v],
        ];

        foreach ($edges as $edge) {
            $dx = ($edge[2] - $edge[0]) * $c->w();
            $dy = ($edge[3] - $edge[1]) * $c->h();
            $length = sqrt($dx * $dx + $dy * $dy);
            $count = max(2, (int) round($length / (2 * $radiusmm)));

            // The outward normal, so every scallop bulges away from the centre.
            $nx = $dy / max(0.0001, $length);
            $ny = -$dx / max(0.0001, $length);

            for ($i = 0; $i < $count; $i++) {
                for ($s = 0; $s <= 8; $s++) {
                    $t = ($i + $s / 8) / $count;
                    $bulge = sin(M_PI * ($s / 8)) * $radiusmm;
                    $pts[] = $edge[0] + ($edge[2] - $edge[0]) * $t + ($nx * $bulge) / $c->w();
                    $pts[] = $edge[1] + ($edge[3] - $edge[1]) * $t + ($ny * $bulge) / $c->h();
                }
            }
        }

        $c->poly($pts, $mode);
    }

    /**
     * A chain of linked rings along a straight run.
     *
     * @param canvas $c the canvas
     * @param float $u1 start x as a fraction
     * @param float $v1 start y as a fraction
     * @param float $u2 end x as a fraction
     * @param float $v2 end y as a fraction
     * @param float $radiusmm ring radius in millimetres
     * @param float $overlap how much neighbouring rings overlap, 0 to 0.6
     * @return void
     */
    public static function chain(canvas $c, float $u1, float $v1, float $u2, float $v2,
            float $radiusmm, float $overlap = 0.35): void {
        $dx = ($u2 - $u1) * $c->w();
        $dy = ($v2 - $v1) * $c->h();
        $length = sqrt($dx * $dx + $dy * $dy);
        $step = $radiusmm * 2 * (1 - $overlap);
        $count = max(1, (int) floor($length / max(0.001, $step)));

        for ($i = 0; $i <= $count; $i++) {
            $t = $count > 0 ? $i / $count : 0.0;
            $c->circle($u1 + ($u2 - $u1) * $t, $v1 + ($v2 - $v1) * $t, $radiusmm, 0, 360, 'D');
        }
    }

    /**
     * A soft tinted ground: a full-page wash with an off-centre bloom.
     *
     * Used by every style that would otherwise sit on bare white. Painted into
     * full-page rectangles with the highlight placed through the shading's own
     * coordinates, so no gradient bounding box can show as an edge.
     *
     * @param canvas $c the canvas
     * @param float $strength overall strength, 0 to 1
     * @param float $bloomu the bloom centre as a width fraction
     * @param float $bloomv the bloom centre as a height fraction
     * @return void
     */
    public static function tinted_ground(canvas $c, float $strength = 1.0, float $bloomu = 0.18,
            float $bloomv = 0.10): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $paper = $c->colour('paper');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.62 * $strength);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $wash, $paper, [0.0, 0.0, 0.35, 1.0]);

        $c->alpha(0.22 * $strength);
        $c->radial_gradient(
            0.0,
            0.0,
            1.0,
            1.0,
            $light,
            $paper,
            [$bloomu, $bloomv, 0.0, $bloomu, $bloomv, 0.70]
        );

        $c->restore();
    }
}
