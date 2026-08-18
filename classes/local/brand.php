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

namespace mod_certificatepro\local;

/**
 * Resolves the site's brand: its logo and its primary colour.
 *
 * Moodle has a reliable core API for the logo and no core API at all for the
 * theme's primary colour, so this class walks the active theme and its parents
 * looking for the settings that the Boost family and its descendants use, and
 * falls back to the plugin's own configurable colour.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brand {
    /** @var string The colour Boost has shipped as its default for many years. */
    public const DEFAULT_PRIMARY = '#0f6cbf';

    /** @var string[] Setting names that themes conventionally use for the brand colour. */
    protected const COLOUR_SETTINGS = [
        'brandcolor',
        'brandcolour',
        'primarycolor',
        'primarycolour',
        'themecolor',
        'maincolor',
    ];

    /**
     * The site's primary brand colour as a hex string.
     *
     * Resolution order: the plugin's own override, then the active theme and
     * each of its parents, then the historical Boost default.
     *
     * @return string a colour in #rrggbb form
     */
    public static function primary_colour(): string {
        $cache = \cache::make('mod_certificatepro', 'brandpalette');
        $themename = self::theme_name();
        $cachekey = 'primary_' . $themename;

        $cached = $cache->get($cachekey);
        if ($cached !== false && is_string($cached)) {
            return $cached;
        }

        $override = trim((string) get_config('mod_certificatepro', 'brandcolour'));
        if ($override !== '' && self::is_hex($override)) {
            $colour = self::normalise_hex($override);
            $cache->set($cachekey, $colour);
            return $colour;
        }

        $colour = self::DEFAULT_PRIMARY;
        foreach (self::theme_chain($themename) as $theme) {
            foreach (self::COLOUR_SETTINGS as $setting) {
                $value = get_config('theme_' . $theme, $setting);
                if (is_string($value) && self::is_hex(trim($value))) {
                    $colour = self::normalise_hex(trim($value));
                    break 2;
                }
            }
        }

        $cache->set($cachekey, $colour);
        return $colour;
    }

    /**
     * The name of the theme to take colours from.
     *
     * Deliberately does NOT read $PAGE->theme. Touching that forces Moodle to
     * initialise theme and output, and this is called from
     * certificatepro_add_instance — a back-end write. Moodle's own module
     * generator throws when a plugin initialises output during a database
     * write, so reading $PAGE here made the plugin untestable and made an
     * activity creation far more expensive than it needs to be.
     *
     * $PAGE->theme would only differ from the site theme for a user or course
     * theme override, and a certificate's brand colour should follow the site
     * in any case.
     *
     * @return string the theme name
     */
    protected static function theme_name(): string {
        global $CFG;

        $configured = trim((string) ($CFG->theme ?? ''));

        return $configured !== '' ? $configured : 'boost';
    }

    /**
     * The active theme followed by each of its ancestors.
     *
     * @param string $themename the theme to start from
     * @return string[]
     */
    protected static function theme_chain(string $themename): array {
        $chain = [$themename];
        try {
            $theme = \theme_config::load($themename);
            foreach ($theme->parents as $parent) {
                $chain[] = $parent;
            }
        } catch (\Throwable $e) {
            // A theme that will not load is not worth failing a certificate over.
            debugging('Could not load theme ' . $themename . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if (!in_array('boost', $chain, true)) {
            $chain[] = 'boost';
        }
        return array_values(array_unique($chain));
    }

    /**
     * A harmonious palette derived from the primary colour.
     *
     * @param string|null $primary a hex colour, or null to resolve the site's own
     * @return array<string, string> keyed by role, each value a hex colour
     */
    public static function palette(?string $primary = null): array {
        $primary = $primary !== null && self::is_hex($primary)
            ? self::normalise_hex($primary)
            : self::primary_colour();

        return [
            'primary' => $primary,
            'primarydark' => self::shade($primary, -0.28),
            'primarylight' => self::shade($primary, 0.35),
            'primarywash' => self::shade($primary, 0.88),
            'accent' => self::rotate_hue($primary, 32),
            'ink' => self::shade($primary, -0.72),
            'muted' => '#6b7280',
            'rule' => self::shade($primary, 0.62),
            'paper' => '#ffffff',
        ];
    }

    /**
     * The site logo as a stored file, ready to be written into a PDF.
     *
     * @param bool $compact true to prefer the compact logo
     * @return \stored_file|null the logo file, or null when the site has no logo
     */
    public static function logo_file(bool $compact = false): ?\stored_file {
        $filearea = $compact ? 'logocompact' : 'logo';
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'core_admin',
            $filearea,
            0,
            'itemid',
            false
        );
        if (empty($files)) {
            return null;
        }
        return reset($files);
    }

    /**
     * Copy the site logo to a temporary path so a PDF library can read it.
     *
     * @param bool $compact true to prefer the compact logo
     * @return string|null an absolute path, or null when the site has no logo
     */
    public static function logo_path(bool $compact = false): ?string {
        $file = self::logo_file($compact);
        if ($file === null) {
            $file = self::logo_file(!$compact);
        }
        if ($file === null) {
            return null;
        }
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) ?: 'png';
        $path = make_request_directory() . '/sitelogo.' . $extension;
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * A browser facing URL for the site logo.
     *
     * @param int $maxwidth the largest width to request in pixels
     * @param int $maxheight the largest height to request in pixels
     * @return \moodle_url|null
     */
    public static function logo_url(int $maxwidth = 400, int $maxheight = 200): ?\moodle_url {
        global $OUTPUT;
        $url = $OUTPUT->get_logo_url($maxwidth, $maxheight);
        if (!$url) {
            $url = $OUTPUT->get_compact_logo_url($maxwidth, $maxheight);
        }
        return $url ?: null;
    }

    /**
     * The dimensions of the site logo in pixels.
     *
     * @param bool $compact true to prefer the compact logo
     * @return array{0: int, 1: int} width then height, both zero when unknown
     */
    public static function logo_dimensions(bool $compact = false): array {
        $file = self::logo_file($compact) ?? self::logo_file(!$compact);
        if ($file === null) {
            return [0, 0];
        }
        $info = $file->get_imageinfo();
        if (!$info || empty($info['width']) || empty($info['height'])) {
            return [0, 0];
        }
        return [(int) $info['width'], (int) $info['height']];
    }

    /** @var string The file area holding the four site logo slots. */
    public const LOGO_FILEAREA = 'sitelogo';

    /** @var int How many named logo slots the site has. */
    public const LOGO_SLOTS = 4;

    /**
     * The logo slots, keyed by slot number, each with the name an admin gave it.
     *
     * Organisations rarely have exactly one mark. There is the institution, the
     * accrediting body, the funding partner and the programme, and a
     * certificate often has to carry several at once. Four named slots are
     * filled once at site level and then chosen by name in any design.
     *
     * @param bool $onlyfilled true to omit slots with no picture uploaded
     * @return array<int, string> slot number mapped to its display name
     */
    public static function logo_slots(bool $onlyfilled = false): array {
        $slots = [];

        for ($slot = 1; $slot <= self::LOGO_SLOTS; $slot++) {
            if ($onlyfilled && self::slot_logo_file($slot) === null) {
                continue;
            }
            $name = trim((string) get_config('mod_certificatepro', 'logo' . $slot . 'name'));
            $slots[$slot] = $name !== ''
                ? $name
                : get_string('logoslotdefault', 'mod_certificatepro', $slot);
        }

        return $slots;
    }

    /**
     * The stored file in a logo slot.
     *
     * @param int $slot a slot number from one to four
     * @return \stored_file|null
     */
    public static function slot_logo_file(int $slot): ?\stored_file {
        if ($slot < 1 || $slot > self::LOGO_SLOTS) {
            return null;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'mod_certificatepro',
            self::LOGO_FILEAREA,
            $slot,
            'filename',
            false
        );

        if (empty($files)) {
            return null;
        }

        return reset($files);
    }

    /**
     * A logo slot copied to a temporary path a PDF library can read.
     *
     * @param int $slot a slot number from one to four
     * @return string|null an absolute path, or null when the slot is empty
     */
    public static function slot_logo_path(int $slot): ?string {
        $file = self::slot_logo_file($slot);

        if ($file === null) {
            return null;
        }

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) ?: 'png';
        $path = make_request_directory() . '/slotlogo' . $slot . '.' . $extension;
        $file->copy_content_to($path);

        return $path;
    }

    /**
     * A browser URL for a logo slot.
     *
     * @param int $slot a slot number from one to four
     * @return string|null
     */
    public static function slot_logo_url(int $slot): ?string {
        $file = self::slot_logo_file($slot);

        if ($file === null) {
            return null;
        }

        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * The pixel dimensions of a logo slot.
     *
     * @param int $slot a slot number from one to four
     * @return array{0: int, 1: int} width then height, both zero when unknown
     */
    public static function slot_logo_dimensions(int $slot): array {
        $file = self::slot_logo_file($slot);

        if ($file === null) {
            return [0, 0];
        }

        $info = $file->get_imageinfo();

        if (!$info || empty($info['width']) || empty($info['height'])) {
            return [0, 0];
        }

        return [(int) $info['width'], (int) $info['height']];
    }

    /**
     * Whether a string is a three or six digit hex colour.
     *
     * @param string $value the candidate colour
     * @return bool
     */
    public static function is_hex(string $value): bool {
        return (bool) preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }

    /**
     * Expand and lowercase a hex colour.
     *
     * @param string $value a three or six digit hex colour, with or without a hash
     * @return string a colour in #rrggbb form
     */
    public static function normalise_hex(string $value): string {
        $value = ltrim(trim($value), '#');
        if (strlen($value) === 3) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }
        return '#' . strtolower($value);
    }

    /**
     * Convert a hex colour to its red, green and blue components.
     *
     * @param string $hex a hex colour
     * @return array{0: int, 1: int, 2: int}
     */
    public static function to_rgb(string $hex): array {
        $hex = ltrim(self::normalise_hex($hex), '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Lighten or darken a colour.
     *
     * @param string $hex the colour to shade
     * @param float $amount between -1 for black and 1 for white
     * @return string a colour in #rrggbb form
     */
    public static function shade(string $hex, float $amount): string {
        [$r, $g, $b] = self::to_rgb($hex);
        $target = $amount >= 0 ? 255 : 0;
        $ratio = abs($amount);
        $mix = static function (int $channel) use ($target, $ratio): int {
            return (int) round($channel + (($target - $channel) * $ratio));
        };
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /**
     * Rotate a colour's hue while keeping its saturation and lightness.
     *
     * @param string $hex the colour to rotate
     * @param float $degrees the rotation in degrees
     * @return string a colour in #rrggbb form
     */
    public static function rotate_hue(string $hex, float $degrees): string {
        [$r, $g, $b] = self::to_rgb($hex);
        [$h, $s, $l] = self::rgb_to_hsl($r, $g, $b);
        $h = fmod($h + ($degrees / 360.0) + 1.0, 1.0);
        [$r, $g, $b] = self::hsl_to_rgb($h, $s, $l);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Whether black or white text reads better on the given background.
     *
     * Uses the relative luminance formula from WCAG 2.1.
     *
     * @param string $hex the background colour
     * @return string either #000000 or #ffffff
     */
    public static function readable_ink(string $hex): string {
        [$r, $g, $b] = self::to_rgb($hex);
        $channel = static function (int $value): float {
            $v = $value / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $luminance = (0.2126 * $channel($r)) + (0.7152 * $channel($g)) + (0.0722 * $channel($b));
        return $luminance > 0.179 ? '#000000' : '#ffffff';
    }

    /**
     * Convert red, green and blue to hue, saturation and lightness.
     *
     * @param int $r red from 0 to 255
     * @param int $g green from 0 to 255
     * @param int $b blue from 0 to 255
     * @return array{0: float, 1: float, 2: float} each from 0 to 1
     */
    protected static function rgb_to_hsl(int $r, int $g, int $b): array {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        if ($max === $r) {
            $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
        } else if ($max === $g) {
            $h = (($b - $r) / $d) + 2;
        } else {
            $h = (($r - $g) / $d) + 4;
        }

        return [$h / 6, $s, $l];
    }

    /**
     * Convert hue, saturation and lightness back to red, green and blue.
     *
     * @param float $h hue from 0 to 1
     * @param float $s saturation from 0 to 1
     * @param float $l lightness from 0 to 1
     * @return array{0: int, 1: int, 2: int} each from 0 to 255
     */
    protected static function hsl_to_rgb(float $h, float $s, float $l): array {
        if ($s == 0.0) {
            $value = (int) round($l * 255);
            return [$value, $value, $value];
        }

        $hue2rgb = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + (($q - $p) * 6 * $t);
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + (($q - $p) * ((2 / 3) - $t) * 6);
            }
            return $p;
        };

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($l * $s);
        $p = (2 * $l) - $q;

        return [
            (int) round($hue2rgb($p, $q, $h + (1 / 3)) * 255),
            (int) round($hue2rgb($p, $q, $h) * 255),
            (int) round($hue2rgb($p, $q, $h - (1 / 3)) * 255),
        ];
    }
}
