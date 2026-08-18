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
 * The catalogue of generated background styles.
 *
 * Thirty styles in six families, every one drawn as vector from a seed, in the
 * site's own brand colour, and guaranteed to leave the middle of the page quiet
 * enough to read a name across.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class background_library {
    /** @var float Left edge of the region reserved for the title and the recipient's name. */
    public const QUIET_U0 = 0.18;

    /** @var float Right edge of the reserved region. */
    public const QUIET_U1 = 0.82;

    /** @var float Top edge of the reserved region. */
    public const QUIET_V0 = 0.28;

    /** @var float Bottom edge of the reserved region. */
    public const QUIET_V1 = 0.72;

    /**
     * The six families, for grouping the picker.
     *
     * @return array family key to language string identifier
     */
    public static function families(): array {
        return [
            'ceremonial' => 'bgfamilyceremonial',
            'wash' => 'bgfamilywash',
            'bold' => 'bgfamilybold',
            'quiet' => 'bgfamilyquiet',
        ];
    }

    /**
     * Every style, in picker order.
     *
     * Each entry carries the family it belongs to, how present it is on the page
     * and the class that draws it.
     *
     * @return array[] keyed by style identifier
     */
    public static function styles(): array {
        return [
            // Engraved and ceremonial: the register of a share certificate or a
            // university award.
            'engraved_plate' => self::entry('Engraved Plate', 'ceremonial', 'full',
                styles_rich::class, 'dark', [0.12, 0.13, 0.76, 0.74]),
            'guilloche_border' => self::entry('Guilloche Border', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.14, 0.15, 0.72, 0.70]),
            'rosette_corners' => self::entry('Rosette Corners', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.13, 0.14, 0.74, 0.72]),
            'medallion_watermark' => self::entry('Medallion', 'ceremonial', 'full',
                styles_rich::class, 'dark', [0.08, 0.10, 0.84, 0.78]),
            'wax_seal_frame' => self::entry('Wax Seal', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.13, 0.14, 0.74, 0.72]),
            'cartouche_title' => self::entry('Cartouche', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.12, 0.13, 0.76, 0.74]),
            'crest_frame' => self::entry('Crest', 'ceremonial', 'full',
                styles_rich::class, 'dark', [0.11, 0.16, 0.78, 0.70]),
            'laurel_arc' => self::entry('Laurel Arc', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.10, 0.19, 0.80, 0.64]),
            'engine_field' => self::entry('Engine Field', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.10, 0.11, 0.80, 0.78]),
            'ceremonial_bands' => self::entry('Ceremonial Bands', 'ceremonial', 'full',
                styles_engraved::class, 'dark', [0.09, 0.14, 0.82, 0.72]),
            'counter_rosette' => self::entry('Counter Rosette', 'ceremonial', 'full',
                styles_security::class, 'dark', [0.08, 0.30, 0.84, 0.62]),

            // Soft tinted grounds: quieter than the ceremonial set, far more
            // present than bare paper.
            'wash_gradient' => self::entry('Wash', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.78]),
            'corner_bloom' => self::entry('Corner Bloom', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'tonal_bands' => self::entry('Tonal Bands', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'vignette_field' => self::entry('Vignette', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'duotone_drift' => self::entry('Duotone Drift', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'laid_paper' => self::entry('Laid Paper', 'wash', 'soft',
                styles_wash::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'watermark_field' => self::entry('Watermark', 'wash', 'soft',
                styles_wash::class, 'dark', [0.09, 0.10, 0.82, 0.78]),

            // Bold grounds. Built and kept, but not expanded into a full family.
            'deep_field' => self::entry('Deep Field', 'bold', 'full',
                styles_rich::class, 'light', [0.09, 0.10, 0.82, 0.80]),
            'card_panel' => self::entry('Card Panel', 'bold', 'full',
                styles_rich::class, 'dark', [0.12, 0.13, 0.76, 0.74]),
            'colour_column' => self::entry('Column', 'bold', 'full',
                styles_rich::class, 'dark', [0.34, 0.10, 0.58, 0.80]),

            // The five survivors of the first set, kept as quiet options and
            // seated on a tinted ground rather than bare white.
            'aurora_field' => self::entry('Aurora Field', 'quiet', 'soft',
                styles_atmospheric::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'drift_field' => self::entry('Drift Field', 'quiet', 'soft',
                styles_organic::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'isoline_terrain' => self::entry('Isoline Terrain', 'quiet', 'soft',
                styles_organic::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
            'lathe_ribbon' => self::entry('Lathe Ribbon', 'quiet', 'soft',
                styles_security::class, 'dark', [0.10, 0.14, 0.80, 0.72]),
            'relief_ruling' => self::entry('Relief Ruling', 'quiet', 'soft',
                styles_security::class, 'dark', [0.10, 0.10, 0.80, 0.80]),
        ];
    }

    /**
     * Build one catalogue entry.
     *
     * @param string $name the display name
     * @param string $family the family key
     * @param string $presence whisper, balanced or confident
     * @param string $class the class that draws it
     * @param string $ink dark for text on a light ground, reversed for a saturated one
     * @param array $textarea the region left clear for text, as page fractions
     * @return array
     */
    protected static function entry(string $name, string $family, string $presence,
            string $class, string $ink = 'dark', array $textarea = [0.0, 0.0, 1.0, 1.0]): array {
        return [
            'name' => $name,
            'family' => $family,
            'presence' => $presence,
            'class' => $class,
            // How text must sit on this background: dark on a light ground, or
            // reversed out of a saturated one. The typography system reads this
            // rather than assuming the page is white.
            'ink' => $ink,
            // The region of the page the design leaves for text, as fractions.
            // A style that puts a colour block down one edge says so here
            // instead of letting the author discover it by overlapping it.
            'textarea' => $textarea,
        ];
    }

    /**
     * Whether a style identifier is known.
     *
     * @param string $style the identifier
     * @return bool
     */
    public static function exists(string $style): bool {
        return isset(self::styles()[$style]);
    }

    /**
     * A menu of style identifiers to display names, grouped by family.
     *
     * @return array[] family key to identifier-name map
     */
    public static function menu(): array {
        $out = [];
        foreach (self::styles() as $id => $entry) {
            $out[$entry['family']][$id] = $entry['name'];
        }
        return $out;
    }

    /**
     * Record a style onto a canvas.
     *
     * An unknown style, or one whose draw method throws, leaves the canvas
     * empty rather than taking down the certificate — a background is never
     * worth a learner's award.
     *
     * @param string $style the style identifier
     * @param canvas $canvas the canvas to draw into
     * @return bool whether anything was recorded
     */
    public static function record(string $style, canvas $canvas): bool {
        $styles = self::styles();
        if (!isset($styles[$style])) {
            return false;
        }

        $class = $styles[$style]['class'];
        $method = 'draw_' . $style;

        if (!method_exists($class, $method)) {
            return false;
        }

        try {
            $class::$method($canvas);
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not record background "' . $style . '": '
                . $e->getMessage(), DEBUG_DEVELOPER);
            $canvas->reset();
            return false;
        }

        return (bool) $canvas->ops();
    }

    /**
     * Draw a background straight onto a TCPDF page.
     *
     * @param \TCPDF $pdf the document
     * @param string $style the style identifier
     * @param array $palette the nine-role palette
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @param int $seed deterministic seed
     * @param float $ox horizontal offset in millimetres
     * @param float $oy vertical offset in millimetres
     * @return bool whether anything was drawn
     */
    public static function draw_pdf($pdf, string $style, array $palette, float $width,
            float $height, int $seed = 1, float $ox = 0.0, float $oy = 0.0): bool {
        $canvas = new canvas($width, $height, $palette, $seed);
        if (!self::record($style, $canvas)) {
            return false;
        }
        (new tcpdf_painter($pdf, $ox, $oy))->paint($canvas->ops());
        return true;
    }

    /**
     * Render a background as SVG for the designer preview.
     *
     * @param string $style the style identifier
     * @param array $palette the nine-role palette
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @param int $seed deterministic seed
     * @return string the SVG markup, or an empty string
     */
    public static function svg(string $style, array $palette, float $width, float $height,
            int $seed = 1): string {
        $canvas = new canvas($width, $height, $palette, $seed);
        if (!self::record($style, $canvas)) {
            return '';
        }
        $painter = new svg_painter('cp' . substr(md5($style . $seed), 0, 6));
        return $painter->render($canvas->ops(), $width, $height);
    }

    /**
     * Whether a point in page fractions falls inside the reserved text region.
     *
     * Styles use this to drop any element that would sit behind the title or
     * the recipient's name, which is what keeps the guarantee true at every
     * page size rather than only on A4.
     *
     * @param float $u width fraction
     * @param float $v height fraction
     * @param float $margin extra clearance to demand
     * @return bool
     */
    public static function in_quiet_zone(float $u, float $v, float $margin = 0.0): bool {
        return $u > self::QUIET_U0 - $margin && $u < self::QUIET_U1 + $margin
            && $v > self::QUIET_V0 - $margin && $v < self::QUIET_V1 + $margin;
    }

    /**
     * How far a point sits outside the reserved text region.
     *
     * Returns 0 at the centre of the page and 1 exactly on the boundary of the
     * reserved region, growing beyond that. Styles that dissolve rather than
     * stop use this as a smooth gate, so the pattern thins out as it approaches
     * the text instead of being cut off at a rectangle.
     *
     * @param float $u width fraction
     * @param float $v height fraction
     * @return float
     */
    public static function quiet_metric(float $u, float $v): float {
        return max(
            abs($u - 0.5) / ((self::QUIET_U1 - self::QUIET_U0) / 2),
            abs($v - 0.5) / ((self::QUIET_V1 - self::QUIET_V0) / 2)
        );
    }

    /**
     * A smooth 0 to 1 gate that opens as a point leaves the reserved region.
     *
     * @param float $u width fraction
     * @param float $v height fraction
     * @param float $start the metric at which the gate begins to open
     * @param float $width how much metric it takes to open fully
     * @return float
     */
    public static function quiet_gate(float $u, float $v, float $start = 0.90,
            float $width = 0.24): float {
        $t = (self::quiet_metric_soft($u, $v) - $start) / max(0.0001, $width);
        return max(0.0, min(1.0, $t));
    }

    /**
     * An elliptical distance from the centre of the reserved region.
     *
     * The rectangular metric gates a pattern into a hard-cornered rectangle,
     * which reads as a hole punched in the artwork. An ellipse inscribed on the
     * same axes dissolves instead, so the clearance looks like part of the
     * drawing. Styles that fade use this; styles that need a hard containment
     * guarantee still use the rectangular metric.
     *
     * @param float $u width fraction
     * @param float $v height fraction
     * @return float
     */
    public static function quiet_metric_soft(float $u, float $v): float {
        $du = ($u - 0.5) / ((self::QUIET_U1 - self::QUIET_U0) / 2);
        $dv = ($v - 0.5) / ((self::QUIET_V1 - self::QUIET_V0) / 2);
        return sqrt($du * $du + $dv * $dv);
    }

    /**
     * Whether a rectangle in page fractions overlaps the reserved text region.
     *
     * @param float $u left edge
     * @param float $v top edge
     * @param float $uw width
     * @param float $vh height
     * @param float $margin extra clearance to demand
     * @return bool
     */
    public static function rect_hits_quiet_zone(float $u, float $v, float $uw, float $vh,
            float $margin = 0.0): bool {
        return $u < self::QUIET_U1 + $margin && $u + $uw > self::QUIET_U0 - $margin
            && $v < self::QUIET_V1 + $margin && $v + $vh > self::QUIET_V0 - $margin;
    }

    /**
     * Clip to the four bands that surround the reserved text region.
     *
     * A style that wants to draw a continuous pattern everywhere except behind
     * the text calls this and draws its pattern once per band. The union of the
     * bands is the whole page minus the reserved region.
     *
     * @param canvas $canvas the canvas
     * @param callable $draw called once per band
     * @param float $margin extra clearance around the reserved region
     * @return void
     */
    public static function outside_quiet_zone(canvas $canvas, callable $draw,
            float $margin = 0.02): void {
        $u0 = self::QUIET_U0 - $margin;
        $u1 = self::QUIET_U1 + $margin;
        $v0 = self::QUIET_V0 - $margin;
        $v1 = self::QUIET_V1 + $margin;

        $bands = [
            [0.0, 0.0, 1.0, $v0],
            [0.0, $v1, 1.0, 1.0 - $v1],
            [0.0, $v0, $u0, $v1 - $v0],
            [$u1, $v0, 1.0 - $u1, $v1 - $v0],
        ];

        foreach ($bands as $band) {
            $canvas->save();
            $canvas->clip($band[0], $band[1], $band[2], $band[3]);
            $draw($canvas);
            $canvas->restore();
        }
    }
}
