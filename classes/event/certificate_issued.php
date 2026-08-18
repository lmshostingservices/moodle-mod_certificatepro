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
 * A certificate was issued to a user.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate_issued extends \core\event\base {
    /**
     * Describe what kind of event this is.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'certificatepro_issues';
    }

    /**
     * The translated name of this event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventcertificate_issued', 'mod_certificatepro');
    }

    /**
     * A sentence describing what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' was issued a certificate with id "
            . "'{$this->objectid}' in the certificatepro activity with course module id "
            . "'{$this->contextinstanceid}'.";
    }

    /**
     * Where an administrator can go to see this.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/certificatepro/report.php', ['id' => $this->contextinstanceid]);
    }
}
