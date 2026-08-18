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
 * Fallback glyph advance widths.
 *
 * Text layout happens once, inside {@see canvas}, and both painters replay the
 * resulting glyph positions. That means parity between the preview and the PDF
 * does not depend on these numbers being right — it is guaranteed by there
 * being only one layout pass. What these numbers affect is *fit*: whether a
 * long institution name is sized correctly for its ring.
 *
 * At PDF render time the canvas is handed TCPDF's own GetStringWidth, which is
 * exact for whatever font is actually loaded, including installed Google Fonts.
 * This table is the fallback for preview and CLI contexts where no PDF object
 * exists.
 *
 * Values are Adobe's Helvetica AFM advance widths in 1/1000 em.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class type_metrics {
    /** @var float Advance for a character absent from the table. */
    const FALLBACK = 0.556;

    /** @var float Bold runs slightly wider than regular at the same size. */
    const BOLD_FACTOR = 1.055;

    /** @var float Italic is near enough the same set width to ignore. */
    const ITALIC_FACTOR = 1.0;

    /** @var array<string,int>|null Lazily built advance table, 1/1000 em. */
    protected static ?array $advances = null;

    /**
     * The advance table, character => width in 1/1000 em.
     *
     * @return array<string,int>
     */
    protected static function advances(): array {
        if (self::$advances !== null) {
            return self::$advances;
        }

        // Adobe Helvetica AFM advance widths, 1/1000 em. Written out one entry
        // per character on purpose: a grouped form is shorter but silently
        // wrong the moment two groups share a character.
        self::$advances = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889,
            '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
            ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
            '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
            ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
            '@' => 1015,
            'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611,
            'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556,
            'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722,
            'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
            'Y' => 667, 'Z' => 611,
            '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278,
            'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222,
            'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
            's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500,
            'y' => 500, 'z' => 500,
            '{' => 334, '|' => 260, '}' => 334, '~' => 584,
            // Divider glyphs a seal ring uses at the three and nine o'clock marks.
            '·' => 333, '•' => 350, '★' => 1000, '◆' => 1000, '✦' => 1000, '—' => 1000,
        ];
        return self::$advances;
    }

    /**
     * Width of a single character as a fraction of the em.
     *
     * @param string $char one character
     * @param string $style TCPDF style string, B and I are recognised
     * @return float advance as a fraction of the em
     */
    public static function advance(string $char, string $style = ''): float {
        $table = self::advances();
        $width = isset($table[$char]) ? $table[$char] / 1000.0 : self::FALLBACK;

        if (stripos($style, 'B') !== false) {
            $width *= self::BOLD_FACTOR;
        }
        if (stripos($style, 'I') !== false) {
            $width *= self::ITALIC_FACTOR;
        }

        return $width;
    }

    /**
     * Width of a run as a fraction of the em, ignoring tracking.
     *
     * @param string $text the run
     * @param string $style TCPDF style string
     * @return float total advance as a fraction of the em
     */
    public static function run(string $text, string $style = ''): float {
        $total = 0.0;
        foreach (self::split($text) as $char) {
            $total += self::advance($char, $style);
        }
        return $total;
    }

    /**
     * Split a string into characters, UTF-8 aware.
     *
     * @param string $text the run
     * @return string[] one entry per character
     */
    public static function split(string $text): array {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? str_split($text) : $chars;
    }
}
