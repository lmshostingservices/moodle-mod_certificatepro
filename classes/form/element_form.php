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

use mod_certificatepro\element\base as element_base;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\template;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * The settings form for one element.
 *
 * The shared settings, typography and position live here; each element type
 * adds only what is unique to it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        /** @var element_base $element */
        $element = $this->_customdata['element'];
        $context = $this->_customdata['context'];

        $mform->addElement('hidden', 'id', $element->get_id());
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'editelement');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'elementid', $element->get_id());
        $mform->setType('elementid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('name'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Whatever this element type needs.
        $element->definition_form($mform, $context);

        // Typography, but only for elements that draw words.
        if ($element instanceof \mod_certificatepro\element\text_base) {
            $mform->addElement('header', 'typographyheader',
                get_string('designer_inspector', 'mod_certificatepro'));

            $fonts = [
                'heading' => get_string('elementfont_heading', 'mod_certificatepro'),
                'body' => get_string('elementfont_body', 'mod_certificatepro'),
                'accent' => get_string('elementfont_accent', 'mod_certificatepro'),
            ] + font_manager::catalogue();

            $mform->addElement('select', 'stylefont',
                get_string('elementfont', 'mod_certificatepro'), $fonts);
            $mform->addHelpButton('stylefont', 'elementfont', 'mod_certificatepro');

            $mform->addElement('text', 'stylesize',
                get_string('elementsize', 'mod_certificatepro'), ['size' => 6]);
            $mform->setType('stylesize', PARAM_FLOAT);

            $mform->addElement('text', 'stylecolour',
                get_string('elementcolour', 'mod_certificatepro'), ['size' => 14]);
            $mform->setType('stylecolour', PARAM_TEXT);
            $mform->addHelpButton('stylecolour', 'elementcolour', 'mod_certificatepro');

            $emphasis = [
                $mform->createElement('advcheckbox', 'stylebold', '', get_string('bold', 'editor')),
                $mform->createElement('advcheckbox', 'styleitalic', '', get_string('italic', 'editor')),
                $mform->createElement('advcheckbox', 'styleunderline', '',
                    get_string('underline', 'editor')),
            ];
            $mform->addGroup($emphasis, 'emphasisgroup',
                get_string('elementemphasis', 'mod_certificatepro'), ' ', false);

            $mform->addElement('select', 'stylecase',
                get_string('elementcase', 'mod_certificatepro'), [
                    'none' => get_string('elementcase_none', 'mod_certificatepro'),
                    'upper' => get_string('elementcase_upper', 'mod_certificatepro'),
                    'lower' => get_string('elementcase_lower', 'mod_certificatepro'),
                    'title' => get_string('elementcase_title', 'mod_certificatepro'),
                ]);

            $mform->addElement('text', 'styletracking',
                get_string('elementtracking', 'mod_certificatepro'), ['size' => 6]);
            $mform->setType('styletracking', PARAM_FLOAT);

            $mform->addElement('text', 'stylelineheight',
                get_string('elementlineheight', 'mod_certificatepro'), ['size' => 6]);
            $mform->setType('stylelineheight', PARAM_FLOAT);

            $mform->addElement('select', 'alignment',
                get_string('elementalignment', 'mod_certificatepro'), [
                    'L' => get_string('elementalignment_left', 'mod_certificatepro'),
                    'C' => get_string('elementalignment_centre', 'mod_certificatepro'),
                    'R' => get_string('elementalignment_right', 'mod_certificatepro'),
                    'J' => get_string('elementalignment_justify', 'mod_certificatepro'),
                ]);
            $mform->addHelpButton('alignment', 'elementalignment', 'mod_certificatepro');

            $mform->addElement('advcheckbox', 'stylefit',
                get_string('elementfit', 'mod_certificatepro'));
            $mform->addHelpButton('stylefit', 'elementfit', 'mod_certificatepro');

            $mform->addElement('text', 'stylemaxlines',
                get_string('elementmaxlines', 'mod_certificatepro'), ['size' => 6]);
            $mform->setType('stylemaxlines', PARAM_INT);
        }

        // Position, for anyone who prefers numbers to dragging.
        $mform->addElement('header', 'positionheader',
            get_string('elementposition', 'mod_certificatepro'));

        foreach (['posx', 'posy', 'width', 'height', 'rotation'] as $field) {
            $mform->addElement('text', $field,
                get_string('designer_' . $field, 'mod_certificatepro'), ['size' => 8]);
            $mform->setType($field, PARAM_FLOAT);
        }

        $mform->addElement('select', 'refpoint',
            get_string('elementrefpoint', 'mod_certificatepro'), [
                'tl' => get_string('refpoint_tl', 'mod_certificatepro'),
                'tc' => get_string('refpoint_tc', 'mod_certificatepro'),
                'tr' => get_string('refpoint_tr', 'mod_certificatepro'),
                'ml' => get_string('refpoint_ml', 'mod_certificatepro'),
                'mc' => get_string('refpoint_mc', 'mod_certificatepro'),
                'mr' => get_string('refpoint_mr', 'mod_certificatepro'),
                'bl' => get_string('refpoint_bl', 'mod_certificatepro'),
                'bc' => get_string('refpoint_bc', 'mod_certificatepro'),
                'br' => get_string('refpoint_br', 'mod_certificatepro'),
            ]);
        $mform->addHelpButton('refpoint', 'elementrefpoint', 'mod_certificatepro');

        $mform->addElement('select', 'valignment',
            get_string('elementvalignment', 'mod_certificatepro'), [
                'T' => get_string('elementvalignment_top', 'mod_certificatepro'),
                'M' => get_string('elementvalignment_middle', 'mod_certificatepro'),
                'B' => get_string('elementvalignment_bottom', 'mod_certificatepro'),
            ]);

        $this->add_action_buttons();

        $this->preset($element, $context);
    }

    /**
     * Fill the form with the element's stored values.
     *
     * @param element_base $element the element being edited
     * @param \context $context the context the element lives in
     * @return void
     */
    protected function preset(element_base $element, \context $context): void {
        $mform = $this->_form;
        $record = $element->get_record();
        $style = $element->get_style();

        $defaults = [
            'name' => $element->get_name(),
            'posx' => $element->get_posx(),
            'posy' => $element->get_posy(),
            'width' => $element->get_width(),
            'height' => $element->get_height(),
            'rotation' => $element->get_rotation(),
            'refpoint' => $element->get_refpoint(),
            'alignment' => $element->get_alignment(),
            'valignment' => $element->get_valignment(),
            'stylefont' => $style['font'] ?? 'body',
            'stylesize' => $style['size'],
            'stylecolour' => $style['colour'] ?? 'ink',
            'stylebold' => !empty($style['bold']) ? 1 : 0,
            'styleitalic' => !empty($style['italic']) ? 1 : 0,
            'styleunderline' => !empty($style['underline']) ? 1 : 0,
            'stylecase' => $style['case'] ?? 'none',
            'styletracking' => $style['tracking'] ?? 0,
            'stylelineheight' => $style['lineheight'] ?? 1.25,
            'stylefit' => !empty($style['fit']) ? 1 : 0,
            'stylemaxlines' => $style['maxlines'] ?? 0,
        ];

        foreach ($defaults as $field => $value) {
            if ($mform->elementExists($field)) {
                $mform->setDefault($field, $value);
            }
        }

        $element->prepare_form($mform);

        // Files live in a draft area until the form is submitted.
        foreach ($element::file_areas() as $area) {
            if (!$mform->elementExists('imagefile')) {
                continue;
            }
            $draftitemid = file_get_submitted_draft_itemid('imagefile');
            file_prepare_draft_area($draftitemid, $context->id, 'mod_certificatepro',
                $area, $element->get_id(), ['subdirs' => 0, 'maxfiles' => 1]);
            $mform->getElement('imagefile')->setValue($draftitemid);
            break;
        }

        unset($record);
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
        /** @var element_base $element */
        $element = $this->_customdata['element'];

        return array_merge($errors, $element->validate_form($data, $files));
    }

    /**
     * Save the submitted settings onto the element.
     *
     * @param element_base $element the element being edited
     * @param \stdClass $data the submitted data
     * @param template $template the design the element belongs to
     * @return void
     */
    public function save(element_base $element, \stdClass $data, template $template): void {
        global $DB;

        $record = $element->get_record();
        $record->name = $data->name;

        $record->data = json_encode($element->save_form_data($data), JSON_PRESERVE_ZERO_FRACTION);

        $style = $element->get_style();
        $map = [
            'font' => 'stylefont',
            'size' => 'stylesize',
            'colour' => 'stylecolour',
            'bold' => 'stylebold',
            'italic' => 'styleitalic',
            'underline' => 'styleunderline',
            'case' => 'stylecase',
            'tracking' => 'styletracking',
            'lineheight' => 'stylelineheight',
            'fit' => 'stylefit',
            'maxlines' => 'stylemaxlines',
        ];

        foreach ($map as $key => $field) {
            if (property_exists($data, $field)) {
                $value = $data->{$field};
                if (in_array($key, ['bold', 'italic', 'underline', 'fit'], true)) {
                    $value = !empty($value);
                } else if ($key === 'size') {
                    $value = ($value === '' || $value === null) ? null : (float) $value;
                } else if (in_array($key, ['tracking', 'lineheight'], true)) {
                    $value = (float) $value;
                } else if ($key === 'maxlines') {
                    $value = (int) $value;
                }
                $style[$key] = $value;
            }
        }
        $record->style = json_encode($style, JSON_PRESERVE_ZERO_FRACTION);

        foreach (['posx', 'posy', 'width', 'height', 'rotation'] as $field) {
            if (property_exists($data, $field)) {
                $record->{$field} = round((float) $data->{$field}, 3);
            }
        }
        foreach (['refpoint', 'alignment', 'valignment'] as $field) {
            if (property_exists($data, $field)) {
                $record->{$field} = clean_param($data->{$field}, PARAM_ALPHA);
            }
        }

        $record->timemodified = time();
        $DB->update_record('certificatepro_elements', $record);

        // Move any uploaded file out of its draft area.
        $areas = $element::file_areas();
        if (!empty($areas) && !empty($data->imagefile)) {
            file_save_draft_area_files(
                $data->imagefile,
                $template->get_context()->id,
                'mod_certificatepro',
                reset($areas),
                $element->get_id(),
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }

        $template->touch();
    }
}
