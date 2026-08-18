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
 * The hand-curated shortlist of artwork offered in the designer.
 *
 * WHY THIS EXISTS
 *
 * Free-text search against a fifty-million-asset library is a bad default for
 * a certificate designer: the results are dominated by finished templates with
 * baked-in text, mockups on desks, and decorative art that has no blank field
 * to write a student's name into. Human curation fixes that in a way no query
 * string can.
 *
 * Vecteezy has a Collections feature on the website but exposes no collections
 * endpoint in the v2 API. The bridge is that a public collection page carries
 * each item's resource id in its href, and the API's /resources/{id} endpoint
 * needs nothing more than that id. So we curate on the website, harvest the
 * ids once (see tools/harvest-collection.js), and ship the id list.
 *
 * WHAT IS AND IS NOT SHIPPED
 *
 * This manifest holds ids, titles, slugs, remote thumbnail URLs and our own
 * placement tags. It holds no artwork. Nothing licensed is bundled into the
 * plugin package, which is what keeps us inside Moodle's GPL requirements.
 * Artwork is fetched at runtime into an institution's own file store, one
 * download per institution.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curated_library {
    /** @var string Placement: an ornamental frame around the whole page. */
    const ROLE_BORDER = 'border';

    /** @var string Placement: a corner flourish, mirrored into the other corners. */
    const ROLE_CORNER = 'corner';

    /** @var string Placement: a seal or emblem. */
    const ROLE_SEAL = 'seal';

    /** @var string Placement: a motif tiled across the ground. */
    const ROLE_PATTERN = 'pattern';

    /** @var string Placement: a full-bleed background panel. */
    const ROLE_FULL = 'full';

    /** @var string Placement: a horizontal rule or separator. */
    const ROLE_DIVIDER = 'divider';

    /** @var array|null Lazily loaded manifest. */
    protected static ?array $manifest = null;

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
            self::ROLE_FULL,
            self::ROLE_DIVIDER,
        ];
    }

    /**
     * Load and cache the manifest shipped with the plugin.
     *
     * @return array Manifest with 'collections' and 'items' keys.
     */
    public static function manifest(): array {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        global $CFG;
        $path = $CFG->dirroot . '/mod/certificatepro/assets/curated_manifest.json';
        $decoded = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($decoded)) {
            $decoded = ['manifest_version' => 1, 'collections' => [], 'items' => []];
        }
        $decoded['items'] = array_values(array_filter(
            $decoded['items'] ?? [],
            fn($item) => isset($item['id']) && (int)$item['id'] > 0
        ));
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
     * Curated items, optionally narrowed.
     *
     * @param array $filters Any of: role, orientation, palette (single colour name),
     *      content_type, q (substring match against the title).
     * @return array List of manifest items.
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
        if (!empty($filters['palette'])) {
            $want = \core_text::strtolower($filters['palette']);
            $items = array_filter($items, function ($i) use ($want) {
                $palette = array_map('\core_text::strtolower', $i['palette'] ?? []);
                return in_array($want, $palette, true);
            });
        }
        if (!empty($filters['content_type'])) {
            $want = $filters['content_type'];
            $items = array_filter($items, fn($i) => ($i['content_type'] ?? 'vector') === $want);
        }
        if (!empty($filters['q'])) {
            $needle = \core_text::strtolower(trim($filters['q']));
            $items = array_filter($items, function ($i) use ($needle) {
                $hay = \core_text::strtolower(($i['title'] ?? '') . ' ' . ($i['slug'] ?? ''));
                return \core_text::strpos($hay, $needle) !== false;
            });
        }

        return array_values($items);
    }

    /**
     * A single curated item by resource id.
     *
     * @param int $id Vecteezy resource id.
     * @return array|null The item, or null when it is not curated.
     */
    public static function item(int $id): ?array {
        foreach (self::manifest()['items'] as $item) {
            if ((int)$item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Whether a resource id is in the curated set.
     *
     * Acquisition must check this. It is the guard that stops an arbitrary id
     * arriving from a crafted request and costing us a download fee.
     *
     * @param int $id Vecteezy resource id.
     * @return bool True when curated.
     */
    public static function is_curated(int $id): bool {
        return self::item($id) !== null;
    }

    /**
     * How many items are curated for each role.
     *
     * Used by the wizard to hide roles we cannot yet serve, rather than
     * showing an empty gallery.
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
     * Enrich curated items with live API data.
     *
     * The manifest is a snapshot: an asset can be withdrawn by its contributor
     * after we harvest it. Hydrating before display means the gallery never
     * offers something that can no longer be downloaded. Each lookup is a
     * cheap metadata call, not a download.
     *
     * Failures are swallowed per item — one withdrawn asset must not empty the
     * gallery. Items that fail to resolve are dropped.
     *
     * @param array $items Manifest items.
     * @param vecteezy_client|null $client Client, or null to skip hydration.
     * @return array Items merged with live data, unresolvable ones removed.
     */
    public static function hydrate(array $items, ?vecteezy_client $client): array {
        if ($client === null) {
            return $items;
        }
        $out = [];
        foreach ($items as $item) {
            try {
                $live = $client->resource((int)$item['id']);
            } catch (\Throwable $e) {
                debugging('certificatepro: could not hydrate resource ' . $item['id'] . ': ' .
                    $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            $payload = $live['resource'] ?? $live;
            $out[] = array_merge($item, [
                'live' => true,
                'preview' => $payload['thumbnail_url'] ?? $payload['preview_url'] ?? $item['thumb'] ?? null,
                'contributor' => $payload['contributor']['name'] ?? null,
                'license' => $payload['license'] ?? null,
            ]);
        }
        return $out;
    }
}
