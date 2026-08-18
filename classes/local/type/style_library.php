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

namespace mod_certificatepro\local\type;

/**
 * Twenty named text style combinations.
 *
 * A teacher picks one and the whole certificate restyles at once: the title,
 * the line above the name, the recipient's name, the descriptive sentence, the
 * small print and the signature block each get their own family, weight, size,
 * tracking, case and palette role. Individual elements keep their own font
 * override, so a combination is a starting point rather than a cage.
 *
 * Every family named here is a real Google Fonts family and every one carries a
 * fallback from the set that is always present, so a certificate still renders
 * correctly on a site where the family was never installed.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class style_library {
    /** @var string[] The six text roles a combination styles. */
    public const ROLES = ['title', 'subtitle', 'recipient', 'body', 'meta', 'signature'];

    /** @var string[] Families that are always available, whatever the site has installed. */
    public const ALWAYS_AVAILABLE = ['DejaVu Sans', 'DejaVu Serif', 'Free Sans', 'Free Serif',
        'Helvetica', 'Times'];

    /**
     * Build one role specification.
     *
     * @param string $family the Google Fonts family
     * @param string $fallback the guaranteed fallback family
     * @param int $weight numeric weight, 300 to 900
     * @param float $size multiplier against the base type scale
     * @param float $tracking letter spacing in em
     * @param string $case none, upper, title or smallcaps
     * @param string $colour the palette role supplying the colour
     * @return array
     */
    protected static function role(string $family, string $fallback, int $weight, float $size,
            float $tracking, string $case, string $colour): array {
        return [
            'family' => $family,
            'fallback' => $fallback,
            'weight' => $weight,
            'size' => $size,
            'tracking' => $tracking,
            'case' => $case,
            'colour' => $colour,
        ];
    }

    /**
     * Every combination, in picker order.
     *
     * @return array[] keyed by combination identifier
     */
    public static function combinations(): array {
        $r = function (...$args) {
            return self::role(...$args);
        };

        return [
            'laurel_crest' => [
                'name' => 'Laurel Crest',
                'mood' => 'formal ceremony, engraved',
                'families' => ['Cinzel', 'EB Garamond'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek'],
                'pairs_with' => ['security', 'graphic', 'atmospheric'],
                'roles' => [
                    'title' => $r('Cinzel', 'Free Serif', 700, 2.05, 0.14, 'upper', 'primary'),
                    'subtitle' => $r('EB Garamond', 'Free Serif', 400, 1.0, 0.05, 'smallcaps', 'muted'),
                    'recipient' => $r('EB Garamond', 'Free Serif', 600, 2.8, -0.015, 'none', 'ink'),
                    'body' => $r('EB Garamond', 'Free Serif', 400, 1.05, 0.0, 'none', 'ink'),
                    'meta' => $r('EB Garamond', 'Free Serif', 500, 0.76, 0.12, 'upper', 'muted'),
                    'signature' => $r('EB Garamond', 'Free Serif', 500, 0.92, 0.04, 'title', 'primarydark'),
                ],
            ],
            'quiet_room' => [
                'name' => 'Quiet Room',
                'mood' => 'calm modern minimal',
                'families' => ['Inter'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek', 'vietnamese'],
                'pairs_with' => ['minimal', 'geometric'],
                'roles' => [
                    'title' => $r('Inter', 'Helvetica', 600, 1.72, 0.16, 'upper', 'ink'),
                    'subtitle' => $r('Inter', 'Helvetica', 400, 0.86, 0.10, 'upper', 'muted'),
                    'recipient' => $r('Inter', 'Helvetica', 700, 2.6, -0.03, 'none', 'primary'),
                    'body' => $r('Inter', 'Helvetica', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Inter', 'Helvetica', 500, 0.74, 0.12, 'upper', 'muted'),
                    'signature' => $r('Inter', 'Helvetica', 500, 0.9, 0.02, 'none', 'primarydark'),
                ],
            ],
            'broadsheet' => [
                'name' => 'Broadsheet',
                'mood' => 'magazine editorial',
                'families' => ['Playfair Display', 'Source Sans 3'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek'],
                'pairs_with' => ['graphic', 'minimal', 'atmospheric'],
                'roles' => [
                    'title' => $r('Playfair Display', 'Free Serif', 700, 2.1, -0.01, 'title', 'ink'),
                    'subtitle' => $r('Source Sans 3', 'Free Sans', 400, 0.9, 0.14, 'upper', 'accent'),
                    'recipient' => $r('Playfair Display', 'Free Serif', 800, 3.0, -0.025, 'none', 'primary'),
                    'body' => $r('Source Sans 3', 'Free Sans', 400, 1.02, 0.0, 'none', 'ink'),
                    'meta' => $r('Source Sans 3', 'Free Sans', 600, 0.75, 0.10, 'upper', 'muted'),
                    'signature' => $r('Playfair Display', 'Free Serif', 500, 0.95, 0.0, 'title', 'primarydark'),
                ],
            ],
            'lab_notes' => [
                'name' => 'Lab Notes',
                'mood' => 'technical, precise',
                'families' => ['IBM Plex Sans', 'IBM Plex Mono'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek', 'vietnamese'],
                'pairs_with' => ['minimal', 'security', 'geometric'],
                'roles' => [
                    'title' => $r('IBM Plex Sans', 'DejaVu Sans', 600, 1.68, 0.02, 'none', 'ink'),
                    'subtitle' => $r('IBM Plex Mono', 'DejaVu Sans', 500, 0.82, 0.08, 'upper', 'accent'),
                    'recipient' => $r('IBM Plex Sans', 'DejaVu Sans', 700, 2.45, -0.02, 'none', 'primary'),
                    'body' => $r('IBM Plex Sans', 'DejaVu Sans', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('IBM Plex Mono', 'DejaVu Sans', 400, 0.78, 0.04, 'upper', 'muted'),
                    'signature' => $r('IBM Plex Sans', 'DejaVu Sans', 500, 0.9, 0.0, 'none', 'primarydark'),
                ],
            ],
            'atelier' => [
                'name' => 'Atelier',
                'mood' => 'luxury fashion house',
                'families' => ['Jost', 'Cormorant Garamond'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic'],
                'pairs_with' => ['minimal', 'atmospheric', 'organic'],
                'roles' => [
                    'title' => $r('Jost', 'Free Sans', 300, 1.55, 0.30, 'upper', 'ink'),
                    'subtitle' => $r('Jost', 'Free Sans', 400, 0.8, 0.22, 'upper', 'muted'),
                    'recipient' => $r('Cormorant Garamond', 'Free Serif', 500, 3.3, -0.02, 'none', 'primary'),
                    'body' => $r('Jost', 'Free Sans', 400, 1.0, 0.01, 'none', 'ink'),
                    'meta' => $r('Jost', 'Free Sans', 400, 0.74, 0.18, 'upper', 'muted'),
                    'signature' => $r('Cormorant Garamond', 'Free Serif', 600, 1.0, 0.02, 'title', 'primarydark'),
                ],
            ],
            'old_quad' => [
                'name' => 'Old Quad',
                'mood' => 'university, scholarly',
                'families' => ['Libre Baskerville', 'Lato'],
                'scripts' => ['latin', 'latin-ext'],
                'pairs_with' => ['security', 'minimal', 'graphic'],
                'roles' => [
                    'title' => $r('Libre Baskerville', 'Times', 400, 1.8, 0.06, 'upper', 'ink'),
                    'subtitle' => $r('Lato', 'Helvetica', 400, 0.88, 0.12, 'upper', 'muted'),
                    'recipient' => $r('Libre Baskerville', 'Times', 700, 2.55, -0.015, 'title', 'primary'),
                    'body' => $r('Lato', 'Helvetica', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Lato', 'Helvetica', 700, 0.75, 0.10, 'upper', 'muted'),
                    'signature' => $r('Lato', 'Helvetica', 700, 0.9, 0.02, 'none', 'primarydark'),
                ],
            ],
            'warm_welcome' => [
                'name' => 'Warm Welcome',
                'mood' => 'friendly and rounded',
                'families' => ['Nunito', 'Nunito Sans'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'vietnamese'],
                'pairs_with' => ['organic', 'geometric', 'atmospheric'],
                'roles' => [
                    'title' => $r('Nunito', 'DejaVu Sans', 700, 1.8, 0.0, 'none', 'ink'),
                    'subtitle' => $r('Nunito Sans', 'DejaVu Sans', 400, 0.92, 0.06, 'none', 'muted'),
                    'recipient' => $r('Nunito', 'DejaVu Sans', 800, 2.8, -0.02, 'none', 'primary'),
                    'body' => $r('Nunito Sans', 'DejaVu Sans', 400, 1.02, 0.0, 'none', 'ink'),
                    'meta' => $r('Nunito Sans', 'DejaVu Sans', 600, 0.76, 0.08, 'upper', 'muted'),
                    'signature' => $r('Nunito Sans', 'DejaVu Sans', 700, 0.92, 0.0, 'none', 'primarydark'),
                ],
            ],
            'launch_day' => [
                'name' => 'Launch Day',
                'mood' => 'startup product energy',
                'families' => ['Space Grotesk', 'Inter'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek'],
                'pairs_with' => ['geometric', 'minimal', 'graphic'],
                'roles' => [
                    'title' => $r('Space Grotesk', 'Helvetica', 500, 1.7, 0.06, 'upper', 'ink'),
                    'subtitle' => $r('Inter', 'Helvetica', 500, 0.84, 0.14, 'upper', 'accent'),
                    'recipient' => $r('Space Grotesk', 'Helvetica', 700, 2.9, -0.035, 'none', 'primary'),
                    'body' => $r('Inter', 'Helvetica', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Inter', 'Helvetica', 500, 0.74, 0.10, 'upper', 'muted'),
                    'signature' => $r('Inter', 'Helvetica', 600, 0.9, 0.0, 'none', 'primarydark'),
                ],
            ],
            'bold_claim' => [
                'name' => 'Bold Claim',
                'mood' => 'brutalist and loud',
                'families' => ['Archivo Black', 'Archivo'],
                'scripts' => ['latin', 'latin-ext'],
                'pairs_with' => ['graphic', 'geometric', 'minimal'],
                'roles' => [
                    'title' => $r('Archivo Black', 'Helvetica', 400, 1.75, -0.02, 'upper', 'ink'),
                    'subtitle' => $r('Archivo', 'Helvetica', 500, 0.85, 0.16, 'upper', 'muted'),
                    'recipient' => $r('Archivo Black', 'Helvetica', 400, 2.85, -0.04, 'none', 'primary'),
                    'body' => $r('Archivo', 'Helvetica', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Archivo', 'Helvetica', 600, 0.75, 0.12, 'upper', 'muted'),
                    'signature' => $r('Archivo', 'Helvetica', 700, 0.92, 0.02, 'upper', 'primarydark'),
                ],
            ],
            'hearthside' => [
                'name' => 'Hearthside',
                'mood' => 'humanist and warm',
                'families' => ['Alegreya', 'Alegreya Sans'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek'],
                'pairs_with' => ['organic', 'atmospheric', 'minimal'],
                'roles' => [
                    'title' => $r('Alegreya', 'Free Serif', 700, 1.95, 0.0, 'none', 'ink'),
                    'subtitle' => $r('Alegreya Sans', 'Free Sans', 400, 0.9, 0.10, 'smallcaps', 'muted'),
                    'recipient' => $r('Alegreya', 'Free Serif', 800, 2.75, -0.02, 'none', 'primary'),
                    'body' => $r('Alegreya Sans', 'Free Sans', 400, 1.02, 0.0, 'none', 'ink'),
                    'meta' => $r('Alegreya Sans', 'Free Sans', 500, 0.76, 0.08, 'upper', 'muted'),
                    'signature' => $r('Alegreya', 'Free Serif', 600, 0.95, 0.0, 'title', 'primarydark'),
                ],
            ],
            'narrow_gauge' => [
                'name' => 'Narrow Gauge',
                'mood' => 'condensed and efficient',
                'families' => ['Barlow Condensed', 'Barlow'],
                'scripts' => ['latin', 'latin-ext', 'vietnamese'],
                'pairs_with' => ['graphic', 'security', 'geometric'],
                'roles' => [
                    'title' => $r('Barlow Condensed', 'DejaVu Sans', 600, 2.0, 0.08, 'upper', 'ink'),
                    'subtitle' => $r('Barlow', 'DejaVu Sans', 500, 0.85, 0.12, 'upper', 'accent'),
                    'recipient' => $r('Barlow Condensed', 'DejaVu Sans', 700, 3.2, -0.01, 'none', 'primary'),
                    'body' => $r('Barlow', 'DejaVu Sans', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Barlow Condensed', 'DejaVu Sans', 500, 0.8, 0.10, 'upper', 'muted'),
                    'signature' => $r('Barlow', 'DejaVu Sans', 600, 0.9, 0.02, 'none', 'primarydark'),
                ],
            ],
            'cut_glass' => [
                'name' => 'Cut Glass',
                'mood' => 'high-contrast display',
                'families' => ['Bodoni Moda', 'Karla'],
                'scripts' => ['latin', 'latin-ext'],
                'pairs_with' => ['minimal', 'atmospheric', 'graphic'],
                'roles' => [
                    'title' => $r('Bodoni Moda', 'Times', 500, 2.0, 0.04, 'upper', 'ink'),
                    'subtitle' => $r('Karla', 'Helvetica', 400, 0.82, 0.20, 'upper', 'muted'),
                    'recipient' => $r('Bodoni Moda', 'Times', 700, 3.4, -0.03, 'none', 'primary'),
                    'body' => $r('Karla', 'Helvetica', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Karla', 'Helvetica', 500, 0.75, 0.16, 'upper', 'muted'),
                    'signature' => $r('Bodoni Moda', 'Times', 500, 0.95, 0.02, 'title', 'primarydark'),
                ],
            ],
            'atlas' => [
                'name' => 'Atlas',
                'mood' => 'neutral and global',
                'families' => ['Noto Serif', 'Noto Sans'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'greek', 'devanagari', 'vietnamese'],
                'pairs_with' => ['minimal', 'security', 'graphic'],
                'roles' => [
                    'title' => $r('Noto Serif', 'DejaVu Serif', 600, 1.85, 0.01, 'none', 'ink'),
                    'subtitle' => $r('Noto Sans', 'DejaVu Sans', 400, 0.88, 0.10, 'upper', 'muted'),
                    'recipient' => $r('Noto Serif', 'DejaVu Serif', 800, 2.7, -0.02, 'none', 'primary'),
                    'body' => $r('Noto Sans', 'DejaVu Sans', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Noto Sans', 'DejaVu Sans', 500, 0.76, 0.08, 'upper', 'muted'),
                    'signature' => $r('Noto Sans', 'DejaVu Sans', 600, 0.9, 0.0, 'none', 'primarydark'),
                ],
            ],
            'crossroads' => [
                'name' => 'Crossroads',
                'mood' => 'multilingual modern',
                'families' => ['Rubik', 'Noto Sans'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic', 'hebrew', 'arabic', 'greek', 'devanagari'],
                'pairs_with' => ['geometric', 'minimal', 'graphic'],
                'roles' => [
                    'title' => $r('Rubik', 'DejaVu Sans', 600, 1.8, 0.04, 'upper', 'ink'),
                    'subtitle' => $r('Noto Sans', 'DejaVu Sans', 400, 0.86, 0.12, 'upper', 'accent'),
                    'recipient' => $r('Rubik', 'DejaVu Sans', 700, 2.7, -0.025, 'none', 'primary'),
                    'body' => $r('Noto Sans', 'DejaVu Sans', 400, 1.02, 0.0, 'none', 'ink'),
                    'meta' => $r('Noto Sans', 'DejaVu Sans', 500, 0.76, 0.10, 'upper', 'muted'),
                    'signature' => $r('Rubik', 'DejaVu Sans', 500, 0.92, 0.0, 'none', 'primarydark'),
                ],
            ],
            'crescent_gate' => [
                'name' => 'Crescent Gate',
                'mood' => 'Arabic-first, dignified',
                'families' => ['Cairo', 'Tajawal'],
                'scripts' => ['arabic', 'latin', 'latin-ext'],
                'pairs_with' => ['organic', 'geometric', 'atmospheric'],
                'roles' => [
                    // No case transforms and no tight tracking: Arabic is
                    // cursive and unicameral, so both would damage it.
                    'title' => $r('Cairo', 'Free Sans', 700, 1.9, 0.02, 'none', 'primary'),
                    'subtitle' => $r('Tajawal', 'Free Sans', 400, 0.9, 0.06, 'none', 'muted'),
                    'recipient' => $r('Cairo', 'Free Sans', 800, 2.8, 0.0, 'none', 'ink'),
                    'body' => $r('Tajawal', 'Free Sans', 400, 1.05, 0.0, 'none', 'ink'),
                    'meta' => $r('Tajawal', 'Free Sans', 500, 0.78, 0.03, 'none', 'muted'),
                    'signature' => $r('Cairo', 'Free Sans', 600, 0.95, 0.0, 'none', 'primarydark'),
                ],
            ],
            'steppe' => [
                'name' => 'Steppe',
                'mood' => 'civic, Cyrillic-ready',
                'families' => ['PT Serif', 'PT Sans'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic'],
                'pairs_with' => ['security', 'minimal', 'graphic'],
                'roles' => [
                    'title' => $r('PT Serif', 'DejaVu Serif', 400, 1.8, 0.06, 'upper', 'ink'),
                    'subtitle' => $r('PT Sans', 'DejaVu Sans', 400, 0.88, 0.10, 'upper', 'muted'),
                    'recipient' => $r('PT Serif', 'DejaVu Serif', 700, 2.65, -0.015, 'none', 'primary'),
                    'body' => $r('PT Sans', 'DejaVu Sans', 400, 1.02, 0.0, 'none', 'ink'),
                    'meta' => $r('PT Sans', 'DejaVu Sans', 700, 0.76, 0.10, 'upper', 'muted'),
                    'signature' => $r('PT Sans', 'DejaVu Sans', 700, 0.9, 0.01, 'none', 'primarydark'),
                ],
            ],
            'slow_craft' => [
                'name' => 'Slow Craft',
                'mood' => 'artisan, characterful',
                'families' => ['Fraunces', 'Work Sans'],
                'scripts' => ['latin', 'latin-ext', 'vietnamese'],
                'pairs_with' => ['organic', 'atmospheric', 'graphic'],
                'roles' => [
                    'title' => $r('Fraunces', 'Free Serif', 600, 1.95, -0.01, 'none', 'ink'),
                    'subtitle' => $r('Work Sans', 'Free Sans', 400, 0.86, 0.14, 'upper', 'muted'),
                    'recipient' => $r('Fraunces', 'Free Serif', 700, 2.85, -0.03, 'none', 'primary'),
                    'body' => $r('Work Sans', 'Free Sans', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Work Sans', 'Free Sans', 500, 0.75, 0.10, 'upper', 'muted'),
                    'signature' => $r('Fraunces', 'Free Serif', 500, 0.95, 0.0, 'title', 'primarydark'),
                ],
            ],
            'deco_line' => [
                'name' => 'Deco Line',
                'mood' => '1920s poster elegance',
                'families' => ['Josefin Sans', 'Lora'],
                'scripts' => ['latin', 'latin-ext', 'cyrillic'],
                'pairs_with' => ['geometric', 'atmospheric', 'graphic'],
                'roles' => [
                    'title' => $r('Josefin Sans', 'Free Sans', 300, 2.0, 0.24, 'upper', 'ink'),
                    'subtitle' => $r('Josefin Sans', 'Free Sans', 400, 0.82, 0.18, 'upper', 'accent'),
                    'recipient' => $r('Lora', 'Free Serif', 600, 2.6, -0.01, 'none', 'primary'),
                    'body' => $r('Lora', 'Free Serif', 400, 1.0, 0.0, 'none', 'ink'),
                    'meta' => $r('Josefin Sans', 'Free Sans', 500, 0.8, 0.14, 'upper', 'muted'),
                    'signature' => $r('Lora', 'Free Serif', 500, 0.92, 0.02, 'title', 'primarydark'),
                ],
            ],
            'fine_hand' => [
                'name' => 'Fine Hand',
                'mood' => 'handwritten flourish',
                'families' => ['Montserrat', 'EB Garamond', 'Great Vibes'],
                'scripts' => ['latin', 'latin-ext'],
                'pairs_with' => ['atmospheric', 'organic', 'security'],
                'roles' => [
                    'title' => $r('Montserrat', 'Free Sans', 600, 1.7, 0.18, 'upper', 'ink'),
                    'subtitle' => $r('EB Garamond', 'Free Serif', 400, 0.95, 0.04, 'none', 'muted'),
                    // The script face is Latin only, so this combination warns
                    // when the recipient's name is not Latin script.
                    'recipient' => $r('Great Vibes', 'Free Serif', 400, 3.3, 0.0, 'title', 'primary'),
                    'body' => $r('EB Garamond', 'Free Serif', 400, 1.08, 0.0, 'none', 'ink'),
                    'meta' => $r('Montserrat', 'Free Sans', 500, 0.74, 0.14, 'upper', 'muted'),
                    'signature' => $r('EB Garamond', 'Free Serif', 500, 0.95, 0.02, 'title', 'primarydark'),
                ],
            ],
            'marigold' => [
                'name' => 'Marigold',
                'mood' => 'bright and inclusive',
                'families' => ['Poppins', 'Lora'],
                'scripts' => ['latin', 'latin-ext', 'devanagari', 'cyrillic'],
                'pairs_with' => ['organic', 'geometric', 'atmospheric'],
                'roles' => [
                    'title' => $r('Poppins', 'Free Sans', 600, 1.8, 0.02, 'none', 'ink'),
                    'subtitle' => $r('Poppins', 'Free Sans', 400, 0.85, 0.14, 'upper', 'accent'),
                    'recipient' => $r('Poppins', 'Free Sans', 700, 2.75, -0.03, 'none', 'primary'),
                    'body' => $r('Lora', 'Free Serif', 400, 1.05, 0.0, 'none', 'ink'),
                    'meta' => $r('Poppins', 'Free Sans', 500, 0.76, 0.10, 'upper', 'muted'),
                    'signature' => $r('Lora', 'Free Serif', 600, 0.95, 0.0, 'title', 'primarydark'),
                ],
            ],
        ];
    }

    /**
     * Whether a combination identifier is known.
     *
     * @param string $id the identifier
     * @return bool
     */
    public static function exists(string $id): bool {
        return isset(self::combinations()[$id]);
    }

    /**
     * A menu of identifiers to display names for a form element.
     *
     * @return string[]
     */
    public static function menu(): array {
        $out = [];
        foreach (self::combinations() as $id => $combo) {
            $out[$id] = $combo['name'] . ' — ' . $combo['mood'];
        }
        return $out;
    }

    /**
     * Resolve one role of one combination against the families actually installed.
     *
     * @param string $id the combination identifier
     * @param string $role one of the six roles
     * @param string[] $available families installed on this site
     * @return array|null the role specification with its family resolved
     */
    public static function resolve(string $id, string $role, array $available): ?array {
        $combos = self::combinations();
        if (!isset($combos[$id]['roles'][$role])) {
            return null;
        }

        $spec = $combos[$id]['roles'][$role];
        $installed = array_map('strtolower', $available);

        if (!in_array(strtolower($spec['family']), $installed, true)) {
            $spec['substituted'] = $spec['family'];
            $spec['family'] = $spec['fallback'];
        }

        return $spec;
    }

    /**
     * Every distinct Google Fonts family used across all combinations.
     *
     * The font manager uses this to offer a one-click install of exactly what
     * the presets need, rather than making an administrator hunt for them.
     *
     * @return string[] sorted, unique family names
     */
    public static function required_families(): array {
        $families = [];
        foreach (self::combinations() as $combo) {
            foreach ($combo['families'] as $family) {
                $families[$family] = true;
            }
        }
        $out = array_keys($families);
        sort($out);
        return $out;
    }

    /**
     * Combinations whose families cover a given script.
     *
     * @param string $script a script key such as cyrillic or arabic
     * @return string[] matching combination identifiers
     */
    public static function for_script(string $script): array {
        $out = [];
        foreach (self::combinations() as $id => $combo) {
            if (in_array($script, $combo['scripts'], true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
