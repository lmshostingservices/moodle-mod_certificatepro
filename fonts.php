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
 * The font manager.
 *
 * Lets an administrator add fonts through the browser, so certificates in
 * scripts a stock Moodle cannot draw stop coming out as question marks.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_certificatepro\local\font_manager;

require_login();
admin_externalpage_setup('modsettingcertificatepro');

$context = context_system::instance();
require_capability('mod/certificatepro:managefonts', $context);

$delete = optional_param('delete', 0, PARAM_INT);

$url = new moodle_url('/mod/certificatepro/fonts.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('fontstitle', 'mod_certificatepro'));
$PAGE->set_heading(get_string('fontstitle', 'mod_certificatepro'));

if ($delete > 0) {
    require_sesskey();
    font_manager::uninstall($delete);
    redirect($url, get_string('fontremoved', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$form = new \mod_certificatepro\form\font_form($url);

if ($data = $form->get_data()) {
    $draftid = $data->fontfile;
    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false);

    if (!empty($files)) {
        $file = reset($files);
        try {
            font_manager::install($file, $data->displayname, $data->fontstyle);
            redirect($url, get_string('fontinstalled', 'mod_certificatepro'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('fontstitle', 'mod_certificatepro'));
echo $OUTPUT->box(get_string('settings_fontsintro', 'mod_certificatepro'));

if (!font_manager::can_install_fonts()) {
    echo $OUTPUT->notification(
        get_string('settings_fontsnotwritable', 'mod_certificatepro',
            font_manager::custom_font_directory()),
        'warning'
    );
} else {
    $form->display();
}

$records = $DB->get_records('certificatepro_fonts', null, 'displayname ASC');

if (empty($records)) {
    echo $OUTPUT->notification(get_string('fontnone', 'mod_certificatepro'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('fontdisplayname', 'mod_certificatepro'),
        get_string('fontstyle', 'mod_certificatepro'),
        get_string('fontscripts', 'mod_certificatepro'),
        '',
    ];

    foreach ($records as $record) {
        $styles = [
            '' => get_string('fontstyle_regular', 'mod_certificatepro'),
            'B' => get_string('fontstyle_bold', 'mod_certificatepro'),
            'I' => get_string('fontstyle_italic', 'mod_certificatepro'),
            'BI' => get_string('fontstyle_bolditalic', 'mod_certificatepro'),
        ];

        $table->data[] = [
            format_string($record->displayname),
            $styles[$record->style] ?? $record->style,
            s($record->scripts),
            $OUTPUT->single_button(
                new moodle_url($url, ['delete' => $record->id, 'sesskey' => sesskey()]),
                get_string('delete'),
                'post'
            ),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
