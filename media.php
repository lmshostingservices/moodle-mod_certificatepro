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
 * The shared media library.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_certificatepro\local\media_library;

require_login();
admin_externalpage_setup('modsettingcertificatepro');

$context = context_system::instance();
require_capability('mod/certificatepro:managemedia', $context);

$delete = optional_param('delete', 0, PARAM_INT);

$url = new moodle_url('/mod/certificatepro/media.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('mediatitle', 'mod_certificatepro'));
$PAGE->set_heading(get_string('mediatitle', 'mod_certificatepro'));

if ($delete > 0) {
    require_sesskey();
    media_library::delete($delete);
    redirect($url, get_string('mediaremoved', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$form = new \mod_certificatepro\form\media_form($url);

if ($data = $form->get_data()) {
    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data->mediafile, 'id', false);

    if (!empty($files)) {
        media_library::save(reset($files), $data->medianame, $data->mediakind);
        redirect($url, get_string('mediaadded', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mediatitle', 'mod_certificatepro'));
echo $OUTPUT->box(get_string('settings_mediaintro', 'mod_certificatepro'));

$form->display();

$records = media_library::all();

if (empty($records)) {
    echo $OUTPUT->notification(get_string('mediaempty', 'mod_certificatepro'), 'info');
} else {
    $kinds = media_library::kinds();

    $table = new html_table();
    $table->head = [
        '',
        get_string('medianame', 'mod_certificatepro'),
        get_string('mediakind', 'mod_certificatepro'),
        get_string('mediadimensions', 'mod_certificatepro'),
        get_string('mediausage', 'mod_certificatepro'),
        '',
    ];
    $table->attributes['class'] = 'generaltable table-sm';

    foreach ($records as $record) {
        $preview = '';
        $mediaurl = media_library::url((int) $record->id);

        if ($mediaurl !== null) {
            $preview = html_writer::empty_tag('img', [
                'src' => $mediaurl,
                'alt' => '',
                'style' => 'max-width:120px;max-height:60px;object-fit:contain;'
                    . 'background:repeating-conic-gradient(#eee 0% 25%,#fff 0% 50%) 50%/10px 10px;',
            ]);
        }

        $table->data[] = [
            $preview,
            format_string($record->name),
            $kinds[$record->kind] ?? $record->kind,
            $record->pixelwidth > 0
                ? $record->pixelwidth . ' x ' . $record->pixelheight
                : '-',
            get_string('mediausagecount', 'mod_certificatepro',
                media_library::usage_count((int) $record->id)),
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
