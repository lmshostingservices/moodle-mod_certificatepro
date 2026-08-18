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
 * Restore structure step for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Rebuilds a Certificate Pro activity from a backup.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_certificatepro_activity_structure_step extends restore_activity_structure_step {
    /**
     * Build the restore structure.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('certificatepro', '/activity/certificatepro');
        $paths[] = new restore_path_element('certificatepro_template',
            '/activity/certificatepro/template');
        $paths[] = new restore_path_element('certificatepro_page',
            '/activity/certificatepro/template/pages/page');
        $paths[] = new restore_path_element('certificatepro_element',
            '/activity/certificatepro/template/pages/page/elements/element');

        if ($userinfo) {
            $paths[] = new restore_path_element('certificatepro_issue',
                '/activity/certificatepro/issues/issue');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * @param array $data the backed up record
     * @return void
     */
    protected function process_certificatepro($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->templateid = 0;

        $newitemid = $DB->insert_record('certificatepro', $data);

        $this->apply_activity_instance($newitemid);
        $this->set_mapping('certificatepro', $oldid, $newitemid);
    }

    /**
     * Restore the design.
     *
     * @param array $data the backed up record
     * @return void
     */
    protected function process_certificatepro_template($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->contextid = $this->task->get_contextid();

        $newitemid = $DB->insert_record('certificatepro_templates', $data);

        $this->set_mapping('certificatepro_template', $oldid, $newitemid);

        $DB->set_field('certificatepro', 'templateid', $newitemid,
            ['id' => $this->get_new_parentid('certificatepro')]);
    }

    /**
     * Restore a page.
     *
     * @param array $data the backed up record
     * @return void
     */
    protected function process_certificatepro_page($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->templateid = $this->get_new_parentid('certificatepro_template');

        $newitemid = $DB->insert_record('certificatepro_pages', $data);

        $this->set_mapping('certificatepro_page', $oldid, $newitemid);
    }

    /**
     * Restore an element.
     *
     * @param array $data the backed up record
     * @return void
     */
    protected function process_certificatepro_element($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->pageid = $this->get_new_parentid('certificatepro_page');

        $newitemid = $DB->insert_record('certificatepro_elements', $data);

        // Mapped with files so images survive a restore, which is exactly the
        // step that tends to be missed.
        $this->set_mapping('certificatepro_element', $oldid, $newitemid, true);
    }

    /**
     * Restore an issued certificate.
     *
     * @param array $data the backed up record
     * @return void
     */
    protected function process_certificatepro_issue($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->certificateproid = $this->get_new_parentid('certificatepro');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->issuedby = $this->get_mappingid('user', $data->issuedby) ?: 0;
        $data->revokedby = $this->get_mappingid('user', $data->revokedby) ?: 0;

        if (empty($data->userid)) {
            return;
        }

        // A verification code is unique site-wide, so a code that has arrived
        // from another site and already exists here is given a fresh one.
        if ($DB->record_exists('certificatepro_issues', ['code' => $data->code])) {
            $data->code = \mod_certificatepro\local\issue_service::generate_code();
        }

        $newitemid = $DB->insert_record('certificatepro_issues', $data);

        $this->set_mapping('certificatepro_issue', $oldid, $newitemid, true);
    }

    /**
     * Bring across every file the activity owns.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_certificatepro', 'intro', null);

        foreach (\mod_certificatepro\element\registry::all_file_areas() as $area) {
            $this->add_related_files('mod_certificatepro', $area, 'certificatepro_element');
        }

        $this->add_related_files('mod_certificatepro',
            \mod_certificatepro\local\issue_service::FILEAREA, 'certificatepro_issue');
    }
}
