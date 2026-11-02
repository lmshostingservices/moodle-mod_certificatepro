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
 * The settings form for a Certificate Pro activity.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The settings form for a Certificate Pro activity.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_certificatepro_mod_form extends moodleform_mod {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        if (empty($this->_instance)) {
            $starters = [];
            foreach (\mod_certificatepro\local\starter_templates::catalogue() as $id => $starter) {
                $starters[$id] = $starter['name'];
            }
            $mform->addElement(
                'select',
                'starter',
                get_string('startingdesign', 'mod_certificatepro'),
                $starters
            );
            $mform->setDefault('starter', 'classic');
            $mform->addHelpButton('starter', 'startingdesign', 'mod_certificatepro');
        }

        // Availability.
        $mform->addElement(
            'header',
            'availabilityheader',
            get_string('availabilityheader', 'mod_certificatepro')
        );

        $mform->addElement(
            'duration',
            'requiredtime',
            get_string('requiredtime', 'mod_certificatepro'),
            ['units' => [MINSECS, HOURSECS], 'defaultunit' => MINSECS]
        );
        $mform->setType('requiredtime', PARAM_INT);
        $mform->addHelpButton('requiredtime', 'requiredtime', 'mod_certificatepro');

        $mform->addElement(
            'text',
            'requiregrade',
            get_string('requiregrade', 'mod_certificatepro'),
            ['size' => 6]
        );
        $mform->setType('requiregrade', PARAM_TEXT);
        $mform->addHelpButton('requiregrade', 'requiregrade', 'mod_certificatepro');

        $mform->addElement(
            'advcheckbox',
            'requirecompletion',
            get_string('requirecompletion', 'mod_certificatepro')
        );
        $mform->addHelpButton('requirecompletion', 'requirecompletion', 'mod_certificatepro');

        $mform->addElement(
            'advcheckbox',
            'autoissue',
            get_string('autoissue', 'mod_certificatepro')
        );
        $mform->setDefault('autoissue', 1);
        $mform->addHelpButton('autoissue', 'autoissue', 'mod_certificatepro');

        // Validity.
        $mform->addElement(
            'header',
            'validityheader',
            get_string('validityheader', 'mod_certificatepro')
        );

        $mform->addElement(
            'duration',
            'validityperiod',
            get_string('validityperiod', 'mod_certificatepro'),
            // Moodle 5.1+ requires the default unit to be one of the allowed units.
            ['units' => [DAYSECS, WEEKSECS], 'defaultunit' => DAYSECS, 'optional' => true]
        );
        $mform->setType('validityperiod', PARAM_INT);
        $mform->addHelpButton('validityperiod', 'validityperiod', 'mod_certificatepro');

        $mform->addElement(
            'text',
            'expirywarndays',
            get_string('expirywarndays', 'mod_certificatepro'),
            ['size' => 6]
        );
        $mform->setType('expirywarndays', PARAM_INT);
        $mform->setDefault('expirywarndays', 0);
        $mform->hideIf('expirywarndays', 'validityperiod[enabled]', 'notchecked');

        $mform->addElement(
            'text',
            'serialprefix',
            get_string('serialprefix', 'mod_certificatepro'),
            ['size' => 24]
        );
        $mform->setType('serialprefix', PARAM_TEXT);
        $mform->addHelpButton('serialprefix', 'serialprefix', 'mod_certificatepro');

        $mform->addElement(
            'select',
            'serialpadding',
            get_string('serialpadding', 'mod_certificatepro'),
            array_combine(range(3, 10), range(3, 10))
        );
        $mform->setDefault('serialpadding', 6);
        $mform->hideIf('serialpadding', 'serialprefix', 'eq', '');

        $mform->addElement(
            'advcheckbox',
            'revocationallowed',
            get_string('revocationallowed', 'mod_certificatepro')
        );
        $mform->setDefault('revocationallowed', 1);

        // Delivery.
        $mform->addElement(
            'header',
            'deliveryheader',
            get_string('deliveryheader', 'mod_certificatepro')
        );

        $mform->addElement(
            'select',
            'deliveryoption',
            get_string('deliveryoption', 'mod_certificatepro'),
            [
                'D' => get_string('deliveryoption_download', 'mod_certificatepro'),
                'I' => get_string('deliveryoption_inline', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('deliveryoption', 'D');

        $mform->addElement(
            'advcheckbox',
            'usecustomfilename',
            get_string('usecustomfilename', 'mod_certificatepro')
        );

        $mform->addElement(
            'text',
            'customfilenamepattern',
            get_string('customfilenamepattern', 'mod_certificatepro'),
            ['size' => 60]
        );
        $mform->setType('customfilenamepattern', PARAM_TEXT);
        $mform->setDefault('customfilenamepattern', '{fullname} - {coursefullname}');
        $mform->hideIf('customfilenamepattern', 'usecustomfilename', 'notchecked');
        $mform->addHelpButton('customfilenamepattern', 'customfilenamepattern', 'mod_certificatepro');

        $mform->addElement(
            'advcheckbox',
            'emailstudents',
            get_string('emailstudents', 'mod_certificatepro')
        );
        $mform->addElement(
            'advcheckbox',
            'emailteachers',
            get_string('emailteachers', 'mod_certificatepro')
        );
        $mform->addElement(
            'text',
            'emailothers',
            get_string('emailothers', 'mod_certificatepro'),
            ['size' => 60]
        );
        $mform->setType('emailothers', PARAM_TEXT);
        $mform->addHelpButton('emailothers', 'emailothers', 'mod_certificatepro');

        // Verification and protection.
        $mform->addElement(
            'header',
            'verificationheader',
            get_string('verificationheader', 'mod_certificatepro')
        );

        $mform->addElement(
            'advcheckbox',
            'verifyany',
            get_string('verifyany', 'mod_certificatepro')
        );
        $mform->setDefault('verifyany', 1);
        $mform->addHelpButton('verifyany', 'verifyany', 'mod_certificatepro');

        $protection = [
            'print' => get_string('protection_print', 'mod_certificatepro'),
            'modify' => get_string('protection_modify', 'mod_certificatepro'),
            'copy' => get_string('protection_copy', 'mod_certificatepro'),
        ];
        $checkboxes = [];
        foreach ($protection as $key => $label) {
            $checkboxes[] = $mform->createElement('advcheckbox', $key, '', $label);
        }
        $mform->addGroup(
            $checkboxes,
            'protectiongroup',
            get_string('protection', 'mod_certificatepro'),
            '<br />',
            true
        );
        $mform->addHelpButton('protectiongroup', 'protection', 'mod_certificatepro');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Fold the protection checkboxes into the stored comma separated value.
     *
     * @return stdClass|null the submitted data
     */
    public function get_data() {
        $data = parent::get_data();

        if ($data === null) {
            return null;
        }

        $flags = [];
        if (!empty($data->protectiongroup) && is_array($data->protectiongroup)) {
            foreach ($data->protectiongroup as $key => $value) {
                if (!empty($value)) {
                    $flags[] = $key;
                }
            }
        }
        $data->protection = implode(',', $flags);
        unset($data->protectiongroup);

        $requiregrade = trim((string)($data->requiregrade ?? ''));
        $data->requiregrade = $requiregrade === '' ? null : unformat_float($requiregrade);

        return $data;
    }

    /**
     * Unfold the stored protection value back into checkboxes.
     *
     * @param array $defaultvalues the values to preset
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        $flags = !empty($defaultvalues['protection'])
            ? explode(',', $defaultvalues['protection'])
            : [];

        foreach (['print', 'modify', 'copy'] as $key) {
            $defaultvalues['protectiongroup[' . $key . ']'] = in_array($key, $flags, true) ? 1 : 0;
        }
    }

    /**
     * Validate the form.
     *
     * @param array $data the submitted data
     * @param array $files the submitted files
     * @return array errors keyed by form element name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $requiregrade = trim((string)($data['requiregrade'] ?? ''));
        if ($requiregrade !== '') {
            $grade = unformat_float($requiregrade, true);
            if ($grade === false || $grade === null || $grade < 0 || $grade > 100) {
                $errors['requiregrade'] = get_string('errorgraderange', 'mod_certificatepro');
            }
        }

        if (!empty($data['emailothers'])) {
            foreach (explode(',', $data['emailothers']) as $address) {
                $address = trim($address);
                if ($address !== '' && !validate_email($address)) {
                    $errors['emailothers'] = get_string(
                        'erroremailaddress',
                        'mod_certificatepro',
                        s($address)
                    );
                    break;
                }
            }
        }

        return $errors;
    }
}
