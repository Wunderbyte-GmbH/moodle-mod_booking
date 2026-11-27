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

namespace mod_booking\table;

use advanced_testcase;
use context_module;
use context_system;
use local_wunderbyte_table\external\execute_action;
use mod_booking\output\scheduledmails;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The table of the scheduled mails can delete scheduled mails. Deleting needs the capability to
 * edit scheduled mails, and only the mails of the table can be deleted - never another
 * background task of the site.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\table\scheduledmails_table
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class scheduledmails_actions_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var context_module The context of the booking instance. */
    private context_module $modulecontext;

    /** @var int[] Ids of the background tasks of the test by name. */
    private array $taskids = [];

    /**
     * Tests set up: scheduled mails of two contexts and a background task of another plugin.
     */
    public function setUp(): void {
        global $DB, $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->modulecontext = context_module::instance($booking->cmid);

        foreach (
            [
                'viewer' => ['mod/booking:viewscheduledmails'],
                'editor' => ['mod/booking:viewscheduledmails', 'mod/booking:editscheduledmails'],
            ] as $name => $capabilities
        ) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
            $roleid = $this->getDataGenerator()->create_role();
            foreach ($capabilities as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $this->modulecontext->id, true);
            }
            role_assign($roleid, $this->users[$name]->id, $this->modulecontext->id);
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with scheduled mails',
            'courseid' => $course->id,
        ]);

        // Two scheduled mails of a rule of the booking instance, one of a rule of the whole site.
        $recipient = $this->getDataGenerator()->create_user();
        foreach (
            [
                'mail' => $this->modulecontext->id,
                'secondmail' => $this->modulecontext->id,
                'sitemail' => context_system::instance()->id,
            ] as $name => $contextid
        ) {
            $ruleid = $DB->insert_record('booking_rules', (object)[
                'contextid' => $contextid,
                'rulename' => 'rule_daysbefore',
                'rulejson' => json_encode(['name' => 'Reminder', 'actiondata' => ['subject' => 's', 'template' => 't']]),
                'eventname' => '',
                'isactive' => 1,
            ]);
            $this->taskids[$name] = $this->insert_background_task('\mod_booking\task\send_mail_by_rule_adhoc', [
                'ruleid' => $ruleid,
                'userid' => (int)$recipient->id,
                'optionid' => (int)$option->id,
                'cmid' => (int)$booking->cmid,
            ]);
        }
        // A background task that has nothing to do with scheduled mails.
        $this->taskids['foreign'] = $this->insert_background_task('\core\task\asynchronous_backup_task', ['backupid' => 1]);

        $PAGE->set_url(new \moodle_url('/mod/booking/scheduledmails.php', ['cmid' => $booking->cmid]));
        $PAGE->set_context($this->modulecontext);

        \cache_helper::purge_by_event('changesinwunderbytetable');
        \cache_helper::purge_by_event('setbackscheduledmailscache');
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Insert a background task.
     *
     * @param string $classname
     * @param array $customdata
     * @return int id of the task
     */
    private function insert_background_task(string $classname, array $customdata): int {
        global $DB;

        return (int)$DB->insert_record('task_adhoc', (object)[
            'component' => 'mod_booking',
            'classname' => $classname,
            'nextruntime' => time() + DAYSECS,
            'faildelay' => 0,
            'customdata' => json_encode($customdata),
            'blocking' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Render the table of the booking instance as administrator and return its hash.
     *
     * @return string
     */
    private function render_table_and_get_hash(): string {
        $this->setAdminUser();
        $hash = (string)(new scheduledmails($this->modulecontext->id))->return_table()->tablecachehash;
        $this->assertNotEmpty($hash, 'The table was not cached.');
        return $hash;
    }

    /**
     * Run an action of the table through the web service, as a user.
     *
     * @param string $username
     * @param string $action
     * @param string $hash
     * @param int $id
     * @return int 1 if the action reports success, 0 if it reports a failure, -1 if it was rejected
     */
    private function run_action(string $username, string $action, string $hash, int $id): int {
        $this->setUser($this->users[$username]);
        try {
            $result = execute_action::execute($action, $hash, $id, '{}');
        } catch (\required_capability_exception $e) {
            return -1;
        }
        return (int)$result['success'];
    }

    /**
     * Ids of the background tasks of the test that still exist.
     *
     * @return string[] names of the tasks
     */
    private function existing_tasks(): array {
        global $DB;

        $names = [];
        foreach ($this->taskids as $name => $id) {
            if ($DB->record_exists('task_adhoc', ['id' => $id])) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Users who may only view the scheduled mails cannot delete anything.
     */
    public function test_viewing_scheduled_mails_does_not_allow_deleting(): void {
        $hash = $this->render_table_and_get_hash();

        $this->assertSame(-1, $this->run_action('viewer', 'deleteitem', $hash, $this->taskids['mail']));
        $this->assertSame(-1, $this->run_action('viewer', 'deleteitem', $hash, $this->taskids['foreign']));
        $this->assertSame(-1, $this->run_action('viewer', 'cleanupinvalid', $hash, 0));

        $this->assertSame(['mail', 'secondmail', 'sitemail', 'foreign'], $this->existing_tasks());
    }

    /**
     * The buttons for deleting are only offered to users who may edit scheduled mails.
     */
    public function test_delete_buttons_are_for_users_who_may_edit(): void {
        $this->setUser($this->users['viewer']);
        $this->assertCount(0, (new scheduledmails($this->modulecontext->id))->return_table()->actionbuttons);

        $this->setUser($this->users['editor']);
        $this->assertCount(2, (new scheduledmails($this->modulecontext->id))->return_table()->actionbuttons);
    }

    /**
     * Users who may edit scheduled mails delete the mails of the table, and nothing else.
     */
    public function test_only_scheduled_mails_of_the_table_can_be_deleted(): void {
        $hash = $this->render_table_and_get_hash();

        // Another background task of the site and the mail of a rule of another context stay.
        $this->assertSame(0, $this->run_action('editor', 'deleteitem', $hash, $this->taskids['foreign']));
        $this->assertSame(0, $this->run_action('editor', 'deleteitem', $hash, $this->taskids['sitemail']));
        $this->assertSame(['mail', 'secondmail', 'sitemail', 'foreign'], $this->existing_tasks());

        // A scheduled mail of the table is deleted.
        $this->assertSame(1, $this->run_action('editor', 'deleteitem', $hash, $this->taskids['mail']));
        $this->assertSame(['secondmail', 'sitemail', 'foreign'], $this->existing_tasks());
    }
}
