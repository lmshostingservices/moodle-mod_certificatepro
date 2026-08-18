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
 * Turns move, line, cubic and close segments into polylines.
 *
 * TCPDF has no general path API. Rather than chain Polycurve calls and hope,
 * curves are sampled into a dense polyline, which at print resolution is
 * indistinguishable from the true curve and behaves identically whether it is
 * filled or stroked.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_flattener {
    /** @var int Samples per cubic segment. */
    protected const SAMPLES = 18;

    /**
     * Flatten a path into one or more subpaths of millimetre coordinates.
     *
     * @param array[] $segments segments as ['M', x, y], ['L', x, y], ['C', ...] or ['Z']
     * @return array[] each entry has a flat points array and a closed flag
     */
    public static function flatten(array $segments): array {
        $subpaths = [];
        $points = [];
        $cx = 0.0;
        $cy = 0.0;

        foreach ($segments as $seg) {
            $cmd = (string) ($seg[0] ?? '');

            if ($cmd === 'M') {
                if (count($points) >= 4) {
                    $subpaths[] = ['points' => $points, 'closed' => false];
                }
                $cx = (float) ($seg[1] ?? 0);
                $cy = (float) ($seg[2] ?? 0);
                $points = [$cx, $cy];
                continue;
            }

            if ($cmd === 'L') {
                $cx = (float) ($seg[1] ?? 0);
                $cy = (float) ($seg[2] ?? 0);
                $points[] = $cx;
                $points[] = $cy;
                continue;
            }

            if ($cmd === 'C') {
                $x1 = (float) ($seg[1] ?? 0);
                $y1 = (float) ($seg[2] ?? 0);
                $x2 = (float) ($seg[3] ?? 0);
                $y2 = (float) ($seg[4] ?? 0);
                $x3 = (float) ($seg[5] ?? 0);
                $y3 = (float) ($seg[6] ?? 0);
                for ($i = 1; $i <= self::SAMPLES; $i++) {
                    $t = $i / self::SAMPLES;
                    $m = 1.0 - $t;
                    $points[] = $m * $m * $m * $cx + 3 * $m * $m * $t * $x1
                        + 3 * $m * $t * $t * $x2 + $t * $t * $t * $x3;
                    $points[] = $m * $m * $m * $cy + 3 * $m * $m * $t * $y1
                        + 3 * $m * $t * $t * $y2 + $t * $t * $t * $y3;
                }
                $cx = $x3;
                $cy = $y3;
                continue;
            }

            if ($cmd === 'Z') {
                if (count($points) >= 4) {
                    $subpaths[] = ['points' => $points, 'closed' => true];
                }
                $points = [];
            }
        }

        if (count($points) >= 4) {
            $subpaths[] = ['points' => $points, 'closed' => false];
        }

        return $subpaths;
    }
}
