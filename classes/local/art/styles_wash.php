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
 * Soft tinted grounds.
 *
 * Quieter than the ceremonial set and far more present than bare paper: washes,
 * blooms, tonal bands and watermarks that fill the sheet at ten to twenty
 * percent. Text prints straight over them.
 *
 * Every shading here is painted into a full-page rectangle with its highlight
 * placed through the gradient's own coordinates. Painting into a smaller
 * rectangle leaves the bounding box visible as a hard edge wherever the
 * gradient's terminal colour does not exactly match what is underneath.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_wash {
    /**
     * Wash: a single soft ramp down the sheet, with a hairline frame.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_wash_gradient(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $paper = $c->colour('paper');
        $primary = $c->colour('primary');

        $down = $c->variant(2) === 0;

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.90);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $wash, $paper,
            $down ? [0.5, 1.0, 0.5, 0.0] : [0.5, 0.0, 0.5, 1.0]);

        $c->alpha(0.20);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $light, $paper,
            $down ? [0.5, 1.0, 0.5, 0.28] : [0.5, 0.0, 0.5, 0.72]);

        $c->restore();

        $inset = 0.042 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();

        $c->alpha(0.45);
        $c->stroke($primary, 0.4);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');

        $c->alpha(0.85);
        $c->fill($primary);
        $c->rect(0.0, $down ? 0.982 : 0.0, 1.0, 0.018, 'F');
    }

    /**
     * Corner Bloom: light gathering into two opposite corners.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_corner_bloom(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $accent = $c->colour('accent');
        $paper = $c->colour('paper');

        $flip = $c->variant(2) === 1;
        $au = $flip ? 0.94 : 0.06;
        $bu = 1.0 - $au;

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.35);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        $c->alpha(0.55);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $paper,
            [$au, 0.04, 0.0, $au, 0.04, 0.62]);

        $c->alpha(0.34);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $accent, $paper,
            [$bu, 0.96, 0.0, $bu, 0.96, 0.55]);

        $c->alpha(0.22);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $wash, $paper,
            [0.5, 0.5, 0.0, 0.5, 0.5, 0.78]);

        $c->restore();
    }

    /**
     * Tonal Bands: broad horizontal bands of tone with soft joins.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_tonal_bands(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $paper = $c->colour('paper');
        $primary = $c->colour('primary');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.25);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        // Overlapping full-page ramps rather than one clipped ramp per band.
        // Clipping each band to its own strip put a hard rule at every join,
        // which is the opposite of what a tonal band is for.
        $ramps = [
            [$wash, 0.55, [0.5, 0.30, 0.5, 0.00]],
            [$light, 0.26, [0.5, 0.30, 0.5, 0.62]],
            [$wash, 0.45, [0.5, 0.72, 0.5, 1.00]],
            [$light, 0.18, [0.5, 0.86, 0.5, 0.52]],
        ];
        foreach ($ramps as $ramp) {
            $c->alpha($ramp[1]);
            $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $ramp[0], $paper, $ramp[2]);
        }

        $c->restore();

        $c->alpha(0.55);
        $c->stroke($primary, 0.45);
        $c->line(0.06, 0.40, 0.94, 0.40);
        $c->alpha(0.30);
        $c->stroke($primary, 0.3);
        $c->line(0.06, 0.84, 0.94, 0.84);
    }

    /**
     * Vignette: a bright centre falling away to tone at every edge.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_vignette_field(canvas $c): void {
        $wash = $c->colour('primarywash');
        $primary = $c->colour('primary');
        $paper = $c->colour('paper');
        $light = $c->colour('primarylight');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.85);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        // The bright centre, slightly above the middle where a page's optical
        // centre actually sits.
        $c->alpha(0.95);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $paper, $wash,
            [0.5, 0.455, 0.0, 0.5, 0.455, 0.56]);

        $c->alpha(0.28);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $paper,
            [0.5, 0.455, 0.0, 0.5, 0.455, 0.85]);

        $c->restore();

        $inset = 0.036 * $c->s();
        $ou = $inset / $c->w();
        $ov = $inset / $c->h();
        $c->alpha(0.40);
        $c->stroke($primary, 0.35);
        $c->rect($ou, $ov, 1 - 2 * $ou, 1 - 2 * $ov, 'D');
    }

    /**
     * Duotone Drift: the brand hue and its accent blending across the sheet.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_duotone_drift(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $accent = $c->colour('accent');
        $paper = $c->colour('paper');

        $corner = $c->variant(4);
        $au = ($corner & 1) ? 0.88 : 0.12;
        $av = ($corner & 2) ? 0.86 : 0.14;

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.40);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        $c->alpha(0.42);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $paper,
            [$au, $av, 0.0, $au, $av, 0.78]);

        $c->alpha(0.34);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $accent, $paper,
            [1 - $au, 1 - $av, 0.0, 1 - $au, 1 - $av, 0.74]);

        // A soft diagonal ramp tying the two together.
        $c->alpha(0.16);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $accent, $light,
            [$au, $av, 1 - $au, 1 - $av]);

        $c->restore();
    }

    /**
     * Laid Paper: chain lines and a fine tonal mottle, like a good sheet.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_laid_paper(canvas $c): void {
        $wash = $c->colour('primarywash');
        $primary = $c->colour('primary');
        $muted = $c->colour('muted');
        $paper = $c->colour('paper');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.62);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        $c->alpha(0.30);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $paper, $wash,
            [0.42, 0.40, 0.0, 0.42, 0.40, 0.70]);

        // Laid lines: fine and close, the way a mould-made sheet shows them.
        $c->alpha(0.10);
        $c->stroke($primary, 0.20);
        $pitch = 1.05;
        $count = (int) floor($c->h() / $pitch);
        for ($i = 0; $i <= $count; $i++) {
            $v = ($i * $pitch) / $c->h();
            $c->line(0.0, $v, 1.0, $v);
        }

        // Chain lines: widely spaced and slightly heavier.
        // Kept faint: at full strength the two rulings together read as
        // graph paper rather than as a mould-made sheet.
        $c->alpha(0.09);
        $c->stroke($primary, 0.30);
        $chain = 26.0;
        $chains = (int) floor($c->w() / $chain);
        for ($i = 1; $i <= $chains; $i++) {
            $u = ($i * $chain) / $c->w();
            $c->line($u, 0.0, $u, 1.0);
        }

        // A fine mottle, so the sheet is not perfectly even.
        $c->alpha(0.10);
        $c->fill($muted);
        for ($i = 0; $i < 900; $i++) {
            $u = $c->rnd();
            $v = $c->rnd();
            $c->circle($u, $v, 0.12 + 0.22 * $c->rnd(), 0, 360, 'F');
        }

        $c->restore();
    }

    /**
     * Watermark: one large soft tonal figure sitting behind the whole text block.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_watermark_field(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $primary = $c->colour('primary');
        $paper = $c->colour('paper');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        $c->alpha(0.50);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $wash, $paper, [0.5, 1.0, 0.5, 0.0]);

        $c->alpha(0.26);
        $c->radial_gradient(0.0, 0.0, 1.0, 1.0, $light, $paper,
            [0.5, 0.46, 0.0, 0.5, 0.46, 0.62]);

        // The figure: a broad ring of overlapping soft discs, which reads as a
        // watermark impressed into the sheet rather than as a drawn shape.
        $r = 0.30 * $c->s();
        $c->alpha(0.05);
        $c->fill($primary);
        for ($i = 0; $i < 18; $i++) {
            $a = deg2rad($i * 20);
            $c->circle(0.5 + ($r * 0.62 * cos($a)) / $c->w(),
                0.46 - ($r * 0.62 * sin($a)) / $c->h(), $r * 0.52, 0, 360, 'F');
        }

        $c->alpha(0.07);
        $c->stroke($primary, 0.5);
        $c->circle(0.5, 0.46, $r * 1.14, 0, 360, 'D');
        $c->circle(0.5, 0.46, $r * 1.08, 0, 360, 'D');
        $c->circle(0.5, 0.46, $r * 0.34, 0, 360, 'D');

        $c->restore();

        $c->alpha(0.80);
        $c->stroke($primary, 0.5);
        $c->line(0.07, 0.940, 0.93, 0.940);
        $c->alpha(0.40);
        $c->stroke($primary, 0.3);
        $c->line(0.07, 0.950, 0.93, 0.950);
    }
}
