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

use mod_certificatepro\local\brand;

/**
 * What a page is standing on, and what that does to the palette.
 *
 * Every colour in the plugin is stored as a palette role rather than as a
 * value: a title is "primarydark", a name is "ink", a hairline is "rule". That
 * indirection is what lets one design follow a site's brand, and it is built on
 * one assumption — that the page underneath is white.
 *
 * Most backgrounds keep that promise. A few do not: a bold ground is a
 * saturated field of the brand colour itself, and on one of those the ink role
 * is very nearly the ground. {@see background_library} has always declared
 * which of the two a style is, as its 'ink' hint. This class is what reads it.
 *
 * Resolution happens per page and per render, never by rewriting what the
 * author saved. A design moved onto a dark ground and back off it again comes
 * out byte for byte as it went in, and a design already saved against a dark
 * ground is repaired the next time it is drawn.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ground {
    /** @var string Text sits dark on a light ground: the ordinary case. */
    public const INK_DARK = 'dark';

    /** @var string Text is reversed out of a saturated ground. */
    public const INK_LIGHT = 'light';

    /**
     * @var array<string, float> The least contrast each role may have against the ground.
     *
     * The two text roles a design actually sets body copy in are held at 7:1,
     * which is WCAG AAA for normal text and leaves headroom for the brighter
     * part of a gradient ground that the single reference colour below cannot
     * know about. The secondary text roles are held at 4.5:1, AA for normal
     * text. The roles that only ever draw lines and fills are held at 3:1,
     * WCAG's threshold for a graphical object.
     *
     * Roles not named here are left exactly as the brand produced them. That is
     * deliberate for 'paper' and 'primarywash': both are already near white, so
     * both read perfectly well on a dark ground, and both are used as fills
     * where a lift would be wrong rather than as marks where it would be right.
     */
    protected const FLOORS = [
        'ink' => 7.0,
        'primarydark' => 7.0,
        'primary' => 4.5,
        'muted' => 4.5,
        'accent' => 3.0,
        'primarylight' => 3.0,
        'rule' => 3.0,
    ];

    /** @var array<int, array{ink: string, colour: string|null}> Resolved grounds by page id. */
    protected static array $pagecache = [];

    /**
     * How text must sit on a background style.
     *
     * @param string $style a style identifier from {@see background_library}
     * @return string one of the INK_ constants
     */
    public static function ink_for_style(string $style): string {
        $styles = background_library::styles();

        if (!isset($styles[$style])) {
            return self::INK_DARK;
        }

        return ($styles[$style]['ink'] ?? self::INK_DARK) === self::INK_LIGHT
            ? self::INK_LIGHT
            : self::INK_DARK;
    }

    /**
     * What the given page is standing on.
     *
     * Two things can put a page on a dark ground. The generated artwork drawn
     * across it may be one of the bold styles, which the catalogue says so
     * about; or the author may simply have set the page's own background colour
     * to something dark, which is measurable. The artwork is drawn over the
     * page colour, so when there is artwork the artwork decides.
     *
     * @param \stdClass $page a page record
     * @return array{ink: string, colour: string|null} the ink, and the ground
     *      colour to measure against when the page named one
     */
    public static function for_page(\stdClass $page): array {
        $pageid = (int) ($page->id ?? 0);

        if ($pageid > 0 && isset(self::$pagecache[$pageid])) {
            return self::$pagecache[$pageid];
        }

        $resolved = ['ink' => self::INK_DARK, 'colour' => null];

        $style = self::artwork_style($pageid);
        if ($style !== null) {
            $resolved['ink'] = self::ink_for_style($style);
        } else if (!empty($page->backgroundcolour) && brand::is_hex((string) $page->backgroundcolour)) {
            // No generated artwork, but the author painted the page. A ground
            // that black or white text would struggle on is a dark ground
            // whatever the catalogue would have said about it.
            $colour = brand::normalise_hex((string) $page->backgroundcolour);
            if (brand::readable_ink($colour) === '#ffffff') {
                $resolved = ['ink' => self::INK_LIGHT, 'colour' => $colour];
            }
        }

        if ($pageid > 0) {
            self::$pagecache[$pageid] = $resolved;
        }

        return $resolved;
    }

    /**
     * Forget what was resolved for a page, because its background just changed.
     *
     * @param int $pageid the page, or zero for every page
     * @return void
     */
    public static function forget(int $pageid = 0): void {
        if ($pageid > 0) {
            unset(self::$pagecache[$pageid]);
            return;
        }

        self::$pagecache = [];
    }

    /**
     * The catalogue style of the generated artwork drawn across a page.
     *
     * The artwork element names a stored background by id and the stored
     * background records the style it was drawn from, so the two together are
     * what say whether this page is dark. A model written background records
     * the style it was briefed with, which is the right answer for it too: the
     * brief told the model to paint a deep ground, so it painted one.
     *
     * @param int $pageid the page
     * @return string|null the style identifier, or null when the page has no artwork
     */
    protected static function artwork_style(int $pageid): ?string {
        global $DB;

        if ($pageid <= 0) {
            return null;
        }

        $records = $DB->get_records('certificatepro_elements',
            ['pageid' => $pageid, 'element' => 'artwork'], 'id ASC', 'id, data, hidden');

        foreach ($records as $record) {
            if (!empty($record->hidden)) {
                continue;
            }

            $data = json_decode((string) $record->data, true);
            $backgroundid = is_array($data) ? (int) ($data['backgroundid'] ?? 0) : 0;
            if ($backgroundid <= 0) {
                continue;
            }

            $style = $DB->get_field('certificatepro_backgrounds', 'style',
                ['id' => $backgroundid]);
            if (is_string($style) && $style !== '') {
                return $style;
            }
        }

        return null;
    }

    /**
     * The colour the palette's contrast is measured against.
     *
     * A bold ground is painted from the dark end of the brand ramp, between the
     * ink role and the primarydark role. Text has to clear the lighter of the
     * two, so that is the reference.
     *
     * @param array $palette the design's palette
     * @param string $ink one of the INK_ constants
     * @param string|null $colour a ground colour the page named, when it did
     * @return string a colour in #rrggbb form
     */
    public static function reference_colour(array $palette, string $ink,
            ?string $colour = null): string {
        if ($colour !== null && brand::is_hex($colour)) {
            return brand::normalise_hex($colour);
        }

        if ($ink !== self::INK_LIGHT) {
            return $palette['paper'] ?? '#ffffff';
        }

        $deep = $palette['ink'] ?? '#111111';
        $mid = $palette['primarydark'] ?? $deep;

        return self::luminance($deep) >= self::luminance($mid) ? $deep : $mid;
    }

    /**
     * The palette as this ground needs it.
     *
     * On an ordinary light ground the palette is returned untouched, which is
     * what makes the whole mechanism symmetric: there is nothing to undo when a
     * design moves back off a dark background.
     *
     * On a dark ground each role that draws a mark is measured against the
     * ground and, if it does not clear its floor, lifted towards white until it
     * does. Lifting rather than substituting keeps the hue, so a design stays
     * recognisably the site's own colour, and keeps the hierarchy, because a
     * role that needed little lift stays quieter than one that needed a lot.
     *
     * @param array $palette the design's palette
     * @param string $ink one of the INK_ constants
     * @param string|null $colour a ground colour the page named, when it did
     * @return array<string, string> the palette to resolve roles against
     */
    public static function adapt_palette(array $palette, string $ink,
            ?string $colour = null): array {
        if ($ink !== self::INK_LIGHT) {
            return $palette;
        }

        $reference = self::reference_colour($palette, $ink, $colour);

        foreach (self::FLOORS as $role => $floor) {
            if (!isset($palette[$role]) || !is_string($palette[$role])
                    || !brand::is_hex($palette[$role])) {
                continue;
            }
            $palette[$role] = self::fit($palette[$role], $reference, $floor);
        }

        return $palette;
    }

    /**
     * Move a colour away from the ground until it clears a contrast floor.
     *
     * @param string $hex the colour to move
     * @param string $ground the ground it has to be read against
     * @param float $floor the contrast ratio to reach
     * @return string a colour in #rrggbb form
     */
    protected static function fit(string $hex, string $ground, float $floor): string {
        $hex = brand::normalise_hex($hex);

        if (self::contrast($hex, $ground) >= $floor) {
            return $hex;
        }

        // Towards white on a dark ground, towards black on a light one.
        $direction = self::luminance($ground) < 0.5 ? 1.0 : -1.0;
        $steps = 50;
        $best = $hex;

        for ($step = 1; $step <= $steps; $step++) {
            $best = brand::shade($hex, $direction * ($step / $steps));
            if (self::contrast($best, $ground) >= $floor) {
                return $best;
            }
        }

        // The floor is out of reach on this ground, which happens when the
        // ground itself is a mid tone. The end of the ramp is the most contrast
        // there is, so take it rather than leave the role where it started.
        return $best;
    }

    /**
     * The WCAG 2.1 contrast ratio between two colours.
     *
     * @param string $a a hex colour
     * @param string $b a hex colour
     * @return float from 1.0 to 21.0
     */
    public static function contrast(string $a, string $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return $la > $lb
            ? ($la + 0.05) / ($lb + 0.05)
            : ($lb + 0.05) / ($la + 0.05);
    }

    /**
     * Relative luminance of a colour, per WCAG 2.1.
     *
     * @param string $hex a hex colour
     * @return float from 0.0 for black to 1.0 for white
     */
    public static function luminance(string $hex): float {
        [$r, $g, $b] = brand::to_rgb($hex);

        $channel = static function (int $value): float {
            $v = $value / 255.0;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return (0.2126 * $channel($r)) + (0.7152 * $channel($g)) + (0.0722 * $channel($b));
    }
}
