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
 * A resolution-independent drawing surface that records primitives rather than
 * emitting them.
 *
 * Every background style draws into a canvas. The recorded operation list is
 * then replayed by one of two painters: {@see tcpdf_painter} for the printed
 * certificate and {@see svg_painter} for the designer preview. Because both
 * painters consume the same list, the preview cannot drift away from the PDF —
 * which is the single longest-running complaint about the incumbent plugin.
 *
 * Styles work in page fractions. The canvas converts to millimetres, so one
 * definition serves A4, A3, A5, Letter, Legal and Tabloid in either
 * orientation.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class canvas {
    /** @var float One typographic point in millimetres. */
    public const PT_TO_MM = 0.3527777777778;

    /** @var float Page width in millimetres. */
    protected $width;

    /** @var float Page height in millimetres. */
    protected $height;

    /** @var array[] The recorded operation list. */
    protected $ops = [];

    /** @var array The nine-role palette, each value a #rrggbb string. */
    protected $palette;

    /** @var int The RNG state. */
    protected $state;

    /** @var int The seed the canvas was created with. */
    protected $seed;

    /** @var int Depth of nested save/restore pairs, so styles cannot leak state. */
    protected $depth = 0;

    /**
     * Create a canvas for a page.
     *
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @param array $palette the nine-role palette from brand::palette()
     * @param int $seed deterministic seed
     */
    public function __construct(float $width, float $height, array $palette, int $seed = 1) {
        $this->width = max(1.0, $width);
        $this->height = max(1.0, $height);
        $this->palette = $palette;
        $this->seed = $seed;
        // Avoid a zero state, which would lock the generator on zero forever.
        $this->state = ($seed & 0x7fffffff) ?: 0x2545f491;
    }

    // ------------------------------------------------------------------
    // Geometry helpers. Styles think in fractions; these convert to mm.
    // ------------------------------------------------------------------

    /**
     * Convert a horizontal page fraction to millimetres.
     *
     * @param float $u fraction of the page width
     * @return float
     */
    public function x(float $u): float {
        return $u * $this->width;
    }

    /**
     * Convert a vertical page fraction to millimetres.
     *
     * @param float $v fraction of the page height
     * @return float
     */
    public function y(float $v): float {
        return $v * $this->height;
    }

    /**
     * The page width in millimetres.
     *
     * @return float
     */
    public function w(): float {
        return $this->width;
    }

    /**
     * The page height in millimetres.
     *
     * @return float
     */
    public function h(): float {
        return $this->height;
    }

    /**
     * The short edge in millimetres.
     *
     * Lengths expressed against this stay physically constant when the page
     * size or orientation changes, which is what keeps margins and dots from
     * distorting.
     *
     * @return float
     */
    public function s(): float {
        return min($this->width, $this->height);
    }

    /**
     * The long edge in millimetres.
     *
     * @return float
     */
    public function l(): float {
        return max($this->width, $this->height);
    }

    /**
     * Whether the page is landscape.
     *
     * @return bool
     */
    public function is_landscape(): bool {
        return $this->width >= $this->height;
    }

    /**
     * Pick the first value on a landscape page and the second on a portrait one.
     *
     * @param float $landscape the landscape value
     * @param float $portrait the portrait value
     * @return float
     */
    public function by_orientation(float $landscape, float $portrait): float {
        return $this->is_landscape() ? $landscape : $portrait;
    }

    // ------------------------------------------------------------------
    // Deterministic randomness.
    // ------------------------------------------------------------------

    /**
     * A deterministic pseudo-random float in [0, 1).
     *
     * xorshift32. The same seed always produces the same artwork, so a
     * certificate never redraws differently between preview, issue and
     * re-render from the snapshot.
     *
     * @return float
     */
    public function rnd(): float {
        $x = $this->state;
        $x ^= ($x << 13) & 0x7fffffff;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0x7fffffff;
        $this->state = $x & 0x7fffffff;
        return $this->state / 2147483648.0;
    }

    /**
     * A deterministic pseudo-random float in a range.
     *
     * @param float $min lower bound
     * @param float $max upper bound
     * @return float
     */
    public function rnd_between(float $min, float $max): float {
        return $min + ($max - $min) * $this->rnd();
    }

    /**
     * A deterministic pseudo-random integer in [0, $n).
     *
     * @param int $n exclusive upper bound
     * @return int
     */
    public function rnd_int(int $n): int {
        return $n < 1 ? 0 : (int) floor($this->rnd() * $n) % $n;
    }

    /**
     * Choose one member of a list deterministically.
     *
     * @param array $options the choices
     * @return mixed
     */
    public function choose(array $options) {
        $values = array_values($options);
        return $values[$this->rnd_int(count($values))];
    }

    /**
     * A stable variant index derived from the seed alone.
     *
     * Unlike rnd(), this does not advance the generator, so a style can branch
     * on it without shifting every later random draw.
     *
     * @param int $n number of variants
     * @return int
     */
    public function variant(int $n): int {
        return $n < 1 ? 0 : ($this->seed % $n + $n) % $n;
    }

    // ------------------------------------------------------------------
    // Colour.
    // ------------------------------------------------------------------

    /**
     * Resolve a palette role to a #rrggbb string.
     *
     * Unknown roles fall back to the ink role and then to black, so a style can
     * never emit an undefined colour.
     *
     * @param string $role one of the nine palette roles
     * @return string
     */
    public function colour(string $role): string {
        if (isset($this->palette[$role])) {
            return (string) $this->palette[$role];
        }
        if (isset($this->palette['ink'])) {
            return (string) $this->palette['ink'];
        }
        return '#111111';
    }

    /**
     * Mix two palette roles.
     *
     * @param string $rolea the first role
     * @param string $roleb the second role
     * @param float $t 0 gives the first role, 1 gives the second
     * @return string
     */
    public function mix(string $rolea, string $roleb, float $t): string {
        $a = self::to_rgb($this->colour($rolea));
        $b = self::to_rgb($this->colour($roleb));
        $t = max(0.0, min(1.0, $t));
        return sprintf('#%02x%02x%02x',
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t));
    }

    /**
     * Convert a hex colour to an RGB triplet.
     *
     * @param string $hex a #rgb or #rrggbb string
     * @return int[] red, green and blue in 0-255
     */
    public static function to_rgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [17, 17, 17];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    // ------------------------------------------------------------------
    // Recording.
    // ------------------------------------------------------------------

    /**
     * Record an operation.
     *
     * @param array $op the operation
     * @return void
     */
    protected function push(array $op): void {
        $this->ops[] = $op;
    }

    /**
     * Save the graphics state.
     *
     * @return void
     */
    public function save(): void {
        $this->depth++;
        $this->push(['save']);
    }

    /**
     * Restore the graphics state.
     *
     * @return void
     */
    public function restore(): void {
        if ($this->depth > 0) {
            $this->depth--;
            $this->push(['restore']);
        }
    }

    /**
     * Clip subsequent drawing to a rectangle, given in page fractions.
     *
     * Must be wrapped in save()/restore().
     *
     * @param float $u left edge as a width fraction
     * @param float $v top edge as a height fraction
     * @param float $uw width as a width fraction
     * @param float $vh height as a height fraction
     * @return void
     */
    public function clip(float $u, float $v, float $uw, float $vh): void {
        $this->push(['clip', $this->x($u), $this->y($v), $this->x($uw), $this->y($vh)]);
    }

    /**
     * Clip subsequent drawing to a rectangle given in millimetres.
     *
     * Needed by styles that rotate the coordinate system first: once rotated,
     * a page fraction no longer means anything useful, but a millimetre still
     * does.
     *
     * @param float $xmm left edge in millimetres
     * @param float $ymm top edge in millimetres
     * @param float $wmm width in millimetres
     * @param float $hmm height in millimetres
     * @return void
     */
    public function clip_mm(float $xmm, float $ymm, float $wmm, float $hmm): void {
        $this->push(['clip', $xmm, $ymm, $wmm, $hmm]);
    }

    /**
     * Fill a millimetre rectangle with a linear gradient.
     *
     * @param float $xmm left edge in millimetres
     * @param float $ymm top edge in millimetres
     * @param float $wmm width in millimetres
     * @param float $hmm height in millimetres
     * @param string $from start colour
     * @param string $to end colour
     * @param float[] $coords x1, y1, x2, y2 within the box, each 0 to 1
     * @return void
     */
    public function linear_gradient_mm(float $xmm, float $ymm, float $wmm, float $hmm,
            string $from, string $to, array $coords = [0.0, 0.0, 1.0, 0.0]): void {
        $this->push(['lgrad', $xmm, $ymm, $wmm, $hmm, $from, $to, array_map('floatval', $coords)]);
    }

    /**
     * Fill a millimetre rectangle with a radial gradient.
     *
     * @param float $xmm left edge in millimetres
     * @param float $ymm top edge in millimetres
     * @param float $wmm width in millimetres
     * @param float $hmm height in millimetres
     * @param string $from centre colour
     * @param string $to edge colour
     * @param float[] $coords focus x, focus y, focus r, centre x, centre y, radius
     * @return void
     */
    public function radial_gradient_mm(float $xmm, float $ymm, float $wmm, float $hmm,
            string $from, string $to,
            array $coords = [0.5, 0.5, 0.0, 0.5, 0.5, 0.5]): void {
        $this->push(['rgrad', $xmm, $ymm, $wmm, $hmm, $from, $to, array_map('floatval', $coords)]);
    }

    /**
     * Set the alpha for subsequent drawing.
     *
     * @param float $alpha 0 transparent to 1 opaque
     * @return void
     */
    public function alpha(float $alpha): void {
        $this->push(['alpha', max(0.0, min(1.0, $alpha))]);
    }

    /**
     * Rotate subsequent drawing about a point given in page fractions.
     *
     * Must be wrapped in save()/restore().
     *
     * @param float $degrees the angle
     * @param float $u pivot as a width fraction
     * @param float $v pivot as a height fraction
     * @return void
     */
    public function rotate(float $degrees, float $u, float $v): void {
        $this->push(['rotate', $degrees, $this->x($u), $this->y($v)]);
    }

    /**
     * Set the fill colour.
     *
     * @param string $colour a #rrggbb string
     * @return void
     */
    public function fill(string $colour): void {
        $this->push(['fill', $colour]);
    }

    /**
     * Set the stroke colour, width and optional dash.
     *
     * @param string $colour a #rrggbb string
     * @param float $widthpt line width in points
     * @param array $dash dash pattern in points, empty for solid
     * @return void
     */
    public function stroke(string $colour, float $widthpt, array $dash = []): void {
        $this->push(['stroke', $colour, max(0.05, $widthpt), $dash]);
    }

    /**
     * Draw a rectangle in page fractions.
     *
     * @param float $u left edge
     * @param float $v top edge
     * @param float $uw width
     * @param float $vh height
     * @param string $mode F to fill, D to stroke, FD for both
     * @return void
     */
    public function rect(float $u, float $v, float $uw, float $vh, string $mode = 'F'): void {
        $this->push(['rect', $this->x($u), $this->y($v), $this->x($uw), $this->y($vh), $mode]);
    }

    /**
     * Draw a rectangle whose position is a fraction but whose size is in millimetres.
     *
     * Useful for marks that must stay physically constant across page sizes.
     *
     * @param float $u left edge as a width fraction
     * @param float $v top edge as a height fraction
     * @param float $wmm width in millimetres
     * @param float $hmm height in millimetres
     * @param string $mode F, D or FD
     * @return void
     */
    public function rect_mm(float $u, float $v, float $wmm, float $hmm, string $mode = 'F'): void {
        $this->push(['rect', $this->x($u), $this->y($v), $wmm, $hmm, $mode]);
    }

    /**
     * Draw a circle. The centre is in page fractions, the radius in millimetres.
     *
     * A true circle needs one radius in one unit, so the radius is always
     * millimetres. Styles derive it from s() or l() to stay proportionate.
     *
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm radius in millimetres
     * @param float $from start angle in degrees
     * @param float $to end angle in degrees
     * @param string $mode F, D or FD
     * @return void
     */
    public function circle(float $u, float $v, float $rmm, float $from = 0.0, float $to = 360.0,
            string $mode = 'D'): void {
        $this->push(['circle', $this->x($u), $this->y($v), max(0.01, $rmm), $from, $to, $mode]);
    }

    /**
     * Draw an ellipse with millimetre radii.
     *
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rxmm horizontal radius in millimetres
     * @param float $rymm vertical radius in millimetres
     * @param float $rot rotation in degrees
     * @param float $from start angle in degrees
     * @param float $to end angle in degrees
     * @param string $mode F, D or FD
     * @return void
     */
    public function ellipse(float $u, float $v, float $rxmm, float $rymm, float $rot = 0.0,
            float $from = 0.0, float $to = 360.0, string $mode = 'D'): void {
        $this->push(['ellipse', $this->x($u), $this->y($v), max(0.01, $rxmm), max(0.01, $rymm),
            $rot, $from, $to, $mode]);
    }

    /**
     * Draw a circle whose centre is in millimetres.
     *
     * @param float $xmm centre x in millimetres
     * @param float $ymm centre y in millimetres
     * @param float $rmm radius in millimetres
     * @param float $from start angle in degrees
     * @param float $to end angle in degrees
     * @param string $mode F, D or FD
     * @return void
     */
    public function circle_mm(float $xmm, float $ymm, float $rmm, float $from = 0.0,
            float $to = 360.0, string $mode = 'D'): void {
        $this->push(['circle', $xmm, $ymm, max(0.01, $rmm), $from, $to, $mode]);
    }

    /**
     * Draw an ellipse whose centre is in millimetres.
     *
     * @param float $xmm centre x in millimetres
     * @param float $ymm centre y in millimetres
     * @param float $rxmm horizontal radius in millimetres
     * @param float $rymm vertical radius in millimetres
     * @param float $rot rotation in degrees
     * @param float $from start angle in degrees
     * @param float $to end angle in degrees
     * @param string $mode F, D or FD
     * @return void
     */
    public function ellipse_mm(float $xmm, float $ymm, float $rxmm, float $rymm,
            float $rot = 0.0, float $from = 0.0, float $to = 360.0, string $mode = 'D'): void {
        $this->push(['ellipse', $xmm, $ymm, max(0.01, $rxmm), max(0.01, $rymm),
            $rot, $from, $to, $mode]);
    }

    /**
     * Draw a line between two points given in millimetres.
     *
     * @param float $x1 start x in millimetres
     * @param float $y1 start y in millimetres
     * @param float $x2 end x in millimetres
     * @param float $y2 end y in millimetres
     * @return void
     */
    public function line_mm(float $x1, float $y1, float $x2, float $y2): void {
        $this->push(['line', $x1, $y1, $x2, $y2]);
    }

    /**
     * Draw a line between two points in page fractions.
     *
     * @param float $u1 start x
     * @param float $v1 start y
     * @param float $u2 end x
     * @param float $v2 end y
     * @return void
     */
    public function line(float $u1, float $v1, float $u2, float $v2): void {
        $this->push(['line', $this->x($u1), $this->y($v1), $this->x($u2), $this->y($v2)]);
    }

    /**
     * Draw a polygon from a flat list of fractional coordinates.
     *
     * @param float[] $points u1, v1, u2, v2, ...
     * @param string $mode F, D or FD
     * @return void
     */
    public function poly(array $points, string $mode = 'F'): void {
        $mm = [];
        $count = count($points);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $mm[] = $this->x((float) $points[$i]);
            $mm[] = $this->y((float) $points[$i + 1]);
        }
        if (count($mm) >= 6) {
            $this->push(['poly', $mm, $mode]);
        }
    }

    /**
     * Draw a polygon from a list of millimetre coordinates.
     *
     * @param float[] $mm x1, y1, x2, y2, ... in millimetres
     * @param string $mode F, D or FD
     * @return void
     */
    public function poly_mm(array $mm, string $mode = 'F'): void {
        if (count($mm) >= 6) {
            $this->push(['poly', array_map('floatval', array_values($mm)), $mode]);
        }
    }

    /**
     * Draw an open polyline from a flat list of fractional coordinates.
     *
     * poly() closes its figure in both painters, which is wrong for a traced
     * curve; this emits a path instead so the ends stay open.
     *
     * @param float[] $points u1, v1, u2, v2, ...
     * @param string $mode F, D or FD
     * @return void
     */
    public function polyline(array $points, string $mode = 'D'): void {
        $count = count($points);
        if ($count < 4) {
            return;
        }
        $segments = [['M', (float) $points[0], (float) $points[1]]];
        for ($i = 2; $i + 1 < $count; $i += 2) {
            $segments[] = ['L', (float) $points[$i], (float) $points[$i + 1]];
        }
        $this->path($segments, $mode);
    }

    /**
     * Draw an open polyline from millimetre coordinates.
     *
     * @param float[] $mm x1, y1, x2, y2, ... in millimetres
     * @param string $mode F, D or FD
     * @return void
     */
    public function polyline_mm(array $mm, string $mode = 'D'): void {
        $count = count($mm);
        if ($count < 4) {
            return;
        }
        $segments = [['M', (float) $mm[0], (float) $mm[1]]];
        for ($i = 2; $i + 1 < $count; $i += 2) {
            $segments[] = ['L', (float) $mm[$i], (float) $mm[$i + 1]];
        }
        $this->push(['path', $segments, $mode]);
    }

    /**
     * Draw a path of move, line, cubic and close segments given in page fractions.
     *
     * Each segment is ['M', u, v], ['L', u, v], ['C', u1, v1, u2, v2, u, v] or ['Z'].
     *
     * @param array[] $segments the path segments
     * @param string $mode F, D or FD
     * @return void
     */
    public function path(array $segments, string $mode = 'D'): void {
        $out = [];
        foreach ($segments as $seg) {
            $cmd = (string) ($seg[0] ?? '');
            if ($cmd === 'Z') {
                $out[] = ['Z'];
                continue;
            }
            $converted = [$cmd];
            $count = count($seg);
            for ($i = 1; $i + 1 < $count; $i += 2) {
                $converted[] = $this->x((float) $seg[$i]);
                $converted[] = $this->y((float) $seg[$i + 1]);
            }
            $out[] = $converted;
        }
        if ($out) {
            $this->push(['path', $out, $mode]);
        }
    }

    /**
     * Fill a rectangle with a linear gradient.
     *
     * @param float $u left edge
     * @param float $v top edge
     * @param float $uw width
     * @param float $vh height
     * @param string $from start colour
     * @param string $to end colour
     * @param float[] $coords x1, y1, x2, y2 within the box, each 0 to 1
     * @return void
     */
    public function linear_gradient(float $u, float $v, float $uw, float $vh, string $from,
            string $to, array $coords = [0.0, 0.0, 1.0, 0.0]): void {
        $this->push(['lgrad', $this->x($u), $this->y($v), $this->x($uw), $this->y($vh),
            $from, $to, array_map('floatval', $coords)]);
    }

    /**
     * Fill a rectangle with a radial gradient.
     *
     * @param float $u left edge
     * @param float $v top edge
     * @param float $uw width
     * @param float $vh height
     * @param string $from centre colour
     * @param string $to edge colour
     * @param float[] $coords focus x, focus y, focus r, centre x, centre y, radius
     * @return void
     */
    public function radial_gradient(float $u, float $v, float $uw, float $vh, string $from,
            string $to, array $coords = [0.5, 0.5, 0.0, 0.5, 0.5, 0.5]): void {
        $this->push(['rgrad', $this->x($u), $this->y($v), $this->x($uw), $this->y($vh),
            $from, $to, array_map('floatval', $coords)]);
    }

    /**
     * Fill a quad with a bilinear colour blend, approximating a gradient mesh patch.
     *
     * TCPDF can draw a true Coons patch, but its SVG counterpart cannot, so both
     * painters subdivide the patch instead. That keeps the preview honest.
     *
     * @param float[] $quad u, v for the four corners clockwise from top left
     * @param string[] $colours the four corner colours in the same order
     * @param int $steps subdivision per axis
     * @return void
     */
    public function mesh_quad(array $quad, array $colours, int $steps = 10): void {
        if (count($quad) < 8 || count($colours) < 4) {
            return;
        }
        $mm = [];
        for ($i = 0; $i < 8; $i += 2) {
            $mm[] = $this->x((float) $quad[$i]);
            $mm[] = $this->y((float) $quad[$i + 1]);
        }
        $this->push(['mesh', $mm, array_values($colours), max(2, min(24, $steps))]);
    }

    // ------------------------------------------------------------------
    // Text.
    //
    // Layout happens HERE, once, and both painters replay the resulting glyph
    // positions. That is deliberate. TCPDF has no textPath at all, and SVG's
    // textPath carries a pile of divergences — bottom arcs render upside down
    // unless the path is re-emitted with a flipped sweep flag, and Firefox
    // still does not support textLength on textPath, so auto-fitting is not
    // portable. Laying out per glyph in one place sidesteps every one of those
    // and keeps the preview identical to the print by construction.
    // ------------------------------------------------------------------

    /** @var callable|null Injected width measurer, or null to use the fallback table. */
    protected $measurer = null;

    /**
     * Supply an exact width measurer.
     *
     * At PDF render time this should wrap TCPDF's GetStringWidth, which knows
     * the metrics of whatever font is actually loaded. Without it the canvas
     * falls back to {@see type_metrics}, which affects fit but never parity.
     *
     * @param callable|null $measurer function (string $text, string $family, string $style, float $sizept): float
     *      returning a width in millimetres
     * @return void
     */
    public function set_measurer(?callable $measurer): void {
        $this->measurer = $measurer;
    }

    /**
     * Width of a run in millimetres, excluding tracking.
     *
     * @param string $text the run
     * @param string $family font family
     * @param string $style TCPDF style string
     * @param float $sizept size in points
     * @return float width in millimetres
     */
    public function measure(string $text, string $family, string $style, float $sizept): float {
        if ($this->measurer !== null) {
            $measure = $this->measurer;

            return (float) $measure($text, $family, $style, $sizept);
        }
        return type_metrics::run($text, $style) * $sizept * self::PT_TO_MM;
    }

    /**
     * Normalise the options every text call accepts.
     *
     * @param array $opts caller options
     * @return array complete options
     */
    protected function text_opts(array $opts): array {
        return $opts + [
            'family' => 'helvetica',
            'style' => '',
            'sizept' => 12.0,
            'anchor' => 'C',
            'tracking' => 0.0,
        ];
    }

    /**
     * Draw a straight run of text.
     *
     * The anchor is resolved here into an explicit left edge, so both painters
     * draw from the same coordinate and neither has to measure anything.
     *
     * @param float $u horizontal position as a width fraction
     * @param float $v baseline position as a height fraction
     * @param string $text the run
     * @param array $opts family, style, sizept, anchor (L, C or R), tracking
     *      as a fraction of the em
     * @return float the width drawn, in millimetres
     */
    public function text(float $u, float $v, string $text, array $opts = []): float {
        $o = $this->text_opts($opts);
        return $this->text_mm($this->x($u), $this->y($v), $text, $o);
    }

    /**
     * Draw a straight run of text at millimetre coordinates.
     *
     * @param float $xmm horizontal position in millimetres
     * @param float $ymm baseline position in millimetres
     * @param string $text the run
     * @param array $opts see {@see text()}
     * @return float the width drawn, in millimetres
     */
    public function text_mm(float $xmm, float $ymm, string $text, array $opts = []): float {
        $o = $this->text_opts($opts);
        if ($text === '') {
            return 0.0;
        }

        $tracking = (float) $o['tracking'] * (float) $o['sizept'] * self::PT_TO_MM;
        $chars = type_metrics::split($text);
        $natural = $this->measure($text, $o['family'], $o['style'], (float) $o['sizept']);
        $total = $natural + $tracking * max(0, count($chars) - 1);

        $left = $xmm;
        if ($o['anchor'] === 'C') {
            $left -= $total / 2.0;
        } else if ($o['anchor'] === 'R') {
            $left -= $total;
        }

        if ($tracking <= 0.0) {
            $this->ops[] = ['text', $left, $ymm, $text, $o['family'], $o['style'],
                (float) $o['sizept']];
            return $total;
        }

        // Tracked runs become glyph runs so the spacing cannot differ between
        // a PDF text object and a browser's letter-spacing implementation.
        $glyphs = [];
        $pen = $left;
        foreach ($chars as $char) {
            $glyphs[] = [$char, $pen, $ymm, 0.0];
            $pen += $this->measure($char, $o['family'], $o['style'], (float) $o['sizept']) + $tracking;
        }
        $this->ops[] = ['glyphs', $glyphs, $o['family'], $o['style'], (float) $o['sizept']];

        return $total;
    }

    /**
     * Set a run of text around a circular arc.
     *
     * The algorithm is the one every engraver and every seal library converges
     * on: turn the run's total width into a sweep angle, pre-rotate by half of
     * it to centre the run, then step each glyph by half its own advance plus
     * half the previous glyph's.
     *
     * Tracking defaults to 20% of the em, which is not a stylistic whim. On a
     * convex arc the tops of adjacent glyphs splay apart while their baselines
     * converge, so text set at its natural width reads as cramped. Twenty per
     * cent is the figure seal engravers and the mPDF implementation both land
     * on, and below about ten per cent a ring stops looking official.
     *
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm radius of the glyph baseline in millimetres
     * @param string $text the run
     * @param array $opts family, style, sizept, tracking, plus:
     *      align  'top' to arc over the centre, 'bottom' to arc beneath it
     *      centre bearing in degrees the run is centred on, default 0 for top
     *             and 180 for bottom
     *      maxsweep largest arc in degrees the run may occupy; with autofit on,
     *             the size shrinks until it fits
     *      autofit true to shrink to maxsweep, default true
     *      minsizept floor for autofit, default 4pt
     * @return array ['sweep' => degrees used, 'sizept' => size drawn]
     */
    public function arc_text(float $u, float $v, float $rmm, string $text, array $opts = []): array {
        $o = $this->text_opts($opts) + [
            'align' => 'top',
            'centre' => null,
            'maxsweep' => 160.0,
            'autofit' => true,
            'minsizept' => 4.0,
        ];
        if ($o['tracking'] <= 0.0) {
            $o['tracking'] = 0.20;
        }

        $chars = type_metrics::split($text);
        if (!$chars || $rmm <= 0.0) {
            return ['sweep' => 0.0, 'sizept' => (float) $o['sizept']];
        }

        $bottom = ($o['align'] === 'bottom');
        $centre = $o['centre'] === null ? ($bottom ? 180.0 : 0.0) : (float) $o['centre'];
        $circumference = 2.0 * M_PI * $rmm;

        // Shrink until the run fits the permitted arc. Ten passes takes any
        // realistic overrun down to well under a point.
        $sizept = (float) $o['sizept'];
        $advances = [];
        $sweep = 0.0;
        for ($pass = 0; $pass < 10; $pass++) {
            $tracking = (float) $o['tracking'] * $sizept * self::PT_TO_MM;
            $advances = [];
            $total = 0.0;
            foreach ($chars as $char) {
                $adv = $this->measure($char, $o['family'], $o['style'], $sizept) + $tracking;
                $advances[] = $adv;
                $total += $adv;
            }
            $sweep = ($total / $circumference) * 360.0;

            if (!$o['autofit'] || $sweep <= (float) $o['maxsweep'] || $sizept <= (float) $o['minsizept']) {
                break;
            }
            $sizept = max((float) $o['minsizept'], $sizept * ((float) $o['maxsweep'] / $sweep) * 0.98);
        }

        // Bearings run clockwise from twelve o'clock. The top arc reads left to
        // right with increasing bearing; the bottom arc reads left to right
        // with DECREASING bearing, which is what stops it coming out mirrored.
        $direction = $bottom ? -1.0 : 1.0;
        $bearing = $centre - $direction * ($sweep / 2.0);

        $glyphs = [];
        foreach ($chars as $i => $char) {
            $step = ($advances[$i] / $circumference) * 360.0;
            $mid = $bearing + $direction * ($step / 2.0);

            $rad = deg2rad($mid);
            $cx = $this->x($u) + $rmm * sin($rad);
            $cy = $this->y($v) - $rmm * cos($rad);

            // Upright on the top arc; flipped on the bottom so the baseline
            // sits outward and the letters are not upside down.
            $screenrot = $bottom ? ($mid + 180.0) : $mid;

            // Offset from the glyph's centre to its left edge, along the
            // tangent, so the recorded coordinate is a drawing origin.
            $half = ($advances[$i] - (float) $o['tracking'] * $sizept * self::PT_TO_MM) / 2.0;
            $trad = deg2rad($screenrot);
            $ox = $cx - $half * cos($trad);
            $oy = $cy - $half * sin($trad);

            // Stored in TCPDF's convention, counter-clockwise positive, which
            // is what svg_painter already negates for the rotate op.
            $glyphs[] = [$char, $ox, $oy, -$screenrot];

            $bearing += $direction * $step;
        }

        $this->ops[] = ['glyphs', $glyphs, $o['family'], $o['style'], $sizept];

        return ['sweep' => $sweep, 'sizept' => $sizept];
    }

    /**
     * The recorded operation list.
     *
     * @return array[]
     */
    public function ops(): array {
        return $this->ops;
    }

    /**
     * Discard everything recorded so far.
     *
     * @return void
     */
    public function reset(): void {
        $this->ops = [];
        $this->depth = 0;
    }
}
