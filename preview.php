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
 * Renders the design as a real PDF, using sample data.
 *
 * This is the same code path an issued certificate takes, so what an author
 * sees here is exactly what a learner will get.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_certificatepro\element\render_context;
use mod_certificatepro\local\render\pdf_renderer;
use mod_certificatepro\template;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'certificatepro');
$instance = $DB->get_record('certificatepro', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/certificatepro:manage', $context);

$template = template::load((int) $instance->templateid);

if ($template->get_context()->id !== $context->id) {
    throw new moodle_exception('errortemplatecontext', 'mod_certificatepro');
}

$ctx = render_context::preview($context, $course, $instance);
$renderer = new pdf_renderer($template);

$content = $renderer->render($ctx, [
    'title' => format_string($instance->name, true, ['context' => $context]),
]);

send_file($content, clean_filename(format_string($instance->name) . ' - preview.pdf'), 0,
    0, true, false, 'application/pdf');
