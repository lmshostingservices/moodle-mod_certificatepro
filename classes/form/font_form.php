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

namespace mod_certificatepro\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * The form for adding a font.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class font_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'uploadheader',
            get_string('fontupload', 'mod_certificatepro'));

        $mform->addElement('filepicker', 'fontfile',
            get_string('fontfile', 'mod_certificatepro'), null,
            ['accepted_types' => ['.ttf', '.otf'], 'maxbytes' => 20 * 1024 * 1024]);
        $mform->addRule('fontfile', null, 'required', null, 'client');

        $mform->addElement('text', 'displayname',
            get_string('fontdisplayname', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('displayname', PARAM_TEXT);
        $mform->addRule('displayname', null, 'required', null, 'client');

        $mform->addElement('select', 'fontstyle',
            get_string('fontstyle', 'mod_certificatepro'), [
                '' => get_string('fontstyle_regular', 'mod_certificatepro'),
                'B' => get_string('fontstyle_bold', 'mod_certificatepro'),
                'I' => get_string('fontstyle_italic', 'mod_certificatepro'),
                'BI' => get_string('fontstyle_bolditalic', 'mod_certificatepro'),
            ]);

        $this->add_action_buttons(false, get_string('fontupload', 'mod_certificatepro'));
    }
}
