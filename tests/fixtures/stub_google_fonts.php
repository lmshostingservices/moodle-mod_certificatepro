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

namespace mod_certificatepro\local\font;

defined('MOODLE_INTERNAL') || die();

/**
 * A Google Fonts installer that answers from local files instead of the network.
 *
 * The installer's whole job is turning HTTP responses into installed faces, so
 * the only honest way to test it is to give it real font bytes and let it do the
 * conversion for real. This replaces nothing but the one HTTP method: the
 * stylesheet parsing, the face-to-style mapping, the two-phase download, the
 * abort rules and the handover to the font manager are all the shipping code.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_google_fonts extends google_fonts {
    /** @var string The scheme and host the stub hands out for font files. */
    public const FILE_HOST = 'https://fonts.gstatic.invalid/';

    /** @var array<string, array<string, string>> family name to style flag to file path. */
    public static $library = [];

    /** @var bool When true nothing answers, as on a site with no outbound access. */
    public static $offline = false;

    /** @var int How many HTTP requests would have been made. */
    public static $requests = 0;

    /** @var array<int, string> Every URL that would have been requested. */
    public static $urls = [];

    /**
     * Forget everything a previous test set up.
     *
     * @return void
     */
    public static function reset(): void {
        self::$library = [];
        self::$offline = false;
        self::$requests = 0;
        self::$urls = [];
        self::purge_face_cache();
    }

    /**
     * Answer a request from the local library rather than from Google.
     *
     * @param string $url the URL
     * @param string[] $headers extra request headers
     * @return string|null the body, or null on any failure
     */
    protected static function get(string $url, array $headers = []): ?string {
        self::$requests++;
        self::$urls[] = $url;
        self::$failure = self::FAILURE_NONE;

        if (self::$offline) {
            self::$failure = self::FAILURE_NETWORK;
            return null;
        }

        if (strpos($url, self::FILE_HOST) === 0) {
            $path = self::$library[self::family_of_file($url)][self::style_of_file($url)] ?? null;
            if ($path === null || !is_readable($path)) {
                self::$failure = self::FAILURE_NOTFOUND;
                return null;
            }
            return (string) file_get_contents($path);
        }

        [$family, $style] = self::parse_css_request($url);
        if ($family === null) {
            self::$failure = self::FAILURE_NOTFOUND;
            return null;
        }

        if (!isset(self::$library[$family][$style])) {
            // Google answers 400 for a face it does not publish, which the real
            // get() turns into a not-found rather than a network failure.
            self::$failure = self::FAILURE_NOTFOUND;
            return null;
        }

        return "@font-face{font-family:'" . $family . "';font-style:normal;src:url("
            . self::FILE_HOST . rawurlencode($family) . '/' . ($style === '' ? 'r' : $style)
            . ".ttf) format('truetype');}";
    }

    /**
     * The family a stylesheet request is for, and which face of it.
     *
     * @param string $url a css2 request URL
     * @return array{0: string|null, 1: string} the family and the style flag
     */
    protected static function parse_css_request(string $url): array {
        if (!preg_match('#[?&]family=([^:&]+):([^&]+)#', $url, $m)) {
            return [null, ''];
        }

        $family = rawurldecode($m[1]);
        $spec = $m[2];

        $italic = false;
        $weight = 400;
        if (preg_match('#^ital,wght@1,(\d+)$#', $spec, $w)) {
            $italic = true;
            $weight = (int) $w[1];
        } else if (preg_match('#^wght@(\d+)$#', $spec, $w)) {
            $weight = (int) $w[1];
        }

        $style = ($weight >= 700 ? 'B' : '') . ($italic ? 'I' : '');

        return [$family, $style];
    }

    /**
     * The family a font file URL belongs to.
     *
     * @param string $url a font file URL the stub handed out
     * @return string
     */
    protected static function family_of_file(string $url): string {
        $parts = explode('/', substr($url, strlen(self::FILE_HOST)));
        return rawurldecode($parts[0] ?? '');
    }

    /**
     * The style flag a font file URL is for.
     *
     * @param string $url a font file URL the stub handed out
     * @return string
     */
    protected static function style_of_file(string $url): string {
        $parts = explode('/', substr($url, strlen(self::FILE_HOST)));
        $name = basename($parts[1] ?? '', '.ttf');
        return $name === 'r' ? '' : $name;
    }
}
