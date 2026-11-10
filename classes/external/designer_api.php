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
     * A preview context that knows which course and activity the design serves.
     *
     * The designer page builds its context with the course and the activity, so
     * an element resolves the real course name and the viewer's real grade. Every
     * web service used to build it with the context alone, which made every
     * element fall back to its sample string the moment it was re-rendered — add
     * an element, change the page size, reset the design, and the canvas would
     * start reading "Introduction to Workplace Safety" and printing a grade for a
     * learner who had none. The PDF was right and the canvas was lying.
     *
     * @param template $template the design being edited
     * @return render_context a context carrying the course and activity when there is one
     */
    protected static function preview_context(template $template): render_context {
        global $DB;

        $context = $template->get_context();

        // A design owned by the system context is a site-wide template with no
        // course behind it, so samples are genuinely the right answer there.
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return render_context::preview($context);
        }

        $cm = get_coursemodule_from_id('certificatepro', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return render_context::preview($context);
        }

        $course = $DB->get_record('course', ['id' => $cm->course]);
        $instance = $DB->get_record('certificatepro', ['id' => $cm->instance]);

        return render_context::preview($context, $course ?: null, $instance ?: null);
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
     * The size a layout save is allowed to give one stored element.
     *
     * The rule itself belongs to the element type and lives on
     * {@see \mod_certificatepro\element\base::constrain_size()}; all this does is
     * find the type a stored row names so it can be asked. That step is only
     * needed here, because this is the one caller that starts from a raw record
     * rather than from an element object, and the type a record names is not
     * guaranteed to still be installed.
     *
     * @param \stdClass $record the element as it is stored
     * @param float $width the submitted width in millimetres
     * @param float $height the submitted height in millimetres
     * @return array{0: float, 1: float} the width and height to store
     */
    protected static function allowed_size(\stdClass $record, float $width, float $height): array {
        $class = \mod_certificatepro\element\registry::class_for((string) $record->element);

        // A type this site no longer has cannot be asked what it permits, and
        // the stored box is the only thing anyone knows about it, so leave it
        // exactly as it is rather than guessing on the type's behalf.
        if (!class_exists($class) || !is_subclass_of($class, \mod_certificatepro\element\base::class)) {
            return [(float) $record->width, (float) $record->height];
        }

        return $class::constrain_size($width, $height, (float) $record->width, (float) $record->height);
    }

    /**
     * The stored columns an element's exported markup is built out of.
     *
     * An element draws itself for the box it has, and some of what it draws is a
     * fixed length worked out from that box - the side of a seal's square, the
     * point size a long name was shrunk to. Change one of these and the markup
     * the browser is holding describes a box that no longer exists.
     *
     * Position is deliberately absent. Nothing an element renders reads posx or
     * posy: the print anchors the box with {@see \mod_certificatepro\element\base::box_origin()}
     * and the canvas draws the box itself. Including it would re-export every
     * element of every drag, which is the common case and the one that must stay
     * cheap.
     *
     * @return string[] column names
     */
    protected static function markup_fields(): array {
        return ['width', 'height', 'refpoint', 'alignment', 'valignment'];
    }

    /**
     * Whether a save changed anything an element's markup was built out of.
     *
     * @param \stdClass $before the element as it was stored before the save
     * @param \stdClass $after the element as it is stored now
     * @return bool true when the markup has to be produced again
     */
    protected static function markup_stale(\stdClass $before, \stdClass $after): bool {
        foreach (self::markup_fields() as $field) {
            if ((string) $before->{$field} !== (string) $after->{$field}) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parameters for saving a page's layout.
     *
     * @return external_function_parameters
     */
    public static function save_layout_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page being edited'),
            'elements' => new external_multiple_structure(
                new external_single_structure([
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
                ])
            ),
        ]);
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

        $params = self::validate_parameters(self::save_layout_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'elements' => $elements,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        // The whole record, not just the ids: settling the size an element may
        // take needs the type it is and the box it already has.
        $onpage = $DB->get_records('certificatepro_elements', ['pageid' => $params['pageid']]);

        $saved = 0;
        foreach ($params['elements'] as $element) {
            $elementid = (int) $element['id'];

            if (!isset($onpage[$elementid])) {
                // Silently skip anything that is not on this page rather than
                // letting a stale browser tab write across a design.
                continue;
            }

            [$width, $height] = self::allowed_size($onpage[$elementid],
                (float) $element['width'], (float) $element['height']);

            $template->move_element($elementid, [
                'posx' => $element['posx'],
                'posy' => $element['posy'],
                'width' => $width,
                'height' => $height,
                'rotation' => $element['rotation'],
                'refpoint' => $element['refpoint'],
                'alignment' => $element['alignment'],
                'valignment' => $element['valignment'],
                'zoneid' => $element['zoneid'],
                'zindex' => $element['zindex'],
            ]);

            $DB->update_record('certificatepro_elements', (object) [
                'id' => $elementid,
                'hidden' => empty($element['hidden']) ? 0 : 1,
                'locked' => empty($element['locked']) ? 0 : 1,
            ]);

            $saved++;
        }

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return [
            'saved' => $saved,
            'revision' => (int) $template->get_record()->revision,
            'elements' => json_encode(self::redraw_reshaped($template, $page, $onpage)),
        ];
    }

    /**
     * Draw again whatever the save has just reshaped.
     *
     * This is what makes the canvas authoritative after a resize. An element's
     * markup is produced on the server, for the box the element had when it was
     * produced, so a resize leaves the browser holding a drawing that no longer
     * matches its box - a seal stuck at the diameter it used to have, a name
     * still set in the size that used to fit. The browser has no way to build
     * the replacement, because building it means the fonts, the palette and the
     * PDF's own text measurement. So the save hands it back, and what the author
     * ends up looking at is the same markup a reload would give them.
     *
     * Only the elements the save actually reshaped are drawn again. A plain
     * drag moves elements without changing anything their markup depends on, and
     * that is the common case: re-exporting the whole page every time an element
     * was nudged would put a QR code render and a PDF text measurement pass in
     * the way of every autosave.
     *
     * @param template $template the design being edited
     * @param \stdClass $page the page that was saved
     * @param \stdClass[] $before the page's elements as they were stored before the save
     * @return array one entry per redrawn element, each with its id, markup and fit
     */
    protected static function redraw_reshaped(template $template, \stdClass $page,
            array $before): array {
        global $DB;

        $after = $DB->get_records('certificatepro_elements', ['pageid' => (int) $page->id]);

        $reshaped = [];
        foreach ($after as $id => $record) {
            if (isset($before[$id]) && self::markup_stale($before[$id], $record)) {
                $reshaped[] = $record;
            }
        }

        if (!$reshaped) {
            return [];
        }

        $ctx = self::preview_context($template);
        $template->configure($ctx);
        $ctx->set_page($page);

        $redrawn = [];
        foreach ($reshaped as $record) {
            $exported = \mod_certificatepro\element\registry::instantiate($record)
                ->export_for_designer($ctx);

            // The markup and the description of how it fits its box, and nothing
            // else. Everything else the export carries - the name, the settings,
            // the style - is unchanged by a layout save, and handing it back
            // would invite the browser to overwrite an edit the author made in
            // the inspector while the save was in flight.
            $redrawn[] = [
                'id' => (int) $record->id,
                'html' => $exported['html'],
                'canvasfit' => $exported['canvasfit'],
            ];
        }

        return $redrawn;
    }

    /**
     * What saving a layout returns.
     *
     * @return external_single_structure
     */
    public static function save_layout_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_INT, 'How many elements were saved'),
            'revision' => new external_value(PARAM_INT, 'The design revision after saving'),
            'elements' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The elements the save reshaped, redrawn, as JSON'),
        ]);
    }

    /**
     * Parameters for adding an element.
     *
     * @return external_function_parameters
     */
    public static function add_element_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to add to'),
            'elementtype' => new external_value(PARAM_ALPHANUMEXT, 'The element type'),
            'zoneid' => new external_value(PARAM_ALPHANUMEXT, 'The zone to dock into', VALUE_DEFAULT, ''),
        ]);
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

        $params = self::validate_parameters(self::add_element_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'elementtype' => $elementtype,
            'zoneid' => $zoneid,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        if (!\mod_certificatepro\element\registry::exists($params['elementtype'])) {
            throw new \moodle_exception('errorunknownelement', 'mod_certificatepro', '',
                $params['elementtype']);
        }

        $elementid = $template->add_element(
            $params['pageid'],
            $params['elementtype'],
            $params['zoneid'] !== '' ? $params['zoneid'] : null
        );

        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);
        $element = \mod_certificatepro\element\registry::instantiate($record);

        $ctx = self::preview_context($template);
        $template->configure($ctx);
        $ctx->set_page($page);

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
                'The new element as JSON'),
        ]);
    }

    /**
     * Parameters for duplicating elements.
     *
     * @return external_function_parameters
     */
    public static function duplicate_elements_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page being edited'),
            'elementids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'An element id')
            ),
        ]);
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

        $params = self::validate_parameters(self::duplicate_elements_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'elementids' => $elementids,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        $ctx = self::preview_context($template);
        $template->configure($ctx);
        $ctx->set_page($page);

        $fs = get_file_storage();
        $areas = \mod_certificatepro\element\registry::all_file_areas();
        $created = [];

        foreach ($params['elementids'] as $elementid) {
            $record = $DB->get_record('certificatepro_elements',
                ['id' => $elementid, 'pageid' => $params['pageid']]);
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
                $files = $fs->get_area_files($template->get_context()->id, 'mod_certificatepro',
                    $area, (int) $record->id, 'id', false);
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
                'The new elements as JSON'),
        ]);
    }

    /**
     * Parameters for deleting elements.
     *
     * @return external_function_parameters
     */
    public static function delete_elements_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'elementids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'An element id')
            ),
        ]);
    }

    /**
     * The stored columns an element has to carry to be able to come back.
     *
     * Taken from db/install.xml: every column of certificatepro_elements except
     * the id and the two timestamps, which describe the row rather than the
     * element and are written fresh. Each one is paired with the type it is
     * cleaned to on the way back in, because the payload reaches
     * {@see self::restore_elements()} through the browser and is therefore user
     * input by the time anyone sees it again.
     *
     * The id the element used to have travels alongside these rather than among
     * them, because it is not a column to write - see
     * {@see self::restore_elements()} for what it is for and what stops it being
     * abused.
     *
     * @return array<string, string> column name to PARAM_ constant
     */
    protected static function restorable_fields(): array {
        return [
            'pageid' => PARAM_INT,
            'element' => PARAM_ALPHANUMEXT,
            'name' => PARAM_TEXT,
            'data' => PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded and re-encoded below.
            'style' => PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded and re-encoded below.
            'posx' => PARAM_FLOAT,
            'posy' => PARAM_FLOAT,
            'width' => PARAM_FLOAT,
            'height' => PARAM_FLOAT,
            'rotation' => PARAM_FLOAT,
            'refpoint' => PARAM_ALPHA,
            'alignment' => PARAM_ALPHA,
            'valignment' => PARAM_ALPHA,
            'zoneid' => PARAM_ALPHANUMEXT,
            'zindex' => PARAM_INT,
            'sequence' => PARAM_INT,
            'locked' => PARAM_BOOL,
            'hidden' => PARAM_BOOL,
        ];
    }

    /**
     * Delete elements, handing back enough of each one to put it back.
     *
     * @param int $templateid the design being edited
     * @param array $elementids the elements to delete
     * @return array
     */
    public static function delete_elements(int $templateid, array $elementids): array {
        global $DB;

        $params = self::validate_parameters(self::delete_elements_parameters(), [
            'templateid' => $templateid,
            'elementids' => $elementids,
        ]);

        $template = self::require_editable($params['templateid']);
        $pageids = array_keys($template->get_pages());
        $fields = self::restorable_fields();

        $deleted = 0;
        $removed = [];
        foreach ($params['elementids'] as $elementid) {
            $record = $DB->get_record('certificatepro_elements', ['id' => $elementid]);
            if (!$record || !in_array((int) $record->pageid, $pageids, true)) {
                continue;
            }

            // Read the element out before it goes, so the browser has something
            // to offer back to restore_elements. Nothing is kept on the server:
            // undoing a delete is a property of the tab the author is working
            // in, and a second author editing the same design should not be
            // able to bring back something the first one meant to remove.
            //
            // The id goes with it. It cannot be reused - the row that comes back
            // is a new one - but the files the element owned stay filed under it
            // after the row is gone, and it is the only handle there will ever
            // be on them.
            $keep = ['id' => (int) $record->id];
            foreach (array_keys($fields) as $field) {
                $keep[$field] = $record->{$field};
            }
            $removed[] = $keep;

            $template->delete_element((int) $elementid);
            $deleted++;
        }

        return ['deleted' => $deleted, 'elements' => json_encode($removed)];
    }

    /**
     * What deleting elements returns.
     *
     * @return external_single_structure
     */
    public static function delete_elements_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_INT, 'How many elements were deleted'),
            'elements' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, handed straight back to restore_elements
                'The deleted elements as JSON, in the shape restore_elements takes'),
        ]);
    }

    /**
     * Parameters for restoring deleted elements.
     *
     * @return external_function_parameters
     */
    public static function restore_elements_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'payload' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, cleaned field by field below
                'The elements to put back, as returned by delete_elements'),
        ]);
    }

    /**
     * Turn one submitted element out of a restore payload into a row to write.
     *
     * Everything here came back from the browser, so nothing in it is trusted:
     * each field is cleaned to the type db/install.xml gives its column, the
     * page has to be one of this design's own, and the type has to be one this
     * site actually has. An element that fails any of those is dropped rather
     * than argued with, because the payload usually describes several elements
     * and the author would rather have the ones that can come back than an
     * error about the one that cannot.
     *
     * @param array $submitted one element from the payload
     * @param \stdClass[] $pages this design's pages, keyed by id
     * @return \stdClass|null the row to insert, or null when it cannot be trusted
     */
    protected static function clean_element(array $submitted, array $pages): ?\stdClass {
        $record = new \stdClass();

        foreach (self::restorable_fields() as $field => $type) {
            $value = $submitted[$field] ?? null;

            if ($type === PARAM_BOOL) {
                $record->{$field} = empty($value) ? 0 : 1;
                continue;
            }

            $record->{$field} = clean_param((string) ($value ?? ''), $type);
        }

        // A page from another design, or one deleted while the author was
        // thinking, would smuggle an element into a design the caller was never
        // checked against.
        if (!isset($pages[$record->pageid])) {
            return null;
        }

        if (!\mod_certificatepro\element\registry::exists($record->element)) {
            return null;
        }

        // An empty zone is stored as null rather than as an empty string, the
        // way move_element() writes it.
        $record->zoneid = $record->zoneid !== '' ? $record->zoneid : null;

        // The two JSON columns are re-encoded from what they decode to, so a
        // payload carrying anything other than an object lands as an empty
        // configuration instead of as a string the element cannot read.
        foreach (['data', 'style'] as $field) {
            $decoded = json_decode((string) $record->{$field}, true);
            $record->{$field} = json_encode(is_array($decoded) ? $decoded : [],
                JSON_PRESERVE_ZERO_FRACTION);
        }

        if ($record->name === '') {
            $class = \mod_certificatepro\element\registry::class_for($record->element);
            $record->name = $class::display_name();
        }

        return $record;
    }

    /**
     * Put deleted elements back, pictures and all.
     *
     * The rows are new ones, so the elements come back under new ids and the
     * browser is told what they are. A picture an author uploaded is filed under
     * the id of the element that held it and outlives the row - see
     * {@see template::delete_element()} - so the old id is the only handle on
     * those files, and it is carried through the payload for exactly that. The
     * element is handed them before it is asked to draw itself, because a
     * picture element with an empty file area exports no picture at all.
     *
     * That old id is never a target and never becomes a row's id. It is only
     * ever read from, and only when {@see template::element_exists()} says no
     * live element answers to it: a caller who put a live id there would
     * otherwise be helping themselves to a picture out of a design they were
     * never checked against. Files are looked for in this design's own context
     * and nowhere else, so a dead id borrowed from another site context finds
     * nothing.
     *
     * @param int $templateid the design being edited
     * @param string $payload the elements to put back, as delete_elements returned them
     * @return array
     */
    public static function restore_elements(int $templateid, string $payload): array {
        global $DB;

        $params = self::validate_parameters(self::restore_elements_parameters(), [
            'templateid' => $templateid,
            'payload' => $payload,
        ]);

        $template = self::require_editable($params['templateid']);
        $pages = $template->get_pages();

        $decoded = json_decode($params['payload'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('errorrestorepayload', 'mod_certificatepro');
        }

        $ctx = self::preview_context($template);
        $template->configure($ctx);

        $now = time();
        $restored = [];
        foreach ($decoded as $submitted) {
            if (!is_array($submitted)) {
                continue;
            }

            $record = self::clean_element($submitted, $pages);
            if ($record === null) {
                continue;
            }

            $record->timecreated = $now;
            $record->timemodified = $now;
            $newid = (int) $DB->insert_record('certificatepro_elements', $record);

            // The id this element was deleted under, which is where its files
            // are still filed. Cleaned like everything else, and read only: a
            // live id names an element some page still draws, and copying out of
            // one would be theft rather than a restore.
            $source = clean_param((string) ($submitted['id'] ?? ''), PARAM_INT);
            if ($source > 0 && $source !== $newid && !$template->element_exists($source)) {
                $template->adopt_element_files($source, $newid);
            }

            $page = $pages[(int) $record->pageid];
            $ctx->set_page($page);

            // Exported after the files have arrived, not before: a picture
            // element with an empty file area draws nothing at all.
            $restored[] = \mod_certificatepro\element\registry::instantiate(
                $DB->get_record('certificatepro_elements', ['id' => $newid], '*', MUST_EXIST)
            )->export_for_designer($ctx);
        }

        if ($restored) {
            $template->touch();

            \mod_certificatepro\event\template_updated::create([
                'objectid' => $template->get_id(),
                'context' => $template->get_context(),
            ])->trigger();
        }

        return ['restored' => count($restored), 'elements' => json_encode($restored)];
    }

    /**
     * What restoring elements returns.
     *
     * @return external_single_structure
     */
    public static function restore_elements_returns(): external_single_structure {
        return new external_single_structure([
            'restored' => new external_value(PARAM_INT, 'How many elements came back'),
            'elements' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The restored elements as JSON, carrying their new ids'),
        ]);
    }

    /**
     * Parameters for generating artwork.
     *
     * @return external_function_parameters
     */
    public static function generate_artwork_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'name' => new external_value(PARAM_TEXT, 'A name for the artwork'),
            'prompt' => new external_value(PARAM_TEXT, 'What the author asked for'),
            'style' => new external_value(PARAM_ALPHANUMEXT, 'The style to draw or take guidance from'),
            'orientation' => new external_value(PARAM_ALPHA, 'L or P'),
            'seed' => new external_value(PARAM_INT, 'A number that varies the composition',
                VALUE_DEFAULT, 1),
            'intensity' => new external_value(PARAM_FLOAT, 'How bold the artwork should be',
                VALUE_DEFAULT, 0.5),
            'usemodel' => new external_value(PARAM_BOOL, 'Whether a model may be asked',
                VALUE_DEFAULT, true),
        ]);
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
        $params = self::validate_parameters(self::generate_artwork_parameters(), [
            'templateid' => $templateid,
            'name' => $name,
            'prompt' => $prompt,
            'style' => $style,
            'orientation' => $orientation,
            'seed' => $seed,
            'intensity' => $intensity,
            'usemodel' => $usemodel,
        ]);

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

        \mod_certificatepro\event\artwork_generated::create([
            'objectid' => $result['id'],
            'context' => $context,
        ])->trigger();

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

    /**
     * One page of a design, in exactly the shape the designer bootstraps from.
     *
     * @param template $template the design
     * @param int $pageid the page to export
     * @return array the exported page
     */
    protected static function export_page(template $template, int $pageid): array {
        $design = $template->export_for_designer(self::preview_context($template));

        foreach ($design['pages'] as $page) {
            if ((int) $page['id'] === $pageid) {
                return $page;
            }
        }

        throw new \moodle_exception('errorpagenotfound', 'mod_certificatepro');
    }

    /**
     * Parameters for changing a page's shape.
     *
     * @return external_function_parameters
     */
    public static function set_page_geometry_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page being reshaped'),
            'orientation' => new external_value(PARAM_ALPHA, 'L or P'),
            'pagesize' => new external_value(PARAM_ALPHANUMEXT, 'A key from the page size catalogue'),
        ]);
    }

    /**
     * Change a page's orientation and paper size, re-docking what is on it.
     *
     * An unknown orientation or paper size is normalised by the design rather
     * than refused, because this is a toolbar with a fixed set of choices: the
     * only way to send something else is a tampered request, and landing on A4
     * landscape is a safer answer to that than a broken page.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page being reshaped
     * @param string $orientation L or P
     * @param string $pagesize a key from the page size catalogue
     * @return array
     */
    public static function set_page_geometry(int $templateid, int $pageid, string $orientation,
            string $pagesize): array {
        $params = self::validate_parameters(self::set_page_geometry_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'orientation' => $orientation,
            'pagesize' => $pagesize,
        ]);

        $template = self::require_editable($params['templateid']);
        self::require_page($template, $params['pageid']);

        $template->set_page_geometry($params['pageid'], $params['orientation'], $params['pagesize']);

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return ['page' => json_encode(self::export_page($template, $params['pageid']))];
    }

    /**
     * What changing a page's shape returns.
     *
     * @return external_single_structure
     */
    public static function set_page_geometry_returns(): external_single_structure {
        return new external_single_structure([
            'page' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The reshaped page as JSON'),
        ]);
    }

    /**
     * Parameters for reverting a design.
     *
     * @return external_function_parameters
     */
    public static function reset_design_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design to revert'),
        ]);
    }

    /**
     * Throw a design's pages away and rebuild them from its starter.
     *
     * @param int $templateid the design to revert
     * @return array
     */
    public static function reset_design(int $templateid): array {
        $params = self::validate_parameters(self::reset_design_parameters(), [
            'templateid' => $templateid,
        ]);

        $template = self::require_editable($params['templateid']);
        $template->reset_to_starter();

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return [
            'design' => json_encode($template->export_for_designer(
                self::preview_context($template))),
        ];
    }

    /**
     * What reverting a design returns.
     *
     * @return external_single_structure
     */
    public static function reset_design_returns(): external_single_structure {
        return new external_single_structure([
            'design' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The rebuilt design as JSON'),
        ]);
    }
}
