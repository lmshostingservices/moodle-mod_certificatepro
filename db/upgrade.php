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
 * Upgrade steps for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute the Certificate Pro upgrade from the given old version.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_certificatepro_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081501) {
        // The shared media library, so a signature or a logo is uploaded once
        // and used by every certificate on the site.
        $table = new xmldb_table('certificatepro_media');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'other');
        $table->add_field('pixelwidth', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('pixelheight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('kind', XMLDB_INDEX_NOTUNIQUE, ['kind']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026081501, 'certificatepro');
    }

    if ($oldversion < 2026081502) {
        // No schema change. The general purpose picture element now offers the
        // whole shared library rather than only pictures filed as "other".
        upgrade_mod_savepoint(true, 2026081502, 'certificatepro');
    }

    if ($oldversion < 2026101200) {
        // A certification cycle awards exactly one document. The unique index is
        // what enforces that: two requests racing to issue for the same cycle can
        // both pass an application level check, and only the database can refuse
        // the second insert. NULL is outside a unique index on every database
        // Moodle supports, so ordinary course awards are unaffected by it.
        $table = new xmldb_table('certificatepro_issues');

        $field = new xmldb_field('cycleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('cycleid', XMLDB_INDEX_UNIQUE, ['cycleid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026101200, 'certificatepro');
    }

    if ($oldversion < 2026101201) {
        $table = new xmldb_table('certificatepro');

        $field = new xmldb_field('cycleonly', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // An activity that a cycle already points at was always meant to be a
        // cycle issuer; it simply had no way to say so. Marking it here means an
        // upgraded site does not start handing out ordinary course awards from an
        // activity whose documents come from the cycle ledger.
        if ($dbman->table_exists(new xmldb_table('local_recertify_cycles'))) {
            $DB->execute(
                'UPDATE {certificatepro} SET cycleonly = 1
                    WHERE id IN (SELECT certificateid FROM {local_recertify_cycles})'
            );
        }

        upgrade_mod_savepoint(true, 2026101201, 'certificatepro');
    }

    if ($oldversion < 2026110200) {
        // The settings form used to save a blank "required grade" as 0, which silently required every
        // learner to hold a course grade. A 0% threshold has no other meaning, so treat it as "no requirement".
        $DB->set_field('certificatepro', 'requiregrade', null, ['requiregrade' => 0]);

        upgrade_mod_savepoint(true, 2026110200, 'certificatepro');
    }

    if ($oldversion < 2026110201) {
        // Keep verification public on sites with forcelogin (the default on Moodle 5.0+), matching core badges.
        if (get_config('mod_certificatepro', 'publicverify') === false) {
            set_config('publicverify', 1, 'mod_certificatepro');
        }

        upgrade_mod_savepoint(true, 2026110201, 'certificatepro');
    }

    if ($oldversion < 2026111004) {
        // A procedurally drawn background is a function of its style, its seed,
        // its palette and the sheet it is drawn on, and until now only the
        // style and the primary colour of the palette were kept. The stored
        // markup was therefore the only copy of the drawing, which forced the
        // print through TCPDF's SVG parser — and that parser flattens every
        // gradient to a single colour.
        //
        // Keeping the whole recipe means the print can replay the drawing
        // through the native painter instead, so the paper shows the same
        // gradient the designer canvas does. A row that predates these two
        // columns carries seed 0 and no palette, which is the signal to leave
        // it on the stored markup; the same signal a model-composed background
        // gives, and for the same reason — it cannot be redrawn.
        $table = new xmldb_table('certificatepro_backgrounds');

        $field = new xmldb_field('palettejson', XMLDB_TYPE_TEXT, null, null, null, null, null,
            'palette');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('seed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
            'palettejson');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026111004, 'certificatepro');
    }

    return true;
}
