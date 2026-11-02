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

/**
 * Access to the Google Fonts library, installed on demand.
 *
 * The whole library is around two thousand families and well over a gigabyte of
 * font files, so bundling it in a plugin ZIP is not an option — the marketplace
 * would reject it and no administrator would thank us. What ships instead is
 * the catalogue: family names, categories, variants and script coverage. An
 * administrator browses the full library, clicks a family, and this downloads
 * just that family and hands it to the existing font manager, which converts it
 * for embedding in the PDF.
 *
 * A site with no outbound access still works. A seed catalogue is bundled, and
 * every text style combination names a fallback that is always present, so a
 * certificate renders correctly whether or not the family was ever installed.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_fonts {
    /** @var string Where the catalogue is fetched from. */
    protected const CATALOGUE_URL = 'https://www.googleapis.com/webfonts/v1/webfonts';

    /** @var string The stylesheet endpoint that resolves a family to font files. */
    protected const CSS_URL = 'https://fonts.googleapis.com/css2';

    /**
     * A user agent old enough that Google serves TrueType rather than WOFF2.
     *
     * TCPDF cannot read WOFF2. Asking as an old browser is the documented way
     * to be handed a .ttf, and it is what makes the one-click install work at
     * all.
     */
    protected const TTF_USER_AGENT = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)';

    /** @var int How long a fetched catalogue is trusted, in seconds. */
    protected const CATALOGUE_TTL = 2592000;

    /** @var string The plugin config name the catalogue is cached under. */
    protected const CACHE_KEY = 'googlefontscatalogue';

    /**
     * The catalogue, refreshing it from Google if the cached copy is stale.
     *
     * Never throws and never blocks a page: if the fetch fails the bundled seed
     * is returned, and the administrator is told the catalogue is a partial one.
     *
     * @param bool $allowfetch whether a network fetch may be attempted
     * @return array[] each entry has family, category, variants and subsets
     */
    public static function catalogue(bool $allowfetch = true): array {
        $cached = get_config('mod_certificatepro', self::CACHE_KEY);
        $fetched = (int) get_config('mod_certificatepro', self::CACHE_KEY . 'time');

        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && $decoded) {
                if (!$allowfetch || (time() - $fetched) < self::CATALOGUE_TTL) {
                    return $decoded;
                }
            }
        }

        if ($allowfetch) {
            $fresh = self::fetch_catalogue();
            if ($fresh) {
                set_config(self::CACHE_KEY, json_encode($fresh), 'mod_certificatepro');
                set_config(self::CACHE_KEY . 'time', time(), 'mod_certificatepro');
                return $fresh;
            }
        }

        return self::seed();
    }

    /**
     * Whether the catalogue currently in use is the full library or the seed.
     *
     * @return bool
     */
    public static function is_full_catalogue(): bool {
        $cached = get_config('mod_certificatepro', self::CACHE_KEY);
        if (!$cached) {
            return false;
        }
        $decoded = json_decode($cached, true);
        return is_array($decoded) && count($decoded) > count(self::seed());
    }

    /**
     * Fetch the catalogue from the Google Fonts Developer API.
     *
     * The API needs a key, which the administrator supplies in plugin settings.
     * Without one this returns nothing and the seed is used, which is a
     * perfectly workable state — the seed already covers every family the
     * bundled text style combinations need.
     *
     * @return array[] the catalogue, or an empty array on any failure
     */
    protected static function fetch_catalogue(): array {
        $key = trim((string) get_config('mod_certificatepro', 'googlefontskey'));
        if ($key === '') {
            return [];
        }

        $url = self::CATALOGUE_URL . '?sort=popularity&key=' . urlencode($key);
        $body = self::get($url, []);
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!isset($decoded['items']) || !is_array($decoded['items'])) {
            return [];
        }

        $out = [];
        foreach ($decoded['items'] as $item) {
            if (empty($item['family'])) {
                continue;
            }
            $out[] = [
                'family' => (string) $item['family'],
                'category' => (string) ($item['category'] ?? 'sans-serif'),
                'variants' => array_values((array) ($item['variants'] ?? ['regular'])),
                'subsets' => array_values((array) ($item['subsets'] ?? ['latin'])),
            ];
        }

        return $out;
    }

    /**
     * Resolve a family and weight to a downloadable TrueType URL.
     *
     * @param string $family the family name
     * @param int $weight the numeric weight
     * @param bool $italic whether to ask for the italic
     * @return string|null the URL, or null if the family has no such face
     */
    public static function ttf_url(string $family, int $weight = 400, bool $italic = false): ?string {
        $spec = $italic
            ? 'ital,wght@1,' . $weight
            : 'wght@' . $weight;
        $url = self::CSS_URL . '?family=' . rawurlencode($family) . ':' . $spec;

        $css = self::get($url, ['User-Agent: ' . self::TTF_USER_AGENT]);
        if ($css === null) {
            return null;
        }

        if (preg_match('#url\((https://[^)]+\.ttf)\)#i', $css, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Download one family at the given weights and install each face.
     *
     * Faces are installed through the existing font manager, so they get the
     * same conversion, the same script-coverage detection and the same
     * catalogue entry as a font an administrator uploaded by hand.
     *
     * @param string $family the family name
     * @param int[] $weights the numeric weights to install
     * @return array a report with the installed faces and any failures
     */
    public static function install(string $family, array $weights = [400, 700]): array {
        $report = ['family' => $family, 'installed' => [], 'failed' => []];

        foreach ($weights as $weight) {
            $weight = (int) $weight;
            $url = self::ttf_url($family, $weight);

            if ($url === null) {
                $report['failed'][] = ['weight' => $weight, 'reason' => 'nosuchface'];
                continue;
            }

            $binary = self::get($url, ['User-Agent: ' . self::TTF_USER_AGENT], true);
            if ($binary === null || strlen($binary) < 1024) {
                $report['failed'][] = ['weight' => $weight, 'reason' => 'downloadfailed'];
                continue;
            }

            // A TrueType or OpenType file starts with one of these signatures.
            $magic = substr($binary, 0, 4);
            if ($magic !== "\x00\x01\x00\x00" && $magic !== 'true' && $magic !== 'OTTO'
                    && $magic !== 'ttcf') {
                $report['failed'][] = ['weight' => $weight, 'reason' => 'notafont'];
                continue;
            }

            $path = make_request_directory() . '/'
                . preg_replace('/[^a-z0-9]+/i', '', $family) . '-' . $weight . '.ttf';
            file_put_contents($path, $binary);

            try {
                $installed = \mod_certificatepro\local\font_manager::install_from_path(
                    $path,
                    $family . ' ' . $weight
                );
                $report['installed'][] = ['weight' => $weight, 'name' => $installed];
            } catch (\Throwable $e) {
                $report['failed'][] = ['weight' => $weight, 'reason' => $e->getMessage()];
            }
        }

        return $report;
    }

    /**
     * Install every family the bundled text style combinations name.
     *
     * Saves an administrator from hunting through two thousand families to find
     * the twenty-odd the presets actually use.
     *
     * @return array[] one report per family
     */
    public static function install_preset_families(): array {
        $reports = [];
        foreach (\mod_certificatepro\local\type\style_library::required_families() as $family) {
            $reports[] = self::install($family);
        }
        return $reports;
    }

    /**
     * Search the catalogue.
     *
     * @param string $query a substring of the family name
     * @param string $category serif, sans-serif, display, handwriting or monospace
     * @param string $subset a script such as cyrillic or arabic
     * @param int $limit the most results to return
     * @return array[] matching catalogue entries
     */
    public static function search(string $query = '', string $category = '', string $subset = '',
            int $limit = 100): array {
        $query = mb_strtolower(trim($query));
        $out = [];

        foreach (self::catalogue(false) as $entry) {
            if ($query !== '' && mb_strpos(mb_strtolower($entry['family']), $query) === false) {
                continue;
            }
            if ($category !== '' && $entry['category'] !== $category) {
                continue;
            }
            if ($subset !== '' && !in_array($subset, $entry['subsets'], true)) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Perform an HTTP GET through Moodle's curl wrapper.
     *
     * Uses the wrapper rather than raw curl so the site's proxy settings and
     * the security helper's blocked-host rules both apply.
     *
     * @param string $url the URL
     * @param string[] $headers extra request headers
     * @param bool $binary whether the response is binary
     * @return string|null the body, or null on any failure
     */
    protected static function get(string $url, array $headers = [], bool $binary = false): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 3,
            'CURLOPT_RETURNTRANSFER' => 1,
        ];
        if ($binary) {
            $options['CURLOPT_BINARYTRANSFER'] = 1;
        }
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $body = $curl->get($url, [], $options);
        $info = $curl->get_info();

        if ($curl->get_errno() || (int) ($info['http_code'] ?? 0) !== 200 || $body === false) {
            debugging(
                'Certificate Pro could not reach the Google Fonts service: ' . $url,
                DEBUG_DEVELOPER
            );
            return null;
        }

        return (string) $body;
    }

    /**
     * The catalogue bundled with the plugin.
     *
     * Deliberately small, and deliberately not arbitrary: it is every family the
     * twenty text style combinations name, plus a handful of widely used
     * neighbours. A site with no outbound access can still use every preset.
     *
     * @return array[]
     */
    public static function seed(): array {
        $latin = ['latin', 'latin-ext'];
        $wide = ['latin', 'latin-ext', 'cyrillic', 'greek', 'vietnamese'];

        $rows = [
            ['Inter', 'sans-serif', $wide],
            ['Noto Sans', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'greek', 'devanagari']],
            ['Noto Serif', 'serif', ['latin', 'latin-ext', 'cyrillic', 'greek', 'devanagari']],
            ['Lato', 'sans-serif', $latin],
            ['Montserrat', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Poppins', 'sans-serif', ['latin', 'latin-ext', 'devanagari']],
            ['Work Sans', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Source Sans 3', 'sans-serif', $wide],
            ['IBM Plex Sans', 'sans-serif', $wide],
            ['IBM Plex Mono', 'monospace', $wide],
            ['Space Grotesk', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Archivo', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Archivo Black', 'sans-serif', $latin],
            ['Barlow', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Barlow Condensed', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Rubik', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'hebrew', 'arabic']],
            ['Karla', 'sans-serif', $latin],
            ['Jost', 'sans-serif', ['latin', 'latin-ext', 'cyrillic']],
            ['Josefin Sans', 'sans-serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Nunito', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Nunito Sans', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Alegreya Sans', 'sans-serif', ['latin', 'latin-ext', 'greek', 'vietnamese']],
            ['PT Sans', 'sans-serif', ['latin', 'latin-ext', 'cyrillic']],
            ['PT Serif', 'serif', ['latin', 'latin-ext', 'cyrillic']],
            ['Cairo', 'sans-serif', ['arabic', 'latin', 'latin-ext']],
            ['Tajawal', 'sans-serif', ['arabic', 'latin']],
            ['EB Garamond', 'serif', ['latin', 'latin-ext', 'cyrillic', 'greek']],
            ['Cormorant Garamond', 'serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Libre Baskerville', 'serif', $latin],
            ['Playfair Display', 'serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Bodoni Moda', 'serif', $latin],
            ['Lora', 'serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Alegreya', 'serif', ['latin', 'latin-ext', 'cyrillic', 'greek', 'vietnamese']],
            ['Fraunces', 'serif', ['latin', 'latin-ext', 'vietnamese']],
            ['Cinzel', 'serif', $latin],
            ['Great Vibes', 'handwriting', $latin],
            ['Merriweather', 'serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
            ['Roboto', 'sans-serif', $wide],
            ['Open Sans', 'sans-serif', $wide],
            ['Raleway', 'sans-serif', ['latin', 'latin-ext', 'cyrillic', 'vietnamese']],
        ];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'family' => $row[0],
                'category' => $row[1],
                'variants' => ['300', 'regular', '500', '600', '700', '800'],
                'subsets' => $row[2],
            ];
        }

        return $out;
    }
}
