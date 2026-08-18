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
 * Backup structure step for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines what a Certificate Pro backup contains.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_certificatepro_activity_structure_step extends backup_activity_structure_step {
    /**
     * Build the backup structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $certificatepro = new backup_nested_element('certificatepro', ['id'], [
            'name', 'intro', 'introformat', 'templateid', 'requiredtime', 'requiregrade',
            'requirecompletion', 'autoissue', 'verifyany', 'deliveryoption', 'usecustomfilename',
            'customfilenamepattern', 'emailstudents', 'emailteachers', 'emailothers',
            'validityperiod', 'expirywarndays', 'serialprefix', 'serialpadding', 'protection',
            'revocationallowed', 'timecreated', 'timemodified',
        ]);

        $template = new backup_nested_element('template', ['id'], [
            'name', 'shared', 'starter', 'themecolour', 'accentcolour', 'fontpairing',
            'revision', 'timecreated', 'timemodified',
        ]);

        $pages = new backup_nested_element('pages');
        $page = new backup_nested_element('page', ['id'], [
            'width', 'height', 'orientation', 'pagesize', 'marginleft', 'marginright',
            'margintop', 'marginbottom', 'backgroundcolour', 'backgroundsvg', 'sequence',
            'timecreated', 'timemodified',
        ]);

        $elements = new backup_nested_element('elements');
        $element = new backup_nested_element('element', ['id'], [
            'element', 'name', 'data', 'style', 'posx', 'posy', 'width', 'height', 'rotation',
            'refpoint', 'alignment', 'valignment', 'zoneid', 'zindex', 'sequence', 'locked',
            'hidden', 'timecreated', 'timemodified',
        ]);

        $issues = new backup_nested_element('issues');
        $issue = new backup_nested_element('issue', ['id'], [
            'userid', 'code', 'serial', 'serialnumber', 'snapshot', 'templaterevision',
            'pdfhash', 'emailed', 'expires', 'expirynotified', 'revoked', 'revokedby',
            'revokedreason', 'timerevoked', 'issuedby', 'timecreated',
        ]);

        $certificatepro->add_child($template);
        $template->add_child($pages);
        $pages->add_child($page);
        $page->add_child($elements);
        $elements->add_child($element);
        $certificatepro->add_child($issues);
        $issues->add_child($issue);

        $certificatepro->set_source_table('certificatepro', ['id' => backup::VAR_ACTIVITYID]);
        $template->set_source_table('certificatepro_templates',
            ['id' => '../templateid']);
        $page->set_source_table('certificatepro_pages',
            ['templateid' => backup::VAR_PARENTID], 'sequence ASC, id ASC');
        $element->set_source_table('certificatepro_elements',
            ['pageid' => backup::VAR_PARENTID], 'zindex ASC, id ASC');

        if ($userinfo) {
            $issue->set_source_table('certificatepro_issues',
                ['certificateproid' => backup::VAR_PARENTID]);
            $issue->annotate_ids('user', 'userid');
            $issue->annotate_ids('user', 'issuedby');
            $issue->annotate_ids('user', 'revokedby');
        }

        $certificatepro->annotate_files('mod_certificatepro', 'intro', null);

        foreach (\mod_certificatepro\element\registry::all_file_areas() as $area) {
            $element->annotate_files('mod_certificatepro', $area, 'id');
        }

        if ($userinfo) {
            $issue->annotate_files('mod_certificatepro',
                \mod_certificatepro\local\issue_service::FILEAREA, 'id');
        }

        return $this->prepare_activity_structure($certificatepro);
    }
}
