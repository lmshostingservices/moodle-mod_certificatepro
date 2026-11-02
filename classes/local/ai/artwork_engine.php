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

namespace mod_certificatepro\local\ai;

use mod_certificatepro\local\brand;

/**
 * Draws the decorative artwork that sits behind a certificate.
 *
 * Everything here produces vector artwork rather than pixels. That matters for
 * a document that gets printed and framed: a gradient wash or a guilloche
 * pattern stays crisp at any size, adds a few kilobytes rather than a few
 * megabytes, and takes its colours from the site's own brand.
 *
 * The procedural styles below always work, need no network access and cost
 * nothing. A language model can additionally be asked to compose artwork from
 * a written description, which it returns as vector markup; that path is
 * optional, is only used when an administrator has configured it, and falls
 * back to a procedural style whenever it is unavailable or returns something
 * that does not pass validation.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artwork_engine {
    /**
     * The procedural styles on offer.
     *
     * @return array<string, string>
     */
    public static function styles(): array {
        return [
            'wash' => get_string('artworkstyle_wash', 'mod_certificatepro'),
            'guilloche' => get_string('artworkstyle_guilloche', 'mod_certificatepro'),
            'geometric' => get_string('artworkstyle_geometric', 'mod_certificatepro'),
            'deco' => get_string('artworkstyle_deco', 'mod_certificatepro'),
            'botanical' => get_string('artworkstyle_botanical', 'mod_certificatepro'),
            'waves' => get_string('artworkstyle_waves', 'mod_certificatepro'),
            'rays' => get_string('artworkstyle_rays', 'mod_certificatepro'),
            'mesh' => get_string('artworkstyle_mesh', 'mod_certificatepro'),
            'watermark' => get_string('artworkstyle_watermark', 'mod_certificatepro'),
            'minimal' => get_string('artworkstyle_minimal', 'mod_certificatepro'),
        ];
    }

    /**
     * Draw a background procedurally.
     *
     * @param string $style a key from {@see self::styles()}
     * @param array $palette a palette from {@see brand::palette()}
     * @param float $width the page width in millimetres
     * @param float $height the page height in millimetres
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 for barely there and 1 for bold
     * @return string SVG markup
     */
    public static function procedural(string $style, array $palette, float $width, float $height,
            int $seed = 1, float $intensity = 0.5): string {
        $primary = $palette['primary'] ?? '#0f6cbf';
        $dark = $palette['primarydark'] ?? brand::shade($primary, -0.3);
        $light = $palette['primarylight'] ?? brand::shade($primary, 0.35);
        $wash = $palette['primarywash'] ?? brand::shade($primary, 0.88);
        $accent = $palette['accent'] ?? brand::rotate_hue($primary, 32);

        $body = match ($style) {
            'guilloche' => self::style_guilloche($width, $height, $primary, $dark, $seed, $intensity),
            'geometric' => self::style_geometric($width, $height, $primary, $accent, $wash, $seed, $intensity),
            'deco' => self::style_deco($width, $height, $primary, $dark, $wash, $intensity),
            'botanical' => self::style_botanical($width, $height, $primary, $light, $seed, $intensity),
            'waves' => self::style_waves($width, $height, $primary, $light, $wash, $seed, $intensity),
            'rays' => self::style_rays($width, $height, $primary, $wash, $intensity),
            'mesh' => self::style_mesh($width, $height, $primary, $accent, $light, $seed, $intensity),
            'watermark' => self::style_watermark($width, $height, $primary, $intensity),
            'minimal' => self::style_minimal($width, $height, $primary, $wash, $intensity),
            default => self::style_wash($width, $height, $primary, $light, $wash, $intensity),
        };

        return self::wrap($width, $height, $body);
    }

    /**
     * Wrap artwork in an SVG document sized to the page.
     *
     * @param float $width the page width in millimetres
     * @param float $height the page height in millimetres
     * @param string $body the artwork markup
     * @return string
     */
    protected static function wrap(float $width, float $height, string $body): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . 'mm" height="'
            . $height . 'mm" viewBox="0 0 ' . round($width, 3) . ' ' . round($height, 3)
            . '" preserveAspectRatio="none">' . $body . '</svg>';
    }

    /**
     * A soft corner gradient, the safest background for any certificate.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $light a lighter tint
     * @param string $wash the palest tint
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_wash(float $w, float $h, string $primary, string $light,
            string $wash, float $intensity): string {
        $alpha = round(0.10 + (0.35 * $intensity), 3);
        return '<defs>'
            . '<radialGradient id="cpw1" cx="0" cy="0" r="1.1">'
            . '<stop offset="0" stop-color="' . $primary . '" stop-opacity="' . $alpha . '"/>'
            . '<stop offset="1" stop-color="' . $primary . '" stop-opacity="0"/>'
            . '</radialGradient>'
            . '<radialGradient id="cpw2" cx="1" cy="1" r="1.1">'
            . '<stop offset="0" stop-color="' . $light . '" stop-opacity="' . ($alpha * 0.8) . '"/>'
            . '<stop offset="1" stop-color="' . $light . '" stop-opacity="0"/>'
            . '</radialGradient>'
            . '</defs>'
            . '<rect width="' . $w . '" height="' . $h . '" fill="' . $wash . '" fill-opacity="'
            . round(0.25 * $intensity, 3) . '"/>'
            . '<rect width="' . $w . '" height="' . $h . '" fill="url(#cpw1)"/>'
            . '<rect width="' . $w . '" height="' . $h . '" fill="url(#cpw2)"/>';
    }

    /**
     * Interlaced spirograph curves, the pattern used on banknotes and share certificates.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $dark a darker shade
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_guilloche(float $w, float $h, string $primary, string $dark,
            int $seed, float $intensity): string {
        $cx = $w / 2;
        $cy = $h / 2;
        $radius = min($w, $h) * 0.44;
        $opacity = round(0.10 + (0.22 * $intensity), 3);
        $body = '';

        $rings = 3;
        for ($ring = 0; $ring < $rings; $ring++) {
            $petals = 7 + (($seed + $ring) % 5);
            $inner = $radius * (0.55 + ($ring * 0.14));
            $amplitude = $radius * (0.10 + ($ring * 0.035));
            $points = [];
            $steps = 720;
            for ($i = 0; $i <= $steps; $i++) {
                $theta = (2 * M_PI * $i) / $steps;
                $r = $inner + ($amplitude * sin($petals * $theta + ($ring * 0.7)));
                $points[] = round($cx + ($r * cos($theta)), 2) . ',' . round($cy + ($r * sin($theta)), 2);
            }
            $colour = $ring % 2 === 0 ? $primary : $dark;
            $body .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="'
                . $colour . '" stroke-width="0.18" stroke-opacity="' . $opacity . '"/>';
        }

        // A pair of offset rosettes in the corners keeps the composition from
        // looking like a single stamp in the middle of the page.
        foreach ([[$w * 0.08, $h * 0.12, 0.30], [$w * 0.92, $h * 0.88, 0.30]] as $spot) {
            $points = [];
            $spotradius = $radius * $spot[2];
            for ($i = 0; $i <= 480; $i++) {
                $theta = (2 * M_PI * $i) / 480;
                $r = $spotradius * (0.8 + (0.2 * sin(11 * $theta)));
                $points[] = round($spot[0] + ($r * cos($theta)), 2) . ','
                    . round($spot[1] + ($r * sin($theta)), 2);
            }
            $body .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="'
                . $primary . '" stroke-width="0.14" stroke-opacity="' . ($opacity * 0.8) . '"/>';
        }

        return $body;
    }

    /**
     * Overlapping translucent polygons.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $accent a complementary colour
     * @param string $wash the palest tint
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_geometric(float $w, float $h, string $primary, string $accent,
            string $wash, int $seed, float $intensity): string {
        $body = '<rect width="' . $w . '" height="' . $h . '" fill="' . $wash
            . '" fill-opacity="' . round(0.30 * $intensity, 3) . '"/>';

        $random = self::sequence($seed, 200);
        $count = 14;
        $opacity = round(0.05 + (0.13 * $intensity), 3);

        for ($i = 0; $i < $count; $i++) {
            $sides = 3 + ($i % 4);
            $cx = $random[$i * 3] * $w;
            $cy = $random[($i * 3) + 1] * $h;
            $r = (0.06 + ($random[($i * 3) + 2] * 0.20)) * min($w, $h);
            $rotation = $random[$i] * 360;
            $points = [];
            for ($s = 0; $s < $sides; $s++) {
                $theta = (2 * M_PI * $s / $sides) + deg2rad($rotation);
                $points[] = round($cx + ($r * cos($theta)), 2) . ',' . round($cy + ($r * sin($theta)), 2);
            }
            $colour = $i % 3 === 0 ? $accent : $primary;
            $body .= '<polygon points="' . implode(' ', $points) . '" fill="' . $colour
                . '" fill-opacity="' . $opacity . '"/>';
        }

        return $body;
    }

    /**
     * Fanned art deco rays with a stepped border.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $dark a darker shade
     * @param string $wash the palest tint
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_deco(float $w, float $h, string $primary, string $dark,
            string $wash, float $intensity): string {
        $body = '<rect width="' . $w . '" height="' . $h . '" fill="' . $wash
            . '" fill-opacity="' . round(0.35 * $intensity, 3) . '"/>';

        $opacity = round(0.06 + (0.14 * $intensity), 3);
        $fans = [[0.0, 0.0], [$w, 0.0], [0.0, $h], [$w, $h]];
        $radius = min($w, $h) * 0.55;

        foreach ($fans as $index => $origin) {
            $start = match ($index) {
                0 => 0,
                1 => 90,
                2 => 270,
                default => 180,
            };
            for ($i = 0; $i < 9; $i++) {
                $a1 = deg2rad($start + ($i * 10));
                $a2 = deg2rad($start + ($i * 10) + 4.5);
                $points = [
                    round($origin[0], 2) . ',' . round($origin[1], 2),
                    round($origin[0] + ($radius * cos($a1)), 2) . ',' . round($origin[1] + ($radius * sin($a1)), 2),
                    round($origin[0] + ($radius * cos($a2)), 2) . ',' . round($origin[1] + ($radius * sin($a2)), 2),
                ];
                $body .= '<polygon points="' . implode(' ', $points) . '" fill="' . $primary
                    . '" fill-opacity="' . $opacity . '"/>';
            }
        }

        // A stepped rule inside each long edge.
        $inset = min($w, $h) * 0.045;
        for ($i = 0; $i < 3; $i++) {
            $offset = $inset + ($i * 1.6);
            $body .= '<rect x="' . $offset . '" y="' . $offset . '" width="' . ($w - (2 * $offset))
                . '" height="' . ($h - (2 * $offset)) . '" fill="none" stroke="' . $dark
                . '" stroke-width="' . round(0.5 - ($i * 0.14), 2) . '" stroke-opacity="'
                . round($opacity * 2.2, 3) . '"/>';
        }

        return $body;
    }

    /**
     * Sparse leaf and stem flourishes in two corners.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $light a lighter tint
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_botanical(float $w, float $h, string $primary, string $light,
            int $seed, float $intensity): string {
        $opacity = round(0.10 + (0.24 * $intensity), 3);
        $random = self::sequence($seed, 120);
        $body = '';

        $corners = [
            ['x' => 0.0, 'y' => $h, 'dx' => 1, 'dy' => -1],
            ['x' => $w, 'y' => 0.0, 'dx' => -1, 'dy' => 1],
        ];

        foreach ($corners as $index => $corner) {
            $stems = 5;
            for ($s = 0; $s < $stems; $s++) {
                $length = (0.20 + ($random[($index * 30) + $s] * 0.22)) * min($w, $h);
                $angle = deg2rad(20 + ($s * 14) + ($random[($index * 30) + $s + 10] * 12));
                $x1 = $corner['x'];
                $y1 = $corner['y'];
                $x2 = $x1 + ($corner['dx'] * $length * cos($angle));
                $y2 = $y1 + ($corner['dy'] * $length * sin($angle));
                $cx = $x1 + ($corner['dx'] * $length * 0.45 * cos($angle - 0.35));
                $cy = $y1 + ($corner['dy'] * $length * 0.65 * sin($angle - 0.35));

                $body .= '<path d="M' . round($x1, 2) . ',' . round($y1, 2)
                    . ' Q' . round($cx, 2) . ',' . round($cy, 2)
                    . ' ' . round($x2, 2) . ',' . round($y2, 2)
                    . '" fill="none" stroke="' . $primary . '" stroke-width="0.35" stroke-opacity="'
                    . $opacity . '" stroke-linecap="round"/>';

                // Leaves along the stem.
                for ($leaf = 1; $leaf <= 4; $leaf++) {
                    $t = $leaf / 5;
                    $lx = ((1 - $t) ** 2 * $x1) + (2 * (1 - $t) * $t * $cx) + ($t ** 2 * $x2);
                    $ly = ((1 - $t) ** 2 * $y1) + (2 * (1 - $t) * $t * $cy) + ($t ** 2 * $y2);
                    $size = (1.6 - ($t * 0.7)) * (min($w, $h) / 90);
                    $body .= '<ellipse cx="' . round($lx, 2) . '" cy="' . round($ly, 2)
                        . '" rx="' . round($size * 2.1, 2) . '" ry="' . round($size, 2)
                        . '" transform="rotate(
                            ' . round(rad2deg($angle) * $corner['dx'] * -1, 1)
                            . ' ' . round($lx, 2) . ' ' . round($ly, 2) . '
                        )" fill="' . $light
                        . '" fill-opacity="' . round($opacity * 0.9, 3) . '"/>';
                }
            }
        }

        return $body;
    }

    /**
     * Layered horizontal waves along the foot of the page.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $light a lighter tint
     * @param string $wash the palest tint
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_waves(float $w, float $h, string $primary, string $light,
            string $wash, int $seed, float $intensity): string {
        $body = '';
        $layers = [
            ['colour' => $wash, 'opacity' => 0.55, 'base' => 0.70, 'amp' => 0.055, 'cycles' => 1.6],
            ['colour' => $light, 'opacity' => 0.38, 'base' => 0.80, 'amp' => 0.045, 'cycles' => 2.1],
            ['colour' => $primary, 'opacity' => 0.20, 'base' => 0.90, 'amp' => 0.032, 'cycles' => 2.7],
        ];

        foreach ($layers as $index => $layer) {
            $points = [];
            $steps = 120;
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $x = $w * $t;
                $y = ($h * $layer['base'])
                    + ($h * $layer['amp'] * sin((2 * M_PI * $layer['cycles'] * $t) + $seed + $index));
                $points[] = round($x, 2) . ',' . round($y, 2);
            }
            $points[] = round($w, 2) . ',' . round($h, 2);
            $points[] = '0,' . round($h, 2);
            $body .= '<polygon points="' . implode(' ', $points) . '" fill="' . $layer['colour']
                . '" fill-opacity="' . round($layer['opacity'] * $intensity * 1.6, 3) . '"/>';
        }

        return $body;
    }

    /**
     * A sunburst rising from behind the centre of the page.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $wash the palest tint
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_rays(float $w, float $h, string $primary, string $wash,
            float $intensity): string {
        $cx = $w / 2;
        $cy = $h * 0.42;
        $radius = max($w, $h);
        $opacity = round(0.03 + (0.07 * $intensity), 3);

        $body = '<rect width="' . $w . '" height="' . $h . '" fill="' . $wash
            . '" fill-opacity="' . round(0.30 * $intensity, 3) . '"/>';

        for ($i = 0; $i < 36; $i += 2) {
            $a1 = deg2rad($i * 10);
            $a2 = deg2rad(($i * 10) + 5);
            $points = [
                round($cx, 2) . ',' . round($cy, 2),
                round($cx + ($radius * cos($a1)), 2) . ',' . round($cy + ($radius * sin($a1)), 2),
                round($cx + ($radius * cos($a2)), 2) . ',' . round($cy + ($radius * sin($a2)), 2),
            ];
            $body .= '<polygon points="' . implode(' ', $points) . '" fill="' . $primary
                . '" fill-opacity="' . $opacity . '"/>';
        }

        return $body;
    }

    /**
     * A soft mesh of blurred colour blooms.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $accent a complementary colour
     * @param string $light a lighter tint
     * @param int $seed a number that varies the composition
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_mesh(float $w, float $h, string $primary, string $accent,
            string $light, int $seed, float $intensity): string {
        $random = self::sequence($seed, 60);
        $colours = [$primary, $accent, $light];
        $body = '<defs>';

        for ($i = 0; $i < 5; $i++) {
            $colour = $colours[$i % 3];
            $body .= '<radialGradient id="cpm' . $i . '" cx="0.5" cy="0.5" r="0.5">'
                . '<stop offset="0" stop-color="' . $colour . '" stop-opacity="'
                . round((0.16 + (0.24 * $intensity)), 3) . '"/>'
                . '<stop offset="1" stop-color="' . $colour . '" stop-opacity="0"/>'
                . '</radialGradient>';
        }
        $body .= '</defs>';

        for ($i = 0; $i < 5; $i++) {
            $cx = $random[$i * 2] * $w;
            $cy = $random[($i * 2) + 1] * $h;
            $r = (0.25 + ($random[$i + 20] * 0.30)) * max($w, $h);
            $body .= '<ellipse cx="' . round($cx, 2) . '" cy="' . round($cy, 2)
                . '" rx="' . round($r, 2) . '" ry="' . round($r * 0.72, 2)
                . '" fill="url(#cpm' . $i . ')"/>';
        }

        return $body;
    }

    /**
     * A single very pale mark in the middle of the page.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_watermark(float $w, float $h, string $primary,
            float $intensity): string {
        $cx = $w / 2;
        $cy = $h / 2;
        $r = min($w, $h) * 0.30;
        $opacity = round(0.03 + (0.05 * $intensity), 3);

        $points = [];
        for ($i = 0; $i < 24; $i++) {
            $theta = (2 * M_PI * $i) / 24;
            $radius = $i % 2 === 0 ? $r : $r * 0.74;
            $points[] = round($cx + ($radius * cos($theta)), 2) . ','
                . round($cy + ($radius * sin($theta)), 2);
        }

        return '<polygon points="' . implode(' ', $points) . '" fill="' . $primary
            . '" fill-opacity="' . $opacity . '"/>'
            . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($r * 0.58, 2)
            . '" fill="none" stroke="' . $primary . '" stroke-width="0.6" stroke-opacity="'
            . round($opacity * 2, 3) . '"/>';
    }

    /**
     * Two restrained colour blocks and nothing else.
     *
     * @param float $w page width
     * @param float $h page height
     * @param string $primary the brand colour
     * @param string $wash the palest tint
     * @param float $intensity between 0 and 1
     * @return string
     */
    protected static function style_minimal(float $w, float $h, string $primary, string $wash,
            float $intensity): string {
        $bar = min($w, $h) * 0.022;
        return '<rect width="' . $w . '" height="' . $h . '" fill="' . $wash
            . '" fill-opacity="' . round(0.35 * $intensity, 3) . '"/>'
            . '<rect x="0" y="0" width="' . $bar . '" height="' . $h . '" fill="' . $primary
            . '" fill-opacity="' . round(0.55 + (0.4 * $intensity), 3) . '"/>'
            . '<rect x="0" y="' . ($h - ($bar * 0.4)) . '" width="' . $w . '" height="'
            . ($bar * 0.4) . '" fill="' . $primary . '" fill-opacity="'
            . round(0.25 + (0.3 * $intensity), 3) . '"/>';
    }

    /**
     * A repeatable sequence of numbers between 0 and 1.
     *
     * Deliberately not random: the same seed must always produce the same
     * artwork, otherwise a certificate would look different every time it is
     * downloaded.
     *
     * @param int $seed the starting value
     * @param int $count how many numbers to produce
     * @return float[]
     */
    protected static function sequence(int $seed, int $count): array {
        $values = [];
        $state = ($seed * 2654435761) % 4294967296;
        for ($i = 0; $i < $count; $i++) {
            $state = (($state * 1103515245) + 12345) % 2147483648;
            $values[] = $state / 2147483648;
        }
        return $values;
    }

    /**
     * A vector award seal.
     *
     * @param string $style rosette, starburst, laurel or plain
     * @param string $colour the seal colour as hex
     * @param array $text the top, centre and bottom wording
     * @param bool $ribbons whether to draw ribbon tails
     * @return string SVG markup
     */
    public static function seal_svg(string $style, string $colour, array $text,
            bool $ribbons = true): string {
        $dark = brand::shade($colour, -0.3);
        $light = brand::shade($colour, 0.4);
        $ink = brand::readable_ink($colour);

        $body = '<defs>'
            . '<linearGradient id="cpsg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $light . '"/>'
            . '<stop offset="0.5" stop-color="' . $colour . '"/>'
            . '<stop offset="1" stop-color="' . $dark . '"/>'
            . '</linearGradient>'
            . '<path id="cpsarc-top" d="M 20,50 A 30,30 0 0,1 80,50" fill="none"/>'
            . '<path id="cpsarc-bottom" d="M 22,50 A 28,28 0 0,0 78,50" fill="none"/>'
            . '</defs>';

        if ($ribbons) {
            $body .= '<path d="M38,72 L30,98 L44,90 L50,99 L56,90 L70,98 L62,72 Z" fill="'
                . $dark . '"/>';
        }

        switch ($style) {
            case 'starburst':
                $points = [];
                for ($i = 0; $i < 40; $i++) {
                    $theta = (2 * M_PI * $i) / 40;
                    $r = $i % 2 === 0 ? 44 : 37;
                    $points[] = round(50 + ($r * cos($theta)), 2) . ','
                        . round(50 + ($r * sin($theta)), 2);
                }
                $body .= '<polygon points="' . implode(' ', $points) . '" fill="url(#cpsg)"/>';
                break;

            case 'laurel':
                $body .= '<circle cx="50" cy="50" r="34" fill="url(#cpsg)"/>';
                for ($side = -1; $side <= 1; $side += 2) {
                    for ($i = 0; $i < 9; $i++) {
                        $angle = deg2rad(200 + ($i * 15));
                        $lx = 50 + ($side * 40 * cos($angle));
                        $ly = 50 - (40 * sin($angle));
                        $body .= '<ellipse cx="' . round($lx, 2) . '" cy="' . round($ly, 2)
                            . '" rx="5.2" ry="2.2" transform="rotate(
                                '
                                . round(rad2deg($angle) * $side, 1) . ' ' . round($lx, 2) . ' '
                                . round($ly, 2) . '
                            )" fill="' . $dark . '" fill-opacity="0.85"/>';
                    }
                }
                break;

            case 'plain':
                $body .= '<circle cx="50" cy="50" r="42" fill="url(#cpsg)"/>';
                break;

            default:
                $points = [];
                for ($i = 0; $i < 48; $i++) {
                    $theta = (2 * M_PI * $i) / 48;
                    $r = 44 - (3.4 * abs(sin(12 * $theta)));
                    $points[] = round(50 + ($r * cos($theta)), 2) . ','
                        . round(50 + ($r * sin($theta)), 2);
                }
                $body .= '<polygon points="' . implode(' ', $points) . '" fill="url(#cpsg)"/>';
                break;
        }

        $body .= '<circle cx="50" cy="50" r="31" fill="none" stroke="' . $ink
            . '" stroke-width="0.9" stroke-opacity="0.55"/>';
        $body .= '<circle cx="50" cy="50" r="28" fill="none" stroke="' . $ink
            . '" stroke-width="0.4" stroke-opacity="0.4"/>';

        $top = trim((string) ($text['top'] ?? ''));
        $centre = trim((string) ($text['centre'] ?? ''));
        $bottom = trim((string) ($text['bottom'] ?? ''));

        if ($top !== '') {
            $body .= '<text font-family="serif" font-size="6.5" font-weight="bold" fill="' . $ink
                . '" letter-spacing="1.1"><textPath href="#cpsarc-top" startOffset="50%" '
                . 'text-anchor="middle">' . self::escape($top) . '</textPath></text>';
        }
        if ($bottom !== '') {
            $body .= '<text font-family="serif" font-size="6" fill="' . $ink
                . '" letter-spacing="1"><textPath href="#cpsarc-bottom" startOffset="50%" '
                . 'text-anchor="middle">' . self::escape($bottom) . '</textPath></text>';
        }
        if ($centre !== '') {
            $body .= '<text x="50" y="54" font-family="serif" font-size="13" font-weight="bold" '
                . 'text-anchor="middle" fill="' . $ink . '">' . self::escape($centre) . '</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" '
            . 'width="100" height="100">' . $body . '</svg>';
    }

    /**
     * A small SVG preview of a page frame, for the designer canvas.
     *
     * @param string $style a frame style identifier
     * @param string $colour the frame colour as hex
     * @param string $wash a pale tint used by banded frames
     * @param float $w the page width in millimetres
     * @param float $h the page height in millimetres
     * @param float $inset the frame inset in millimetres
     * @param float $thickness the line weight in millimetres
     * @return string SVG markup
     */
    public static function border_preview_svg(string $style, string $colour, string $wash,
            float $w, float $h, float $inset, float $thickness): string {
        $x = $inset;
        $y = $inset;
        $iw = max(1.0, $w - (2 * $inset));
        $ih = max(1.0, $h - (2 * $inset));
        $body = '';

        $rect = static function (float $rx, float $ry, float $rw, float $rh, float $weight)
                use ($colour): string {
            return '<rect x="' . round($rx, 2) . '" y="' . round($ry, 2) . '" width="'
                . round($rw, 2) . '" height="' . round($rh, 2) . '" fill="none" stroke="'
                . $colour . '" stroke-width="' . round($weight, 2) . '"/>';
        };

        switch ($style) {
            case 'hairline':
                $body = $rect($x, $y, $iw, $ih, $thickness);
                break;

            case 'double':
                $gap = max(1.5, $thickness * 3);
                $body = $rect($x, $y, $iw, $ih, $thickness)
                    . $rect($x + $gap, $y + $gap, $iw - (2 * $gap), $ih - (2 * $gap), $thickness * 0.5);
                break;

            case 'corners':
                $arm = min($iw, $ih) * 0.14;
                $weight = round($thickness * 1.8, 2);
                $line = static function (float $x1, float $y1, float $x2, float $y2)
                        use ($colour, $weight): string {
                    return '<line x1="' . round($x1, 2) . '" y1="' . round($y1, 2) . '" x2="'
                        . round($x2, 2) . '" y2="' . round($y2, 2) . '" stroke="' . $colour
                        . '" stroke-width="' . $weight . '"/>';
                };
                $body = $line($x, $y, $x + $arm, $y) . $line($x, $y, $x, $y + $arm)
                    . $line($x + $iw - $arm, $y, $x + $iw, $y) . $line($x + $iw, $y, $x + $iw, $y + $arm)
                    . $line($x, $y + $ih - $arm, $x, $y + $ih) . $line($x, $y + $ih, $x + $arm, $y + $ih)
                    . $line($x + $iw - $arm, $y + $ih, $x + $iw, $y + $ih)
                    . $line($x + $iw, $y + $ih - $arm, $x + $iw, $y + $ih);
                break;

            case 'band':
                $band = max(4.0, min($iw, $ih) * 0.035);
                $body = '<rect x="' . $x . '" y="' . $y . '" width="' . $iw . '" height="' . $band
                    . '" fill="' . $colour . '"/>'
                    . '<rect x="' . $x . '" y="' . ($y + $ih - $band) . '" width="' . $iw
                    . '" height="' . $band . '" fill="' . $colour . '"/>'
                    . '<rect x="' . $x . '" y="' . ($y + $band) . '" width="' . $iw . '" height="'
                    . ($band * 0.28) . '" fill="' . $wash . '"/>';
                break;

            case 'guilloche':
                $body = self::guilloche_band_svg($x, $y, $iw, $ih, $colour, $thickness)
                    . $rect($x, $y, $iw, $ih, $thickness);
                break;

            case 'deco':
                $body = $rect($x, $y, $iw, $ih, $thickness);
                for ($i = 1; $i <= 3; $i++) {
                    $offset = $i * 2.2;
                    $body .= $rect(
                        $x + $offset,
                        $y + $offset,
                        $iw - (2 * $offset),
                        $ih - (2 * $offset),
                        max(0.1, $thickness - ($i * 0.18))
                    );
                }
                break;

            default:
                $gap = max(2.0, $thickness * 3.5);
                $dot = max(1.2, $thickness * 2.4);
                $body = $rect($x, $y, $iw, $ih, $thickness * 1.6)
                    . $rect($x + $gap, $y + $gap, $iw - (2 * $gap), $ih - (2 * $gap), $thickness * 0.4);
                foreach ([[$x + $gap, $y + $gap], [$x + $iw - $gap, $y + $gap],
                          [$x + $gap, $y + $ih - $gap], [$x + $iw - $gap, $y + $ih - $gap]] as $corner) {
                    $body .= '<rect x="' . round($corner[0] - ($dot / 2), 2) . '" y="'
                        . round($corner[1] - ($dot / 2), 2) . '" width="' . round($dot, 2)
                        . '" height="' . round($dot, 2) . '" fill="' . $colour . '"/>';
                }
                break;
        }

        return self::wrap($w, $h, $body);
    }

    /**
     * The interlaced band a guilloche frame draws inside its own edges.
     *
     * Mirrors the geometry the PDF renderer uses, so the designer preview and
     * the printed certificate show the same pattern.
     *
     * @param float $x the frame left edge in millimetres
     * @param float $y the frame top edge in millimetres
     * @param float $w the frame width in millimetres
     * @param float $h the frame height in millimetres
     * @param string $colour the line colour as hex
     * @param float $thickness the frame line weight in millimetres
     * @return string SVG markup
     */
    public static function guilloche_band_svg(float $x, float $y, float $w, float $h,
            string $colour, float $thickness): string {
        $band = max(3.0, min($w, $h) * 0.022);
        $steps = 180;
        $weight = max(0.08, $thickness * 0.22);
        $body = '';

        foreach ([0, 1] as $pass) {
            $phase = $pass * M_PI / 2;

            $cycles = 26 + ($pass * 5);
            $top = [];
            $bottom = [];
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $px = $x + ($w * $t);
                $offset = ($band / 2) * sin((2 * M_PI * $cycles * $t) + $phase);
                $top[] = round($px, 2) . ',' . round($y + ($band / 2) + $offset, 2);
                $bottom[] = round($px, 2) . ',' . round($y + $h - ($band / 2) + $offset, 2);
            }

            $cycles = 18 + ($pass * 4);
            $left = [];
            $right = [];
            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $py = $y + ($h * $t);
                $offset = ($band / 2) * sin((2 * M_PI * $cycles * $t) + $phase);
                $left[] = round($x + ($band / 2) + $offset, 2) . ',' . round($py, 2);
                $right[] = round($x + $w - ($band / 2) + $offset, 2) . ',' . round($py, 2);
            }

            foreach ([$top, $bottom, $left, $right] as $run) {
                $body .= '<polyline points="' . implode(' ', $run) . '" fill="none" stroke="'
                    . $colour . '" stroke-width="' . round($weight, 3) . '" stroke-opacity="0.85"/>';
            }
        }

        return $body;
    }

    /**
     * Escape a string for inclusion in SVG text.
     *
     * @param string $value the text to escape
     * @return string
     */
    protected static function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
