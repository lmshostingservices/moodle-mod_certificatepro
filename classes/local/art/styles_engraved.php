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
 * Engraved and ceremonial backgrounds.
 *
 * The register of a share certificate or a university award: guilloche borders,
 * rosette medallions, ornamental corners. All of them carry tone behind the
 * text on purpose, and none of them sits on bare white.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_engraved {
    /**
     * Guilloche Border: a wide, dense engine-turned frame and nothing else.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_guilloche_border(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');

        ornament::tinted_ground($c, 0.85, 0.5, 0.42);

        $inset = 0.036 * $c->s();
        $band = 0.052 * $c->s();

        ornament::guilloche_frame($c, $inset, $band, 9, $dark, $primary, 0.58);

        // An inner, finer band running counter to the outer one.
        ornament::guilloche_frame($c, $inset + $band + 0.010 * $c->s(), $band * 0.40, 5,
            $primary, $dark, 0.42);

        $ou = $inset / $c->w();
        $ov = $inset / $c->h();
        $c->alpha(1.0);
        $c->stroke($dark, 1.8);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $iu = ($inset + $band) / $c->w();
        $iv = ($inset + $band) / $c->h();
        $c->alpha(0.8);
        $c->stroke($dark, 0.5);
        $c->rect($iu, $iv, 1 - 2 * $iu, 1 - 2 * $iv, 'D');

        $tu = ($inset + $band * 1.5 + 0.012 * $c->s()) / $c->w();
        $tv = ($inset + $band * 1.5 + 0.012 * $c->s()) / $c->h();
        $c->alpha(0.55);
        $c->stroke($primary, 0.35);
        $c->rect($tu, $tv, 1 - 2 * $tu, 1 - 2 * $tv, 'D');
    }

    /**
     * Rosette Corners: a light frame carrying four large corner rosettes.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_rosette_corners(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $paper = $c->colour('paper');
        $accent = $c->colour('accent');

        ornament::tinted_ground($c, 0.9, 0.5, 0.30);

        $inset = 0.058 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();
        $rr = 0.085 * $c->s();

        // The frame, drawn first so the rosettes sit on it.
        $c->alpha(0.95);
        $c->stroke($dark, 1.4);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $g = 0.011 * $c->s();
        $c->alpha(0.55);
        $c->stroke($primary, 0.4);
        $c->rect($ou + $g / $c->w(), $ov + $g / $c->h(),
            1 - 2 * ($ou + $g / $c->w()), 1 - 2 * ($ov + $g / $c->h()), 'D');

        // A linked chain running the long edges between the corners.
        // A fine chain. At a larger ring size this read as a caterpillar
        // rather than as ornament.
        $c->alpha(0.30);
        $c->stroke($primary, 0.26);
        ornament::chain($c, $ou + (2 * $rr) / $c->w(), $ov, 1 - $ou - (2 * $rr) / $c->w(), $ov,
            $rr * 0.13, 0.50);
        ornament::chain($c, $ou + (2 * $rr) / $c->w(), 1 - $ov,
            1 - $ou - (2 * $rr) / $c->w(), 1 - $ov, $rr * 0.13, 0.50);

        foreach ([[$ou, $ov], [1 - $ou, $ov], [$ou, 1 - $ov], [1 - $ou, 1 - $ov]] as $corner) {
            $c->alpha(1.0);
            $c->fill($paper);
            $c->circle($corner[0], $corner[1], $rr, 0, 360, 'F');

            $c->alpha(0.62);
            $c->stroke($primary, 0.30);
            ornament::rosette_family($c, $corner[0], $corner[1], $rr * 0.90, 17, 6, 7, 0.40);

            $c->alpha(1.0);
            $c->stroke($dark, 1.1);
            $c->circle($corner[0], $corner[1], $rr, 0, 360, 'D');
            $c->alpha(0.7);
            $c->stroke($dark, 0.35);
            $c->circle($corner[0], $corner[1], $rr * 1.12, 0, 360, 'D');

            $c->alpha(1.0);
            $c->fill($accent);
            $c->circle($corner[0], $corner[1], $rr * 0.11, 0, 360, 'F');
        }
    }

    /**
     * Laurel Arc: abstracted wreath arcs above and below the text.
     *
     * Tapered strokes on an arc rather than drawn leaves — the gesture of a
     * wreath without the clip art.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_laurel_arc(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $accent = $c->colour('accent');

        ornament::tinted_ground($c, 0.8, 0.5, 0.20);

        $arcs = [[0.145, 1.0], [0.862, -1.0]];

        foreach ($arcs as $arc) {
            $baseline = $arc[0];
            $dir = $arc[1];
            $rise = 0.055 * $c->s() / $c->h() * $dir;

            // The stem, as a function so the blades can be set on it and angled
            // to its tangent. Placing blades on an independent arc, as the first
            // version did, left them reading as scattered dashes.
            $stemu = function (float $t): float {
                return 0.125 + 0.75 * $t;
            };
            $stemv = function (float $t) use ($baseline, $rise): float {
                return $baseline - $rise * sin(M_PI * $t) * 0.85;
            };

            $c->alpha(0.75);
            $c->stroke($dark, 0.7);
            $pts = [];
            for ($i = 0; $i <= 40; $i++) {
                $t = $i / 40;
                $pts[] = $stemu($t);
                $pts[] = $stemv($t);
            }
            $c->polyline($pts, 'D');

            // Blades set densely along the stem, angled to its tangent and
            // sweeping outward from the centre, with a gap left for the diamond.
            $blades = 30;
            for ($i = 0; $i <= $blades; $i++) {
                $t = $i / $blades;
                if ($t > 0.455 && $t < 0.545) {
                    continue;
                }

                $side = $t < 0.5 ? -1.0 : 1.0;
                $out = abs($t - 0.5) * 2;

                $dx = ($stemu($t + 0.01) - $stemu($t - 0.01)) * $c->w();
                $dy = ($stemv($t + 0.01) - $stemv($t - 0.01)) * $c->h();
                $tangent = atan2($dy, $dx);

                $angle = $tangent + $side * deg2rad(46) + ($side < 0 ? M_PI : 0.0);
                $length = (0.014 + 0.020 * $out) * $c->s();

                $c->alpha(0.30 + 0.34 * $out);
                $c->fill($i % 4 === 0 ? $dark : $primary);
                self::blade($c, $stemu($t), $stemv($t), $length, $angle, $length * 0.30);
            }
        }

        // A small diamond centred between the arcs, top and bottom.
        foreach ([0.145, 0.862] as $v) {
            $ds = 0.011 * $c->s();
            $c->alpha(1.0);
            $c->fill($accent);
            $c->poly([0.5, $v - $ds / $c->h(), 0.5 + $ds / $c->w(), $v,
                0.5, $v + $ds / $c->h(), 0.5 - $ds / $c->w(), $v], 'F');
        }

        // Rules running out to the trim from each arc.
        $c->alpha(0.40);
        $c->stroke($primary, 0.35);
        foreach ([0.145, 0.862] as $v) {
            $c->line(0.055, $v, 0.5 - 0.395, $v);
            $c->line(0.5 + 0.395, $v, 0.945, $v);
        }
    }

    /**
     * One tapered blade, as a filled lens.
     *
     * @param canvas $c the canvas
     * @param float $u root x as a fraction
     * @param float $v root y as a fraction
     * @param float $lengthmm blade length in millimetres
     * @param float $angle direction in radians
     * @param float $widthmm blade half width in millimetres
     * @return void
     */
    protected static function blade(canvas $c, float $u, float $v, float $lengthmm, float $angle,
            float $widthmm): void {
        $pts = [];
        $steps = 10;
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $offset = sin(M_PI * $t) * $widthmm;
            $along = $lengthmm * $t;
            $pts[] = $u + ($along * cos($angle) - $offset * sin($angle)) / $c->w();
            $pts[] = $v + ($along * sin($angle) + $offset * cos($angle)) / $c->h();
        }
        for ($i = $steps; $i >= 0; $i--) {
            $t = $i / $steps;
            $offset = -sin(M_PI * $t) * $widthmm * 0.35;
            $along = $lengthmm * $t;
            $pts[] = $u + ($along * cos($angle) - $offset * sin($angle)) / $c->w();
            $pts[] = $v + ($along * sin($angle) + $offset * cos($angle)) / $c->h();
        }
        $c->poly($pts, 'F');
    }

    /**
     * Wax Seal Frame: a scalloped border with a soft medallion behind the text.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_wax_seal_frame(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $wash = $c->colour('primarywash');

        ornament::tinted_ground($c, 0.7, 0.5, 0.45);

        $inset = 0.052 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();

        // The scalloped plate.
        $c->alpha(0.30);
        $c->fill($wash);
        ornament::scalloped_rect($c, $ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 0.020 * $c->s(), 'F');

        $c->alpha(0.9);
        $c->stroke($dark, 1.2);
        ornament::scalloped_rect($c, $ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 0.020 * $c->s(), 'D');

        $g = 0.016 * $c->s();
        $c->alpha(0.6);
        $c->stroke($primary, 0.45);
        $c->rect($ou + $g / $c->w(), $ov + $g / $c->h(),
            1 - 2 * ($ou + $g / $c->w()), 1 - 2 * ($ov + $g / $c->h()), 'D');

        // The medallion.
        $c->save();
        $c->clip($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov);
        $c->alpha(0.10);
        $c->stroke($primary, 0.30);
        ornament::rosette_family($c, 0.5, 0.47, 0.31 * $c->s(), 19, 7, 9, 0.38);
        $c->restore();

        $c->alpha(0.14);
        $c->stroke($primary, 0.6);
        $c->circle(0.5, 0.47, 0.315 * $c->s(), 0, 360, 'D');
    }

    /**
     * Cartouche: a double-rule frame with an ornamental panel behind the title.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_cartouche_title(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $wash = $c->colour('primarywash');
        $accent = $c->colour('accent');

        ornament::tinted_ground($c, 0.75, 0.5, 0.55);

        $inset = 0.046 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();

        $c->alpha(1.0);
        $c->stroke($dark, 1.7);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $g = 0.013 * $c->s();
        $c->alpha(0.65);
        $c->stroke($primary, 0.42);
        $c->rect($ou + $g / $c->w(), $ov + $g / $c->h(),
            1 - 2 * ($ou + $g / $c->w()), 1 - 2 * ($ov + $g / $c->h()), 'D');

        // The cartouche the title sits inside.
        // Positioned to sit behind the title, not above it.
        $cu = 0.19;
        $cv = 0.188;
        $cw = 0.62;
        $ch = 0.098;

        $c->alpha(0.55);
        $c->fill($wash);
        $c->rect($cu, $cv, $cw, $ch, 'F');

        $c->alpha(0.35);
        $c->stroke($primary, 0.3);
        ornament::strand_band($c, false, $cu, $cu + $cw, $cv + $ch / 2,
            $ch * 0.36 * $c->h(), 5, $primary, $accent, 0.30);

        $c->alpha(0.95);
        $c->stroke($dark, 0.9);
        $c->rect($cu, $cv, $cw, $ch, 'D');

        // Ends: a small diamond at each side of the cartouche.
        foreach ([$cu, $cu + $cw] as $du) {
            $ds = 0.013 * $c->s();
            $c->alpha(1.0);
            $c->fill($dark);
            $c->poly([$du, $cv + $ch / 2 - $ds / $c->h(), $du + $ds / $c->w(), $cv + $ch / 2,
                $du, $cv + $ch / 2 + $ds / $c->h(), $du - $ds / $c->w(), $cv + $ch / 2], 'F');
        }

        // A matching rule at the foot with a centred rosette.
        // Below the serial line, which the rosette used to collide with.
        $fv = 0.918;
        $c->alpha(0.7);
        $c->stroke($dark, 0.7);
        $c->line($cu, $fv, 0.5 - 0.055, $fv);
        $c->line(0.5 + 0.055, $fv, $cu + $cw, $fv);

        $c->alpha(0.75);
        $c->stroke($primary, 0.32);
        ornament::rosette_family($c, 0.5, $fv, 0.040 * $c->s(), 13, 5, 5, 0.42);
        $c->alpha(0.9);
        $c->stroke($dark, 0.6);
        $c->circle(0.5, $fv, 0.046 * $c->s(), 0, 360, 'D');
    }

    /**
     * Engine Field: a fine lathe pattern across the whole sheet.
     *
     * The real security background — a continuous engine-turned field, kept
     * pale enough to print text straight over it.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_engine_field(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');

        ornament::tinted_ground($c, 0.55, 0.5, 0.5);

        $inset = 0.040 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();

        $c->save();
        $c->clip($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov);

        // Bands stacked down the page, each a shifted phase family, so the
        // whole sheet reads as one continuous turning.
        $bands = 15;
        for ($b = 0; $b < $bands; $b++) {
            $centre = $ov + (1 - 2 * $ov) * (($b + 0.5) / $bands);
            ornament::strand_band($c, false, $ou, 1 - $ou, $centre,
                (1 - 2 * $ov) * $c->h() / $bands * 0.55, 5, $primary, $dark, 0.115, 0.26);
        }

        $c->restore();

        $c->alpha(1.0);
        $c->stroke($dark, 1.5);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $g = 0.012 * $c->s();
        $c->alpha(0.6);
        $c->stroke($primary, 0.4);
        $c->rect($ou + $g / $c->w(), $ov + $g / $c->h(),
            1 - 2 * ($ou + $g / $c->w()), 1 - 2 * ($ov + $g / $c->h()), 'D');
    }

    /**
     * Ceremonial Bands: ornamental bands at head and foot, sides left open.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_ceremonial_bands(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $accent = $c->colour('accent');
        $paper = $c->colour('paper');

        ornament::tinted_ground($c, 0.7, 0.5, 0.5);

        $band = 0.052 * $c->s();

        foreach ([[0.052, 1.0], [0.948, -1.0]] as $spec) {
            $centre = $spec[0];
            $dir = $spec[1];

            ornament::strand_band($c, false, 0.05, 0.95, $centre, $band * 0.44, 9,
                $dark, $primary, 0.55);

            $c->alpha(0.9);
            $c->stroke($dark, 1.2);
            $edge = $centre - $dir * ($band * 0.62) / $c->h();
            $c->line(0.05, $edge, 0.95, $edge);

            $c->alpha(0.5);
            $c->stroke($primary, 0.4);
            $inner = $centre + $dir * ($band * 0.62) / $c->h();
            $c->line(0.05, $inner, 0.95, $inner);

            // A rosette stop at each end of the band.
            foreach ([0.05, 0.95] as $eu) {
                $rr = $band * 0.62;
                $c->alpha(1.0);
                $c->fill($paper);
                $c->circle($eu, $centre, $rr, 0, 360, 'F');

                $c->alpha(0.6);
                $c->stroke($primary, 0.28);
                ornament::rosette_family($c, $eu, $centre, $rr * 0.88, 13, 5, 5, 0.42);

                $c->alpha(1.0);
                $c->stroke($dark, 1.0);
                $c->circle($eu, $centre, $rr, 0, 360, 'D');
                $c->fill($accent);
                $c->circle($eu, $centre, $rr * 0.13, 0, 360, 'F');
            }
        }
    }
}
