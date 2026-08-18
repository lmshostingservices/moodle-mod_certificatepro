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

namespace mod_certificatepro\local\asset;

/**
 * The catalogue of public domain ornament bundled inside the plugin.
 *
 * WHY THIS EXISTS
 *
 * The curated library next door describes artwork we may point at but may not
 * ship: it holds ids and thumbnails, and the artwork is fetched into each
 * institution's own file store because the licence does not survive being
 * bundled. Moodle's contribution rules pull in the other direction — bundled
 * media has to be GPL-compatible — and public domain and CC0 ornament satisfies
 * both at once. So this library describes artwork that is genuinely present in
 * the plugin directory, on disk, at install time.
 *
 * The practical consequences are worth stating, because they are why the two
 * classes are not one class:
 *
 * - There is no remote call, no API key and no hydration step. An entry either
 *   resolves to a file under assets/ornament or it is dropped.
 * - There is no per-download cost, so nothing here needs a guard against an
 *   arbitrary id arriving from a crafted request.
 * - Every entry carries its own licence and provenance, because an assessor
 *   reading assets/CREDITS.md must be able to trace any single file back to the
 *   page it was taken from.
 *
 * The method names deliberately match curated_library so that the wizard can
 * treat the two as interchangeable sources behind one picker.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pd_library {
    /** @var string Placement: an ornamental frame around the whole page. */
    const ROLE_BORDER = 'border';

    /** @var string Placement: a corner flourish, mirrored into the other corners. */
    const ROLE_CORNER = 'corner';

    /** @var string Placement: a seal or emblem. */
    const ROLE_SEAL = 'seal';

    /** @var string Placement: a motif tiled across the ground. */
    const ROLE_PATTERN = 'pattern';

    /** @var string Placement: a horizontal rule or separator. */
    const ROLE_DIVIDER = 'divider';

    /** @var string Placement: a full-bleed background panel. */
    const ROLE_FULL = 'full';

    /** @var string Palette affinity meaning the motif sits happily in any scheme. */
    const PALETTE_ANY = 'any';

    /** @var array|null Lazily loaded manifest. */
    protected static ?array $manifest = null;

    /** @var string|null Overridden manifest path. Test seam. */
    protected static ?string $manifestpath = null;

    /** @var string|null Overridden asset root. Test seam. */
    protected static ?string $assetroot = null;

    /**
     * All placement roles, in the order the wizard presents them.
     *
     * @return string[] Role constants.
     */
    public static function roles(): array {
        return [
            self::ROLE_BORDER,
            self::ROLE_CORNER,
            self::ROLE_SEAL,
            self::ROLE_PATTERN,
            self::ROLE_DIVIDER,
            self::ROLE_FULL,
        ];
    }

    /**
     * Where the bundled manifest lives.
     *
     * @return string Absolute path to pd_manifest.json.
     */
    public static function manifest_path(): string {
        if (self::$manifestpath !== null) {
            return self::$manifestpath;
        }
        return self::asset_root() . '/pd_manifest.json';
    }

    /**
     * Where the bundled assets live.
     *
     * @return string Absolute path to the assets directory.
     */
    public static function asset_root(): string {
        if (self::$assetroot !== null) {
            return self::$assetroot;
        }
        global $CFG;
        return $CFG->dirroot . '/mod/certificatepro/assets';
    }

    /**
     * Point the library at a different assets directory.
     *
     * Test seam. The importer also uses it so that a build can stage assets
     * outside the plugin directory before they are committed.
     *
     * @param string|null $root Absolute path, or null to restore the default.
     * @return void
     */
    public static function set_asset_root(?string $root): void {
        self::$assetroot = $root === null ? null : rtrim($root, '/');
        self::$manifestpath = null;
        self::reset_cache();
    }

    /**
     * Load and cache the manifest shipped with the plugin.
     *
     * A missing or malformed manifest is not an error worth throwing over. The
     * plugin has generated backgrounds, generated guilloche and parametric
     * seals; an empty ornament library costs the wizard one tab, not a page.
     *
     * @return array Manifest with 'items' and 'roles' keys.
     */
    public static function manifest(): array {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = self::manifest_path();
        $decoded = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($decoded)) {
            $decoded = ['manifest_version' => 1, 'items' => []];
        }

        $roles = self::roles();
        $decoded['items'] = array_values(array_filter(
            $decoded['items'] ?? [],
            function ($item) use ($roles) {
                return is_array($item)
                    && !empty($item['id'])
                    && !empty($item['file'])
                    && in_array($item['role'] ?? null, $roles, true);
            }
        ));
        $decoded['roles'] = $roles;

        self::$manifest = $decoded;
        return self::$manifest;
    }

    /**
     * Reset the in-process cache. Test seam.
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$manifest = null;
    }

    /**
     * Bundled items, optionally narrowed.
     *
     * Filters combine with AND. An unknown filter key is ignored rather than
     * treated as an error, so a caller may pass the whole wizard state through.
     *
     * @param array $filters Any of: role, orientation, style, palette,
     *      source, licence, q (substring match against title, id and style),
     *      attribution (bool, true for items that must be credited),
     *      present (bool, true to drop items whose file is missing on disk).
     * @return array List of manifest items, each with an added 'path' key.
     */
    public static function items(array $filters = []): array {
        $items = self::manifest()['items'];

        if (!empty($filters['role'])) {
            $role = $filters['role'];
            $items = array_filter($items, fn($i) => ($i['role'] ?? null) === $role);
        }
        if (!empty($filters['orientation'])) {
            $want = $filters['orientation'];
            $items = array_filter($items, function ($i) use ($want) {
                $has = $i['orientation'] ?? 'both';
                return $has === 'both' || $has === $want;
            });
        }
        if (!empty($filters['style'])) {
            $want = \core_text::strtolower($filters['style']);
            $items = array_filter($items, fn($i) => \core_text::strtolower($i['style'] ?? '') === $want);
        }
        if (!empty($filters['palette'])) {
            $want = \core_text::strtolower($filters['palette']);
            $items = array_filter($items, function ($i) use ($want) {
                // An entry that is recolourable, or explicitly tagged for any
                // scheme, answers every palette question.
                if (!empty($i['recolourable'])) {
                    return true;
                }
                $affinity = array_map('\core_text::strtolower', $i['palette_affinity'] ?? []);
                return in_array(self::PALETTE_ANY, $affinity, true)
                    || in_array($want, $affinity, true);
            });
        }
        if (!empty($filters['source'])) {
            $want = $filters['source'];
            $items = array_filter($items, fn($i) => ($i['source'] ?? null) === $want);
        }
        if (!empty($filters['licence'])) {
            $want = \core_text::strtolower($filters['licence']);
            $items = array_filter($items, fn($i) => \core_text::strtolower($i['licence'] ?? '') === $want);
        }
        if (array_key_exists('attribution', $filters) && $filters['attribution'] !== null) {
            $want = (bool)$filters['attribution'];
            $items = array_filter($items, fn($i) => (bool)($i['attribution_required'] ?? false) === $want);
        }
        if (!empty($filters['q'])) {
            $needle = \core_text::strtolower(trim($filters['q']));
            $items = array_filter($items, function ($i) use ($needle) {
                $hay = \core_text::strtolower(
                    ($i['title'] ?? '') . ' ' . ($i['id'] ?? '') . ' ' . ($i['style'] ?? '')
                );
                return \core_text::strpos($hay, $needle) !== false;
            });
        }

        $items = array_map([self::class, 'decorate'], array_values($items));

        if (!empty($filters['present'])) {
            $items = array_values(array_filter($items, fn($i) => (bool)$i['present']));
        }

        return array_values($items);
    }

    /**
     * A single bundled item by manifest id.
     *
     * Ids are strings such as "openclipart-222491-decorative-ornamental-frame-border",
     * but an int is accepted so that a caller sharing code with curated_library
     * does not have to cast.
     *
     * @param int|string $id Manifest id.
     * @return array|null The item with its 'path' resolved, or null when it is not bundled.
     */
    public static function item(int|string $id): ?array {
        $id = (string)$id;
        foreach (self::manifest()['items'] as $item) {
            if ((string)$item['id'] === $id) {
                return self::decorate($item);
            }
        }
        return null;
    }

    /**
     * Whether an id is in the bundled set and its file is on disk.
     *
     * @param int|string $id Manifest id.
     * @return bool True when the asset can actually be drawn.
     */
    public static function is_bundled(int|string $id): bool {
        $item = self::item($id);
        return $item !== null && $item['present'];
    }

    /**
     * How many items are bundled for each role.
     *
     * Used by the wizard to hide roles we cannot yet serve, rather than showing
     * an empty gallery.
     *
     * @return array Role constant => count.
     */
    public static function counts_by_role(): array {
        $counts = array_fill_keys(self::roles(), 0);
        foreach (self::manifest()['items'] as $item) {
            $role = $item['role'] ?? null;
            if ($role !== null && array_key_exists($role, $counts)) {
                $counts[$role]++;
            }
        }
        return $counts;
    }

    /**
     * Every style present in the library, with its item count.
     *
     * @return array Style key => count, ordered by descending count.
     */
    public static function counts_by_style(): array {
        $counts = [];
        foreach (self::manifest()['items'] as $item) {
            $style = (string)($item['style'] ?? 'unclassified');
            $counts[$style] = ($counts[$style] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Every source represented in the library, with its item count.
     *
     * @return array Source key => count.
     */
    public static function counts_by_source(): array {
        $counts = [];
        foreach (self::manifest()['items'] as $item) {
            $source = (string)($item['source'] ?? 'unknown');
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * The source registry read from pd_sources.json.
     *
     * The importer needs it; the runtime needs it only to name a licence in the
     * picker, so a missing file yields an empty list rather than an error.
     *
     * @return array Source key => source definition.
     */
    public static function sources(): array {
        $path = self::asset_root() . '/pd_sources.json';
        $decoded = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($decoded) && is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
    }

    /**
     * Items that carry an attribution requirement.
     *
     * Public domain and CC0 assets do not need crediting, but a Commons file
     * under CC BY does, and the difference has to survive into whatever the
     * renderer puts on the page.
     *
     * @return array Items requiring attribution.
     */
    public static function attributable(): array {
        return self::items(['attribution' => true]);
    }

    /**
     * The markup for a bundled asset.
     *
     * Already sanitised at import time, so this reads the file rather than
     * re-cleaning it on every render.
     *
     * @param int|string $id Manifest id.
     * @return string|null The SVG document, or null when the asset is missing.
     */
    public static function svg(int|string $id): ?string {
        $item = self::item($id);
        if ($item === null || !$item['present']) {
            return null;
        }
        $contents = file_get_contents($item['path']);
        return $contents === false ? null : $contents;
    }

    /**
     * Add the resolved path and presence flag to a manifest item.
     *
     * @param array $item A raw manifest item.
     * @return array The item with 'path' and 'present' keys added.
     */
    protected static function decorate(array $item): array {
        $relative = ltrim((string)($item['file'] ?? ''), '/');
        $path = self::asset_root() . '/ornament/' . $relative;
        $item['path'] = $path;
        $item['present'] = $relative !== '' && is_readable($path);
        return $item;
    }
}
