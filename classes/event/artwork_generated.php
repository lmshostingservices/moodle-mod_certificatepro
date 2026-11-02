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

namespace mod_certificatepro\event;

/**
 * Background artwork was generated.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artwork_generated extends \core\event\base {
    /**
     * Describe what kind of event this is.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'certificatepro_backgrounds';
    }

    /**
     * The translated name of this event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventartwork_generated', 'mod_certificatepro');
    }

    /**
     * A sentence describing what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' generated background artwork with id '{$this->objectid}'.";
    }

    /**
     * Where an administrator can go to see this.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/certificatepro/edit.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Mapping used when logs are restored.
     *
     * @return array|int
     */
    public static function get_objectid_mapping() {
        // Site-level design records are not part of a course backup.
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Other data needs no mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
