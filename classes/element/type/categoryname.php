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

namespace mod_certificatepro\element\type;

use mod_certificatepro\element\render_context;
use mod_certificatepro\element\text_base;

/**
 * The name of the category the course sits in.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categoryname extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'categoryname';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DATA;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-folder-open';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [120.0, 8.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(
            parent::default_style(),
            [
                'font' => 'accent',
                'scalerole' => 'eyebrow',
                'size' => null,
                'colour' => 'muted',
                'tracking' => 2.0,
                'case' => 'upper',
            ]
        );
    }

    /**
     * Offer the whole category path as an alternative to the leaf name.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'advcheckbox',
            'showpath',
            get_string('showcategorypath', 'mod_certificatepro')
        );
        $mform->setDefault('showpath', 0);
    }

    /**
     * Persist the path preference.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return ['showpath' => !empty($data->showpath) ? 1 : 0];
    }

    /**
     * The category name, optionally as a full path.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $course = $ctx->get_course();
        if ($course === null || empty($course->category)) {
            return get_string('sample_categoryname', 'mod_certificatepro');
        }

        $category = \core_course_category::get((int) $course->category, IGNORE_MISSING, true);
        if ($category === null) {
            return '';
        }

        if ($this->config('showpath', 0)) {
            $names = [];
            foreach ($category->get_parents() as $parentid) {
                $parent = \core_course_category::get($parentid, IGNORE_MISSING, true);
                if ($parent !== null) {
                    $names[] = $parent->get_formatted_name();
                }
            }
            $names[] = $category->get_formatted_name();
            return implode(' / ', $names);
        }

        return $category->get_formatted_name();
    }}
