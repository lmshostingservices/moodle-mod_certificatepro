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
 * Draws organisation seals as recorded operations.
 *
 * WHY THIS IS GENERATED RATHER THAN LICENSED
 *
 * Ornamental borders are compositional: whether a frame looks expensive turns
 * on judgements no parameter captures. Seals are the opposite. Every seal in
 * every reference set decomposes into an edge treatment, one or two rings of
 * arced text, a centre mark and a founding-year line. Change the strings and
 * the ring count and you have a different seal.
 *
 * More to the point, a bought seal carries somebody else's name. Its lettering
 * is outlined and its arc is baked, so re-lettering it for an institution is
 * the hard part — you would buy the easy fifth and still build the difficult
 * rest.
 *
 * WHAT THE NUMBERS COME FROM
 *
 * Ring text is tracked at 20% of the em. That is not a stylistic preference:
 * on a convex arc the tops of adjacent glyphs splay apart while their
 * baselines converge, so text set at its natural width reads as cramped.
 * Twenty per cent is where seal engravers and the established implementations
 * both land.
 *
 * The default proportions target legibility at 19mm, which is the smallest
 * reproduction size university brand guidelines permit, and the statutory
 * requirement in many jurisdictions that an official seal be "capable of
 * photographic reproduction".
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seal_engine {
    // ------------------------------------------------------------------
    // Edge treatments.
    // ------------------------------------------------------------------

    /** @var string A single circular rule. */
    const EDGE_PLAIN = 'plain';

    /** @var string A heavy rule with a hairline companion inside it. */
    const EDGE_DOUBLE = 'double';

    /** @var string Semicircular bites around the circumference. */
    const EDGE_SCALLOP = 'scallop';

    /** @var string Square teeth: the official-stamp register. */
    const EDGE_COG = 'cog';

    /** @var string Sharp points, alternating long and short. */
    const EDGE_STARBURST = 'starburst';

    /** @var string A postage-stamp edge of punched circles. */
    const EDGE_PERFORATED = 'perforated';

    /** @var string The rule replaced by evenly spaced dots. */
    const EDGE_DOTTED = 'dotted';

    /** @var string A ring broken by a horizontal wordmark bar. */
    const EDGE_INTERRUPTED = 'interrupted';

    /** @var string A rope or cable moulding. */
    const EDGE_ROPE = 'rope';

    // ------------------------------------------------------------------
    // Ring layouts.
    // ------------------------------------------------------------------

    /** @var string Name over the top only. */
    const RING_TOP = 'top';

    /** @var string Name on top, a secondary legend beneath. */
    const RING_BOTH = 'both';

    /** @var string One phrase repeated around the whole ring. */
    const RING_FULL = 'full';

    /** @var string No ring lettering at all. */
    const RING_NONE = 'none';

    // ------------------------------------------------------------------
    // Centre marks.
    // ------------------------------------------------------------------

    /** @var string Two or three initials. */
    const MARK_MONOGRAM = 'monogram';

    /** @var string The name set in two or three letterspaced lines. */
    const MARK_WORDMARK = 'wordmark';

    /** @var string A quartered heraldic shield. */
    const MARK_SHIELD = 'shield';

    /** @var string A shield cradled by laurel with a motto ribbon. */
    const MARK_CREST = 'crest';

    /** @var string An open book, the classical charge for learning. */
    const MARK_BOOK = 'book';

    /** @var string A torch: knowledge, enlightenment. */
    const MARK_TORCH = 'torch';

    /** @var string A star, five-pointed. */
    const MARK_STAR = 'star';

    /** @var string A solid disc with the mark reversed out of it. */
    const MARK_REVERSED = 'reversed';

    /** @var string Nothing in the middle. */
    const MARK_NONE = 'none';

    /** @var float Tracking for ring lettering, as a fraction of the em. */
    const RING_TRACKING = 0.20;

    /** @var float Degrees of clear arc either side of a divider. */
    const DIVIDER_GAP = 9.0;

    /**
     * Every edge treatment, keyed by constant.
     *
     * @return string[] the edge identifiers
     */
    public static function edges(): array {
        return [
            self::EDGE_PLAIN, self::EDGE_DOUBLE, self::EDGE_SCALLOP, self::EDGE_COG,
            self::EDGE_STARBURST, self::EDGE_PERFORATED, self::EDGE_DOTTED,
            self::EDGE_INTERRUPTED, self::EDGE_ROPE,
        ];
    }

    /**
     * Every ring layout.
     *
     * @return string[] the layout identifiers
     */
    public static function ring_layouts(): array {
        return [self::RING_TOP, self::RING_BOTH, self::RING_FULL, self::RING_NONE];
    }

    /**
     * Every centre mark.
     *
     * @return string[] the mark identifiers
     */
    public static function marks(): array {
        return [
            self::MARK_MONOGRAM, self::MARK_WORDMARK, self::MARK_SHIELD, self::MARK_CREST,
            self::MARK_BOOK, self::MARK_TORCH, self::MARK_STAR, self::MARK_REVERSED,
            self::MARK_NONE,
        ];
    }

    /**
     * The complete option set, with defaults applied.
     *
     * @param array $spec caller options
     * @return array the resolved specification
     */
    public static function defaults(array $spec = []): array {
        return $spec + [
            'edge' => self::EDGE_DOUBLE,
            'ring' => self::RING_BOTH,
            'mark' => self::MARK_MONOGRAM,
            'top' => '',
            'bottom' => '',
            'centre' => '',
            'year' => '',
            'motto' => '',
            'ink' => '#1b3a5c',
            'paper' => '#ffffff',
            'metal' => null,
            'family' => 'helvetica',
            'divider' => true,
            'innerkeyline' => true,
            'weight' => 1.0,
        ];
    }

    /**
     * Draw a seal filling a square box.
     *
     * @param canvas $c the canvas to record into
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param array $spec see {@see defaults()}
     * @return void
     */
    public static function draw(canvas $c, float $u, float $v, float $rmm, array $spec = []): void {
        $s = self::tincture(self::defaults($spec));
        if ($rmm <= 0.5) {
            return;
        }

        // Rules scale with the seal so a 19mm impression and a 60mm one read
        // the same. Below about 12mm the hairline would vanish in print, so it
        // is floored rather than allowed to go arbitrarily thin.
        $unit = $rmm / 26.0;
        $heavy = max(0.35, 1.6 * $unit * (float) $s['weight']);
        $hair = max(0.18, 0.5 * $unit * (float) $s['weight']);

        // A stroke is painted centred on the line, so the outermost rule spills
        // half its width beyond the radius it was given. The canvas clips at the
        // element's box and the PDF does not, which left every seal about one
        // per cent bigger on paper than on screen. Pulling the radius in by that
        // half width makes the element's square its true extent in both. The
        // stroke widths here are in points and the radius is in millimetres, so
        // the conversion is not optional: taking the half width raw pulled the
        // seal in nearly three times too far.
        $rmm = max(0.5, $rmm - (($heavy * canvas::PT_TO_MM) / 2.0));

        $c->save();

        $inner = self::draw_edge($c, $u, $v, $rmm, $heavy, $hair, $s);
        $ringradius = $inner - max(1.4, 2.2 * $unit);

        $junction = self::draw_ring($c, $u, $v, $ringradius, $rmm, $s);

        if ($s['divider'] && $junction !== null && $s['ring'] === self::RING_BOTH) {
            self::draw_dividers($c, $u, $v, $ringradius, $rmm, $junction, $s);
        }

        $markradius = $inner - max(2.6, 6.0 * $unit);
        if ($s['innerkeyline'] && $s['ring'] !== self::RING_NONE && $markradius > 2.0) {
            $c->stroke($s['ink'], $hair);
            $c->circle($u, $v, $markradius, 0.0, 360.0, 'D');
        }

        self::draw_mark($c, $u, $v, $markradius, $rmm, $hair, $s);

        $c->restore();
    }

    // ------------------------------------------------------------------
    // Edge treatments.
    // ------------------------------------------------------------------

    /**
     * Draw the outer boundary.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param float $heavy heavy rule width in points
     * @param float $hair hairline width in points
     * @param array $s the resolved specification
     * @return float the radius the ring lettering must stay inside
     */
    protected static function draw_edge(canvas $c, float $u, float $v, float $rmm,
            float $heavy, float $hair, array $s): float {
        $ink = $s['ink'];

        switch ($s['edge']) {
            case self::EDGE_PLAIN:
                $c->stroke($ink, $heavy);
                $c->circle($u, $v, $rmm, 0.0, 360.0, 'D');
                return $rmm;

            case self::EDGE_SCALLOP:
                self::toothed($c, $u, $v, $rmm, $heavy, $ink, 36, 'scallop');
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.90, 0.0, 360.0, 'D');
                return $rmm * 0.90;

            case self::EDGE_COG:
                self::toothed($c, $u, $v, $rmm, $heavy, $ink, 32, 'cog');
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.90, 0.0, 360.0, 'D');
                return $rmm * 0.90;

            case self::EDGE_STARBURST:
                self::toothed($c, $u, $v, $rmm, $heavy, $ink, 48, 'star');
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.88, 0.0, 360.0, 'D');
                return $rmm * 0.88;

            case self::EDGE_PERFORATED:
                self::perforate($c, $u, $v, $rmm, $hair, $s);
                return $rmm * 0.90;

            case self::EDGE_DOTTED:
                self::dotted($c, $u, $v, $rmm, $ink);
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.88, 0.0, 360.0, 'D');
                return $rmm * 0.88;

            case self::EDGE_ROPE:
                self::rope($c, $u, $v, $rmm, $hair, $ink);
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.88, 0.0, 360.0, 'D');
                return $rmm * 0.88;

            case self::EDGE_INTERRUPTED:
                // The ring is broken at three and nine o'clock; the gap is
                // where a horizontal wordmark bar crosses it.
                $c->stroke($ink, $heavy);
                $c->circle($u, $v, $rmm, 14.0, 166.0, 'D');
                $c->circle($u, $v, $rmm, 194.0, 346.0, 'D');
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.93, 16.0, 164.0, 'D');
                $c->circle($u, $v, $rmm * 0.93, 196.0, 344.0, 'D');
                return $rmm * 0.93;

            case self::EDGE_DOUBLE:
            default:
                $c->stroke($ink, $heavy);
                $c->circle($u, $v, $rmm, 0.0, 360.0, 'D');
                $c->stroke($ink, $hair);
                $c->circle($u, $v, $rmm * 0.925, 0.0, 360.0, 'D');
                return $rmm * 0.925;
        }
    }

    /**
     * Draw a toothed outer edge as a single closed polygon.
     *
     * Emitted as one polygon rather than as many small shapes so the outline
     * closes cleanly and the PDF carries one path instead of dozens.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param float $widthpt rule width in points
     * @param string $ink the colour
     * @param int $teeth how many teeth
     * @param string $shape scallop, cog or star
     * @return void
     */
    protected static function toothed(canvas $c, float $u, float $v, float $rmm,
            float $widthpt, string $ink, int $teeth, string $shape): void {
        $cx = $c->x($u);
        $cy = $c->y($v);
        $depth = $shape === 'star' ? 0.90 : ($shape === 'cog' ? 0.905 : 0.895);
        $points = [];

        $steps = $shape === 'scallop' ? 6 : 1;
        for ($i = 0; $i < $teeth; $i++) {
            $base = ($i / $teeth) * 2.0 * M_PI;
            $span = (2.0 * M_PI) / $teeth;

            if ($shape === 'cog') {
                // Flat-topped teeth: out, along, in, along.
                $points[] = [$cx + $rmm * sin($base), $cy - $rmm * cos($base)];
                $points[] = [$cx + $rmm * sin($base + $span * 0.5),
                    $cy - $rmm * cos($base + $span * 0.5)];
                $points[] = [$cx + $rmm * $depth * sin($base + $span * 0.5),
                    $cy - $rmm * $depth * cos($base + $span * 0.5)];
                $points[] = [$cx + $rmm * $depth * sin($base + $span),
                    $cy - $rmm * $depth * cos($base + $span)];
                continue;
            }

            if ($shape === 'star') {
                // Alternating long and short points.
                $long = ($i % 2 === 0) ? $rmm : $rmm * 0.955;
                $points[] = [$cx + $long * sin($base), $cy - $long * cos($base)];
                $points[] = [$cx + $rmm * $depth * sin($base + $span * 0.5),
                    $cy - $rmm * $depth * cos($base + $span * 0.5)];
                continue;
            }

            // Scallop: a semicircular bite, approximated finely enough that the
            // curve reads as a curve rather than a chamfer.
            for ($k = 0; $k <= $steps; $k++) {
                $t = $k / $steps;
                $angle = $base + $span * $t;
                $bulge = $rmm * $depth + ($rmm - $rmm * $depth) * sin($t * M_PI);
                $points[] = [$cx + $bulge * sin($angle), $cy - $bulge * cos($angle)];
            }
        }

        $flat = [];
        foreach ($points as $p) {
            $flat[] = $p[0];
            $flat[] = $p[1];
        }

        $c->stroke($ink, $widthpt);
        $c->poly_mm($flat, 'D');
    }

    /**
     * Draw a postage-stamp perforated edge.
     *
     * The punches are drawn in the paper colour over a filled disc, which is
     * how a real perforation reads — material removed, not ink added.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param array $s the resolved specification
     * @return void
     */
    protected static function perforate(canvas $c, float $u, float $v, float $rmm,
            float $hair, array $s): void {
        $c->stroke($s['ink'], $hair);
        $c->circle($u, $v, $rmm * 0.955, 0.0, 360.0, 'D');

        $count = max(24, (int) round($rmm * 1.6));
        $punch = max(0.3, $rmm * 0.030);
        $cx = $c->x($u);
        $cy = $c->y($v);

        $c->fill($s['paper']);
        for ($i = 0; $i < $count; $i++) {
            $angle = ($i / $count) * 2.0 * M_PI;
            $px = $cx + $rmm * 0.955 * sin($angle);
            $py = $cy - $rmm * 0.955 * cos($angle);
            $c->circle_mm($px, $py, $punch, 0.0, 360.0, 'F');
        }
    }

    /**
     * Draw a ring of evenly spaced dots in place of a rule.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param string $ink the colour
     * @return void
     */
    protected static function dotted(canvas $c, float $u, float $v, float $rmm,
            string $ink): void {
        $count = max(32, (int) round($rmm * 2.4));
        $dot = max(0.16, $rmm * 0.016);
        $cx = $c->x($u);
        $cy = $c->y($v);

        // Each dot is filled from its centre outwards, so seating the centres
        // on the rim itself would put half of every dot outside the element.
        $seat = $rmm - $dot;

        $c->fill($ink);
        for ($i = 0; $i < $count; $i++) {
            $angle = ($i / $count) * 2.0 * M_PI;
            $c->circle_mm($cx + $seat * sin($angle), $cy - $seat * cos($angle),
                $dot, 0.0, 360.0, 'F');
        }
    }

    /**
     * Draw a rope moulding: short arcs leaning around the circumference.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @return void
     */
    protected static function rope(canvas $c, float $u, float $v, float $rmm,
            float $hair, string $ink): void {
        $count = max(28, (int) round($rmm * 1.5));
        $cx = $c->x($u);
        $cy = $c->y($v);
        $bead = max(0.5, $rmm * 0.055);

        // The bead is drawn from its centre, so the centre line has to sit a
        // bead's radius plus half its stroke inside the rim or the moulding
        // paints outside the square the element declares.
        $seat = $rmm - $bead - (($hair * 1.4 * canvas::PT_TO_MM) / 2.0);

        $c->stroke($ink, $hair * 1.4);
        for ($i = 0; $i < $count; $i++) {
            $angle = ($i / $count) * 2.0 * M_PI;
            $px = $cx + $seat * sin($angle);
            $py = $cy - $seat * cos($angle);
            // Each bead is an arc leaning with the tangent, which is what
            // gives a rope its twist rather than a bead-and-reel's symmetry.
            $lean = rad2deg($angle) + 34.0;
            $c->ellipse_mm($px, $py, $bead, $bead * 0.52, $lean, 0.0, 360.0, 'D');
        }
    }

    // ------------------------------------------------------------------
    // Ring lettering.
    // ------------------------------------------------------------------

    /**
     * Set the ring lettering.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $ringradius baseline radius in millimetres
     * @param float $rmm outer radius in millimetres
     * @param array $s the resolved specification
     * @return float|null the bearing the two arcs meet at, or null when there is no junction
     */
    protected static function draw_ring(canvas $c, float $u, float $v, float $ringradius,
            float $rmm, array $s): ?float {
        if ($s['ring'] === self::RING_NONE || $ringradius <= 1.0) {
            return null;
        }

        $c->fill($s['ink']);
        $size = max(3.6, $rmm * 0.29);

        if ($s['ring'] === self::RING_FULL) {
            $phrase = trim((string) $s['top']);
            if ($phrase === '') {
                return null;
            }
            // Repeat until the ring is full, then let autofit settle it. Two
            // repeats is not "until full" — a short phrase on a wide ring left
            // the bottom half of the band bare, which is the one thing a full
            // ring is for. Count the repeats the ring actually needs.
            $unit = $phrase . '  ·  ';
            $repeats = self::repeats_to_fill($c, $unit, $ringradius, $size, $s['family'], 'B');
            $c->arc_text($u, $v, $ringradius, str_repeat($unit, $repeats), [
                'family' => $s['family'],
                'style' => 'B',
                'sizept' => $size,
                'align' => 'top',
                'maxsweep' => 358.0,
            ]);
            return null;
        }

        $top = trim((string) $s['top']);
        if ($top === '') {
            return null;
        }

        $result = $c->arc_text($u, $v, $ringradius, $top, [
            'family' => $s['family'],
            'style' => 'B',
            'sizept' => $size,
            'align' => 'top',
            'maxsweep' => $s['ring'] === self::RING_TOP ? 250.0 : 200.0,
        ]);

        if ($s['ring'] === self::RING_TOP) {
            return null;
        }

        $bottom = trim((string) $s['bottom']);
        // The dividers sit where the arcs actually meet, computed from the top
        // arc's real sweep. A fixed three and nine o'clock is wrong the moment
        // an institution has a long name.
        $junction = min(168.0, $result['sweep'] / 2.0 + self::DIVIDER_GAP);

        if ($bottom !== '') {
            $c->arc_text($u, $v, $ringradius, $bottom, [
                'family' => $s['family'],
                'sizept' => $size * 0.84,
                'align' => 'bottom',
                'maxsweep' => max(24.0, 2.0 * (180.0 - $junction - self::DIVIDER_GAP)),
            ]);
        }

        return $junction;
    }

    /**
     * Draw the divider marks where the two arcs meet.
     *
     * Drawn as geometry, never as a character. A core PDF font has no diamond
     * or star glyph, so a divider set as text renders in the browser and
     * silently disappears from the PDF.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $ringradius baseline radius in millimetres
     * @param float $rmm outer radius in millimetres
     * @param float $junction bearing in degrees
     * @param array $s the resolved specification
     * @return void
     */
    protected static function draw_dividers(canvas $c, float $u, float $v, float $ringradius,
            float $rmm, float $junction, array $s): void {
        $size = max(0.42, $rmm * 0.045);
        $cx = $c->x($u);
        $cy = $c->y($v);

        $c->fill($s['metal'] ?: $s['ink']);
        foreach ([$junction, -$junction] as $bearing) {
            $rad = deg2rad($bearing);
            $px = $cx + $ringradius * sin($rad);
            $py = $cy - $ringradius * cos($rad);
            $c->poly_mm([
                $px, $py - $size,
                $px + $size, $py,
                $px, $py + $size,
                $px - $size, $py,
            ], 'F');
        }
    }

    // ------------------------------------------------------------------
    // Centre marks.
    // ------------------------------------------------------------------

    /**
     * Draw whatever sits in the middle.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the radius the mark must stay inside
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param array $s the resolved specification
     * @return void
     */
    protected static function draw_mark(canvas $c, float $u, float $v, float $markradius,
            float $rmm, float $hair, array $s): void {
        if ($s['mark'] === self::MARK_NONE || $markradius <= 1.5) {
            return;
        }

        $ink = $s['ink'];
        $reversed = ($s['mark'] === self::MARK_REVERSED);
        if ($reversed) {
            $c->fill($ink);
            $c->circle($u, $v, $markradius, 0.0, 360.0, 'F');
            $ink = $s['paper'];
        }

        switch ($s['mark']) {
            case self::MARK_SHIELD:
                self::shield($c, $u, $v, $markradius, $hair, $ink, $s);
                break;
            case self::MARK_CREST:
                self::crest($c, $u, $v, $markradius, $hair, $ink, $s);
                break;
            case self::MARK_BOOK:
                self::book($c, $u, $v, $markradius, $hair, $ink);
                self::legend($c, $u, $v, $markradius, $rmm, $hair, $ink, $s, 0.62);
                break;
            case self::MARK_TORCH:
                self::torch($c, $u, $v, $markradius, $hair, $ink);
                self::legend($c, $u, $v, $markradius, $rmm, $hair, $ink, $s, 0.62);
                break;
            case self::MARK_STAR:
                self::star($c, $u, $v, $markradius * 0.52, $ink);
                self::legend($c, $u, $v, $markradius, $rmm, $hair, $ink, $s, 0.62);
                break;
            case self::MARK_WORDMARK:
                self::wordmark($c, $u, $v, $markradius, $rmm, $hair, $ink, $s);
                break;
            case self::MARK_MONOGRAM:
            case self::MARK_REVERSED:
            default:
                self::monogram($c, $u, $v, $markradius, $rmm, $hair, $ink, $s);
                break;
        }
    }

    /**
     * Initials over a rule over the founding year.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @param array $s the resolved specification
     * @return void
     */
    protected static function monogram(canvas $c, float $u, float $v, float $markradius,
            float $rmm, float $hair, string $ink, array $s): void {
        $text = trim((string) $s['centre']);
        if ($text === '') {
            return;
        }

        $year = trim((string) $s['year']);
        $size = max(5.0, $markradius * 1.05);
        // Three initials need to fit the same chord two do, so scale by length.
        $len = max(1, self::length($text));
        $size = min($size, $markradius * 2.0 / ($len * 0.42));

        $c->fill($ink);
        $lift = $year !== '' ? $markradius * 0.16 : 0.0;
        $c->text_mm($c->x($u), $c->y($v) + $size * canvas::PT_TO_MM * 0.34 - $lift, $text, [
            'family' => $s['family'],
            'style' => 'B',
            'sizept' => $size,
            'anchor' => 'C',
            'tracking' => 0.05,
        ]);

        if ($year === '') {
            return;
        }

        $rule = $markradius * 0.62;
        $ry = $c->y($v) + $markradius * 0.30;
        $c->stroke($ink, $hair);
        $c->line_mm($c->x($u) - $rule, $ry, $c->x($u) + $rule, $ry);

        $c->fill($ink);
        $c->text_mm($c->x($u), $ry + max(1.6, $rmm * 0.10), $year, [
            'family' => $s['family'],
            'sizept' => max(3.2, $rmm * 0.22),
            'anchor' => 'C',
            'tracking' => 0.22,
        ]);
    }

    /**
     * The institution name set in stacked, letterspaced lines.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @param array $s the resolved specification
     * @return void
     */
    protected static function wordmark(canvas $c, float $u, float $v, float $markradius,
            float $rmm, float $hair, string $ink, array $s): void {
        $text = trim((string) $s['centre']);
        if ($text === '') {
            return;
        }

        $lines = self::wrap($text, 3);
        $size = max(3.0, $rmm * 0.30);
        // Shrink until the widest line clears the inner circle.
        for ($pass = 0; $pass < 8; $pass++) {
            $widest = 0.0;
            foreach ($lines as $line) {
                $w = $c->measure($line, $s['family'], 'B', $size)
                    + 0.08 * $size * canvas::PT_TO_MM * max(0, self::length($line) - 1);
                $widest = max($widest, $w);
            }
            if ($widest <= $markradius * 1.68 || $size <= 3.0) {
                break;
            }
            $size = max(3.0, $size * ($markradius * 1.68 / $widest) * 0.98);
        }

        $leading = $size * canvas::PT_TO_MM * 1.30;
        $year = trim((string) $s['year']);
        $count = count($lines);
        $top = $c->y($v) - ($count - 1) * $leading / 2.0 + $size * canvas::PT_TO_MM * 0.32;
        if ($year !== '') {
            $top -= $leading * 0.42;
        }

        $c->fill($ink);
        foreach ($lines as $i => $line) {
            $c->text_mm($c->x($u), $top + $i * $leading, $line, [
                'family' => $s['family'],
                'style' => 'B',
                'sizept' => $size,
                'anchor' => 'C',
                'tracking' => 0.08,
            ]);
        }

        if ($year === '') {
            return;
        }

        $ry = $top + ($count - 1) * $leading + $leading * 0.72;
        $rule = $markradius * 0.46;
        $c->stroke($ink, $hair);
        $c->line_mm($c->x($u) - $rule, $ry, $c->x($u) + $rule, $ry);
        $c->fill($ink);
        $c->text_mm($c->x($u), $ry + max(1.5, $rmm * 0.095), $year, [
            'family' => $s['family'],
            'sizept' => max(3.0, $size * 0.62),
            'anchor' => 'C',
            'tracking' => 0.22,
        ]);
    }

    /**
     * A supporting legend beneath a pictorial charge.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $rmm outer radius in millimetres
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @param array $s the resolved specification
     * @param float $drop where to sit, as a fraction of the inner radius
     * @return void
     */
    protected static function legend(canvas $c, float $u, float $v, float $markradius,
            float $rmm, float $hair, string $ink, array $s, float $drop): void {
        $year = trim((string) $s['year']);
        if ($year === '') {
            return;
        }
        $c->fill($ink);
        $c->text_mm($c->x($u), $c->y($v) + $markradius * $drop, $year, [
            'family' => $s['family'],
            'sizept' => max(3.0, $rmm * 0.20),
            'anchor' => 'C',
            'tracking' => 0.22,
        ]);
    }

    // ------------------------------------------------------------------
    // Heraldic charges. This is the register a college actually expects.
    // ------------------------------------------------------------------

    /**
     * A quartered heraldic shield.
     *
     * The quartering respects the rule of tincture — never colour on colour,
     * never metal on metal — by alternating the ink with the metal or paper.
     * The rule exists for legibility at distance, which is exactly the problem
     * a seal reproduced at 19mm has.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @param array $s the resolved specification
     * @return void
     */
    protected static function shield(canvas $c, float $u, float $v, float $markradius,
            float $hair, string $ink, array $s): void {
        // Sized so the top corners — the shield's furthest points from centre —
        // stay inside the inner keyline rather than crossing it.
        $w = $markradius * 0.66;
        $h = $markradius * 1.05;
        $cx = $c->x($u);
        $cy = $c->y($v) - $markradius * 0.08;

        $outline = self::shield_path($cx, $cy, $w, $h);

        // Quarters, drawn clipped to the shield's bounding box then overlaid
        // with the outline so the escutcheon edge stays crisp.
        $metal = $s['metal'] ?: $s['paper'];
        $c->save();
        $c->clip_mm($cx - $w, $cy - $h * 0.5, $w, $h * 0.55);
        $c->fill($ink);
        $c->poly_mm($outline, 'F');
        $c->restore();

        $c->save();
        $c->clip_mm($cx, $cy + $h * 0.05, $w, $h * 0.95);
        $c->fill($ink);
        $c->poly_mm($outline, 'F');
        $c->restore();

        $c->stroke($ink, $hair * 1.6);
        $c->poly_mm($outline, 'D');

        // The per-pale and per-fess division lines.
        $c->stroke($ink, $hair);
        $c->line_mm($cx, $cy - $h * 0.5, $cx, $cy + $h * 0.5);
        $c->line_mm($cx - $w, $cy + $h * 0.05, $cx + $w, $cy + $h * 0.05);

        // A charge in the two metal quarters so they are not left bare.
        $c->fill($ink);
        self::star_mm($c, $cx + $w * 0.45, $cy - $h * 0.20, $markradius * 0.16, $ink);
        self::star_mm($c, $cx - $w * 0.45, $cy + $h * 0.26, $markradius * 0.16, $ink);
    }

    /**
     * A shield cradled by laurel, with a motto ribbon beneath.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @param array $s the resolved specification
     * @return void
     */
    protected static function crest(canvas $c, float $u, float $v, float $markradius,
            float $hair, string $ink, array $s): void {
        $cx = $c->x($u);
        $cy = $c->y($v) - $markradius * 0.12;
        $w = $markradius * 0.46;
        $h = $markradius * 0.78;

        // The wreath frames the shield from outside it. Drawn first and kept
        // wide, or the leaves crowd the escutcheon and the whole device reads
        // as a smudge at reproduction size.
        self::laurel($c, $cx, $cy + $markradius * 0.10, $markradius * 0.90, $hair, $ink);

        $outline = self::shield_path($cx, $cy, $w, $h);

        // Fill first, then the chief, then the outline last so nothing paints
        // over the escutcheon edge. That edge is the whole silhouette — at
        // 19mm it is often the only part of a crest that still reads.
        $c->fill($s['metal'] ?: $s['paper']);
        $c->poly_mm($outline, 'F');

        // A chief across the top, the commonest heraldic ordinary. Clipped to
        // the shield so it cannot overhang the sides.
        $c->save();
        $c->clip_mm($cx - $w, $cy - $h * 0.5, $w * 2.0, $h * 0.30);
        $c->fill($ink);
        $c->poly_mm($outline, 'F');
        $c->restore();

        $c->stroke($ink, max(0.45, $hair * 2.6));
        $c->poly_mm($outline, 'D');

        $motto = trim((string) $s['motto']);
        if ($motto === '') {
            $motto = trim((string) $s['year']);
        }
        if ($motto === '') {
            return;
        }

        // A ribbon beneath, with the motto on it.
        $ry = $cy + $h * 0.62;
        $rw = $markradius * 0.92;
        $rh = max(1.4, $markradius * 0.22);
        $c->fill($s['metal'] ?: $s['paper']);
        $c->stroke($ink, $hair);
        $c->poly_mm([
            $cx - $rw, $ry - $rh * 0.5,
            $cx + $rw, $ry - $rh * 0.5,
            $cx + $rw * 0.86, $ry,
            $cx + $rw, $ry + $rh * 0.5,
            $cx - $rw, $ry + $rh * 0.5,
            $cx - $rw * 0.86, $ry,
        ], 'FD');

        $c->fill($ink);
        $c->text_mm($cx, $ry + $rh * 0.30, $motto, [
            'family' => $s['family'],
            'sizept' => max(2.8, $markradius * 0.30),
            'anchor' => 'C',
            'tracking' => 0.14,
        ]);
    }

    /**
     * An open book: the classical charge for learning.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @return void
     */
    protected static function book(canvas $c, float $u, float $v, float $markradius,
            float $hair, string $ink): void {
        $cx = $c->x($u);
        $cy = $c->y($v) - $markradius * 0.12;
        $w = $markradius * 0.80;
        $h = $markradius * 0.52;

        $c->stroke($ink, $hair * 1.6);
        // Two leaves meeting at the spine, each with a sagging fore-edge.
        foreach ([-1.0, 1.0] as $side) {
            $c->poly_mm([
                $cx, $cy - $h * 0.72,
                $cx + $side * $w, $cy - $h * 0.46,
                $cx + $side * $w, $cy + $h * 0.72,
                $cx, $cy + $h * 0.44,
            ], 'D');
        }

        // Text lines on each leaf.
        $c->stroke($ink, $hair * 0.75);
        for ($i = 1; $i <= 3; $i++) {
            $t = $i / 4.0;
            $y = $cy - $h * 0.46 + $h * 1.12 * $t;
            $c->line_mm($cx - $w * 0.82, $y - $h * 0.10, $cx - $w * 0.18, $y - $h * 0.02);
            $c->line_mm($cx + $w * 0.18, $y - $h * 0.02, $cx + $w * 0.82, $y - $h * 0.10);
        }

        $c->stroke($ink, $hair * 1.6);
        $c->line_mm($cx, $cy - $h * 0.72, $cx, $cy + $h * 0.44);
    }

    /**
     * A torch: knowledge, enlightenment, and the commonest academic device.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $markradius the inner radius
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @return void
     */
    protected static function torch(canvas $c, float $u, float $v, float $markradius,
            float $hair, string $ink): void {
        $cx = $c->x($u);
        $cy = $c->y($v);
        $h = $markradius * 0.60;

        $c->fill($ink);
        // Flame: three tongues, the centre one tallest. The width ramp is
        // what decides whether this reads as fire or as foliage. A gentle
        // ramp reaches nearly full width one step below the tip, rounding
        // it into a petal, and the charge then looks like a fleur-de-lis
        // on a plinth; a steep ramp keeps the tip sharp. The outer two
        // lean away from the centre so the group flickers rather than
        // sitting symmetrical like a flower.
        foreach ([[0.0, 1.00, 0.125, 0.0], [-0.235, 0.60, 0.092, -0.05],
                    [0.235, 0.60, 0.092, 0.05]] as $tongue) {
            [$offset, $height, $width, $lean] = $tongue;
            $ox = $cx + $markradius * $offset;
            $tip = $cy - $h * (0.30 + $height * 1.05);
            $root = $cy - $h * 0.18;
            $points = [];
            // Right flank, tip to root, then the left flank back up. The lean
            // on the tip is what gives a flame its flicker.
            for ($k = 0; $k <= 12; $k++) {
                $t = $k / 12.0;
                $y = $tip + ($root - $tip) * $t;
                $bulge = $markradius * $width * sin(pow($t, 1.45) * M_PI * 0.96);
                $points[] = $ox + $bulge + $markradius * $lean * (1.0 - $t);
                $points[] = $y;
            }
            for ($k = 12; $k >= 0; $k--) {
                $t = $k / 12.0;
                $y = $tip + ($root - $tip) * $t;
                $bulge = $markradius * $width * sin(pow($t, 1.45) * M_PI * 0.96);
                $points[] = $ox - $bulge + $markradius * $lean * (1.0 - $t);
                $points[] = $y;
            }
            $c->poly_mm($points, 'F');
        }

        // Bowl, shaft and foot.
        $c->poly_mm([
            $cx - $markradius * 0.26, $cy - $h * 0.18,
            $cx + $markradius * 0.26, $cy - $h * 0.18,
            $cx + $markradius * 0.15, $cy + $h * 0.06,
            $cx - $markradius * 0.15, $cy + $h * 0.06,
        ], 'F');
        $c->rect_mm(($cx - $markradius * 0.075) / $c->w(), ($cy + $h * 0.06) / $c->h(),
            $markradius * 0.15, $h * 0.72, 'F');
        $c->poly_mm([
            $cx - $markradius * 0.22, $cy + $h * 0.92,
            $cx + $markradius * 0.22, $cy + $h * 0.92,
            $cx + $markradius * 0.16, $cy + $h * 0.78,
            $cx - $markradius * 0.16, $cy + $h * 0.78,
        ], 'F');
        unset($hair);
    }

    /**
     * A laurel pair cradling the centre.
     *
     * @param canvas $c the canvas
     * @param float $cx centre x in millimetres
     * @param float $cy centre y in millimetres
     * @param float $rmm the wreath radius in millimetres
     * @param float $hair hairline width in points
     * @param string $ink the colour
     * @return void
     */
    protected static function laurel(canvas $c, float $cx, float $cy, float $rmm,
            float $hair, string $ink): void {
        $leaves = 10;

        // Bearings clockwise from twelve o'clock, computed directly rather
        // than handed to an arc primitive: TCPDF measures its arcs
        // counter-clockwise from three o'clock, and mixing the two conventions
        // is what collapses a wreath into a blob on one side.
        $branches = [
            ['from' => 172.0, 'to' => 292.0, 'lean' => -1.0],
            ['from' => 188.0, 'to' => 68.0,  'lean' => 1.0],
        ];

        foreach ($branches as $branch) {
            $stem = [];
            for ($k = 0; $k <= 24; $k++) {
                $t = $k / 24.0;
                $bearing = $branch['from'] + ($branch['to'] - $branch['from']) * $t;
                $rad = deg2rad($bearing);
                $stem[] = $cx + $rmm * sin($rad);
                $stem[] = $cy - $rmm * cos($rad);
            }
            $c->stroke($ink, $hair * 1.1);
            $c->polyline_mm($stem, 'D');

            $c->fill($ink);
            for ($i = 0; $i < $leaves; $i++) {
                $t = ($i + 0.5) / $leaves;
                $bearing = $branch['from'] + ($branch['to'] - $branch['from']) * $t;
                $rad = deg2rad($bearing);
                // Leaves sit just outside the stem so the branch reads as a
                // spine with foliage rather than as a dotted line.
                $seat = $rmm * 1.06;
                $lx = $cx + $seat * sin($rad);
                $ly = $cy - $seat * cos($rad);
                // Tapered towards the tip, as laurel is.
                $len = $rmm * 0.19 * (0.55 + 0.45 * sin($t * M_PI));
                $lean = $bearing + $branch['lean'] * 34.0;
                $c->ellipse_mm($lx, $ly, $len, $len * 0.34, $lean, 0.0, 360.0, 'F');
            }
        }
    }

    /**
     * A five-pointed star at a page fraction.
     *
     * @param canvas $c the canvas
     * @param float $u centre as a width fraction
     * @param float $v centre as a height fraction
     * @param float $rmm the outer radius in millimetres
     * @param string $ink the colour
     * @return void
     */
    protected static function star(canvas $c, float $u, float $v, float $rmm, string $ink): void {
        self::star_mm($c, $c->x($u), $c->y($v), $rmm, $ink);
    }

    /**
     * A five-pointed star at millimetre coordinates.
     *
     * @param canvas $c the canvas
     * @param float $cx centre x in millimetres
     * @param float $cy centre y in millimetres
     * @param float $rmm the outer radius in millimetres
     * @param string $ink the colour
     * @return void
     */
    protected static function star_mm(canvas $c, float $cx, float $cy, float $rmm,
            string $ink): void {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $radius = ($i % 2 === 0) ? $rmm : $rmm * 0.382;
            $angle = ($i / 10.0) * 2.0 * M_PI;
            $points[] = $cx + $radius * sin($angle);
            $points[] = $cy - $radius * cos($angle);
        }
        $c->fill($ink);
        $c->poly_mm($points, 'F');
    }

    /**
     * The outline of a heater shield as a flat coordinate list.
     *
     * @param float $cx centre x in millimetres
     * @param float $cy centre y in millimetres
     * @param float $w half-width in millimetres
     * @param float $h half-height in millimetres
     * @return float[] x1, y1, x2, y2, ...
     */
    protected static function shield_path(float $cx, float $cy, float $w, float $h): array {
        $points = [
            $cx - $w, $cy - $h * 0.5,
            $cx + $w, $cy - $h * 0.5,
            $cx + $w, $cy + $h * 0.02,
        ];
        // The point of the shield, swept rather than mitred.
        $steps = 10;
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $points[] = $cx + $w * cos($t * M_PI * 0.5) * (1.0 - 0.30 * $t);
            $points[] = $cy + $h * (0.02 + 0.48 * sin($t * M_PI * 0.5));
        }
        for ($i = $steps; $i >= 0; $i--) {
            $t = $i / $steps;
            $points[] = $cx - $w * cos($t * M_PI * 0.5) * (1.0 - 0.30 * $t);
            $points[] = $cy + $h * (0.02 + 0.48 * sin($t * M_PI * 0.5));
        }
        $points[] = $cx - $w;
        $points[] = $cy + $h * 0.02;

        return $points;
    }

    // ------------------------------------------------------------------
    // Text helpers.
    // ------------------------------------------------------------------

    /**
     * How many times a phrase has to repeat before it closes a full ring.
     *
     * Measured the same way {@see canvas::arc_text()} measures, so the count
     * this returns is the count that just overfills the band and leaves autofit
     * a small correction rather than a bare half.
     *
     * @param canvas $c the canvas, which owns the measurer
     * @param string $unit the phrase plus its separator
     * @param float $ringradius baseline radius in millimetres
     * @param float $sizept size in points
     * @param string $family font family
     * @param string $style TCPDF style string
     * @return int at least one, capped so a hairline phrase cannot run away
     */
    protected static function repeats_to_fill(canvas $c, string $unit, float $ringradius,
            float $sizept, string $family, string $style): int {
        $circumference = 2.0 * M_PI * $ringradius;
        if ($circumference <= 0.0) {
            return 1;
        }

        // arc_text's default tracking, applied per character exactly as it does.
        $tracking = 0.20 * $sizept * canvas::PT_TO_MM;
        $width = 0.0;
        foreach (type_metrics::split($unit) as $char) {
            $width += $c->measure($char, $family, $style, $sizept) + $tracking;
        }

        if ($width <= 0.0) {
            return 1;
        }

        return (int) min(40, max(1, ceil($circumference / $width)));
    }

    /**
     * Apply the heraldic rule of tincture to the specification.
     *
     * Heraldry's oldest rule is never colour on colour and never metal on
     * metal. It is not decoration: it exists because a device has to read at
     * distance, and two mid-tones of similar value dissolve into each other.
     * That is exactly the problem a seal reproduced at 19mm has.
     *
     * The rule is machine-checkable, so the engine checks it. A site whose
     * brand palette makes the accent role a mid-blue would otherwise get a
     * blue shield charged on a blue field — heraldically illiterate and, more
     * to the point, illegible.
     *
     * A contrast ratio of 3:1 is the threshold, matching WCAG for graphical
     * objects. Below it the metal is dropped for the paper, which always
     * contrasts because the ink was chosen to sit on it.
     *
     * @param array $s the resolved specification
     * @return array the specification with a legible metal
     */
    protected static function tincture(array $s): array {
        $metal = $s['metal'];
        if (!is_string($metal) || $metal === '') {
            $s['metal'] = $s['paper'];
            return $s;
        }

        if (self::contrast($metal, (string) $s['ink']) < 3.0) {
            $s['metal'] = $s['paper'];
        }

        return $s;
    }

    /**
     * The WCAG contrast ratio between two colours.
     *
     * @param string $a a #rrggbb string
     * @param string $b a #rrggbb string
     * @return float the ratio, from 1.0 to 21.0
     */
    protected static function contrast(string $a, string $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return ($la > $lb)
            ? ($la + 0.05) / ($lb + 0.05)
            : ($lb + 0.05) / ($la + 0.05);
    }

    /**
     * Relative luminance of a colour, per WCAG.
     *
     * @param string $hex a #rrggbb string
     * @return float from 0.0 for black to 1.0 for white
     */
    protected static function luminance(string $hex): float {
        [$r, $g, $b] = canvas::to_rgb($hex);

        $channel = function (float $value): float {
            $value /= 255.0;
            return $value <= 0.03928 ? $value / 12.92 : pow(($value + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $channel((float) $r)
            + 0.7152 * $channel((float) $g)
            + 0.0722 * $channel((float) $b);
    }

    /**
     * Initials for an organisation name, for use as a centre monogram.
     *
     * Stop words are dropped, because "The University of Western Australia"
     * abbreviates to UWA and never to TUOWA. Lives here rather than with the
     * caller because more than one place needs the same answer and they must
     * agree — the seal on a certificate and the seal on its badge are supposed
     * to be the same mark.
     *
     * @param string $name the organisation name
     * @return string two or three initials, or a star when nothing survives
     */
    public static function initials(string $name): string {
        $skip = ['the', 'of', 'and', 'for', 'at', 'in', 'a', 'an', 'de', 'du', 'la', 'le'];
        $letters = '';

        foreach (preg_split('/[\s\-–—]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array(\core_text::strtolower($word), $skip, true)) {
                continue;
            }
            $letters .= \core_text::strtoupper(\core_text::substr($word, 0, 1));
            if (\core_text::strlen($letters) >= 3) {
                break;
            }
        }

        return $letters !== '' ? $letters : '★';
    }

    /**
     * Character count, UTF-8 aware.
     *
     * @param string $text the string
     * @return int the number of characters
     */
    protected static function length(string $text): int {
        return count(type_metrics::split($text));
    }

    /**
     * Break a name into at most a given number of balanced lines.
     *
     * @param string $text the name
     * @param int $max the greatest number of lines
     * @return string[] the lines
     */
    protected static function wrap(string $text, int $max): array {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) <= 1) {
            return $words ?: [''];
        }

        $target = (int) ceil(self::length($text) / min($max, count($words)));
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && self::length($candidate) > $target && count($lines) < $max - 1) {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines, 0, $max);
    }
}
