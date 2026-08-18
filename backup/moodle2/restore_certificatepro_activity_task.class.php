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

/**
 * Restore task for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/certificatepro/backup/moodle2/restore_certificatepro_stepslib.php');

/**
 * Restore task for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_certificatepro_activity_task extends restore_activity_task {
    /**
     * No settings of our own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the one structure step this activity needs.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_certificatepro_activity_structure_step(
            'certificatepro_structure', 'certificatepro.xml'));
    }

    /**
     * The file areas that need decoding.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('certificatepro', ['intro'], 'certificatepro')];
    }

    /**
     * The link patterns this activity encodes.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('CERTIFICATEPROVIEWBYID',
                '/mod/certificatepro/view.php?id=$1', 'course_module'),
            new restore_decode_rule('CERTIFICATEPROINDEX',
                '/mod/certificatepro/index.php?id=$1', 'course'),
        ];
    }
}
