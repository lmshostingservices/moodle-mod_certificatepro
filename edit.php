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
 * The certificate designer.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_certificatepro\element\render_context;
use mod_certificatepro\template;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$elementid = optional_param('elementid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'certificatepro');
$instance = $DB->get_record('certificatepro', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/certificatepro:manage', $context);

$url = new moodle_url('/mod/certificatepro/edit.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $instance);
$PAGE->set_title(get_string('editdesign', 'mod_certificatepro'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$template = template::load((int) $instance->templateid);

if ($template->get_context()->id !== $context->id) {
    throw new moodle_exception('errortemplatecontext', 'mod_certificatepro');
}

// Editing one element's settings happens on its own page, because element
// forms vary widely and a modal cannot host a file picker reliably.
if ($action === 'editelement' && $elementid > 0) {
    require_once($CFG->dirroot . '/mod/certificatepro/classes/form/element_form.php');

    $record = $DB->get_record('certificatepro_elements', ['id' => $elementid], '*', MUST_EXIST);
    $pages = $template->get_pages();

    if (!isset($pages[$record->pageid])) {
        throw new moodle_exception('errorpagenotfound', 'mod_certificatepro');
    }

    $element = \mod_certificatepro\element\registry::instantiate($record);

    $form = new \mod_certificatepro\form\element_form(null, [
        'element' => $element,
        'context' => $context,
        'cmid' => $cm->id,
    ]);

    if ($form->is_cancelled()) {
        redirect($url);
    }

    if ($data = $form->get_data()) {
        $form->save($element, $data, $template);
        redirect($url, get_string('elementsaved', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($element->get_name());
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();

$ctx = render_context::preview($context, $course, $instance);
$renderer = $PAGE->get_renderer('mod_certificatepro');

echo $renderer->render_designer($template, $ctx, $cm);

echo $OUTPUT->footer();
