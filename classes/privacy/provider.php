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

namespace mod_certificatepro\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy implementation for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe everything this plugin stores about a person.
     *
     * @param collection $collection the collection to add to
     * @return collection the same collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'certificatepro_issues',
            [
                'cycleid' => 'privacy:metadata:issues:cycleid',
                'userid' => 'privacy:metadata:issues:userid',
                'certificateproid' => 'privacy:metadata:issues:certificateproid',
                'code' => 'privacy:metadata:issues:code',
                'serial' => 'privacy:metadata:issues:serial',
                'snapshot' => 'privacy:metadata:issues:snapshot',
                'timecreated' => 'privacy:metadata:issues:timecreated',
                'expires' => 'privacy:metadata:issues:expires',
                'issuedby' => 'privacy:metadata:issues:issuedby',
                'revokedby' => 'privacy:metadata:issues:revokedby',
                'revokedreason' => 'privacy:metadata:issues:revokedreason',
                'pdfhash' => 'privacy:metadata:issues:pdfhash',
                'emailed' => 'privacy:metadata:issues:emailed',
            ],
            'privacy:metadata:issues'
        );

        // Site-wide authoring records only keep a reference to the uploader/creator.
        $collection->add_database_table(
            'certificatepro_backgrounds',
            [
                'createdby' => 'privacy:metadata:authoring:createdby',
            ],
            'privacy:metadata:backgrounds'
        );
        $collection->add_database_table(
            'certificatepro_media',
            [
                'createdby' => 'privacy:metadata:authoring:createdby',
            ],
            'privacy:metadata:media'
        );
        $collection->add_database_table(
            'certificatepro_fonts',
            [
                'uploadedby' => 'privacy:metadata:authoring:createdby',
            ],
            'privacy:metadata:fonts'
        );

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');

        // Artwork generation is optional, is configured by an administrator, and
        // sends only the author's written description of the wanted background.
        $collection->add_external_location_link(
            'artworkservice',
            [
                'prompt' => 'privacy:metadata:artworkservice:prompt',
            ],
            'privacy:metadata:artworkservice'
        );

        return $collection;
    }

    /**
     * The contexts in which a person holds a certificate.
     *
     * @param int $userid the user to look for
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {certificatepro} c ON c.id = cm.instance
                  JOIN {certificatepro_issues} i ON i.certificateproid = c.id
                 WHERE i.userid = :userid";

        $contextlist->add_from_sql(
            $sql,
            [
                'contextlevel' => CONTEXT_MODULE,
                'modname' => 'certificatepro',
                'userid' => $userid,
            ]
        );

        return $contextlist;
    }

    /**
     * The people who hold a certificate in a context.
     *
     * @param userlist $userlist the list to add to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT i.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {certificatepro} c ON c.id = cm.instance
                  JOIN {certificatepro_issues} i ON i.certificateproid = c.id
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql(
            'userid',
            $sql,
            [
                'modname' => 'certificatepro',
                'cmid' => $context->instanceid,
            ]
        );
    }

    /**
     * Export everything held about a person.
     *
     * @param approved_contextlist $contextlist the contexts approved for export
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id(
                'certificatepro',
                $context->instanceid,
                0,
                false,
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }

            $issues = $DB->get_records(
                'certificatepro_issues',
                [
                    'certificateproid' => $cm->instance,
                    'userid' => $user->id,
                ]
            );

            if (empty($issues)) {
                continue;
            }

            $exported = [];
            foreach ($issues as $issue) {
                $exported[] = (object) [
                    'cycleid' => $issue->cycleid,
                    'code' => $issue->code,
                    'serial' => $issue->serial,
                    'issued' => transform::datetime($issue->timecreated),
                    'expires' => empty($issue->expires)
                        ? get_string('neverexpires', 'mod_certificatepro')
                        : transform::datetime($issue->expires),
                    'revoked' => transform::yesno($issue->revoked),
                    'revokedreason' => $issue->revokedreason,
                    'printedvalues' => json_decode($issue->snapshot ?? '', true),
                ];

                writer::with_context($context)->export_area_files(
                    [get_string('privacy:path:issues', 'mod_certificatepro')],
                    'mod_certificatepro',
                    \mod_certificatepro\local\issue_service::FILEAREA,
                    $issue->id
                );
            }

            $data = helper::get_context_data($context, $user);
            $data->certificates = $exported;

            writer::with_context($context)->export_data([], $data);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Delete everything held in one context.
     *
     * @param \context $context the context to clear
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id(
            'certificatepro',
            $context->instanceid,
            0,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }

        get_file_storage()->delete_area_files(
            $context->id,
            'mod_certificatepro',
            \mod_certificatepro\local\issue_service::FILEAREA
        );

        $DB->delete_records('certificatepro_issues', ['certificateproid' => $cm->instance]);
    }

    /**
     * Delete everything held about one person.
     *
     * @param approved_contextlist $contextlist the contexts approved for deletion
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        $fs = get_file_storage();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id(
                'certificatepro',
                $context->instanceid,
                0,
                false,
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }

            $issues = $DB->get_records(
                'certificatepro_issues',
                [
                    'certificateproid' => $cm->instance,
                    'userid' => $user->id,
                ],
                '',
                'id'
            );

            foreach (array_keys($issues) as $issueid) {
                $fs->delete_area_files(
                    $context->id,
                    'mod_certificatepro',
                    \mod_certificatepro\local\issue_service::FILEAREA,
                    $issueid
                );
            }

            $DB->delete_records(
                'certificatepro_issues',
                [
                    'certificateproid' => $cm->instance,
                    'userid' => $user->id,
                ]
            );
            // Keep other learners' certificates but drop the reference to this person as issuer/revoker.
            $DB->set_field(
                'certificatepro_issues',
                'issuedby',
                0,
                ['certificateproid' => $cm->instance, 'issuedby' => $user->id]
            );
            $DB->set_field(
                'certificatepro_issues',
                'revokedby',
                0,
                ['certificateproid' => $cm->instance, 'revokedby' => $user->id]
            );
        }
    }

    /**
     * Delete everything held about a set of people in one context.
     *
     * @param approved_userlist $userlist the users approved for deletion
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id(
            'certificatepro',
            $context->instanceid,
            0,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['certificateproid'] = $cm->instance;

        $issues = $DB->get_records_select(
            'certificatepro_issues',
            "certificateproid = :certificateproid AND userid {$insql}",
            $params,
            '',
            'id'
        );

        $fs = get_file_storage();
        foreach (array_keys($issues) as $issueid) {
            $fs->delete_area_files(
                $context->id,
                'mod_certificatepro',
                \mod_certificatepro\local\issue_service::FILEAREA,
                $issueid
            );
        }

        $DB->delete_records_select(
            'certificatepro_issues',
            "certificateproid = :certificateproid AND userid {$insql}",
            $params
        );
    }
}
