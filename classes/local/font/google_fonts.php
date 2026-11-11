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

use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\type\style_library;

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
     * The four faces a family is installed as, in installation order.
     *
     * The key is the TCPDF style flag the font manager expects; the value is the
     * numeric weight and whether the face is italic. Regular comes first on
     * purpose: TCPDF derives a family's existence from the regular definition
     * file, and a bold face installed without one registers as a family of its
     * own called "somethingb" instead of as the bold of "something".
     */
    public const FACES = [
        '' => [400, false],
        'B' => [700, false],
        'I' => [400, true],
        'BI' => [700, true],
    ];

    /** @var string Plugin config holding, as JSON, faces found not to exist. */
    protected const UNAVAILABLE_CONFIG = 'gfontsunavailablefaces';

    /**
     * @var array[] Faces the bundled designs' own families do not publish,
     *              keyed by family key. Checked against the Google Fonts API:
     *              a request for a weight or an italic axis a family does not
     *              have is refused, and these are the refusals. Only families
     *              the designs actually name are listed, because this exists to
     *              stop the fonts page reporting permanent work on a site that
     *              installed everything before installs kept the answer.
     */
    protected const UNAVAILABLE_SEED = [
        'archivoblack' => ['B', 'I', 'BI'],
        'greatvibes' => ['B', 'I', 'BI'],
        'cairo' => ['I', 'BI'],
        'cinzel' => ['I', 'BI'],
        'spacegrotesk' => ['I', 'BI'],
        'tajawal' => ['I', 'BI'],
    ];

    /** @var string The last HTTP request succeeded. */
    public const FAILURE_NONE = '';

    /** @var string The service could not be reached at all. */
    public const FAILURE_NETWORK = 'network';

    /** @var string The service answered, and does not have what was asked for. */
    public const FAILURE_NOTFOUND = 'notfound';

    /** @var int How many times to offer a face to the file pool before giving up. */
    protected const STORE_ATTEMPTS = 3;

    /** @var int Microseconds to wait after a failed store, multiplied by the attempt. */
    protected const STORE_RETRY_PAUSE = 400000;

    /** @var string Why the last HTTP request failed; one of the FAILURE_ constants. */
    protected static $failure = self::FAILURE_NONE;

    /** @var string The technical detail of the last failure, for an administrator. */
    protected static $failuredetail = '';

    /** @var array<string, array<string, string>>|null Installed families and their faces. */
    protected static $familymap = null;

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
        $body = static::get($url, []);
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

        $css = static::get($url, ['User-Agent: ' . self::TTF_USER_AGENT]);
        if ($css === null) {
            // The failure reason set by get() stands: either the service could
            // not be reached, or it answered that there is no such face.
            return null;
        }

        if (preg_match('#url\((https?://[^)]+\.ttf)\)#i', $css, $m)) {
            self::$failure = self::FAILURE_NONE;
            return $m[1];
        }

        // A stylesheet with no TrueType in it means Google has the family but
        // not this face of it, which is an answer rather than a failure.
        self::fail(self::FAILURE_NOTFOUND, $url, 200, '');

        return null;
    }

    /**
     * Why the last request to Google failed.
     *
     * Lets a caller tell "this family has no italic" apart from "this site has
     * no outbound network", which are the same null from {@see ttf_url()} but
     * need opposite handling: the first is a face to skip, the second is a
     * reason to stop and tell the administrator.
     *
     * @return string one of the FAILURE_ constants
     */
    public static function last_failure(): string {
        return self::$failure;
    }

    /**
     * An empty install report for one family.
     *
     * @param string $family the family name
     * @return array
     */
    protected static function blank_report(string $family): array {
        return [
            'family' => $family,
            'installed' => [],
            'present' => [],
            'skipped' => [],
            'failed' => [],
            'error' => '',
            'detail' => '',
        ];
    }

    /**
     * A report for a family nothing was installed for, and why.
     *
     * @param string $family the family name
     * @param string $error the error key
     * @return array
     */
    protected static function aborted_report(string $family, string $error): array {
        $report = self::blank_report($family);
        $report['error'] = $error;
        $report['detail'] = self::last_failure_detail();
        return $report;
    }

    /**
     * Download one family and install it as one family with four faces.
     *
     * The point of this method is the difference between a family and a face.
     * Regular, bold, italic and bold italic are four separate files, and TCPDF
     * only treats them as one family when each is registered under the family's
     * own key with the matching style flag — that is what the font manager's
     * $style argument does. Handing bold over as a family in its own right,
     * which is what this used to do, leaves SetFont('lato', 'B') with no bold
     * face to find, and TCPDF then silently draws the regular one.
     *
     * Nothing is installed until every face that is going to be installed has
     * been downloaded. A site that loses the network halfway through therefore
     * ends up with the family untouched rather than with a regular face and no
     * bold, which would be worse than not having the family at all.
     *
     * Faces Google does not publish — most display and handwriting families
     * have no italic — are skipped and reported as skipped. That is an answer,
     * not a failure, and it must not stop the rest of the family installing.
     *
     * @param string $family the family name, as Google spells it
     * @param string[] $styles the TCPDF style flags to install, from {@see FACES}
     * @param bool $force re-download faces that are already installed
     * @return array a report: installed, present, skipped and failed faces, and
     *               an error key that is non-empty when nothing was installed
     */
    public static function install(string $family, array $styles = ['', 'B', 'I', 'BI'],
            bool $force = false): array {
        $family = trim($family);
        $report = self::blank_report($family);

        $key = font_manager::family_key($family);
        if ($family === '' || $key === '') {
            $report['error'] = 'badfamily';
            return $report;
        }

        if (!font_manager::can_install_fonts()) {
            $report['error'] = 'notwritable';
            return $report;
        }

        $existing = self::installed_faces($family);

        // Phase one: fetch every face into memory, and give up on the whole
        // family the moment the network rather than the catalogue is at fault.
        $downloads = [];
        foreach (array_keys(self::FACES) as $style) {
            if (!in_array($style, $styles, true)) {
                continue;
            }
            if (!$force && !empty($existing[$style])) {
                $report['present'][] = $style;
                continue;
            }

            [$weight, $italic] = self::FACES[$style];
            $url = static::ttf_url($family, $weight, $italic);

            if ($url === null) {
                if (self::$failure === self::FAILURE_NETWORK) {
                    return self::aborted_report($family, 'network');
                }
                $report['skipped'][] = $style;
                continue;
            }

            $binary = static::get($url, ['User-Agent: ' . self::TTF_USER_AGENT]);
            if ($binary === null) {
                if (self::$failure === self::FAILURE_NETWORK) {
                    return self::aborted_report($family, 'network');
                }
                $report['failed'][$style] = 'downloadfailed';
                continue;
            }

            if (strlen($binary) < 1024 || !self::looks_like_font($binary)) {
                $report['failed'][$style] = 'notafont';
                continue;
            }

            $downloads[$style] = $binary;
        }

        // Without a regular face there is no family to hang the others off, so
        // installing bold on its own would register a bogus family called
        // "latob". Refuse instead, and say why.
        if (!isset($downloads['']) && empty($existing[''])) {
            $report['error'] = in_array('', $report['skipped'], true) ? 'nosuchfamily' : 'noregular';
            $report['installed'] = [];
            return $report;
        }

        // Phase two: hand each face to the font manager, regular first.
        $directory = make_request_directory();
        foreach ($downloads as $style => $binary) {
            $path = $directory . '/' . $key . strtolower($style) . '.ttf';
            if (file_put_contents($path, $binary) === false) {
                $report['failed'][$style] = 'notwritable';
                continue;
            }

            // Moodle's file pool is the fragile step here, not the download.
            // Installing every face of thirty-odd families is a burst of a
            // hundred and forty writes, and on a busy or space-tight server a
            // few of them come back with "cannot create local file pool
            // directories" — the same face then installs perfectly on its own a
            // minute later. Left unretried, the administrator is handed a set of
            // half-installed families and expected to notice and repair them by
            // hand. Two short pauses cost nothing and clear it.
            $attempts = 0;
            while (true) {
                $attempts++;
                try {
                    $report['installed'][$style] = font_manager::install_from_path(
                        $path, $family, $style);
                    break;
                } catch (\Throwable $e) {
                    if ($attempts >= self::STORE_ATTEMPTS) {
                        $report['failed'][$style] = $e->getMessage();
                        break;
                    }
                    usleep(self::STORE_RETRY_PAUSE * $attempts);
                }
            }
        }

        if ($report['installed']) {
            self::purge_face_cache();
        }

        // What this run learned about which faces exist is the only reliable
        // answer there is, and it is worth more than the catalogue's guess.
        self::record_unavailable($family, $report['skipped'],
            array_merge(array_keys($report['installed']), $report['present']));

        return $report;
    }

    /**
     * Whether a downloaded blob begins with a TrueType or OpenType signature.
     *
     * @param string $binary the first bytes of the file
     * @return bool
     */
    protected static function looks_like_font(string $binary): bool {
        return in_array(substr($binary, 0, 4), ["\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf'], true);
    }

    /**
     * Install every family the bundled text style combinations name.
     *
     * Saves an administrator from hunting through two thousand families to find
     * the three dozen the presets actually use. The batch stops at the first
     * network failure rather than grinding through another thirty-five families
     * that are certain to fail the same way, and says which families it never
     * got to.
     *
     * @param callable|null $progress called with each family's report as it finishes
     * @param bool $missingonly skip families that already have all four faces
     * @return array reports, plus error and notattempted keys
     */
    public static function install_preset_families(?callable $progress = null,
            bool $missingonly = true): array {
        $families = $missingonly ? self::incomplete_families() : style_library::required_families();
        $out = ['reports' => [], 'error' => '', 'notattempted' => []];

        foreach ($families as $index => $family) {
            $report = static::install($family);
            $out['reports'][] = $report;

            if ($progress !== null) {
                $progress($report);
            }

            if ($report['error'] === 'network' || $report['error'] === 'notwritable') {
                $out['error'] = $report['error'];
                $out['notattempted'] = array_slice($families, $index + 1);
                break;
            }
        }

        return $out;
    }

    /**
     * Which of the four faces of a family are installed on this site.
     *
     * The answer comes from the font definition files TCPDF can actually see,
     * not from the plugin's own catalogue table, because it is TCPDF that has to
     * find a bold face at render time.
     *
     * @param string $family the family name or key
     * @return array<string, bool> keyed by style flag: '', B, I and BI
     */
    public static function installed_faces(string $family): array {
        $faces = ['' => false, 'B' => false, 'I' => false, 'BI' => false];

        $key = font_manager::family_key($family);
        if ($key === '') {
            return $faces;
        }

        $styles = self::family_map()[$key] ?? null;
        if ($styles === null) {
            return $faces;
        }

        $faces[''] = isset($styles['R']);
        $faces['B'] = isset($styles['B']);
        $faces['I'] = isset($styles['I']);
        $faces['BI'] = isset($styles['BI']);

        return $faces;
    }

    /**
     * Faces a family's publisher does not offer, which can never be installed.
     *
     * A family is not deficient for lacking a face that does not exist. Most
     * display and handwriting families publish no italic and several publish no
     * bold, and counting those as missing leaves the fonts page permanently
     * reporting work that no amount of installing can finish: the batch button
     * downloads the same families again on every run and the count never falls.
     *
     * Two sources, in that order. First what an install actually found, because
     * {@see install()} already separates a face the publisher does not offer
     * from one that failed, and {@see record_unavailable()} now keeps that
     * answer instead of discarding it. Second the table above, so that a site
     * installed before the answer was kept still reads correctly without having
     * to reinstall anything.
     *
     * @param string $family the family name or key
     * @return string[] TCPDF style flags that do not exist upstream
     */
    public static function unavailable_faces(string $family): array {
        $key = font_manager::family_key($family);
        if ($key === '') {
            return [];
        }

        $recorded = json_decode(
            (string) get_config('mod_certificatepro', self::UNAVAILABLE_CONFIG), true);
        if (is_array($recorded) && is_array($recorded[$key] ?? null)) {
            return array_values(array_intersect(array_keys(self::FACES), $recorded[$key]));
        }

        return self::UNAVAILABLE_SEED[$key] ?? [];
    }

    /**
     * Keep what an install discovered about which faces exist upstream.
     *
     * A face that has just installed plainly does exist whatever an earlier run
     * concluded, so a family that gains an italic repairs its own record.
     *
     * @param string $family the family name
     * @param string[] $skipped style flags the publisher did not offer
     * @param string[] $exists style flags now known to be installed or present
     * @return void
     */
    protected static function record_unavailable(string $family, array $skipped,
            array $exists): void {
        $key = font_manager::family_key($family);
        if ($key === '') {
            return;
        }

        $all = json_decode(
            (string) get_config('mod_certificatepro', self::UNAVAILABLE_CONFIG), true);
        if (!is_array($all)) {
            $all = [];
        }

        $known = $all[$key] ?? (self::UNAVAILABLE_SEED[$key] ?? []);
        $known = array_diff(array_merge($known, $skipped), $exists);
        $known = array_values(array_unique(
            array_intersect(array_keys(self::FACES), $known)));

        if (($all[$key] ?? null) === $known) {
            return;
        }

        $all[$key] = $known;
        set_config(self::UNAVAILABLE_CONFIG, json_encode($all), 'mod_certificatepro');
    }

    /**
     * Whether a family has every face its publisher offers installed.
     *
     * @param string $family the family name or key
     * @return bool
     */
    public static function is_complete(string $family): bool {
        $unavailable = self::unavailable_faces($family);
        foreach (self::installed_faces($family) as $style => $present) {
            if (!$present && !in_array($style, $unavailable, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * How every family the bundled designs name stands on this site.
     *
     * This is what turns a silent fallback into something an author can see: a
     * design that asks for Playfair Display and gets Free Serif looks fine until
     * you know what it was supposed to look like.
     *
     * @return array[] one row per family, in name order
     */
    public static function preset_status(): array {
        $rows = [];

        foreach (style_library::required_families() as $family) {
            $faces = self::installed_faces($family);
            $unavailable = self::unavailable_faces($family);

            $missing = false;
            foreach ($faces as $style => $present) {
                if (!$present && !in_array($style, $unavailable, true)) {
                    $missing = true;
                }
            }

            $rows[] = [
                'family' => $family,
                'faces' => $faces,
                'unavailable' => $unavailable,
                'installed' => $faces[''],
                'complete' => !$missing,
            ];
        }

        return $rows;
    }

    /**
     * The families the bundled designs name that this site cannot draw at all.
     *
     * @return string[] family names
     */
    public static function missing_families(): array {
        $out = [];
        foreach (self::preset_status() as $row) {
            if (!$row['installed']) {
                $out[] = $row['family'];
            }
        }
        return $out;
    }

    /**
     * The families the bundled designs name that are missing at least one face.
     *
     * This, not {@see missing_families()}, is what a batch install has to do:
     * a family with a regular and no bold is not finished, it just fails less
     * visibly.
     *
     * @return string[] family names
     */
    public static function incomplete_families(): array {
        $out = [];
        foreach (self::preset_status() as $row) {
            if (!$row['complete']) {
                $out[] = $row['family'];
            }
        }
        return $out;
    }

    /**
     * Every installed family and the faces it has, keyed by family key.
     *
     * Reads the font definition directories rather than asking a \pdf object,
     * because TCPDF fixes the directory it reads into a constant the first time
     * anything in the request loads pdflib. On the request that creates the
     * custom font directory that constant is already pointing at Moodle's own
     * one, so a family installed a moment ago would be reported as missing.
     * The directories are the same two {@see font_manager::definition_path()}
     * looks in, and the grouping rule is TCPDF's own.
     *
     * @return array<string, array<string, string>>
     */
    protected static function family_map(): array {
        global $CFG;

        if (self::$familymap !== null) {
            return self::$familymap;
        }

        $directories = [
            defined('PDF_CUSTOM_FONT_PATH') ? PDF_CUSTOM_FONT_PATH : $CFG->dataroot . '/fonts/',
            $CFG->libdir . '/tcpdf/fonts/',
        ];

        $keys = [];
        foreach ($directories as $directory) {
            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $path) {
                $key = strtolower(basename($path, '.php'));
                if (strpos($key, 'uni2cid') === 0) {
                    // A character map, not a font.
                    continue;
                }
                $keys[$key] = true;
            }
        }

        $map = [];
        foreach (array_keys($keys) as $key) {
            $face = null;
            foreach (['bi' => 'BI', 'i' => 'I', 'b' => 'B'] as $suffix => $style) {
                $base = substr($key, 0, -strlen($suffix));
                if (substr($key, -strlen($suffix)) === $suffix && $base !== ''
                        && isset($keys[$base])) {
                    $map[$base][$style] = $style;
                    $face = $style;
                    break;
                }
            }
            if ($face === null) {
                $map[$key]['R'] = 'R';
            }
        }

        ksort($map);
        self::$familymap = $map;

        return $map;
    }

    /**
     * Forget which families are installed, after installing or removing one.
     *
     * @return void
     */
    public static function purge_face_cache(): void {
        self::$familymap = null;
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
     * Records why it failed, because the callers have to tell "Google says this
     * family has no italic" apart from "this server cannot reach Google". Both
     * are a null here; only one of them is a reason to abandon the install.
     *
     * There is deliberately no binary flag. CURLOPT_BINARYTRANSFER has done
     * nothing since PHP 5.1 and is deprecated in PHP 8.4, where merely naming
     * the constant raises a deprecation notice — which on a site with display
     * debugging turned on used to land in the middle of this page. curl returns
     * the bytes as they came either way.
     *
     * @param string $url the URL
     * @param string[] $headers extra request headers
     * @return string|null the body, or null on any failure
     */
    protected static function get(string $url, array $headers = []): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        self::$failure = self::FAILURE_NONE;
        self::$failuredetail = '';

        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 3,
            'CURLOPT_RETURNTRANSFER' => 1,
        ];
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }

        $body = $curl->get($url, [], $options);
        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);

        if ($curl->get_errno() || $status === 0 || $body === false) {
            // Nothing answered. Every other family will fail the same way, so
            // the caller is expected to stop rather than keep trying.
            self::fail(self::FAILURE_NETWORK, $url, $status, $curl->error);
            return null;
        }

        if ($status === 400 || $status === 404) {
            // Google answered, and does not have that family or that face of it.
            // The css2 endpoint uses 400 for both.
            self::fail(self::FAILURE_NOTFOUND, $url, $status, '');
            return null;
        }

        if ($status !== 200) {
            // A server error or a rate limit: the service is there but not
            // working, which is a network condition rather than an answer.
            self::fail(self::FAILURE_NETWORK, $url, $status, '');
            return null;
        }

        return (string) $body;
    }

    /**
     * Record why a request failed.
     *
     * Deliberately not debugging(): a site with no outbound access is an
     * ordinary state for this code, and a developer notice on the expected path
     * turns an administrator's install into a stack trace on any site running
     * with display debugging on. The reason is returned to the caller instead,
     * and the caller puts it on the page.
     *
     * @param string $reason one of the FAILURE_ constants
     * @param string $url the URL that was asked for
     * @param int $status the HTTP status, zero when nothing answered
     * @param string $error the transport error, when there was one
     * @return void
     */
    protected static function fail(string $reason, string $url, int $status, string $error): void {
        self::$failure = $reason;
        self::$failuredetail = 'HTTP ' . $status . ($error !== '' ? ' (' . $error . ')' : '')
            . ' for ' . $url;
    }

    /**
     * What went wrong on the last failed request, for an administrator to act on.
     *
     * @return string an untranslated technical detail, or an empty string
     */
    public static function last_failure_detail(): string {
        return self::$failuredetail;
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
