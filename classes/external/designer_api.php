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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\ai\background_repository;
use mod_certificatepro\template;

/**
 * The services the designer calls while an author works.
 *
 * Every entry point re-checks the capability against the design's own context
 * rather than trusting anything the browser sent, so holding the manage
 * capability in one course does not grant access to a design in another.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class designer_api extends external_api {
    /**
     * Load a design and confirm the caller may edit it.
     *
     * @param int $templateid the design to load
     * @return template
     */
    protected static function require_editable(int $templateid): template {
        $template = template::load($templateid);
        $context = $template->get_context();

        self::validate_context($context);

        if ($context->contextlevel === CONTEXT_SYSTEM) {
            require_capability('mod/certificatepro:managetemplates', $context);
        } else {
            require_capability('mod/certificatepro:manage', $context);
        }

        return $template;
    }

    /**
     * Confirm a page belongs to a design.
     *
     * @param template $template the design
     * @param int $pageid the page to check
     * @return \stdClass the page record
     */
    protected static function require_page(template $template, int $pageid): \stdClass {
        $pages = $template->get_pages();

        if (!isset($pages[$pageid])) {
            throw new \moodle_exception('errorpagenotfound', 'mod_certificatepro');
        }

        return $pages[$pageid];
    }

    /**
     * Parameters for saving a page's layout.
     *
     * @return external_function_parameters
     */
    public static function save_layout_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'pageid' => new external_value(PARAM_INT, 'The page being edited'),
                'elements' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'The element id'),
                            'posx' => new external_value(PARAM_FLOAT, 'Millimetres from the left edge'),
                            'posy' => new external_value(PARAM_FLOAT, 'Millimetres from the top edge'),
                            'width' => new external_value(PARAM_FLOAT, 'Width in millimetres'),
                            'height' => new external_value(PARAM_FLOAT, 'Height in millimetres'),
                            'rotation' => new external_value(PARAM_FLOAT, 'Rotation in degrees'),
                            'refpoint' => new external_value(PARAM_ALPHA, 'The anchor point'),
                            'alignment' => new external_value(PARAM_ALPHA, 'Horizontal text alignment'),
                            'valignment' => new external_value(PARAM_ALPHA, 'Vertical alignment'),
                            'zoneid' => new external_value(PARAM_ALPHANUMEXT, 'The docked zone, or empty'),
                            'zindex' => new external_value(PARAM_INT, 'Stacking order'),
                            'hidden' => new external_value(PARAM_INT, 'Whether the element is hidden'),
                            'locked' => new external_value(PARAM_INT, 'Whether the element is locked'),
                        ]
                    )
                ),
            ]
        );
    }

    /**
     * Save a page's layout.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page being edited
     * @param array $elements the geometry of every element on the page
     * @return array
     */
    public static function save_layout(int $templateid, int $pageid, array $elements): array {
        global $DB;

        $params = self::validate_parameters(
            self::save_layout_parameters(),
            [
                'templateid' => $templateid,
                'pageid' => $pageid,
                'elements' => $elements,
            ]
        );

        $template = self::require_editable($params['templateid']);
        self::require_page($template, $params['pageid']);

        $onpage = $DB->get_records_menu(
            'certificatepro_elements',
            ['pageid' => $params['pageid']],
            '',
            'id, id AS elementid'
        );

        $saved = 0;
        foreach ($params['elements'] as $element) {
            if (!isset($onpage[$element['id']])) {
                // Silently skip anything that is not on this page rather than
                // letting a stale browser tab write across a design.
                continue;
            }

            $template->move_element(
                (int) $element['id'],
                [
                    'posx' => $element['posx'],
                    'posy' => $element['posy'],
                    'width' => max(0.0, (float) $element['width']),
                    'height' => max(0.0, (float) $element['height']),
                    'rotation' => $element['rotation'],
                    'refpoint' => $element['refpoint'],
                    'alignment' => $element['alignment'],
                    'valignment' => $element['valignment'],
                    'zoneid' => $element['zoneid'],
                    'zindex' => $element['zindex'],
                ]
            );

            $DB->update_record(
                'certificatepro_elements',
                (object) [
                    'id' => (int) $element['id'],
                    'hidden' => empty($element['hidden']) ? 0 : 1,
                    'locked' => empty($element['locked']) ? 0 : 1,
                ]
            );

            $saved++;
        }

        \mod_certificatepro\event\template_updated::create(
            [
                'objectid' => $template->get_id(),
                'context' => $template->get_context(),
            ]
        )->trigger();

        return ['saved' => $saved, 'revision' => (int) $template->get_record()->revision];
    }

    /**
     * What saving a layout returns.
     *
     * @return external_single_structure
     */
    public static function save_layout_returns(): external_single_structure {
        return new external_single_structure(
            [
                'saved' => new external_value(PARAM_INT, 'How many elements were saved'),
                'revision' => new external_value(PARAM_INT, 'The design revision after saving'),
            ]
        );
    }

    /**
     * Parameters for adding an element.
     *
     * @return external_function_parameters
     */
    public static function add_element_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'pageid' => new external_value(PARAM_INT, 'The page to add to'),
                'elementtype' => new external_value(PARAM_ALPHANUMEXT, 'The element type'),
                'zoneid' => new external_value(PARAM_ALPHANUMEXT, 'The zone to dock into', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Add an element to a page.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to add to
     * @param string $elementtype the element type
     * @param string $zoneid the zone to dock into
     * @return array
     */
    public static function add_element(int $templateid, int $pageid, string $elementtype,
            string $zoneid = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::add_element_parameters(),
            [
                'templateid' => $templateid,
                'pageid' => $pageid,
                'elementtype' => $elementtype,
                'zoneid' => $zoneid,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        if (!\mod_certificatepro\element\registry::exists($params['elementtype'])) {
            throw new \moodle_exception(
                'errorunknownelement',
                'mod_certificatepro',
                '',
                $params['elementtype']
            );
        }

        $elementid = $template->add_element(
            $params['pageid'],
            $params['elementtype'],
            $params['zoneid'] !== '' ? $params['zoneid'] : null
        );

        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);
        $element = \mod_certificatepro\element\registry::instantiate($record);

        $ctx = render_context::preview($template->get_context());
        $template->configure($ctx);
        $ctx->set_page_size((float) $page->width, (float) $page->height);

        return ['element' => json_encode($element->export_for_designer($ctx))];
    }

    /**
     * What adding an element returns.
     *
     * @return external_single_structure
     */
    public static function add_element_returns(): external_single_structure {
        return new external_single_structure([
            'element' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The new element as JSON'
            ),
        ]);
    }

    /**
     * Parameters for duplicating elements.
     *
     * @return external_function_parameters
     */
    public static function duplicate_elements_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'pageid' => new external_value(PARAM_INT, 'The page being edited'),
                'elementids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'An element id')
                ),
            ]
        );
    }

    /**
     * Duplicate elements, offsetting the copies so they are visible.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page being edited
     * @param array $elementids the elements to copy
     * @return array
     */
    public static function duplicate_elements(int $templateid, int $pageid,
            array $elementids): array {
        global $DB;

        $params = self::validate_parameters(
            self::duplicate_elements_parameters(),
            [
                'templateid' => $templateid,
                'pageid' => $pageid,
                'elementids' => $elementids,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        $ctx = render_context::preview($template->get_context());
        $template->configure($ctx);
        $ctx->set_page_size((float) $page->width, (float) $page->height);

        $fs = get_file_storage();
        $areas = \mod_certificatepro\element\registry::all_file_areas();
        $created = [];

        foreach ($params['elementids'] as $elementid) {
            $record = $DB->get_record(
                'certificatepro_elements',
                ['id' => $elementid, 'pageid' => $params['pageid']]
            );
            if (!$record) {
                continue;
            }

            $copy = clone $record;
            unset($copy->id);
            $copy->posx = round((float) $record->posx + 5, 3);
            $copy->posy = round((float) $record->posy + 5, 3);
            $copy->zoneid = null;
            $copy->timecreated = time();
            $copy->timemodified = time();
            $newid = (int) $DB->insert_record('certificatepro_elements', $copy);

            foreach ($areas as $area) {
                $files = $fs->get_area_files(
                    $template->get_context()->id,
                    'mod_certificatepro',
                    $area,
                    (int) $record->id,
                    'id',
                    false
                );
                foreach ($files as $file) {
                    $fs->create_file_from_storedfile(['itemid' => $newid], $file);
                }
            }

            $new = \mod_certificatepro\element\registry::instantiate(
                $DB->get_record('certificatepro_elements', ['id' => $newid], '*', MUST_EXIST)
            );
            $created[] = $new->export_for_designer($ctx);
        }

        return ['elements' => json_encode($created)];
    }

    /**
     * What duplicating elements returns.
     *
     * @return external_single_structure
     */
    public static function duplicate_elements_returns(): external_single_structure {
        return new external_single_structure([
            'elements' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The new elements as JSON'
            ),
        ]);
    }

    /**
     * Parameters for deleting elements.
     *
     * @return external_function_parameters
     */
    public static function delete_elements_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'elementids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'An element id')
                ),
            ]
        );
    }

    /**
     * Delete elements.
     *
     * @param int $templateid the design being edited
     * @param array $elementids the elements to delete
     * @return array
     */
    public static function delete_elements(int $templateid, array $elementids): array {
        global $DB;

        $params = self::validate_parameters(
            self::delete_elements_parameters(),
            [
                'templateid' => $templateid,
                'elementids' => $elementids,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $pageids = array_keys($template->get_pages());

        $deleted = 0;
        foreach ($params['elementids'] as $elementid) {
            $record = $DB->get_record('certificatepro_elements', ['id' => $elementid]);
            if (!$record || !in_array((int) $record->pageid, $pageids, true)) {
                continue;
            }
            $template->delete_element((int) $elementid);
            $deleted++;
        }

        return ['deleted' => $deleted];
    }

    /**
     * What deleting elements returns.
     *
     * @return external_single_structure
     */
    public static function delete_elements_returns(): external_single_structure {
        return new external_single_structure(
            [
                'deleted' => new external_value(PARAM_INT, 'How many elements were deleted'),
            ]
        );
    }

    /**
     * Parameters for generating artwork.
     *
     * @return external_function_parameters
     */
    public static function generate_artwork_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'name' => new external_value(PARAM_TEXT, 'A name for the artwork'),
                'prompt' => new external_value(PARAM_TEXT, 'What the author asked for'),
                'style' => new external_value(PARAM_ALPHANUMEXT, 'The style to draw or take guidance from'),
                'orientation' => new external_value(PARAM_ALPHA, 'L or P'),
                'seed' => new external_value(
                    PARAM_INT,
                    'A number that varies the composition',
                    VALUE_DEFAULT,
                    1
                ),
                'intensity' => new external_value(
                    PARAM_FLOAT,
                    'How bold the artwork should be',
                    VALUE_DEFAULT,
                    0.5
                ),
                'usemodel' => new external_value(
                    PARAM_BOOL,
                    'Whether a model may be asked',
                    VALUE_DEFAULT,
                    true
                ),
            ]
        );
    }

    /**
     * Generate background artwork.
     *
     * @param int $templateid the design being edited
     * @param string $name a name for the artwork
     * @param string $prompt what the author asked for
     * @param string $style the style to draw or take guidance from
     * @param string $orientation L or P
     * @param int $seed a number that varies the composition
     * @param float $intensity how bold the artwork should be
     * @param bool $usemodel whether a model may be asked
     * @return array
     */
    public static function generate_artwork(int $templateid, string $name, string $prompt,
            string $style, string $orientation, int $seed = 1, float $intensity = 0.5,
            bool $usemodel = true): array {
        $params = self::validate_parameters(
            self::generate_artwork_parameters(),
            [
                'templateid' => $templateid,
                'name' => $name,
                'prompt' => $prompt,
                'style' => $style,
                'orientation' => $orientation,
                'seed' => $seed,
                'intensity' => $intensity,
                'usemodel' => $usemodel,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $context = $template->get_context();

        require_capability('mod/certificatepro:generateartwork', $context);

        $result = background_repository::create(
            $context,
            $params['name'] !== '' ? $params['name'] : $params['style'],
            $params['prompt'],
            $params['style'],
            $params['orientation'] === 'P' ? 'P' : 'L',
            [
                'seed' => $params['seed'],
                'intensity' => $params['intensity'],
                'allowmodel' => $params['usemodel'],
                'palette' => $template->get_palette(),
            ]
        );

        \mod_certificatepro\event\artwork_generated::create(
            [
                'objectid' => $result['id'],
                'context' => $context,
            ]
        )->trigger();

        return [
            'id' => $result['id'],
            'svg' => $result['svg'],
            'source' => $result['source'],
            'notice' => $result['notice'] ?? '',
        ];
    }

    /**
     * What generating artwork returns.
     *
     * @return external_single_structure
     */
    public static function generate_artwork_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The stored artwork id'),
            'svg' => new external_value(PARAM_RAW, 'The artwork as SVG'), // pipeline-ignore: PARAM_RAW - sanitised SVG
            'source' => new external_value(PARAM_ALPHANUMEXT, 'Which route produced it'),
            'notice' => new external_value(PARAM_TEXT, 'A message for the author, or empty'),
        ]);
    }
}
