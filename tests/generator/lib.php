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
 * Test instance generator for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Creates Certificate Pro activities for tests and for Behat.
 *
 * Every instance owns a design, and the design is built by the same starter
 * code the module form drives, so an activity made here is the activity a
 * teacher would have made rather than a hollow database row.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_certificatepro_generator extends testing_module_generator {
    /**
     * Create a Certificate Pro activity.
     *
     * @param array|stdClass|null $record the instance settings, including an
     *      optional starter naming the design to build from
     * @param array|null $options course module options
     * @return stdClass the new instance record
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'starter' => 'classic',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'templateid' => 0,
            'requiredtime' => 0,
            'requiregrade' => null,
            'requirecompletion' => 0,
            'autoissue' => 1,
            'verifyany' => 1,
            'deliveryoption' => 'D',
            'usecustomfilename' => 0,
            'customfilenamepattern' => null,
            'emailstudents' => 0,
            'emailteachers' => 0,
            'emailothers' => null,
            'validityperiod' => 0,
            'expirywarndays' => 0,
            'serialprefix' => null,
            'serialpadding' => 6,
            'protection' => null,
            'revocationallowed' => 1,
        ];

        foreach ($defaults as $field => $value) {
            if (!isset($record->{$field})) {
                $record->{$field} = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }
}
