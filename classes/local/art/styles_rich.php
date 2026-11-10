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
 * Backgrounds that actually occupy the page.
 *
 * The first thirty styles were built around a rule that the middle of the sheet
 * must be left almost blank. That rule was wrong. "Must not compete with the
 * text" is not the same as "must be empty": a watermark at eight percent, a
 * tinted field, or a white panel inset into a saturated ground are all
 * completely legible, and they are what a certificate that feels worth having
 * actually looks like.
 *
 * These six therefore carry real colour, real contrast and real ornament. Each
 * declares how text should sit on it — dark on light, reversed out of a
 * saturated ground, or on an inset panel — so the typography system can respond
 * rather than guess.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_rich {
    /**
     * Deep Field: the whole sheet in saturated brand colour, text reversed out.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_deep_field(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $light = $c->colour('primarylight');
        $ink = $c->colour('ink');
        $paper = $c->colour('paper');
        $accent = $c->colour('accent');

        $corner = $c->variant(4);

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        // A deep ground, not a tint.
        $c->alpha(1.0);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $ink, $dark,
            [$corner & 1 ? 1.0 : 0.0, 0.0, $corner & 1 ? 0.0 : 1.0, 1.0]);

        // Every shading is painted into a full-page rectangle, with the highlight
        // positioned through the gradient's own coordinates. Painting into a
        // smaller rectangle leaves the bounding box visible as a hard edge
        // wherever the gradient's terminal colour does not exactly match the
        // ground underneath it.
        $hu = ($corner & 1) ? 0.82 : 0.18;

        $c->alpha(0.60);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $primary, $dark,
            [$hu, 0.06, 0.0, $hu, 0.06, 0.62]);

        $c->alpha(0.32);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $accent, $dark,
            [1.0 - $hu, 0.92, 0.0, 1.0 - $hu, 0.92, 0.55]);

        $c->alpha(0.20);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $dark,
            [$hu, -0.10, 0.0, $hu, -0.10, 0.42]);

        $c->restore();

        // The frame: a double rule held off the trim, in paper.
        $inset = 0.052 * $c->s();
        $iu = $inset / $c->w();
        $iv = $inset / $c->h();

        $c->alpha(0.55);
        $c->stroke($paper, 1.1);
        $c->rect($iu, $iv, 1 - 2 * $iu, 1 - 2 * $iv, 'D');

        $c->alpha(0.28);
        $c->stroke($paper, 0.35);
        $gap = 0.014 * $c->s();
        $c->rect($iu + $gap / $c->w(), $iv + $gap / $c->h(),
            1 - 2 * ($iu + $gap / $c->w()), 1 - 2 * ($iv + $gap / $c->h()), 'D');

        // Corner ticks, so the frame reads as made rather than drawn.
        $arm = 0.030 * $c->s();
        $c->alpha(0.85);
        $c->stroke($paper, 1.4);
        foreach ([[0, 0, 1, 1], [1, 0, -1, 1], [0, 1, 1, -1], [1, 1, -1, -1]] as $k) {
            $cu = $k[0] ? 1 - $iu : $iu;
            $cv = $k[1] ? 1 - $iv : $iv;
            $c->line($cu, $cv, $cu + $k[2] * $arm / $c->w(), $cv);
            $c->line($cu, $cv, $cu, $cv + $k[3] * $arm / $c->h());
        }
    }

    /**
     * Engraved Plate: a dense guilloche frame with a medallion behind the text.
     *
     * The frame is four straight harmonic bands meeting at corner rosettes,
     * which is exactly how an engraved share certificate is actually built — the
     * rosettes exist to hide the joins.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_engraved_plate(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $wash = $c->colour('primarywash');

        $out = 0.048 * $c->s();
        $ou = $out / $c->w();
        $ov = $out / $c->h();
        $band = 0.040 * $c->s();

        // A warm field inside the frame, so the paper is not bare.
        $c->alpha(0.55);
        $c->fill($wash);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'F');

        // The medallion, sitting deliberately behind the text.
        $c->save();
        $c->clip($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov);
        // A high petal count with a modest pen offset. A low count and a large
        // offset gives a spiky star, which reads as clip art rather than as
        // engine turning.
        $c->alpha(0.11);
        $c->stroke($primary, 0.30);
        $mr = 0.30 * $c->s();
        for ($k = 0; $k < 9; $k++) {
            self::rosette($c, 0.5, 0.48, $mr, 17, 6, 0.40 * (1 + 0.035 * $k),
                $k * 2 * M_PI / (17 * 9));
        }
        $c->alpha(0.13);
        $c->stroke($primary, 0.5);
        $c->circle(0.5, 0.48, $mr * 0.42, 0, 360, 'D');
        $c->circle(0.5, 0.48, $mr * 0.40, 0, 360, 'D');
        $c->restore();

        // Four guilloche bands, one per edge.
        $inner = $out + $band;
        self::strand_band($c, false, $ou, 1 - $ou, ($out + $band / 2) / $c->h(),
            $band * 0.42, 7, $dark, $primary, 0.55);
        self::strand_band($c, false, $ou, 1 - $ou, 1 - ($out + $band / 2) / $c->h(),
            $band * 0.42, 7, $dark, $primary, 0.55);
        self::strand_band($c, true, $ov, 1 - $ov, ($out + $band / 2) / $c->w(),
            $band * 0.42, 7, $dark, $primary, 0.55);
        self::strand_band($c, true, $ov, 1 - $ov, 1 - ($out + $band / 2) / $c->w(),
            $band * 0.42, 7, $dark, $primary, 0.55);

        // The rules that contain the bands.
        $c->alpha(0.95);
        $c->stroke($dark, 1.5);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $c->alpha(0.75);
        $c->stroke($dark, 0.45);
        $c->rect($inner / $c->w(), $inner / $c->h(),
            1 - 2 * $inner / $c->w(), 1 - 2 * $inner / $c->h(), 'D');

        $c->alpha(0.40);
        $c->stroke($primary, 0.3);
        $tight = $inner + 0.012 * $c->s();
        $c->rect($tight / $c->w(), $tight / $c->h(),
            1 - 2 * $tight / $c->w(), 1 - 2 * $tight / $c->h(), 'D');

        // Corner rosettes covering the joins.
        $rr = $band * 0.86;
        foreach ([[$ou, $ov], [1 - $ou, $ov], [$ou, 1 - $ov], [1 - $ou, 1 - $ov]] as $corner) {
            $c->alpha(0.95);
            $c->fill($c->colour('paper'));
            $c->circle($corner[0], $corner[1], $rr, 0, 360, 'F');

            $c->alpha(0.62);
            $c->stroke($dark, 0.32);
            for ($k = 0; $k < 5; $k++) {
                self::rosette($c, $corner[0], $corner[1], $rr * 0.92, 7, 2,
                    0.70 * (1 + 0.06 * $k), $k * 2 * M_PI / (7 * 5));
            }
            $c->alpha(0.95);
            $c->stroke($dark, 0.8);
            $c->circle($corner[0], $corner[1], $rr, 0, 360, 'D');
            $c->alpha(0.9);
            $c->fill($primary);
            $c->circle($corner[0], $corner[1], $rr * 0.16, 0, 360, 'F');
        }
    }

    /**
     * Medallion: a large tonal rosette behind the text, with a solid footer.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_medallion_watermark(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $wash = $c->colour('primarywash');
        $accent = $c->colour('accent');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.40);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $wash, $c->colour('paper'),
            [0.5, 0.0, 0.5, 1.0]);

        // Rays from the centre, gathered behind the text.
        $c->alpha(0.07);
        $c->stroke($primary, 0.4);
        for ($i = 0; $i < 72; $i++) {
            $a = deg2rad($i * 5 + 2.5);
            $r0 = 0.10 * $c->s();
            $r1 = 0.92 * $c->l();
            $c->line(0.5 + $r0 * cos($a) / $c->w(), 0.46 - $r0 * sin($a) / $c->h(),
                0.5 + $r1 * cos($a) / $c->w(), 0.46 - $r1 * sin($a) / $c->h());
        }

        // The medallion itself.
        $mr = 0.40 * $c->s();
        $c->alpha(0.12);
        $c->stroke($primary, 0.32);
        for ($k = 0; $k < 11; $k++) {
            self::rosette($c, 0.5, 0.46, $mr, 19, 7, 0.38 * (1 + 0.03 * $k),
                $k * 2 * M_PI / (19 * 11));
        }

        $c->alpha(0.16);
        $c->stroke($primary, 0.7);
        $c->circle(0.5, 0.46, $mr * 0.98, 0, 360, 'D');
        $c->circle(0.5, 0.46, $mr * 0.93, 0, 360, 'D');
        $c->circle(0.5, 0.46, $mr * 0.30, 0, 360, 'D');

        $c->restore();

        // A header rule and a solid footer, so the page has architecture.
        $c->alpha(0.95);
        $c->fill($dark);
        $c->rect(0.0, 0.928, 1.0, 0.072, 'F');

        $c->alpha(0.9);
        $c->fill($accent);
        $c->rect(0.0, 0.918, 1.0, 0.010, 'F');

        $c->alpha(0.85);
        $c->stroke($dark, 1.6);
        $c->line(0.0, 0.055, 1.0, 0.055);
        $c->alpha(0.55);
        $c->stroke($primary, 0.5);
        $c->line(0.0, 0.064, 1.0, 0.064);
    }

    /**
     * Card Panel: a saturated ground with the text on an inset white card.
     *
     * Solves legibility and richness at once — the ground can be as strong as it
     * likes because no text ever sits on it.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_card_panel(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $light = $c->colour('primarylight');
        $accent = $c->colour('accent');
        $ink = $c->colour('ink');
        $paper = $c->colour('paper');

        $flip = $c->variant(2) === 1;

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(1.0);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $dark, $primary,
            $flip ? [1.0, 0.0, 0.0, 1.0] : [0.0, 0.0, 1.0, 1.0]);

        // Full-page shadings, positioned by their own coordinates, so no
        // bounding box can show as an edge.
        $hu = $flip ? 0.86 : 0.14;

        $c->alpha(0.52);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $primary,
            [$hu, 0.04, 0.0, $hu, 0.04, 0.58]);

        $c->alpha(0.36);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $accent, $primary,
            [1.0 - $hu, 0.86, 0.0, 1.0 - $hu, 0.86, 0.52]);

        $c->alpha(0.24);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $ink, $primary,
            [0.5, 1.15, 0.0, 0.5, 1.15, 0.60]);

        $c->restore();

        // The card the text sits on.
        $mu = 0.062 * $c->s() / $c->w();
        $mv = 0.062 * $c->s() / $c->h();

        $c->alpha(0.16);
        $c->fill($ink);
        $c->rect($mu + 0.004, $mv + 0.005, 1 - 2 * $mu, 1 - 2 * $mv, 'F');

        $c->alpha(1.0);
        $c->fill($paper);
        $c->rect($mu, $mv, 1 - 2 * $mu, 1 - 2 * $mv, 'F');

        // A hairline and a colour edge on the card.
        $c->alpha(0.30);
        $c->stroke($primary, 0.4);
        $c->rect($mu, $mv, 1 - 2 * $mu, 1 - 2 * $mv, 'D');

        $c->alpha(0.95);
        $c->fill($accent);
        $c->rect($mu, $mv, 1 - 2 * $mu, 0.008, 'F');
    }

    /**
     * Column: a solid brand block down one edge against a clean text field.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_colour_column(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $light = $c->colour('primarylight');
        $accent = $c->colour('accent');
        $wash = $c->colour('primarywash');
        $paper = $c->colour('paper');

        // The block always sits on the left. It used to mirror with the seed,
        // but the catalogue declares where this style leaves room for text, and
        // a variant that moves the block invalidates that declaration — the
        // recipient's name ended up printed straight across it.
        $flip = false;
        $bw = $c->by_orientation(0.28, 0.22);
        $mu = function (float $u): float {
            return $u;
        };

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        // The text field, tinted rather than bare.
        $c->alpha(0.35);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $wash, $paper, [0.0, 0.0, 1.0, 0.6]);

        // The block.
        $c->alpha(1.0);
        $c->save();
        $c->clip(0.0, 0.0, $bw, 1.0);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $dark, $primary, [0.0, 0.0, 0.4, 1.0]);
        $c->alpha(0.42);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $primary,
            [0.10, 0.06, 0.0, 0.10, 0.06, 0.55]);
        $c->restore();

        // Fine rules inside the block, reversed out.
        $c->alpha(0.22);
        $c->stroke($paper, 0.35);
        for ($i = 1; $i <= 16; $i++) {
            $v = 0.06 + $i * 0.055;
            if ($v > 0.96) {
                break;
            }
            $c->line($mu(0.03), $v, $mu($bw - 0.03), $v);
        }

        $c->alpha(0.9);
        $c->stroke($paper, 1.2);
        $c->line($mu(0.03), 0.06, $mu($bw - 0.03), 0.06);
        $c->line($mu(0.03), 0.94, $mu($bw - 0.03), 0.94);

        $c->restore();

        // The seam, and a counterweight in the far corner.
        $c->alpha(1.0);
        $c->fill($accent);
        $c->rect($bw, 0.0, 0.006, 1.0, 'F');

        $c->alpha(0.9);
        $c->fill($primary);
        $c->rect(0.86, 0.0, 0.14, 0.020, 'F');

        $c->alpha(0.45);
        $c->stroke($primary, 0.5);
        $c->line($mu($bw + 0.045), 0.930, $mu(0.96), 0.930);
    }

    /**
     * Crest: a symmetrical ornamental frame with a cartouche at the head.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_crest_frame(canvas $c): void {
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');
        $wash = $c->colour('primarywash');
        $accent = $c->colour('accent');
        $paper = $c->colour('paper');

        $ou = 0.044 * $c->s() / $c->w();
        $ov = 0.044 * $c->s() / $c->h();

        $c->alpha(0.45);
        $c->fill($wash);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'F');

        // A soft vignette toward the foot, so the field is not flat.
        $c->save();
        $c->clip($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov);
        $c->alpha(0.30);
        $c->radial_gradient(-0.10, 0.30, 1.20, 1.05, $paper, $wash,
            [0.5, 0.5, 0.0, 0.5, 0.5, 0.52]);
        $c->restore();

        // The frame: heavy, fine, and a dotted third.
        $c->alpha(1.0);
        $c->stroke($dark, 2.0);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $g1 = 0.016 * $c->s();
        $c->alpha(0.70);
        $c->stroke($primary, 0.5);
        $c->rect($ou + $g1 / $c->w(), $ov + $g1 / $c->h(),
            1 - 2 * ($ou + $g1 / $c->w()), 1 - 2 * ($ov + $g1 / $c->h()), 'D');

        $g2 = 0.026 * $c->s();
        $c->alpha(0.45);
        $c->stroke($primary, 0.4, [1.2, 2.2]);
        $c->rect($ou + $g2 / $c->w(), $ov + $g2 / $c->h(),
            1 - 2 * ($ou + $g2 / $c->w()), 1 - 2 * ($ov + $g2 / $c->h()), 'D');

        // Corner fans: denser, and weighted so they read as ornament rather
        // than as a few stray rules.
        $fan = 0.082 * $c->s();
        foreach ([[$ou, $ov, 1, 1], [1 - $ou, $ov, -1, 1], [$ou, 1 - $ov, 1, -1],
                [1 - $ou, 1 - $ov, -1, -1]] as $k) {
            $c->alpha(0.62);
            $c->stroke($primary, 0.45);
            for ($i = 0; $i <= 15; $i++) {
                $a = deg2rad($i * (90 / 15));
                $len = $fan * (0.62 + 0.38 * sin(deg2rad($i * (180 / 15))));
                $c->line($k[0], $k[1],
                    $k[0] + $k[2] * $len * cos($a) / $c->w(),
                    $k[1] + $k[3] * $len * sin($a) / $c->h());
            }
            $c->alpha(0.9);
            $c->stroke($dark, 0.9);
            $c->circle($k[0], $k[1], $fan * 0.34, 0, 360, 'D');
            $c->circle($k[0], $k[1], $fan * 0.30, 0, 360, 'D');
            $c->alpha(1.0);
            $c->fill($accent);
            $c->circle($k[0], $k[1], $fan * 0.12, 0, 360, 'F');
        }

        // A rosette medallion at the head, sitting on the frame rather than in
        // an empty box. The white cartouche it replaced read as an unfinished
        // placeholder waiting for a logo.
        $rr = 0.070 * $c->s();
        $c->alpha(1.0);
        $c->fill($paper);
        $c->circle(0.5, $ov, $rr * 1.16, 0, 360, 'F');

        $c->alpha(0.72);
        $c->stroke($primary, 0.36);
        for ($k = 0; $k < 7; $k++) {
            self::rosette($c, 0.5, $ov, $rr, 13, 5, 0.42 * (1 + 0.05 * $k),
                $k * 2 * M_PI / (13 * 7));
        }

        $c->alpha(1.0);
        $c->stroke($dark, 1.3);
        $c->circle(0.5, $ov, $rr * 1.16, 0, 360, 'D');
        $c->alpha(0.8);
        $c->stroke($dark, 0.4);
        $c->circle(0.5, $ov, $rr * 1.30, 0, 360, 'D');

        // A foot rule with a centred diamond.
        $c->alpha(0.85);
        $c->stroke($dark, 0.8);
        $c->line(0.5 - 0.22, 1 - $ov - 0.030 * $c->s() / $c->h(),
            0.5 + 0.22, 1 - $ov - 0.030 * $c->s() / $c->h());
        $dv = 1 - $ov - 0.030 * $c->s() / $c->h();
        $ds = 0.014 * $c->s();
        $c->alpha(1.0);
        $c->fill($dark);
        $c->poly([0.5, $dv - $ds / $c->h(), 0.5 + $ds / $c->w(), $dv,
            0.5, $dv + $ds / $c->h(), 0.5 - $ds / $c->w(), $dv], 'F');
    }

    /**
     * One hypotrochoid trace, ungated.
     *
     * @param canvas $c the canvas
     * @param float $cu centre as a width fraction
     * @param float $cv centre as a height fraction
     * @param float $bigr the fixed wheel radius in millimetres
     * @param int $p petals
     * @param int $q turns
     * @param float $offset pen offset as a fraction of the rolling radius
     * @param float $phase rotation in radians
     * @return void
     */
    protected static function rosette(canvas $c, float $cu, float $cv, float $bigr, int $p,
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
     * A band of engine-turned strands along one edge.
     *
     * @param canvas $c the canvas
     * @param bool $vertical whether the band runs down the page
     * @param float $from start of the band along its axis, as a fraction
     * @param float $to end of the band along its axis, as a fraction
     * @param float $centre the band centreline across its axis, as a fraction
     * @param float $ampmm the strand amplitude in millimetres
     * @param int $strands how many strands
     * @param string $colour the main strand colour
     * @param string $brandcolour the colour for the two brand strands
     * @param float $alpha the base alpha
     * @return void
     */
    protected static function strand_band(canvas $c, bool $vertical, float $from, float $to,
            float $centre, float $ampmm, int $strands, string $colour, string $brandcolour,
            float $alpha): void {
        $f1 = 6.0;
        $f2 = 13.0;
        $f3 = 21.0;
        $across = $vertical ? $c->w() : $c->h();

        for ($k = 0; $k < $strands; $k++) {
            $phi = 2 * M_PI * $k / $strands;
            $brand = ($k === 2 || $k === 5);
            $c->alpha($brand ? $alpha : $alpha * ($k % 2 === 0 ? 1.0 : 0.68));
            $c->stroke($brand ? $brandcolour : $colour, 0.3);

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
}
