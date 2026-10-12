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
 * Library of functions for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declare which Moodle features this activity supports.
 *
 * @param string $feature a FEATURE_ constant
 * @return mixed true, false, a string, or null when the feature is unknown
 */
function certificatepro_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_MOD_INTRO:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_SHOW_DESCRIPTION:
            return true;

        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_RATE:
            return false;

        case FEATURE_MOD_PURPOSE:
            return defined('MOD_PURPOSE_ADMINISTRATION') ? MOD_PURPOSE_ADMINISTRATION : 'administration';

        default:
            return null;
    }
}

/**
 * Create a new Certificate Pro instance.
 *
 * @param stdClass $data the form data
 * @param mod_certificatepro_mod_form|null $mform the form, when one was used
 * @return int the new instance id
 */
function certificatepro_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = time();
    $data->templateid = 0;

    $data->id = $DB->insert_record('certificatepro', $data);

    $context = context_module::instance($data->coursemodule);
    $starter = $data->starter ?? 'classic';

    $template = \mod_certificatepro\local\starter_templates::build(
        $starter,
        $data->name,
        $context
    );

    $DB->set_field('certificatepro', 'templateid', $template->get_id(), ['id' => $data->id]);

    return $data->id;
}

/**
 * Update an existing Certificate Pro instance.
 *
 * @param stdClass $data the form data
 * @param mod_certificatepro_mod_form|null $mform the form, when one was used
 * @return bool always true
 */
function certificatepro_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    unset($data->starter);
    unset($data->templateid);

    $DB->update_record('certificatepro', $data);

    return true;
}

/**
 * Delete a Certificate Pro instance and everything belonging to it.
 *
 * @param int $id the instance id
 * @return bool true when the instance existed
 */
function certificatepro_delete_instance($id) {
    global $DB;

    // Serialize with issuer binding and ordinary issuance. Checking only issued
    // documents strands an allocated learner before their first award exists.
    $lock = \core\lock\lock_config::get_lock_factory('mod_certificatepro')
        ->get_lock('issuer_' . $id, 10);
    if (!$lock) {
        throw new moodle_exception('locktimeout');
    }
    try {
        $instance = $DB->get_record('certificatepro', ['id' => $id]);
        if (!$instance) {
            return false;
        }
        $hascycles = $DB->get_manager()->table_exists(new xmldb_table('local_recertify_cycles'))
            && $DB->record_exists('local_recertify_cycles', ['certificateid' => $id]);
        if ($hascycles || $DB->record_exists_select('certificatepro_issues',
                'certificateproid = :id AND cycleid IS NOT NULL', ['id' => $id])) {
            throw new moodle_exception('cyclehistoryprotected', 'mod_certificatepro');
        }
        $cm = get_coursemodule_from_instance('certificatepro', $id, $instance->course, false, IGNORE_MISSING);
        if ($cm) {
            $context = context_module::instance($cm->id);
            try {
                $template = \mod_certificatepro\template::load((int) $instance->templateid);
                if ($template->get_context()->id === $context->id) {
                    $template->delete();
                }
            } catch (Throwable $e) {
                debugging('Certificate Pro could not delete a design: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            get_file_storage()->delete_area_files($context->id, 'mod_certificatepro');
        }
        $DB->delete_records('certificatepro_issues', ['certificateproid' => $id]);
        $DB->delete_records('certificatepro', ['id' => $id]);
        return true;
    } finally {
        $lock->release();
    }
}

/**
 * Serve a file belonging to this plugin.
 *
 * @param stdClass $course the course
 * @param stdClass $cm the course module
 * @param context $context the context the file lives in
 * @param string $filearea the file area
 * @param array $args the remaining path components
 * @param bool $forcedownload whether to force a download
 * @param array $options extra options for send_stored_file
 * @return bool false when the file could not be served
 */
function certificatepro_pluginfile($course, $cm, $context, string $filearea, array $args,
        bool $forcedownload, array $options = []): bool {
    global $CFG, $DB, $USER;

    require_once($CFG->libdir . '/filelib.php');

    $known = array_merge(
        \mod_certificatepro\element\registry::all_file_areas(),
        [\mod_certificatepro\local\issue_service::FILEAREA,
         \mod_certificatepro\local\font_manager::FILEAREA,
         \mod_certificatepro\local\media_library::FILEAREA,
         \mod_certificatepro\local\brand::LOGO_FILEAREA,
         'intro']
    );

    if (!in_array($filearea, $known, true)) {
        return false;
    }

    // Fonts, shared pictures and the logo slots are all site level, and every
    // one of them has to be readable by anyone who can see a certificate: a
    // learner looking at their own certificate needs the signature image on it.
    $sitewide = [
        \mod_certificatepro\local\font_manager::FILEAREA,
        \mod_certificatepro\local\media_library::FILEAREA,
        \mod_certificatepro\local\brand::LOGO_FILEAREA,
    ];

    if (in_array($filearea, $sitewide, true)) {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return false;
        }
        require_login();
    } else if ($context->contextlevel === CONTEXT_MODULE) {
        require_login($course, false, $cm);
        if (!has_capability('mod/certificatepro:view', $context)) {
            return false;
        }
    } else if ($context->contextlevel === CONTEXT_SYSTEM) {
        require_login();
        if (!has_capability('mod/certificatepro:managetemplates', $context)) {
            return false;
        }
    } else {
        return false;
    }

    // A rendered certificate belongs to one person.
    if ($filearea === \mod_certificatepro\local\issue_service::FILEAREA) {
        $issueid = (int) ($args[0] ?? 0);
        $issue = $DB->get_record('certificatepro_issues', ['id' => $issueid]);
        if (!$issue) {
            return false;
        }
        if ((int)$issue->certificateproid !== (int)$cm->instance
                || (!empty($issue->cycleid)
                    && !in_array(\mod_certificatepro\local\issue_service::status($issue), ['valid', 'expired'], true))) {
            return false;
        }
        if ((int) $issue->userid !== (int) $USER->id
                && !has_capability('mod/certificatepro:viewreport', $context)) {
            return false;
        }
    }

    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/mod_certificatepro/{$filearea}/{$relativepath}";

    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);

    return true;
}

