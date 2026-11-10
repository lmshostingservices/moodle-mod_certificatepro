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

namespace mod_certificatepro;

use mod_certificatepro\element\base as element_base;
use mod_certificatepro\element\registry;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\starter_templates;
use mod_certificatepro\local\zone\zone_library;

/**
 * A certificate design: its pages, its elements and everything done to them.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {
    /** @var \stdClass The stored template record. */
    protected \stdClass $record;

    /** @var \context The context that owns this design. */
    protected \context $context;

    /**
     * Wrap a stored template record.
     *
     * @param \stdClass $record a row from the templates table
     */
    public function __construct(\stdClass $record) {
        $this->record = $record;
        $this->context = \context::instance_by_id($record->contextid, IGNORE_MISSING)
            ?: \context_system::instance();
    }

    /**
     * Load a design by id.
     *
     * @param int $templateid the design to load
     * @return self
     */
    public static function load(int $templateid): self {
        global $DB;

        $record = $DB->get_record('certificatepro_templates', ['id' => $templateid], '*', MUST_EXIST);

        return new self($record);
    }

    /**
     * Create an empty design.
     *
     * @param string $name the author facing name
     * @param \context $context the context that will own it
     * @return self
     */
    public static function create(string $name, \context $context): self {
        global $DB;

        $record = new \stdClass();
        $record->name = $name;
        $record->contextid = $context->id;
        $record->shared = 0;
        $record->starter = null;
        $record->themecolour = brand::primary_colour();
        $record->accentcolour = null;
        $record->fontpairing = get_config('mod_certificatepro', 'defaultfontpairing') ?: 'heritage';
        $record->revision = 1;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('certificatepro_templates', $record);

        return new self($record);
    }

    /**
     * The design id.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) $this->record->id;
    }

    /**
     * The author facing name.
     *
     * @return string
     */
    public function get_name(): string {
        return (string) $this->record->name;
    }

    /**
     * The underlying record.
     *
     * @return \stdClass
     */
    public function get_record(): \stdClass {
        return $this->record;
    }

    /**
     * The context that owns this design.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->context;
    }

    /**
     * The font pairing this design uses.
     *
     * @return string
     */
    public function get_pairing(): string {
        return (string) ($this->record->fontpairing ?: 'system');
    }

    /**
     * The palette this design uses.
     *
     * @return array<string, string>
     */
    public function get_palette(): array {
        $palette = brand::palette($this->record->themecolour ?: null);
        if (!empty($this->record->accentcolour) && brand::is_hex($this->record->accentcolour)) {
            $palette['accent'] = brand::normalise_hex($this->record->accentcolour);
        }
        return $palette;
    }

    /**
     * Every page of this design, in order.
     *
     * @return \stdClass[] keyed by page id
     */
    public function get_pages(): array {
        global $DB;

        return $DB->get_records('certificatepro_pages', ['templateid' => $this->get_id()],
            'sequence ASC, id ASC');
    }

    /**
     * Every element on a page, in drawing order.
     *
     * @param int $pageid the page to read
     * @return element_base[]
     */
    public function get_elements(int $pageid): array {
        global $DB;

        $records = $DB->get_records('certificatepro_elements', ['pageid' => $pageid],
            'zindex ASC, sequence ASC, id ASC');

        $elements = [];
        foreach ($records as $record) {
            $elements[(int) $record->id] = registry::instantiate($record);
        }

        return $elements;
    }

    /**
     * Add a page to this design.
     *
     * @param string $orientation L or P
     * @param string $pagesize a key from the page size catalogue
     * @return int the new page id
     */
    public function add_page(string $orientation = 'L', string $pagesize = 'A4'): int {
        global $DB;

        [$width, $height] = zone_library::dimensions($pagesize, $orientation);

        $page = new \stdClass();
        $page->templateid = $this->get_id();
        $page->width = $width;
        $page->height = $height;
        $page->orientation = $orientation;
        $page->pagesize = $pagesize;
        $page->marginleft = 15;
        $page->marginright = 15;
        $page->margintop = 15;
        $page->marginbottom = 15;
        $page->backgroundcolour = null;
        $page->backgroundsvg = null;
        $page->sequence = count($this->get_pages()) + 1;
        $page->timecreated = time();
        $page->timemodified = time();

        $id = (int) $DB->insert_record('certificatepro_pages', $page);
        $this->touch();

        return $id;
    }

    /**
     * Change a page's shape, and rearrange what is on it to suit.
     *
     * A zone is defined as a fraction of the page, so turning a landscape page
     * portrait moves and resizes every zone. An element left where it was would
     * therefore no longer be in the zone it belongs to: a title that spanned a
     * 297mm page would hang 87mm off the edge of a 210mm one. Every docked
     * element is re-measured against its own zone at the new size and re-docked,
     * so the design still reads as a design afterwards.
     *
     * @param int $pageid the page to reshape
     * @param string $orientation zone_library::LANDSCAPE or zone_library::PORTRAIT
     * @param string $pagesize a key from {@see zone_library::page_sizes()}
     * @return \stdClass the updated page record
     */
    public function set_page_geometry(int $pageid, string $orientation, string $pagesize): \stdClass {
        global $DB;

        $page = $DB->get_record('certificatepro_pages',
            ['id' => $pageid, 'templateid' => $this->get_id()], '*', MUST_EXIST);

        $orientation = $orientation === zone_library::PORTRAIT
            ? zone_library::PORTRAIT
            : zone_library::LANDSCAPE;
        if (!array_key_exists($pagesize, zone_library::page_sizes())) {
            $pagesize = 'A4';
        }

        $oldwidth = (float) $page->width;
        $oldheight = (float) $page->height;
        $oldzones = zone_library::resolve($page->orientation, $oldwidth, $oldheight);

        [$width, $height] = zone_library::dimensions($pagesize, $orientation);

        $page->width = $width;
        $page->height = $height;
        $page->orientation = $orientation;
        $page->pagesize = $pagesize;
        $page->timemodified = time();
        $DB->update_record('certificatepro_pages', $page);

        $zones = zone_library::resolve($orientation, $width, $height);

        foreach ($DB->get_records('certificatepro_elements', ['pageid' => $pageid]) as $record) {
            $zoneid = (string) $record->zoneid;
            if ($zoneid !== '' && isset($zones[$zoneid])) {
                $this->redock_element($record, $zones[$zoneid], $oldzones[$zoneid] ?? null);
            } else {
                $this->refit_element($record, $oldwidth, $oldheight, $width, $height);
            }
        }

        $this->touch();

        return $page;
    }

    /**
     * Fit one element back into its zone after the zone has changed size.
     *
     * The element keeps the proportion of its zone that the author gave it,
     * rather than merely being clamped, so that a headline which filled its zone
     * still fills it and switching orientation twice returns where it started.
     *
     * @param \stdClass $record the element record
     * @param array $zone the zone at its new size, from {@see zone_library::resolve()}
     * @param array|null $oldzone the same zone at its previous size, when it is known
     * @return void
     */
    protected function redock_element(\stdClass $record, array $zone, ?array $oldzone): void {
        global $DB;

        $width = (float) $record->width;
        $height = (float) $record->height;

        if ($oldzone !== null && $oldzone['w'] > 0 && $oldzone['h'] > 0) {
            $width *= $zone['w'] / $oldzone['w'];
            $height *= $zone['h'] / $oldzone['h'];

            // A seal, a badge or a QR code is square, and no zone keeps its
            // proportions through a change of orientation. Scaling the two sides
            // by different factors would leave a circle drawn as an ellipse, so
            // anything that arrived square leaves square.
            if (abs((float) $record->width - (float) $record->height) < 0.01) {
                $width = $height = min($width, $height);
            }
        }

        $width = round(min($width, $zone['w']), 3);
        $height = round(min($height, $zone['h']), 3);
        $anchored = zone_library::anchor($zone, $width, $height);

        $DB->update_record('certificatepro_elements', (object) [
            'id' => (int) $record->id,
            'posx' => $anchored['posx'],
            'posy' => $anchored['posy'],
            'width' => $width,
            'height' => $height,
            'refpoint' => $zone['refpoint'],
            'alignment' => $zone['alignment'],
            'valignment' => $zone['valignment'],
            'timemodified' => time(),
        ]);
    }

    /**
     * Bring a freely positioned element back inside a page that has changed shape.
     *
     * A hand chosen position is the author's decision and is left alone wherever
     * it still fits, so this only moves what would otherwise fall off the canvas.
     *
     * @param \stdClass $record the element record
     * @param float $oldwidth the page width before the change, in millimetres
     * @param float $oldheight the page height before the change, in millimetres
     * @param float $width the new page width in millimetres
     * @param float $height the new page height in millimetres
     * @return void
     */
    protected function refit_element(\stdClass $record, float $oldwidth, float $oldheight,
            float $width, float $height): void {
        global $DB;

        $box = $this->box_for($record);

        // Artwork and borders are commonly dropped straight onto the canvas
        // without a zone. Clamping one would leave a bare strip down the side of
        // the page, so anything that covered the whole page still covers it.
        if ($box['left'] <= 0.5 && $box['top'] <= 0.5
                && $box['width'] >= $oldwidth - 0.5 && $box['height'] >= $oldheight - 0.5) {
            $box = ['left' => 0.0, 'top' => 0.0, 'width' => $width, 'height' => $height];
        } else {
            $box['width'] = min($box['width'], $width);
            $box['height'] = min($box['height'], $height);
            $box['left'] = max(0.0, min($box['left'], $width - $box['width']));
            $box['top'] = max(0.0, min($box['top'], $height - $box['height']));
        }

        $anchored = $this->anchor_for($record, $box);

        $DB->update_record('certificatepro_elements', (object) [
            'id' => (int) $record->id,
            'posx' => $anchored['posx'],
            'posy' => $anchored['posy'],
            'width' => round($box['width'], 3),
            'height' => round($box['height'], 3),
            'timemodified' => time(),
        ]);
    }

    /**
     * The top left corner and size of an element, in millimetres.
     *
     * Stored coordinates are those of the element's reference point rather than
     * of its corner, so they have to be unpacked before any geometry is done.
     *
     * @param \stdClass $record the element record
     * @return array{left: float, top: float, width: float, height: float}
     */
    protected function box_for(\stdClass $record): array {
        $width = (float) $record->width;
        $height = (float) $record->height;
        $refpoint = (string) ($record->refpoint ?: 'tl');
        $left = (float) $record->posx;
        $top = (float) $record->posy;

        $left -= match (substr($refpoint, 1, 1)) {
            'c' => $width / 2,
            'r' => $width,
            default => 0.0,
        };

        $top -= match (substr($refpoint, 0, 1)) {
            'm' => $height / 2,
            'b' => $height,
            default => 0.0,
        };

        return ['left' => $left, 'top' => $top, 'width' => $width, 'height' => $height];
    }

    /**
     * The stored coordinates for a box, given the element's reference point.
     *
     * @param \stdClass $record the element record
     * @param array $box left, top, width and height in millimetres
     * @return array{posx: float, posy: float}
     */
    protected function anchor_for(\stdClass $record, array $box): array {
        $refpoint = (string) ($record->refpoint ?: 'tl');

        $posx = $box['left'] + match (substr($refpoint, 1, 1)) {
            'c' => $box['width'] / 2,
            'r' => $box['width'],
            default => 0.0,
        };

        $posy = $box['top'] + match (substr($refpoint, 0, 1)) {
            'm' => $box['height'] / 2,
            'b' => $box['height'],
            default => 0.0,
        };

        return ['posx' => round($posx, 3), 'posy' => round($posy, 3)];
    }

    /**
     * Remove a page and everything on it.
     *
     * @param int $pageid the page to remove
     * @return void
     */
    public function delete_page(int $pageid): void {
        global $DB;

        foreach (array_keys($this->get_elements($pageid)) as $elementid) {
            $this->delete_element($elementid);
        }

        $DB->delete_records('certificatepro_pages', ['id' => $pageid, 'templateid' => $this->get_id()]);
        $this->resequence_pages();
        $this->touch();
    }

    /**
     * Renumber pages so their sequence has no gaps.
     *
     * @return void
     */
    protected function resequence_pages(): void {
        global $DB;

        $sequence = 1;
        foreach ($this->get_pages() as $page) {
            if ((int) $page->sequence !== $sequence) {
                $DB->set_field('certificatepro_pages', 'sequence', $sequence, ['id' => $page->id]);
            }
            $sequence++;
        }
    }

    /**
     * Put this design's pages into a given order.
     *
     * The order is stated as the whole list rather than as one page and a
     * destination, because that is what makes the result independent of what
     * the browser believed the order was when the author took hold of a page.
     * A list that is not a rearrangement of exactly this design's pages is
     * refused outright: renumbering from a partial list would silently drop
     * pages to the end of the design in an order nobody chose.
     *
     * @param int[] $pageids every page of this design, in the order wanted
     * @return bool true when the order was applied, false when the list did not match
     */
    public function reorder_pages(array $pageids): bool {
        global $DB;

        $wanted = array_values(array_unique(array_map('intval', $pageids)));
        $known = array_map('intval', array_keys($this->get_pages()));

        $sorted = $wanted;
        sort($sorted);
        sort($known);
        if ($sorted !== $known) {
            return false;
        }

        $sequence = 1;
        foreach ($wanted as $pageid) {
            $DB->set_field('certificatepro_pages', 'sequence', $sequence,
                ['id' => $pageid, 'templateid' => $this->get_id()]);
            $sequence++;
        }

        $this->touch();

        return true;
    }

    /**
     * Add an element to a page, docking it into a zone when one is named.
     *
     * @param int $pageid the page to add to
     * @param string $type the element type identifier
     * @param string|null $zoneid the zone to dock into, or null to place at the origin
     * @return int the new element id
     */
    public function add_element(int $pageid, string $type, ?string $zoneid = null): int {
        global $DB;

        $page = $DB->get_record('certificatepro_pages',
            ['id' => $pageid, 'templateid' => $this->get_id()], '*', MUST_EXIST);

        $element = registry::create($type, $pageid);
        $record = $element->get_record();

        if ($zoneid !== null) {
            $zones = zone_library::resolve($page->orientation, (float) $page->width, (float) $page->height);
            if (isset($zones[$zoneid])) {
                $zone = $zones[$zoneid];
                $width = min((float) $record->width, $zone['w']);
                $height = min((float) $record->height, $zone['h']);

                // Fitting a square into a zone means the smaller side, not each
                // side clamped on its own: a seal dropped into a wide, shallow
                // signature zone would otherwise be created as an oval and stay
                // that way until someone dragged it.
                if ($element::keepsquare()) {
                    $width = $height = min($width, $height);
                }

                [$record->width, $record->height] = $element::constrain_size(
                    $width, $height, (float) $record->width, (float) $record->height);
                $anchored = zone_library::anchor($zone, (float) $record->width, (float) $record->height);
                $record->posx = $anchored['posx'];
                $record->posy = $anchored['posy'];
                $record->refpoint = $zone['refpoint'];
                $record->alignment = $zone['alignment'];
                $record->valignment = $zone['valignment'];
                $record->zoneid = $zoneid;
            }
        } else {
            $record->posx = round((float) $page->width / 2, 3);
            $record->posy = round((float) $page->height / 2, 3);
            $record->refpoint = 'mc';
            $record->alignment = 'C';
            $record->valignment = 'M';
        }

        $record->zindex = $this->next_zindex($pageid);
        $record->sequence = $record->zindex;

        unset($record->id);
        $id = (int) $DB->insert_record('certificatepro_elements', $record);
        $this->touch();

        return $id;
    }

    /**
     * The z index a newly added element should take.
     *
     * @param int $pageid the page to inspect
     * @return int
     */
    protected function next_zindex(int $pageid): int {
        global $DB;

        $max = $DB->get_field_sql(
            'SELECT MAX(zindex) FROM {certificatepro_elements} WHERE pageid = ?',
            [$pageid]
        );

        return (int) $max + 1;
    }

    /**
     * Remove an element, leaving the files it owns where they are.
     *
     * Deleting an element can be taken back, and destroying the picture on the
     * way out made that promise a lie: everything else about a picture element
     * came back and the picture did not, which is the one loss an author cannot
     * repair from inside the designer. The files therefore outlive the row that
     * pointed at them, filed under the id the element had.
     *
     * Nothing is leaked by that. A file whose element has gone is reachable only
     * through this design, and {@see self::delete()} sweeps every one of them up
     * when the design itself goes. Holding them until then rather than until
     * some shorter deadline is deliberate: the undo window lives in the author's
     * browser, the server is never told when it closes, and any timer invented
     * here would eventually expire while an author still had the undo in front
     * of them.
     *
     * @param int $elementid the element to remove
     * @return void
     */
    public function delete_element(int $elementid): void {
        global $DB;

        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid]);
        if (!$record) {
            return;
        }

        $DB->delete_records('certificatepro_elements', ['id' => $elementid]);
        $this->touch();

        // Removing the generated background takes the page off its ground, and
        // the colour roles have to go back to their light page values.
        \mod_certificatepro\local\art\ground::forget((int) $record->pageid);
    }

    /**
     * Destroy the files a set of elements owned.
     *
     * @param int[] $elementids the elements whose files are to go
     * @return void
     */
    protected function purge_element_files(array $elementids): void {
        $fs = get_file_storage();
        $areas = registry::all_file_areas();

        foreach ($elementids as $elementid) {
            foreach ($areas as $area) {
                $fs->delete_area_files($this->context->id, 'mod_certificatepro', $area,
                    (int) $elementid);
            }
        }
    }

    /**
     * Give one element a copy of every file another element owns.
     *
     * An element's files are filed under its own id, so an element written as a
     * new row - a duplicate, or one put back by a restore - starts with none of
     * them however faithfully the rest of it was copied. This is what carries
     * them across, and it is the call any code that rebuilds an element under a
     * new id has to make before asking that element to draw itself, because a
     * picture element with an empty file area exports no picture at all.
     *
     * The count is handed back so a caller can tell an element that gained a
     * picture from one that never had one, and re-export only the elements whose
     * drawing has actually changed.
     *
     * @param int $fromid the element whose files are to be copied
     * @param int $toid the element to give them to
     * @param \context|null $target the context to copy into, or null for this design's own
     * @return int how many files were copied
     */
    public function adopt_element_files(int $fromid, int $toid, ?\context $target = null): int {
        $fs = get_file_storage();
        $destination = $target ?? $this->context;
        $copied = 0;

        foreach (registry::all_file_areas() as $area) {
            $files = $fs->get_area_files($this->context->id, 'mod_certificatepro', $area,
                $fromid, 'id', false);

            foreach ($files as $file) {
                $fs->create_file_from_storedfile([
                    'contextid' => $destination->id,
                    'itemid' => $toid,
                ], $file);
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * Whether an element id names a row that still exists anywhere on this site.
     *
     * Files left behind by a delete are filed under the id their element had, and
     * that id is the only handle on them. Handing one to
     * {@see self::adopt_element_files()} is therefore how a restored element gets
     * its picture back — and it would equally be how one element could help
     * itself to a live element's picture, so a caller acting on an id that came
     * from a browser has to be able to insist the id is a dead one.
     *
     * @param int $elementid the id to test
     * @return bool true when an element row still holds that id
     */
    public function element_exists(int $elementid): bool {
        global $DB;

        return $DB->record_exists('certificatepro_elements', ['id' => $elementid]);
    }

    /**
     * Every element id this design still holds, across all of its pages.
     *
     * @return int[]
     */
    protected function all_element_ids(): array {
        global $DB;

        $pageids = array_keys($this->get_pages());
        if (!$pageids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($pageids);

        return array_map('intval',
            $DB->get_fieldset_select('certificatepro_elements', 'id', "pageid $insql", $params));
    }

    /**
     * Destroy element files this context holds that no element points at any more.
     *
     * The files an element delete leaves behind are only findable through the
     * id that element had, and that id is gone from the table the moment the row
     * is deleted, so a design cannot simply list them when it comes to be
     * removed. They are found the other way round instead: every element file in
     * this context is looked at, and one whose itemid names no element at all is
     * a file no design can ever draw again.
     *
     * Sweeping the whole context rather than this design alone is safe because
     * of that test - a file still owned by a second design in the same context
     * names a live element and is left alone - and it is what keeps a site whose
     * authors delete elements but never delete designs from growing files it can
     * never use.
     *
     * @return void
     */
    protected function purge_orphan_files(): void {
        global $DB;

        $fs = get_file_storage();

        foreach (registry::all_file_areas() as $area) {
            $itemids = $DB->get_fieldset_select('files', 'DISTINCT itemid',
                'contextid = ? AND component = ? AND filearea = ? AND itemid > 0',
                [$this->context->id, 'mod_certificatepro', $area]);

            foreach ($itemids as $itemid) {
                if ($DB->record_exists('certificatepro_elements', ['id' => (int) $itemid])) {
                    continue;
                }

                $fs->delete_area_files($this->context->id, 'mod_certificatepro', $area,
                    (int) $itemid);
            }
        }
    }

    /**
     * Move and resize an element, recording which zone it landed in.
     *
     * @param int $elementid the element to move
     * @param array $geometry posx, posy, width, height, rotation, refpoint, zoneid
     * @return void
     */
    public function move_element(int $elementid, array $geometry): void {
        global $DB;

        $record = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);

        $fields = ['posx', 'posy', 'width', 'height', 'rotation'];
        foreach ($fields as $field) {
            if (isset($geometry[$field])) {
                $record->{$field} = round((float) $geometry[$field], 3);
            }
        }

        foreach (['refpoint', 'alignment', 'valignment'] as $field) {
            if (!empty($geometry[$field])) {
                $record->{$field} = clean_param($geometry[$field], PARAM_ALPHA);
            }
        }

        if (array_key_exists('zoneid', $geometry)) {
            $record->zoneid = $geometry['zoneid'] !== null && $geometry['zoneid'] !== ''
                ? clean_param($geometry['zoneid'], PARAM_ALPHANUMEXT)
                : null;
        }

        if (isset($geometry['zindex'])) {
            $record->zindex = (int) $geometry['zindex'];
        }

        $record->timemodified = time();
        $DB->update_record('certificatepro_elements', $record);
        $this->touch();
    }

    /**
     * Duplicate this design, including every page, element and file.
     *
     * @param string|null $name the new name, or null to derive one
     * @param \context|null $context the context to copy into, or null for the same one
     * @return self the new design
     */
    public function duplicate(?string $name = null, ?\context $context = null): self {
        global $DB;

        $target = $context ?? $this->context;

        $copy = clone $this->record;
        unset($copy->id);
        $copy->name = $name ?? get_string('copyofname', 'mod_certificatepro', $this->get_name());
        $copy->contextid = $target->id;
        $copy->shared = 0;
        $copy->revision = 1;
        $copy->timecreated = time();
        $copy->timemodified = time();
        $copy->id = $DB->insert_record('certificatepro_templates', $copy);

        foreach ($this->get_pages() as $page) {
            $pagecopy = clone $page;
            unset($pagecopy->id);
            $pagecopy->templateid = $copy->id;
            $pagecopy->timecreated = time();
            $pagecopy->timemodified = time();
            $newpageid = (int) $DB->insert_record('certificatepro_pages', $pagecopy);

            $elements = $DB->get_records('certificatepro_elements', ['pageid' => $page->id],
                'zindex ASC, id ASC');

            foreach ($elements as $element) {
                $oldid = (int) $element->id;
                $elementcopy = clone $element;
                unset($elementcopy->id);
                $elementcopy->pageid = $newpageid;
                $elementcopy->timecreated = time();
                $elementcopy->timemodified = time();
                $newid = (int) $DB->insert_record('certificatepro_elements', $elementcopy);

                $this->adopt_element_files($oldid, $newid, $target);
            }
        }

        return new self($DB->get_record('certificatepro_templates', ['id' => $copy->id], '*', MUST_EXIST));
    }

    /**
     * Bump the revision so issued certificates can record which version they came from.
     *
     * @return void
     */
    public function touch(): void {
        global $DB;

        $this->record->revision = (int) $this->record->revision + 1;
        $this->record->timemodified = time();

        $DB->update_record('certificatepro_templates', (object) [
            'id' => $this->record->id,
            'revision' => $this->record->revision,
            'timemodified' => $this->record->timemodified,
        ]);
    }

    /**
     * Save the design's own properties.
     *
     * @param array $properties name, themecolour, accentcolour, fontpairing, shared
     * @return void
     */
    public function update(array $properties): void {
        global $DB;

        $allowed = ['name', 'themecolour', 'accentcolour', 'fontpairing', 'shared', 'starter'];
        $update = (object) ['id' => $this->record->id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $properties)) {
                $this->record->{$field} = $properties[$field];
                $update->{$field} = $properties[$field];
            }
        }

        $update->timemodified = time();
        $DB->update_record('certificatepro_templates', $update);
        $this->touch();
    }

    /**
     * Throw away every page of this design and rebuild it from its starter.
     *
     * The design is rebuilt in place rather than replaced, because the activity,
     * every issued certificate and every backup refer to this design by id.
     * The starter is asked for a scratch design and its pages are re-parented
     * here, which keeps one description of what a starter contains instead of a
     * second copy of it that would drift out of step.
     *
     * @return self
     */
    public function reset_to_starter(): self {
        global $DB;

        // A design that was never built from a starter still has to revert to
        // something, and the catalogue itself treats classic as the default.
        $starter = (string) ($this->record->starter ?: 'classic');
        $fresh = starter_templates::build($starter, $this->get_name(), $this->context);

        // Reverting is the one destructive act the designer offers that undo
        // cannot walk back, so the pictures of the elements it throws away have
        // nothing left to come back for. They are noted before the elements go,
        // because afterwards there is no id left to find them by.
        $discarded = $this->all_element_ids();

        foreach ($this->get_pages() as $page) {
            $this->delete_page((int) $page->id);
        }

        $this->purge_element_files($discarded);

        $DB->set_field('certificatepro_pages', 'templateid', $this->get_id(),
            ['templateid' => $fresh->get_id()]);

        // The scratch design existed only to be rebuilt into this one, and its
        // pages have moved here, so only the now empty row is left to remove.
        $DB->delete_records('certificatepro_templates', ['id' => $fresh->get_id()]);

        // The author's own colours are a property of the design rather than of
        // the starter, so reverting the layout must not silently rebrand it.
        $this->update([
            'fontpairing' => $fresh->get_record()->fontpairing,
            'starter' => $starter,
        ]);

        return $this;
    }

    /**
     * Delete this design and everything belonging to it.
     *
     * This is where element files finally go. An element delete leaves them
     * behind so that undo can put a picture back; nothing else in the plugin
     * ever removes them, so a design that is being removed has to take both its
     * own elements' files and the ones its earlier deletes left stranded.
     *
     * @return void
     */
    public function delete(): void {
        global $DB;

        // Read before the pages go: once the element rows are deleted their ids
        // are the only handle on their files and the table no longer has them.
        $owned = $this->all_element_ids();

        foreach ($this->get_pages() as $page) {
            $this->delete_page((int) $page->id);
        }

        $this->purge_element_files($owned);
        $this->purge_orphan_files();

        $DB->delete_records('certificatepro_templates', ['id' => $this->get_id()]);
    }

    /**
     * Build a render context configured for this design.
     *
     * @param render_context $ctx the context to configure
     * @return render_context the same context, for chaining
     */
    public function configure(render_context $ctx): render_context {
        $ctx->set_palette($this->get_palette());
        $ctx->set_pairing($this->get_pairing());

        return $ctx;
    }

    /**
     * Everything the designer needs to draw this design, as plain data.
     *
     * @param render_context $ctx the render context
     * @return array
     */
    public function export_for_designer(render_context $ctx): array {
        $this->configure($ctx);

        $pages = [];
        foreach ($this->get_pages() as $page) {
            $ctx->set_page($page);

            $elements = [];
            foreach ($this->get_elements((int) $page->id) as $element) {
                $elements[] = $element->export_for_designer($ctx);
            }

            $pages[] = [
                'id' => (int) $page->id,
                'width' => (float) $page->width,
                'height' => (float) $page->height,
                'orientation' => $page->orientation,
                'pagesize' => $page->pagesize,
                'margins' => [
                    'left' => (float) $page->marginleft,
                    'right' => (float) $page->marginright,
                    'top' => (float) $page->margintop,
                    'bottom' => (float) $page->marginbottom,
                ],
                'backgroundcolour' => $page->backgroundcolour,
                'sequence' => (int) $page->sequence,
                'zones' => array_values(zone_library::resolve(
                    $page->orientation, (float) $page->width, (float) $page->height)),
                'guides' => zone_library::guides(
                    (float) $page->width,
                    (float) $page->height,
                    (float) $page->marginleft,
                    (float) $page->marginright,
                    (float) $page->margintop,
                    (float) $page->marginbottom
                ),
                'elements' => $elements,
            ];
        }

        return [
            'id' => $this->get_id(),
            'name' => $this->get_name(),
            'palette' => $this->get_palette(),
            'pairing' => $this->get_pairing(),
            'revision' => (int) $this->record->revision,
            'pages' => $pages,
        ];
    }
}
