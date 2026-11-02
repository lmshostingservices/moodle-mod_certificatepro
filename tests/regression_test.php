<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_certificatepro;

use mod_certificatepro\local\field_helper;
use mod_certificatepro\local\grade_helper;
use mod_certificatepro\local\issue_service;
use mod_certificatepro\template;

/**
 * Regression tests for defects fixed in 1.1.4.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_certificatepro\local\issue_service
 * @covers \mod_certificatepro\local\field_helper
 * @covers \mod_certificatepro\local\grade_helper
 * @covers \mod_certificatepro\external\learner_api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(issue_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(field_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grade_helper::class)]
final class regression_test extends \advanced_testcase {
    /**
     * A course, a learner and an activity.
     *
     * @param array $settings activity settings
     * @return array [course, user, instance, cm, context]
     */
    private function setup_activity(array $settings = []): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $instance = $this->getDataGenerator()->create_module('certificatepro', ['course' => $course->id] + $settings);
        $cm = get_coursemodule_from_instance('certificatepro', $instance->id);
        return [$course, $user, $instance, $cm, \context_module::instance($cm->id)];
    }

    /** Minimum time is stored in seconds and compared in seconds. */
    public function test_required_time_is_seconds(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $instance, $cm] = $this->setup_activity(['requiredtime' => 10 * MINSECS]);
        $instance = $DB->get_record('certificatepro', ['id' => $instance->id]);
        $unmet = issue_service::unmet_criteria($instance, $user, $course, $cm);
        $this->assertCount(1, $unmet);
        // The message quotes ten minutes, not ten hours.
        $this->assertStringContainsString(format_time(10 * MINSECS), $unmet[0]);
    }

    /** A blank grade requirement is no requirement. */
    public function test_null_grade_requirement(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $instance, $cm] = $this->setup_activity();
        $instance = $DB->get_record('certificatepro', ['id' => $instance->id]);
        $this->assertNull($instance->requiregrade);
        $this->assertSame([], issue_service::unmet_criteria($instance, $user, $course, $cm));
    }

    /** Only whitelisted profile fields can be printed. */
    public function test_user_field_whitelist(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['city' => 'Perth']);
        $user = \core_user::get_user($user->id);
        $issue = (object)['id' => 1, 'code' => 'X', 'snapshot' => null, 'timecreated' => time()];
        $ctx = \mod_certificatepro\element\render_context::for_issue(
            \context_system::instance(),
            (object)['id' => 1, 'name' => 'C'],
            get_site(),
            $user,
            $issue
        );
        $this->assertSame('Perth', field_helper::user_field_value('city', $ctx));
        $this->assertSame('', field_helper::user_field_value('password', $ctx));
        $this->assertSame('', field_helper::user_field_value('secret', $ctx));
    }

    /** Blank pass/fail wording falls back to the default words. */
    public function test_blank_passfail_wording(): void {
        $method = new \ReflectionMethod(grade_helper::class, 'word');
        $method->setAccessible(true);
        $this->assertSame(
            get_string('gradepassdefault', 'mod_certificatepro'),
            $method->invoke(null, ['pass' => '  ', 'fail' => ''], 'pass')
        );
        $this->assertSame('Competent', $method->invoke(null, ['pass' => 'Competent'], 'pass'));
    }

    /** Issuing sends the configured notifications and records that it did. */
    public function test_issue_sends_notifications(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$course, $user, $instance, , $context] = $this->setup_activity(['emailstudents' => 1, 'emailteachers' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $instance = $DB->get_record('certificatepro', ['id' => $instance->id]);
        $sink = $this->redirectMessages();
        $issue = issue_service::issue($instance, $course, $context, $user);
        $messages = $sink->get_messages();
        $sink->close();
        $recipients = array_map(fn($m) => (int)$m->useridto, $messages);
        sort($recipients);
        $expected = [(int)$user->id, (int)$teacher->id];
        sort($expected);
        $this->assertSame($expected, $recipients);
        $this->assertEquals(1, $issue->emailed);
    }

    /** Staff who can view the activity do not receive certificates automatically. */
    public function test_receive_capability(): void {
        $this->resetAfterTest();
        [$course, , , , $context] = $this->setup_activity();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->assertTrue(has_capability('mod/certificatepro:view', $context, $teacher));
        $this->assertFalse(has_capability('mod/certificatepro:receive', $context, $teacher));
        $this->assertFalse(has_capability('mod/certificatepro:receive', $context, get_admin(), false));
    }

    /** The scheduled task only issues to active learners who can receive the certificate. */
    public function test_task_skips_suspended_and_staff(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $instance] = $this->setup_activity();
        $suspended = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $suspended->id,
            $course->id,
            'student',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        ob_start();
        (new task\issue_certificates())->execute();
        ob_end_clean();
        $issued = $DB->get_fieldset_select('certificatepro_issues', 'userid', 'certificateproid = ?', [$instance->id]);
        $this->assertEquals([$user->id], array_values($issued));
    }

    /** The verify web service uses the same visibility rule as the verify page. */
    public function test_verify_service_respects_verifyany(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $instance, , $context] = $this->setup_activity(['verifyany' => 0]);
        $instance = $DB->get_record('certificatepro', ['id' => $instance->id]);
        $issue = issue_service::issue($instance, $course, $context, $user);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(external\learner_api::verify_code($issue->code)['found']);
        $this->setAdminUser();
        $this->assertTrue(external\learner_api::verify_code($issue->code)['found']);
    }

    /** Grade elements follow their grade item through a backup and restore into another course. */
    public function test_restore_remaps_grade_item(): void {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = true;

        [$course, , $instance] = $this->setup_activity();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
                'courseid' => $course->id]
        );
        $template = template::load((int)$DB->get_field('certificatepro', 'templateid', ['id' => $instance->id]));
        $pageid = (int)array_key_first($template->get_pages());
        $elementid = $template->add_element($pageid, 'grade');
        $DB->set_field(
            'certificatepro_elements',
            'data',
            json_encode(['gradeitem' => 'item_' . $item->id]),
            ['id' => $elementid]
        );

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $target = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newinstance = $DB->get_record('certificatepro', ['course' => $target->id], '*', MUST_EXIST);
        $newitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'courseid' => $target->id]);
        $refs = [];
        $sql = "SELECT e.data
                  FROM {certificatepro_elements} e
                  JOIN {certificatepro_pages} p ON p.id = e.pageid
                 WHERE p.templateid = ? AND e.element = 'grade'";
        foreach ($DB->get_fieldset_sql($sql, [$newinstance->templateid]) as $data) {
            $refs[] = json_decode($data, true)['gradeitem'] ?? '';
        }
        $this->assertContains('item_' . $newitem->id, $refs);
        $this->assertNotContains('item_' . $item->id, $refs);
        $this->assertNotEquals($item->id, $newitem->id);
    }

    /** Verification stays public by default even though Moodle 5 sites force login. */
    public function test_public_verification_default(): void {
        $this->resetAfterTest();
        $this->assertEquals(1, get_config('mod_certificatepro', 'publicverify'));
    }
}