/**
 * Add this activity's own entries to the settings navigation.
 *
 * @param settings_navigation $settings the settings navigation
 * @param navigation_node $node this activity's node
 * @return void
 */
function certificatepro_extend_settings_navigation(settings_navigation $settings,
        navigation_node $node): void {
    $cm = $settings->get_page()->cm;
    if (!$cm) {
        return;
    }

    $context = $cm->context;

    if (has_capability('mod/certificatepro:manage', $context)) {
        $node->add(
            get_string('editdesign', 'mod_certificatepro'),
            new moodle_url('/mod/certificatepro/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'certificateprodesign',
            new pix_icon('t/edit', '')
        );
    }

    if (has_capability('mod/certificatepro:viewreport', $context)) {
        $node->add(
            get_string('issuedcertificates', 'mod_certificatepro'),
            new moodle_url('/mod/certificatepro/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'certificateproreport',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Reset a course's certificate data.
 *
 * @param stdClass $data the reset form data
 * @return array[] the outcome of each reset action
 */
function certificatepro_reset_userdata($data): array {
    global $DB;

    $status = [];

    if (!empty($data->reset_certificatepro_issues)) {
        $instances = $DB->get_records('certificatepro', ['course' => $data->courseid], '', 'id');

        foreach ($instances as $instance) {
            if ($DB->record_exists_select('certificatepro_issues',
                    'certificateproid = :id AND cycleid IS NOT NULL', ['id' => $instance->id])) {
                $status[] = [
                    'component' => get_string('modulenameplural', 'mod_certificatepro'),
                    'item' => get_string('resetissues', 'mod_certificatepro'),
                    'error' => get_string('cyclehistoryprotected', 'mod_certificatepro'),
                ];
                continue;
            }
            $cm = get_coursemodule_from_instance('certificatepro', $instance->id,
                $data->courseid, false, IGNORE_MISSING);
            if ($cm) {
                get_file_storage()->delete_area_files(
                    context_module::instance($cm->id)->id,
                    'mod_certificatepro',
                    \mod_certificatepro\local\issue_service::FILEAREA
                );
            }
            $DB->delete_records('certificatepro_issues', ['certificateproid' => $instance->id]);
        }

        $status[] = [
            'component' => get_string('modulenameplural', 'mod_certificatepro'),
            'item' => get_string('resetissues', 'mod_certificatepro'),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Add this activity's options to the course reset form.
 *
 * @param MoodleQuickForm $mform the reset form
 * @return void
 */
function certificatepro_reset_course_form_definition(&$mform): void {
    $mform->addElement('header', 'certificateproheader',
        get_string('modulenameplural', 'mod_certificatepro'));
    $mform->addElement('advcheckbox', 'reset_certificatepro_issues',
        get_string('resetissues', 'mod_certificatepro'));
}

/**
 * Whether a scale is in use by this activity.
 *
 * @param int $moduleinstance the instance id
 * @param int $scaleid the scale id
 * @return bool always false, because this activity does not grade
 */
function certificatepro_scale_used_anywhere($scaleid): bool {
    return false;
}

/**
 * Describe an instance for the course listing.
 *
 * @param stdClass $coursemodule the course module
 * @return cached_cm_info|null
 */
function certificatepro_get_coursemodule_info($coursemodule) {
    global $DB;

    $instance = $DB->get_record('certificatepro', ['id' => $coursemodule->instance],
        'id, name, intro, introformat');

    if (!$instance) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('certificatepro', $instance, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Mark the activity as viewed for completion and logging.
 *
 * @param stdClass $instance the activity instance
 * @param stdClass $course the course
 * @param stdClass $cm the course module
 * @param context $context the module context
 * @return void
 */
function certificatepro_view($instance, $course, $cm, $context): void {
    \mod_certificatepro\event\course_module_viewed::create([
        'objectid' => $instance->id,
        'context' => $context,
    ])->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}
