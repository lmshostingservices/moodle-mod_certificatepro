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

use mod_certificatepro\local\media_library;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * The form for adding a picture to the shared library.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'header',
            'uploadheader',
            get_string('mediaupload', 'mod_certificatepro')
        );

        $mform->addElement(
            'filepicker',
            'mediafile',
            get_string('mediafile', 'mod_certificatepro'),
            null,
            ['accepted_types' => ['web_image', '.svg'], 'maxbytes' => 20 * 1024 * 1024]
        );
        $mform->addRule('mediafile', null, 'required', null, 'client');

        $mform->addElement(
            'text',
            'medianame',
            get_string('medianame', 'mod_certificatepro'),
            ['size' => 50]
        );
        $mform->setType('medianame', PARAM_TEXT);
        $mform->addRule('medianame', null, 'required', null, 'client');
        $mform->addHelpButton('medianame', 'medianame', 'mod_certificatepro');

        $mform->addElement(
            'select',
            'mediakind',
            get_string('mediakind', 'mod_certificatepro'),
            media_library::kinds()
        );
        $mform->setDefault('mediakind', media_library::KIND_SIGNATURE);

        $this->add_action_buttons(false, get_string('mediaupload', 'mod_certificatepro'));
    }
}
