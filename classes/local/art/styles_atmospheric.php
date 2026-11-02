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
 * Atmospheric backgrounds: light and depth without objects.
 *
 * These are built almost entirely from the pale end of the palette, so they sit
 * as comfortably under a magenta brand as under a navy one. Where a smooth ramp
 * is wanted they use real PDF shadings rather than subdivided cells, because a
 * shading is both continuous and a fraction of the file size.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_atmospheric {
    /**
     * Aurora Field: columns of light rising from the outer edges of a pale page.
     *
     * Gradients fade to paper rather than to transparency, because PDF axial and
     * radial shadings carry no per-stop alpha; the translucency comes from the
     * alpha set around each object.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_aurora_field(canvas $c): void {
        $wash = $c->colour('primarywash');
        $light = $c->colour('primarylight');
        $accent = $c->colour('accent');
        $paper = $c->colour('paper');
        $rule = $c->colour('rule');

        $c->save();
        $c->clip(0.0, 0.0, 1.0, 1.0);

        // The ground is a flat tint plus real shadings rather than a subdivided
        // mesh. A mesh gives four-corner colour, but at any cell count cheap
        // enough to draw it banded visibly wherever a saturated node met a pale
        // one, and it cost a third of a megabyte of preview markup. Layered
        // axial and radial shadings are continuous by construction, a few
        // hundred bytes, and read as the same field of light.
        $c->alpha(0.34);
        $c->fill($wash);
        $c->rect(0.0, 0.0, 1.0, 1.0, 'F');

        // The curtains: tall radial shadings that read as columns of light.
        // Exactly one carries the accent, so a hue rotation stays a single
        // incident rather than a scheme.
        $accentcolumn = $c->rnd_int(3);
        foreach ([0.10, 0.27, 0.89] as $index => $base) {
            $uc = $base + ($c->rnd() - 0.5) * 0.05;
            if ($uc > 0.33 && $uc < 0.67) {
                $uc = 1.0 - $uc;
            }
            $c->alpha($index === $accentcolumn ? 0.26 : 0.30);
            $c->radial_gradient(
                $uc - 0.15,
                -0.05,
                0.30,
                1.10,
                $index === $accentcolumn ? $accent : $light,
                $paper,
                [0.5, 0.42, 0.0, 0.5, 0.42, 0.62]
            );
        }

        // One broad wash of light behind the curtains, seated on the long edge.
        $c->alpha(0.12);
        $c->radial_gradient(
            -0.10,
            0.30,
            0.70,
            0.90,
            $light,
            $paper,
            [0.5, 0.5, 0.0, 0.5, 0.5, 0.55]
        );

        // The floor, seating the bottom edge.
        $c->alpha(0.08);
        $c->linear_gradient(0.0, 0.0, 1.0, 1.0, $paper, $wash, [0.0, 0.0, 0.0, 1.0]);

        $c->restore();

        // The only hard edge in the composition.
        $c->alpha(0.55);
        $c->stroke($rule, 0.28);
        $c->line(0.06, 0.935, 0.94, 0.935);
    }
}
