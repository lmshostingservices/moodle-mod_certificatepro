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
 * Confirms a revocation and collects the reason for it.
 *
 * This is a form rather than an $OUTPUT->confirm() because revoking asks a
 * question as well as posing one: the reason is written to the issue record and
 * is then shown to anybody who checks the code on the public verify page, so it
 * has to be typed before the record is touched, not afterwards.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $holder = (string) ($this->_customdata['holder'] ?? '');

        $mform->addElement('static', 'revokewarning', '',
            \html_writer::div(
                get_string('confirmrevoke', 'mod_certificatepro', $holder),
                'alert alert-warning'
            ));

        // A revocation is announced publicly, so it is not allowed to be
        // anonymous: an unexplained "withdrawn" tells the holder and whoever
        // checked the code nothing they can act on. The field the wording is
        // written into is already displayed on the verify page, so leaving it
        // empty would put a blank line in front of the public.
        $mform->addElement('textarea', 'reason',
            get_string('revokereason', 'mod_certificatepro'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'issueid');
        $mform->setType('issueid', PARAM_INT);

        $mform->addElement('hidden', 'action', 'revoke');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('revokecertificate', 'mod_certificatepro'));
    }

    /**
     * Reject a reason that is only whitespace.
     *
     * @param array $data the submitted data
     * @param array $files the submitted files
     * @return array the errors, keyed by element name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['reason'] ?? '')) === '') {
            $errors['reason'] = get_string('required');
        }

        return $errors;
    }
}
