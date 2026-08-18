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

namespace mod_certificatepro\element;

/**
 * Knows every element type and turns stored records into element objects.
 *
 * An element type that is no longer installed never causes a fatal error: the
 * registry substitutes a placeholder that keeps its position and its stored
 * configuration, so a design opened on a site without some optional element
 * still round trips intact.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /** @var string[]|null Cached list of type identifiers. */
    protected static ?array $types = null;

    /**
     * Every element type identifier this site can render.
     *
     * @return string[]
     */
    public static function types(): array {
        if (self::$types !== null) {
            return self::$types;
        }

        global $CFG;

        $types = [];
        $directory = $CFG->dirroot . '/mod/certificatepro/classes/element/type';
        foreach (glob($directory . '/*.php') ?: [] as $path) {
            $type = basename($path, '.php');
            $class = self::class_for($type);
            if (class_exists($class) && is_subclass_of($class, base::class)) {
                $types[] = $type;
            }
        }

        sort($types);
        self::$types = $types;

        return $types;
    }

    /**
     * The class name that implements a type.
     *
     * @param string $type the element type identifier
     * @return string a fully qualified class name
     */
    public static function class_for(string $type): string {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?? '';
        return '\\mod_certificatepro\\element\\type\\' . $type;
    }

    /**
     * Whether a type is available on this site.
     *
     * @param string $type the element type identifier
     * @return bool
     */
    public static function exists(string $type): bool {
        return in_array($type, self::types(), true);
    }

    /**
     * Build an element object from a stored record.
     *
     * @param \stdClass $record a row from the elements table
     * @return base
     */
    public static function instantiate(\stdClass $record): base {
        $class = self::class_for($record->element ?? '');
        if (class_exists($class) && is_subclass_of($class, base::class)) {
            return new $class($record);
        }
        return new type\unavailable($record);
    }

    /**
     * Build a fresh, unsaved element of the given type.
     *
     * @param string $type the element type identifier
     * @param int $pageid the page the element belongs to
     * @return base
     */
    public static function create(string $type, int $pageid): base {
        $class = self::class_for($type);
        if (!class_exists($class) || !is_subclass_of($class, base::class)) {
            throw new \moodle_exception('errorunknownelement', 'mod_certificatepro', '', $type);
        }

        [$width, $height] = $class::default_size();

        $record = new \stdClass();
        $record->id = 0;
        $record->pageid = $pageid;
        $record->element = $type;
        $record->name = $class::display_name();
        $record->data = json_encode([]);
        $record->style = json_encode($class::default_style(), JSON_PRESERVE_ZERO_FRACTION);
        $record->posx = 0.0;
        $record->posy = 0.0;
        $record->width = $width;
        $record->height = $height;
        $record->rotation = 0.0;
        $record->refpoint = 'tl';
        $record->alignment = 'L';
        $record->valignment = 'T';
        $record->zoneid = null;
        $record->zindex = 0;
        $record->sequence = 0;
        $record->locked = 0;
        $record->hidden = 0;
        $record->timecreated = time();
        $record->timemodified = time();

        return new $class($record);
    }

    /**
     * Every element type grouped by category, ready for the element picker.
     *
     * @return array[] categories, each with a translated name and a list of types
     */
    public static function picker_catalogue(): array {
        $categories = [
            base::CATEGORY_TEXT => [],
            base::CATEGORY_DATA => [],
            base::CATEGORY_IMAGE => [],
            base::CATEGORY_VERIFY => [],
            base::CATEGORY_DECOR => [],
        ];

        foreach (self::types() as $type) {
            $class = self::class_for($type);
            if ($type === 'unavailable') {
                continue;
            }
            $category = $class::category();
            $categories[$category][] = [
                'type' => $type,
                'name' => $class::display_name(),
                'description' => $class::description(),
                'icon' => $class::icon(),
                'defaultwidth' => $class::default_size()[0],
                'defaultheight' => $class::default_size()[1],
            ];
        }

        $catalogue = [];
        foreach ($categories as $category => $items) {
            if (empty($items)) {
                continue;
            }
            usort($items, static function (array $a, array $b): int {
                return strcmp($a['name'], $b['name']);
            });
            $catalogue[] = [
                'id' => $category,
                'name' => get_string('elementcategory_' . $category, 'mod_certificatepro'),
                'elements' => $items,
            ];
        }

        return $catalogue;
    }

    /**
     * Every file area owned by any element type.
     *
     * @return string[]
     */
    public static function all_file_areas(): array {
        $areas = [];
        foreach (self::types() as $type) {
            $class = self::class_for($type);
            $areas = array_merge($areas, $class::file_areas());
        }
        return array_values(array_unique($areas));
    }

    /**
     * Forget the cached type list. Used by tests.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$types = null;
    }
}
