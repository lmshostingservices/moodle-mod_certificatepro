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

        return $DB->get_records(
            'certificatepro_pages',
            ['templateid' => $this->get_id()],
            'sequence ASC, id ASC'
        );
    }

    /**
     * Every element on a page, in drawing order.
     *
     * @param int $pageid the page to read
     * @return element_base[]
     */
    public function get_elements(int $pageid): array {
        global $DB;

        $records = $DB->get_records(
            'certificatepro_elements',
            ['pageid' => $pageid],
            'zindex ASC, sequence ASC, id ASC'
        );

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
     * Add an element to a page, docking it into a zone when one is named.
     *
     * @param int $pageid the page to add to
     * @param string $type the element type identifier
     * @param string|null $zoneid the zone to dock into, or null to place at the origin
     * @return int the new element id
     */
    public function add_element(int $pageid, string $type, ?string $zoneid = null): int {
        global $DB;

        $page = $DB->get_record(
            'certificatepro_pages',
            ['id' => $pageid, 'templateid' => $this->get_id()],
            '*',
            MUST_EXIST
        );

        $element = registry::create($type, $pageid);
        $record = $element->get_record();

        if ($zoneid !== null) {
            $zones = zone_library::resolve($page->orientation, (float) $page->width, (float) $page->height);
            if (isset($zones[$zoneid])) {
                $zone = $zones[$zoneid];
                $record->width = min((float) $record->width, $zone['w']);
                $record->height = min((float) $record->height, $zone['h']);
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
     * Remove an element and any files it owns.
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

        $fs = get_file_storage();
        foreach (registry::all_file_areas() as $area) {
            $fs->delete_area_files($this->context->id, 'mod_certificatepro', $area, $elementid);
        }

        $DB->delete_records('certificatepro_elements', ['id' => $elementid]);
        $this->touch();
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

        $fs = get_file_storage();
        $areas = registry::all_file_areas();

        foreach ($this->get_pages() as $page) {
            $pagecopy = clone $page;
            unset($pagecopy->id);
            $pagecopy->templateid = $copy->id;
            $pagecopy->timecreated = time();
            $pagecopy->timemodified = time();
            $newpageid = (int) $DB->insert_record('certificatepro_pages', $pagecopy);

            $elements = $DB->get_records(
                'certificatepro_elements',
                ['pageid' => $page->id],
                'zindex ASC, id ASC'
            );

            foreach ($elements as $element) {
                $oldid = (int) $element->id;
                $elementcopy = clone $element;
                unset($elementcopy->id);
                $elementcopy->pageid = $newpageid;
                $elementcopy->timecreated = time();
                $elementcopy->timemodified = time();
                $newid = (int) $DB->insert_record('certificatepro_elements', $elementcopy);

                foreach ($areas as $area) {
                    $files = $fs->get_area_files(
                        $this->context->id,
                        'mod_certificatepro',
                        $area,
                        $oldid,
                        'id',
                        false
                    );
                    foreach ($files as $file) {
                        $fs->create_file_from_storedfile(
                            [
                                'contextid' => $target->id,
                                'itemid' => $newid,
                            ],
                            $file
                        );
                    }
                }
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

        $DB->update_record(
            'certificatepro_templates',
            (object) [
                'id' => $this->record->id,
                'revision' => $this->record->revision,
                'timemodified' => $this->record->timemodified,
            ]
        );
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
     * Delete this design and everything belonging to it.
     *
     * @return void
     */
    public function delete(): void {
        global $DB;

        foreach ($this->get_pages() as $page) {
            $this->delete_page((int) $page->id);
        }

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
            $ctx->set_page_size((float) $page->width, (float) $page->height);

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
                'zones' => array_values(
                    zone_library::resolve(
                        $page->orientation,
                        (float) $page->width,
                        (float) $page->height
                    )
                ),
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
