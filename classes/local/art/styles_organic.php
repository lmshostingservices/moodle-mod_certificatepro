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
 * Organic backgrounds built from natural systems.
 *
 * Flow fields, contour lines, cell structures, drainage networks and stipple.
 * Every one dissolves as it approaches the text rather than being cut off at a
 * rectangle, so the clearance reads as part of the drawing.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class styles_organic {
    /**
     * Build a smooth deterministic noise function from three sinusoids.
     *
     * A sum of low-frequency sinusoids is infinitely differentiable, so the
     * field reads as a soft current rather than as ripples, and it needs no
     * lookup table.
     *
     * @param canvas $c the canvas, used as the source of seeded parameters
     * @param int $terms how many sinusoidal terms
     * @return callable the field, taking u and v and returning 0 to 1
     */
    protected static function noise(canvas $c, int $terms = 3): callable {
        $k = [];
        $p = [];
        for ($i = 0; $i < $terms * 2; $i++) {
            $k[] = 0.5 + 1.6 * $c->rnd();
        }
        for ($i = 0; $i < $terms; $i++) {
            $p[] = 2 * M_PI * $c->rnd();
        }
        $weights = [0.55, 0.30, 0.15, 0.10];

        return function (float $u, float $v) use ($k, $p, $terms, $weights): float {
            $sum = 0.0;
            $total = 0.0;
            for ($i = 0; $i < $terms; $i++) {
                $w = $weights[$i] ?? 0.1;
                $sign = ($i % 2 === 1) ? -1.0 : 1.0;
                $sum += $w * sin(2 * M_PI * ($k[$i * 2] * $u + $sign * $k[$i * 2 + 1] * $v) + $p[$i]);
                $total += $w;
            }
            return 0.5 + 0.5 * ($sum / max(0.0001, $total));
        };
    }

    /**
     * Drift Field: streamlines tracing the path of a particle through a current.
     *
     * Integrated in one direction only. Integrating backwards as well would make
     * it read as hair rather than as current.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_drift_field(canvas $c): void {
        // Seated on a tinted ground. On bare white this read as a nearly
        // empty sheet, which is what the whole first set got wrong.
        ornament::tinted_ground($c, 0.7, 0.5, 0.35);

        $noise = self::noise($c, 3);
        $bias = 0.10 * M_PI;
        $primary = $c->colour('primary');
        $muted = $c->colour('muted');
        $dark = $c->colour('primarydark');

        $cols = 18;
        $rows = 10;
        $step = 0.0042 * $c->l();

        $starts = [];
        for ($i = 0; $i < $cols; $i++) {
            for ($j = 0; $j < $rows; $j++) {
                $u = ($i + 0.5 + ($c->rnd() - 0.5) * 0.85) / $cols;
                $v = ($j + 0.5 + ($c->rnd() - 0.5) * 0.85) / $rows;
                if (background_library::quiet_metric($u, $v) < 1.0) {
                    continue;
                }
                $starts[] = [$u, $v,
                    pow($u - 0.5, 2) + pow($v - 0.5, 2)];
            }
        }

        // The nine that begin furthest out become the emphasis lines.
        $ranked = $starts;
        usort(
            $ranked,
            function ($a, $b) {
                return $b[2] <=> $a[2];
            }
        );
        $emphasis = [];
        foreach (array_slice($ranked, 0, 9) as $e) {
            $emphasis[$e[0] . ':' . $e[1]] = true;
        }

        foreach ($starts as $index => $start) {
            $hero = isset($emphasis[$start[0] . ':' . $start[1]]);
            $c->alpha($hero ? 0.40 : 0.20 + 0.22 * $c->rnd());
            $c->stroke(
                $hero ? $dark : ($index % 3 === 0 ? $muted : $primary),
                $hero ? 0.62 : 0.26 + 0.18 * ($index % 3) / 2
            );

            $u = $start[0];
            $v = $start[1];
            $pts = [$u, $v];
            $limit = $hero ? 90 : 55;

            for ($s = 0; $s < $limit; $s++) {
                $theta = 2 * M_PI * $noise($u, $v) + $bias;
                $u += $step * cos($theta) / $c->w();
                $v += $step * sin($theta) / $c->h();

                if ($u < -0.03 || $u > 1.03 || $v < -0.03 || $v > 1.03) {
                    break;
                }
                if (background_library::quiet_gate($u, $v, 0.90, 0.24) <= 0.0) {
                    break;
                }
                $pts[] = $u;
                $pts[] = $v;
            }

            if (count($pts) >= 6) {
                $c->polyline($pts, 'D');
            }
        }
    }

    /**
     * Isoline Terrain: contour lines describing a landscape that does not exist.
     *
     * The field is biased upward through the middle of the page, so the centre
     * becomes a smooth plateau with no contours at all — geologically coherent,
     * rather than an obvious hole cut in a pattern.
     *
     * @param canvas $c the canvas
     * @return void
     */
    public static function draw_isoline_terrain(canvas $c): void {
        ornament::tinted_ground($c, 0.7, 0.35, 0.30);

        $noise = self::noise($c, 4);
        $primary = $c->colour('primary');
        $dark = $c->colour('primarydark');

        $gx = (int) $c->by_orientation(84, 60);
        $gy = (int) $c->by_orientation(60, 84);

        $field = [];
        for ($i = 0; $i <= $gx; $i++) {
            for ($j = 0; $j <= $gy; $j++) {
                $u = $i / $gx;
                $v = $j / $gy;
                $qz = background_library::quiet_metric($u, $v);
                $lift = 1.6 * pow(max(0.0, min(1.0, 1.0 - $qz)), 2);
                $field[$i][$j] = ($noise($u, $v) * 2 - 1) + $lift;
            }
        }

        $longest = [];
        $longestlen = 0.0;

        for ($lv = 0; $lv < 13; $lv++) {
            $level = -0.84 + $lv * 0.14;
            $even = ($lv % 2 === 0);
            $c->alpha($even ? 0.24 : 0.30);
            $c->stroke($even ? $dark : $primary, $even ? 0.45 : 0.24);

            $segments = [];
            for ($i = 0; $i < $gx; $i++) {
                for ($j = 0; $j < $gy; $j++) {
                    $seg = self::marching_square($field, $i, $j, $gx, $gy, $level);
                    if ($seg !== null) {
                        $segments[] = $seg;
                    }
                }
            }

            foreach (self::chain($segments) as $chainpts) {
                if (count($chainpts) < 6) {
                    continue;
                }
                $filtered = [];
                for ($n = 0; $n + 1 < count($chainpts); $n += 2) {
                    if (background_library::quiet_metric($chainpts[$n], $chainpts[$n + 1]) < 1.0) {
                        if (count($filtered) >= 6) {
                            $c->polyline($filtered, 'D');
                        }
                        $filtered = [];
                        continue;
                    }
                    $filtered[] = $chainpts[$n];
                    $filtered[] = $chainpts[$n + 1];
                }
                if (count($filtered) >= 6) {
                    $c->polyline($filtered, 'D');
                    if (count($filtered) > $longestlen) {
                        $longestlen = count($filtered);
                        $longest = $filtered;
                    }
                }
            }
        }

        // One deliberate index line, drawn on top.
        if ($longest) {
            $c->alpha(0.45);
            $c->stroke($dark, 0.85);
            $c->polyline($longest, 'D');
        }
    }

    /**
     * One marching-squares cell, returning at most a single segment.
     *
     * The four ambiguous saddle cases are resolved by the mean of the corners,
     * which is what stops contours crossing one another.
     *
     * @param array[] $f the sampled field
     * @param int $i cell column
     * @param int $j cell row
     * @param int $gx columns
     * @param int $gy rows
     * @param float $level the contour level
     * @return float[]|null four coordinates, or null if the cell is not crossed
     */
    protected static function marching_square(array $f, int $i, int $j, int $gx, int $gy,
            float $level): ?array {
        $a = $f[$i][$j];
        $b = $f[$i + 1][$j];
        $cc = $f[$i + 1][$j + 1];
        $d = $f[$i][$j + 1];

        $code = ($a > $level ? 1 : 0) | ($b > $level ? 2 : 0)
            | ($cc > $level ? 4 : 0) | ($d > $level ? 8 : 0);
        if ($code === 0 || $code === 15) {
            return null;
        }

        $u0 = $i / $gx;
        $u1 = ($i + 1) / $gx;
        $v0 = $j / $gy;
        $v1 = ($j + 1) / $gy;

        $lerp = function (float $p0, float $p1, float $va, float $vb) use ($level): float {
            $t = ($vb - $va);
            if (abs($t) < 1e-9) {
                return $p0;
            }
            return $p0 + ($p1 - $p0) * (($level - $va) / $t);
        };

        $top = [$lerp($u0, $u1, $a, $b), $v0];
        $right = [$u1, $lerp($v0, $v1, $b, $cc)];
        $bottom = [$lerp($u0, $u1, $d, $cc), $v1];
        $left = [$u0, $lerp($v0, $v1, $a, $d)];

        $pairs = [
            1 => [$left, $top], 2 => [$top, $right], 3 => [$left, $right],
            4 => [$right, $bottom], 6 => [$top, $bottom], 7 => [$left, $bottom],
            8 => [$bottom, $left], 9 => [$bottom, $top], 11 => [$bottom, $right],
            12 => [$right, $left], 13 => [$right, $top], 14 => [$top, $left],
        ];

        if ($code === 5 || $code === 10) {
            $mean = ($a + $b + $cc + $d) / 4;
            $pair = ($mean > $level) === ($code === 5)
                ? [$left, $top]
                : [$top, $right];
        } else {
            $pair = $pairs[$code] ?? null;
        }

        if ($pair === null) {
            return null;
        }
        return [$pair[0][0], $pair[0][1], $pair[1][0], $pair[1][1]];
    }

    /**
     * Chain loose segments into polylines by matching endpoints.
     *
     * Without this the terrain emits thousands of two-point lines; with it, a
     * level becomes a handful of long polylines.
     *
     * @param array[] $segments each four coordinates
     * @return array[] flat coordinate lists
     */
    protected static function chain(array $segments): array {
        $key = function (float $x, float $y): string {
            return round($x, 5) . ',' . round($y, 5);
        };

        $starts = [];
        foreach ($segments as $index => $s) {
            $starts[$key($s[0], $s[1])][] = $index;
        }

        $used = [];
        $chains = [];

        foreach ($segments as $index => $s) {
            if (isset($used[$index])) {
                continue;
            }
            $used[$index] = true;
            $pts = [$s[0], $s[1], $s[2], $s[3]];
            $endx = $s[2];
            $endy = $s[3];

            for ($guard = 0; $guard < 4000; $guard++) {
                $k = $key($endx, $endy);
                $next = null;
                foreach ($starts[$k] ?? [] as $cand) {
                    if (!isset($used[$cand])) {
                        $next = $cand;
                        break;
                    }
                }
                if ($next === null) {
                    break;
                }
                $used[$next] = true;
                $endx = $segments[$next][2];
                $endy = $segments[$next][3];
                $pts[] = $endx;
                $pts[] = $endy;
            }

            $chains[] = $pts;
        }

        return $chains;
    }
}
