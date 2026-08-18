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

namespace mod_certificatepro\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_certificatepro\local\ai\background_repository;
use mod_certificatepro\local\art\background_library;
use mod_certificatepro\local\asset\pd_library;
use mod_certificatepro\template;

/**
 * The services behind the background gallery and the ornament picker.
 *
 * Backgrounds are procedural, so a preview is generated on demand rather than
 * stored: the whole catalogue rendered at once is roughly three megabytes of
 * vector, which is a payload nobody should be asked to download to look at a
 * gallery. The picker therefore asks for previews a handful at a time as tiles
 * come into view.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_api extends library_base {
    /** @var int The most previews one request will render. */
    public const PREVIEW_BATCH = 6;

    /** @var int The largest ornament this will inline into a gallery response. */
    public const ORNAMENT_INLINE_LIMIT = 262144;

    /**
     * Parameters for listing the background styles.
     *
     * @return external_function_parameters
     */
    public static function get_background_styles_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
        ]);
    }

    /**
     * Every procedural background style, grouped by family.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_background_styles(int $templateid): array {
        $params = self::validate_parameters(self::get_background_styles_parameters(), [
            'templateid' => $templateid,
        ]);

        $template = self::require_readable($params['templateid']);
        $page = self::require_page($template, 0);

        $families = [];
        foreach (background_library::families() as $key => $stringid) {
            $families[$key] = [
                'family' => $key,
                'name' => get_string($stringid, 'mod_certificatepro'),
                'styles' => [],
            ];
        }

        foreach (background_library::styles() as $id => $entry) {
            if (!isset($families[$entry['family']])) {
                continue;
            }
            $families[$entry['family']]['styles'][] = [
                'id' => $id,
                'name' => self::label('bgstyle_' . $id, $entry['name']),
                'family' => $entry['family'],
                'presence' => $entry['presence'],
                'reversed' => $entry['ink'] === 'light',
            ];
        }

        return [
            'families' => array_values(array_filter($families, fn($f) => !empty($f['styles']))),
            'width' => round((float) $page->width, 3),
            'height' => round((float) $page->height, 3),
        ];
    }

    /**
     * What listing the background styles returns.
     *
     * @return external_single_structure
     */
    public static function get_background_styles_returns(): external_single_structure {
        return new external_single_structure([
            'families' => new external_multiple_structure(
                new external_single_structure([
                    'family' => new external_value(PARAM_ALPHANUMEXT, 'The family key'),
                    'name' => new external_value(PARAM_TEXT, 'The family name'),
                    'styles' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_ALPHANUMEXT, 'The style identifier'),
                            'name' => new external_value(PARAM_TEXT, 'The style name'),
                            'family' => new external_value(PARAM_ALPHANUMEXT, 'The family key'),
                            'presence' => new external_value(PARAM_ALPHA,
                                'How present the style is on the page'),
                            'reversed' => new external_value(PARAM_BOOL,
                                'Whether text must be reversed out of this ground'),
                        ])
                    ),
                ])
            ),
            'width' => new external_value(PARAM_FLOAT, 'The first page width in millimetres'),
            'height' => new external_value(PARAM_FLOAT, 'The first page height in millimetres'),
        ]);
    }

    /**
     * Parameters for rendering background previews.
     *
     * @return external_function_parameters
     */
    public static function get_background_previews_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'styles' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'A style identifier')
            ),
            'seed' => new external_value(PARAM_INT, 'The composition seed', VALUE_DEFAULT, 7),
        ]);
    }

    /**
     * Render a handful of background previews in the design's own palette.
     *
     * @param int $templateid the design being edited
     * @param array $styles the styles to render
     * @param int $seed the composition seed
     * @return array
     */
    public static function get_background_previews(int $templateid, array $styles,
            int $seed = 7): array {
        $params = self::validate_parameters(self::get_background_previews_parameters(), [
            'templateid' => $templateid,
            'styles' => $styles,
            'seed' => $seed,
        ]);

        $template = self::require_readable($params['templateid']);
        $page = self::require_page($template, 0);
        $palette = $template->get_palette();

        $previews = [];
        $requested = array_slice(array_values(array_unique($params['styles'])),
            0, self::PREVIEW_BATCH);

        foreach ($requested as $style) {
            if (!background_library::exists($style)) {
                continue;
            }
            $previews[] = [
                'id' => $style,
                'svg' => background_library::svg($style, $palette,
                    (float) $page->width, (float) $page->height, max(1, $params['seed'])),
            ];
        }

        return ['previews' => $previews];
    }

    /**
     * What rendering background previews returns.
     *
     * @return external_single_structure
     */
    public static function get_background_previews_returns(): external_single_structure {
        return new external_single_structure([
            'previews' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_ALPHANUMEXT, 'The style identifier'),
                    'svg' => new external_value(PARAM_RAW, 'The preview as SVG'), // pipeline-ignore: PARAM_RAW - sanitised SVG
                ])
            ),
        ]);
    }

    /**
     * Parameters for applying a background.
     *
     * @return external_function_parameters
     */
    public static function apply_background_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
            'style' => new external_value(PARAM_ALPHANUMEXT, 'The style identifier'),
            'seed' => new external_value(PARAM_INT, 'The composition seed', VALUE_DEFAULT, 7),
        ]);
    }

    /**
     * Draw a procedural background and place it on a page.
     *
     * Nothing here asks a model anything: the author chose a style from the
     * gallery, so the answer is already known and drawing it is deterministic.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param string $style the style identifier
     * @param int $seed the composition seed
     * @return array
     */
    public static function apply_background(int $templateid, int $pageid, string $style,
            int $seed = 7): array {
        $params = self::validate_parameters(self::apply_background_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'style' => $style,
            'seed' => $seed,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        if (!background_library::exists($params['style'])) {
            throw new \moodle_exception('errorunknownstyle', 'mod_certificatepro', '',
                $params['style']);
        }

        $entry = background_library::styles()[$params['style']];
        $svg = background_library::svg($params['style'], $template->get_palette(),
            (float) $page->width, (float) $page->height, max(1, $params['seed']));

        if ($svg === '') {
            throw new \moodle_exception('errorbackgroundempty', 'mod_certificatepro');
        }

        $backgroundid = background_repository::save($template->get_context(),
            self::label('bgstyle_' . $params['style'], $entry['name']), $svg, [
                'prompt' => '',
                'style' => $params['style'],
                'palette' => $template->get_palette()['primary'] ?? '',
                'orientation' => $page->orientation,
                'generator' => 'procedural',
            ]);

        $elementid = self::place_artwork($template, $page, $backgroundid);

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return [
            'backgroundid' => $backgroundid,
            'elementid' => $elementid,
            'reversed' => $entry['ink'] === 'light',
        ];
    }

    /**
     * Point a page's artwork element at a stored background, creating it if needed.
     *
     * Only one generated background makes sense per page, so an existing
     * artwork element is repointed rather than a second one stacked on top of
     * it — which would otherwise be invisible and impossible to explain.
     *
     * @param template $template the design
     * @param \stdClass $page the page
     * @param int $backgroundid the stored background
     * @return int the artwork element id
     */
    protected static function place_artwork(template $template, \stdClass $page,
            int $backgroundid): int {
        global $DB;

        $existing = $DB->get_records('certificatepro_elements',
            ['pageid' => (int) $page->id, 'element' => 'artwork'], 'id ASC');

        $record = reset($existing);

        if ($record === false) {
            $elementid = $template->add_element((int) $page->id, 'artwork');
            $record = $DB->get_record('certificatepro_elements', ['id' => $elementid],
                '*', MUST_EXIST);
        }

        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        $data['backgroundid'] = $backgroundid;
        $data['opacity'] = isset($data['opacity']) ? (float) $data['opacity'] : 1.0;

        // Artwork covers the sheet: anything smaller is a mistake nobody wants
        // to fix by dragging.
        $DB->update_record('certificatepro_elements', (object) [
            'id' => (int) $record->id,
            'data' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION),
            'posx' => 0,
            'posy' => 0,
            'width' => round((float) $page->width, 3),
            'height' => round((float) $page->height, 3),
            'refpoint' => 'tl',
            'zindex' => -100,
            'timemodified' => time(),
        ]);

        $template->touch();

        return (int) $record->id;
    }

    /**
     * What applying a background returns.
     *
     * @return external_single_structure
     */
    public static function apply_background_returns(): external_single_structure {
        return new external_single_structure([
            'backgroundid' => new external_value(PARAM_INT, 'The stored background id'),
            'elementid' => new external_value(PARAM_INT, 'The artwork element it was placed on'),
            'reversed' => new external_value(PARAM_BOOL,
                'Whether text must be reversed out of this ground'),
        ]);
    }

    /**
     * Parameters for browsing the bundled ornament.
     *
     * @return external_function_parameters
     */
    public static function get_ornaments_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'role' => new external_value(PARAM_ALPHA, 'A placement role, or empty for all',
                VALUE_DEFAULT, ''),
            'orientation' => new external_value(PARAM_ALPHA, 'L, P or empty for both',
                VALUE_DEFAULT, ''),
            'palette' => new external_value(PARAM_ALPHANUMEXT, 'A palette affinity, or empty',
                VALUE_DEFAULT, ''),
            'q' => new external_value(PARAM_TEXT, 'A search term, or empty', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * The bundled public domain ornament, filtered.
     *
     * The catalogue may legitimately be empty: it is populated by an import
     * step, and a source install ships the manifest without the artwork. That
     * is a state the picker has to explain rather than crash on, so the counts
     * come back regardless and the caller is told the library is empty.
     *
     * @param int $templateid the design being edited
     * @param string $role a placement role, or empty
     * @param string $orientation L, P or empty
     * @param string $palette a palette affinity, or empty
     * @param string $q a search term, or empty
     * @return array
     */
    public static function get_ornaments(int $templateid, string $role = '',
            string $orientation = '', string $palette = '', string $q = ''): array {
        $params = self::validate_parameters(self::get_ornaments_parameters(), [
            'templateid' => $templateid,
            'role' => $role,
            'orientation' => $orientation,
            'palette' => $palette,
            'q' => $q,
        ]);

        self::require_readable($params['templateid']);

        $roles = pd_library::roles();
        $wanted = in_array($params['role'], $roles, true) ? $params['role'] : '';

        $items = pd_library::items([
            'role' => $wanted,
            'orientation' => in_array($params['orientation'], ['L', 'P'], true)
                ? $params['orientation'] : '',
            'palette' => $params['palette'],
            'q' => trim($params['q']),
            'present' => true,
        ]);

        $counts = pd_library::counts_by_role();
        $sources = pd_library::sources();

        $out = [];
        foreach ($items as $item) {
            $svg = '';
            if (!empty($item['path']) && is_readable($item['path'])
                    && filesize($item['path']) <= self::ORNAMENT_INLINE_LIMIT) {
                $svg = (string) file_get_contents($item['path']);
            }

            $sourcekey = (string) ($item['source'] ?? '');

            $out[] = [
                'id' => (string) $item['id'],
                'title' => (string) ($item['title'] ?? $item['id']),
                'role' => (string) ($item['role'] ?? ''),
                'style' => (string) ($item['style'] ?? ''),
                'licence' => (string) ($item['licence'] ?? ''),
                'source' => (string) ($sources[$sourcekey]['name'] ?? $sourcekey),
                'attribution' => !empty($item['attribution_required']),
                'svg' => $svg,
            ];
        }

        $rolefacets = [];
        foreach ($roles as $key) {
            $rolefacets[] = [
                'role' => $key,
                'name' => get_string('ornamentrole_' . $key, 'mod_certificatepro'),
                'count' => (int) ($counts[$key] ?? 0),
                'selected' => $key === $wanted,
            ];
        }

        return [
            'items' => $out,
            'roles' => $rolefacets,
            'total' => array_sum($counts),
            'libraryempty' => array_sum($counts) === 0,
        ];
    }

    /**
     * What browsing the ornament returns.
     *
     * @return external_single_structure
     */
    public static function get_ornaments_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'The manifest id'), // pipeline-ignore: PARAM_RAW - validated manifest id
                    'title' => new external_value(PARAM_TEXT, 'The item title'),
                    'role' => new external_value(PARAM_ALPHA, 'Its placement role'),
                    'style' => new external_value(PARAM_TEXT, 'Its style classification'),
                    'licence' => new external_value(PARAM_TEXT, 'Its licence'),
                    'source' => new external_value(PARAM_TEXT, 'Where it came from'),
                    'attribution' => new external_value(PARAM_BOOL,
                        'Whether it must be credited'),
                    'svg' => new external_value(
                        PARAM_RAW, // pipeline-ignore: PARAM_RAW - sanitised SVG
                        'The artwork, or empty when too large'),
                ])
            ),
            'roles' => new external_multiple_structure(
                new external_single_structure([
                    'role' => new external_value(PARAM_ALPHA, 'The role key'),
                    'name' => new external_value(PARAM_TEXT, 'The role name'),
                    'count' => new external_value(PARAM_INT, 'How many items carry it'),
                    'selected' => new external_value(PARAM_BOOL, 'Whether it is the active filter'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'How many items are bundled in total'),
            'libraryempty' => new external_value(PARAM_BOOL, 'Whether nothing is bundled at all'),
        ]);
    }

    /**
     * Parameters for placing an ornament.
     *
     * @return external_function_parameters
     */
    public static function place_ornament_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
            'assetid' => new external_value(PARAM_RAW, 'The manifest id'), // pipeline-ignore: PARAM_RAW - validated manifest id
        ]);
    }

    /**
     * Place a bundled ornament on a page as an image element.
     *
     * The bundled file is copied into the design's own file area rather than
     * referenced in place, so a later plugin upgrade that drops or replaces an
     * asset cannot silently change a certificate that has already been issued.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param string $assetid the manifest id
     * @return array
     */
    public static function place_ornament(int $templateid, int $pageid, string $assetid): array {
        global $DB;

        $params = self::validate_parameters(self::place_ornament_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'assetid' => $assetid,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        $item = pd_library::item($params['assetid']);
        if ($item === null || empty($item['present'])) {
            throw new \moodle_exception('errorornamentmissing', 'mod_certificatepro');
        }

        $elementid = $template->add_element((int) $page->id, 'image');
        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid],
            '*', MUST_EXIST);

        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $template->get_context()->id,
            'component' => 'mod_certificatepro',
            'filearea' => 'elementimage',
            'itemid' => $elementid,
            'filepath' => '/',
            'filename' => basename((string) $item['file']) ?: ($params['assetid'] . '.svg'),
        ], $item['path']);

        $size = self::ornament_size((string) ($item['role'] ?? ''), $page);

        $DB->update_record('certificatepro_elements', (object) [
            'id' => $elementid,
            'name' => (string) ($item['title'] ?? $item['id']),
            'width' => $size['width'],
            'height' => $size['height'],
            'posx' => $size['posx'],
            'posy' => $size['posy'],
            'refpoint' => $size['refpoint'],
            'zindex' => $size['zindex'],
            'timemodified' => time(),
        ]);

        $template->touch();

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return ['elementid' => $elementid];
    }

    /**
     * Where an ornament of a given role wants to sit on a page.
     *
     * A border wants the whole sheet; a corner wants a corner; a divider wants
     * to be wide and short. Dropping every ornament in the middle at its
     * natural size would make the picker feel broken even though it worked.
     *
     * @param string $role the placement role
     * @param \stdClass $page the page
     * @return array the geometry
     */
    protected static function ornament_size(string $role, \stdClass $page): array {
        $w = (float) $page->width;
        $h = (float) $page->height;

        return match ($role) {
            pd_library::ROLE_BORDER, pd_library::ROLE_FULL, pd_library::ROLE_PATTERN => [
                'width' => round($w, 3), 'height' => round($h, 3),
                'posx' => 0.0, 'posy' => 0.0, 'refpoint' => 'tl', 'zindex' => -50,
            ],
            pd_library::ROLE_CORNER => [
                'width' => round($w * 0.18, 3), 'height' => round($w * 0.18, 3),
                'posx' => round($w * 0.06, 3), 'posy' => round($h * 0.08, 3),
                'refpoint' => 'tl', 'zindex' => -10,
            ],
            pd_library::ROLE_DIVIDER => [
                'width' => round($w * 0.34, 3), 'height' => round($h * 0.045, 3),
                'posx' => round($w / 2, 3), 'posy' => round($h * 0.62, 3),
                'refpoint' => 'mc', 'zindex' => 1,
            ],
            pd_library::ROLE_SEAL => [
                'width' => 34.0, 'height' => 34.0,
                'posx' => round($w * 0.5, 3), 'posy' => round($h * 0.78, 3),
                'refpoint' => 'mc', 'zindex' => 1,
            ],
            default => [
                'width' => round($w * 0.25, 3), 'height' => round($h * 0.25, 3),
                'posx' => round($w / 2, 3), 'posy' => round($h / 2, 3),
                'refpoint' => 'mc', 'zindex' => 1,
            ],
        };
    }

    /**
     * What placing an ornament returns.
     *
     * @return external_single_structure
     */
    public static function place_ornament_returns(): external_single_structure {
        return new external_single_structure([
            'elementid' => new external_value(PARAM_INT, 'The new element id'),
        ]);
    }
}
