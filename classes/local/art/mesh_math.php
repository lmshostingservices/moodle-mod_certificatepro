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
 * Bilinear interpolation across a quad, shared by both painters.
 *
 * A gradient mesh is the one effect that makes generated artwork look designed
 * rather than generated, but TCPDF's Coons patch and SVG have no common ground.
 * Both painters therefore subdivide the same way using this class, so the
 * preview and the print agree.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mesh_math {
    /**
     * A point inside a quad at parameters s and t.
     *
     * @param float[] $quad x, y for four corners clockwise from top left
     * @param float $s horizontal parameter, 0 to 1
     * @param float $t vertical parameter, 0 to 1
     * @return float[] the x and y
     */
    public static function point(array $quad, float $s, float $t): array {
        $topx = $quad[0] + ($quad[2] - $quad[0]) * $s;
        $topy = $quad[1] + ($quad[3] - $quad[1]) * $s;
        $botx = $quad[6] + ($quad[4] - $quad[6]) * $s;
        $boty = $quad[7] + ($quad[5] - $quad[7]) * $s;
        return [$topx + ($botx - $topx) * $t, $topy + ($boty - $topy) * $t];
    }

    /**
     * The blended colour inside a quad at parameters s and t.
     *
     * @param array[] $rgb four RGB triplets clockwise from top left
     * @param float $s horizontal parameter, 0 to 1
     * @param float $t vertical parameter, 0 to 1
     * @return int[] the blended RGB triplet
     */
    public static function colour(array $rgb, float $s, float $t): array {
        $out = [];
        for ($c = 0; $c < 3; $c++) {
            $top = $rgb[0][$c] + ($rgb[1][$c] - $rgb[0][$c]) * $s;
            $bot = $rgb[3][$c] + ($rgb[2][$c] - $rgb[3][$c]) * $s;
            $out[] = (int) round(max(0, min(255, $top + ($bot - $top) * $t)));
        }
        return $out;
    }
}
