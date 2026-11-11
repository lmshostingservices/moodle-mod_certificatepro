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
 * scripts a stock Moodle cannot draw stop coming out as question marks, and
 * install the Google Fonts families the bundled designs are drawn with.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\font\google_fonts;
use mod_certificatepro\local\font\install_report;

require_login();
admin_externalpage_setup('certificateprofonts');

$context = context_system::instance();
require_capability('mod/certificatepro:managefonts', $context);

$delete = optional_param('delete', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$installfamily = optional_param('family', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/mod/certificatepro/fonts.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('fontstitle', 'mod_certificatepro'));
$PAGE->set_heading(get_string('fontstitle', 'mod_certificatepro'));

if ($delete > 0) {
    require_sesskey();
    font_manager::uninstall($delete);
    google_fonts::purge_face_cache();
    redirect($url, get_string('fontremoved', 'mod_certificatepro'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// A search changes nothing, so it runs on GET and is bookmarkable.
$searchform = new \mod_certificatepro\form\google_font_search_form($url, null, 'get');
$search = $searchform->get_data();

// ---------------------------------------------------------------------------
// Installing from Google Fonts. Both routes confirm first, because both spend
// minutes on the network and megabytes of disk.
// ---------------------------------------------------------------------------

if ($action === 'installall' || $action === 'installone') {
    require_sesskey();

    if (!font_manager::can_install_fonts()) {
        redirect($url, get_string('settings_fontsnotwritable', 'mod_certificatepro',
            font_manager::custom_font_directory()), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $todo = google_fonts::incomplete_families();

    if ($action === 'installone' && trim($installfamily) === '') {
        redirect($url, get_string('gfontserrorbadfamily', 'mod_certificatepro'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    if (!$confirm) {
        $continue = new moodle_url($url, [
            'action' => $action,
            'family' => $installfamily,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);
        $message = $action === 'installall'
            ? get_string('gfontsinstallallconfirm', 'mod_certificatepro', count($todo))
            : get_string('gfontsinstallfamilyconfirm', 'mod_certificatepro',
                s(trim($installfamily)));

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('gfontsinstalling', 'mod_certificatepro'));
        echo $OUTPUT->confirm($message, $continue, $url);
        echo $OUTPUT->footer();
        exit;
    }

    // Downloading three dozen families is minutes of work, not seconds.
    \core_php_time_limit::raise(30 * MINSECS);
    raise_memory_limit(MEMORY_EXTRA);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('gfontsinstalling', 'mod_certificatepro'));
    echo $OUTPUT->notification(get_string('gfontsinstallingwait', 'mod_certificatepro'), 'info');

    // Show each family's outcome as it lands rather than a blank page followed
    // by a wall of text.
    $emit = function (array $report) use ($OUTPUT) {
        foreach (install_report::messages($report) as $line) {
            echo $OUTPUT->notification($line['text'], $line['type']);
        }
        flush();
    };

    flush();

    if ($action === 'installall') {
        $outcome = google_fonts::install_preset_families($emit);
        $reports = $outcome['reports'];
        $notattempted = $outcome['notattempted'];
    } else {
        $report = google_fonts::install(trim($installfamily));
        $emit($report);
        $reports = [$report];
        $notattempted = [];
    }

    $totals = install_report::totals($reports);
    if ($totals['faces'] > 0) {
        echo $OUTPUT->notification(
            get_string('gfontsdone', 'mod_certificatepro', (object) $totals), 'success');
    } else {
        echo $OUTPUT->notification(
            get_string('gfontsdonenothing', 'mod_certificatepro'), 'info');
    }

    if (!empty($notattempted)) {
        echo $OUTPUT->notification(
            get_string('gfontsnotattempted', 'mod_certificatepro', count($notattempted)),
            'warning');
    }

    // Not a continue_button: nothing continues from here. The install is over
    // and the only thing this button does is go back, so it says so.
    echo $OUTPUT->single_button($url, get_string('gfontsback', 'mod_certificatepro'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
// Uploading a font by hand.
// ---------------------------------------------------------------------------

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
            google_fonts::purge_face_cache();
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

$writable = font_manager::can_install_fonts();

if (!$writable) {
    echo $OUTPUT->notification(
        get_string('settings_fontsnotwritable', 'mod_certificatepro',
            font_manager::custom_font_directory()),
        'warning'
    );
}

// ---------------------------------------------------------------------------
// What the bundled designs ask for, and what this site can actually draw.
// ---------------------------------------------------------------------------

// Everything under this heading comes from one external service. The upload
// form further down does not, and without the section heading the two look like
// one undifferentiated list of font controls.
echo $OUTPUT->heading(get_string('gfontssubheading', 'mod_certificatepro'), 3);

echo $OUTPUT->heading(get_string('gfontsheading', 'mod_certificatepro'), 4);
echo $OUTPUT->box(get_string('gfontsintro', 'mod_certificatepro'));

$status = google_fonts::preset_status();
$missingcount = 0;
$partialcount = 0;
foreach ($status as $row) {
    if (!$row['installed']) {
        $missingcount++;
    } else if (!$row['complete']) {
        $partialcount++;
    }
}

if ($missingcount > 0) {
    echo $OUTPUT->notification(get_string('gfontsmissing', 'mod_certificatepro', (object) [
        'missing' => $missingcount,
        'total' => count($status),
    ]), 'warning');
} else if ($partialcount === 0) {
    echo $OUTPUT->notification(get_string('gfontsnonemissing', 'mod_certificatepro'), 'success');
}

if ($partialcount > 0) {
    echo $OUTPUT->notification(
        get_string('gfontspartial', 'mod_certificatepro', $partialcount), 'info');
}

/**
 * A cell describing which faces of a family are installed.
 *
 * @param array $faces the map from {@see google_fonts::installed_faces()}
 * @param array $unavailable style flags the publisher does not offer
 * @return string HTML
 */
$facecell = function (array $faces, array $unavailable = []): string {
    $present = [];
    foreach ($faces as $style => $installed) {
        if ($installed) {
            $present[] = install_report::face_name($style);
        }
    }
    if (empty($present)) {
        return html_writer::span(get_string('gfontsfacesnone', 'mod_certificatepro'),
            'badge bg-warning text-dark');
    }
    $list = s(implode(', ', $present));

    // Two faces beside a neighbour's four reads as a half-finished install
    // unless the row says the other two were never published.
    if ($unavailable && (count($present) + count($unavailable)) >= count($faces)) {
        $list .= ' ' . html_writer::span(
            get_string('gfontsfacesallpublished', 'mod_certificatepro'), 'text-muted');
    }

    return $list;
};

/**
 * Where one family stands: everything Google publishes, the regular face only,
 * or nothing at all.
 *
 * The faces cell lists what is present, which answers "what have I got" but not
 * "is this family finished" — a family showing "Regular, Bold" is complete for
 * one family and short of two faces for the next. This says which.
 *
 * @param bool $installed whether anything of the family is installed
 * @param bool $complete whether every face Google publishes is installed
 * @return string HTML
 */
$statuscell = function (bool $installed, bool $complete): string {
    if (!$installed) {
        return html_writer::span(get_string('gfontsstatusmissing', 'mod_certificatepro'),
            'badge bg-warning text-dark');
    }
    if (!$complete) {
        return html_writer::span(get_string('gfontsstatuspartial', 'mod_certificatepro'),
            'badge bg-info text-dark');
    }
    return html_writer::span(get_string('gfontsstatuscomplete', 'mod_certificatepro'),
        'badge bg-success');
};

/**
 * The install button for one family.
 *
 * @param string $family the family name
 * @param bool $installed whether anything of it is already installed
 * @return string HTML
 */
$installbutton = function (string $family, bool $installed) use ($OUTPUT, $url, $writable): string {
    if (!$writable) {
        return '';
    }
    return $OUTPUT->single_button(
        new moodle_url($url, [
            'action' => 'installone',
            'family' => $family,
            'sesskey' => sesskey(),
        ]),
        $installed
            ? get_string('gfontsreinstall', 'mod_certificatepro')
            : get_string('gfontsinstall', 'mod_certificatepro'),
        'post'
    );
};

if ($writable && ($missingcount + $partialcount) > 0) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'installall', 'sesskey' => sesskey()]),
        get_string('gfontsinstallall', 'mod_certificatepro', $missingcount + $partialcount),
        'post'
    );
}

$required = new html_table();
$required->id = 'certificatepro-required-fonts';
$required->attributes['class'] = 'generaltable';
$required->head = [
    get_string('gfontsfamily', 'mod_certificatepro'),
    get_string('status'),
    get_string('gfontsfaces', 'mod_certificatepro'),
    '',
];

foreach ($status as $row) {
    $required->data[] = [
        s($row['family']),
        $statuscell((bool) $row['installed'], (bool) $row['complete']),
        $facecell($row['faces'], $row['unavailable'] ?? []),
        $installbutton($row['family'], $row['installed']),
    ];
}

echo html_writer::table($required);

// ---------------------------------------------------------------------------
// Searching the catalogue for anything else.
// ---------------------------------------------------------------------------

echo $OUTPUT->heading(get_string('gfontssearchheading', 'mod_certificatepro'), 4);
echo $OUTPUT->box(get_string('gfontssearchintro', 'mod_certificatepro'));

if (!google_fonts::is_full_catalogue()) {
    echo $OUTPUT->notification(get_string('gfontsseedcatalogue', 'mod_certificatepro',
        count(google_fonts::seed())), 'info');
}

$searchform->display();

if ($search && (trim((string) $search->q) !== '' || (string) $search->category !== '')) {
    $results = google_fonts::search(trim((string) $search->q), (string) $search->category, '', 60);

    if (empty($results)) {
        echo $OUTPUT->notification(get_string('gfontssearchnone', 'mod_certificatepro'), 'info');
    } else {
        $found = new html_table();
        $found->id = 'certificatepro-font-search-results';
        $found->attributes['class'] = 'generaltable';
        $found->head = [
            get_string('gfontsfamily', 'mod_certificatepro'),
            get_string('gfontscategory', 'mod_certificatepro'),
            get_string('fontscripts', 'mod_certificatepro'),
            get_string('status'),
            get_string('gfontsfaces', 'mod_certificatepro'),
            '',
        ];

        foreach ($results as $entry) {
            $faces = google_fonts::installed_faces($entry['family']);
            $found->data[] = [
                s($entry['family']),
                s($entry['category']),
                s(implode(', ', $entry['subsets'])),
                $statuscell((bool) $faces[''], !in_array(false, $faces, true)),
                $facecell($faces),
                $installbutton($entry['family'], (bool) $faces['']),
            ];
        }

        echo html_writer::table($found);
    }
}

// ---------------------------------------------------------------------------
// Uploading a font of your own, and what is installed already.
// ---------------------------------------------------------------------------

if ($writable) {
    $form->display();
}

$records = $DB->get_records('certificatepro_fonts', null, 'displayname ASC, style ASC');

if (empty($records)) {
    echo $OUTPUT->notification(get_string('fontnone', 'mod_certificatepro'), 'info');
} else {
    $table = new html_table();
    $table->id = 'certificatepro-installed-fonts';
    $table->head = [
        get_string('fontdisplayname', 'mod_certificatepro'),
        get_string('fontstyle', 'mod_certificatepro'),
        get_string('fontscripts', 'mod_certificatepro'),
        '',
    ];

    foreach ($records as $record) {
        $table->data[] = [
            format_string($record->displayname),
            install_report::face_name($record->style),
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
