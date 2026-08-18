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
 * The name of the group or groups the recipient belongs to.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groupname extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'groupname';
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
        return 'fa-users';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [90.0, 8.0];
    }

    /**
     * Choose how multiple groups are joined.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'groupmode',
            get_string('groupdisplay', 'mod_certificatepro'), [
                'first' => get_string('groupdisplay_first', 'mod_certificatepro'),
                'all' => get_string('groupdisplay_all', 'mod_certificatepro'),
            ]);
        $mform->setDefault('groupmode', 'first');
    }

    /**
     * Persist the chosen mode.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return ['groupmode' => $data->groupmode ?? 'first'];
    }

    /**
     * The group name or names.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $course = $ctx->get_course();
        $user = $ctx->get_user();
        if ($course === null || $user === null) {
            return get_string('sample_groupname', 'mod_certificatepro');
        }

        $groups = groups_get_all_groups($course->id, $user->id);
        if (empty($groups)) {
            return '';
        }

        $names = [];
        foreach ($groups as $group) {
            $names[] = format_string($group->name, true, ['context' => $ctx->get_context()]);
        }

        if ($this->config('groupmode', 'first') === 'first') {
            return (string) reset($names);
        }

        return implode(', ', $names);
    }}
