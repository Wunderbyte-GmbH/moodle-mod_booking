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

/**
 * Tests for scheduled mails table col_status.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Copilot
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\output\scheduledmails;
use mod_booking\table\scheduledmails_table;
use tool_mocktesttime\time_mock;
use context_system;

/**
 * Tests for scheduled mails table status column.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scheduledmails_table_test extends advanced_testcase {
    /**
     * Returns the status cell for a specific subject from the scheduled mails table.
     *
     * @param string $subject
     * @return string
     */
    private function get_status_by_subject(string $subject): string {
        $scheduledmails = new scheduledmails(context_system::instance()->id);
        $table = $scheduledmails->return_table();

        foreach ($table->formatedrows as $row) {
            if ((string)($row['subject'] ?? '') === $subject) {
                return (string)($row['status'] ?? '');
            }
        }

        $this->fail('Expected scheduled mail row for subject "' . $subject . '" was not found.');
    }

    /**
     * Returns computed status for a specific adhoc task row.
     *
     * @param \core\task\adhoc_task $task
     * @return string
     */
    private function get_status_for_task(\core\task\adhoc_task $task): string {
        global $DB;

        $taskid = method_exists($task, 'get_id') ? (int)$task->get_id() : (int)$task->id;
        $customdata = $task->get_custom_data();
        $ruleid = (int)($customdata->ruleid ?? 0);

        $taskrecord = $DB->get_record('task_adhoc', ['id' => $taskid], 'id, customdata', MUST_EXIST);
        $rulerecord = $DB->get_record(
            'booking_rules',
            ['id' => $ruleid],
            'id, rulename, isactive, rulejson, contextid',
            MUST_EXIST
        );

        $taskcustomdata = json_decode((string)$taskrecord->customdata);
        if (empty($taskcustomdata)) {
            $taskcustomdata = new \stdClass();
        }
        $taskcustomdata->rulename = $rulerecord->rulename;
        $taskcustomdata->rulejson = $rulerecord->rulejson;

        $table = new scheduledmails_table('scheduledmails_unittest', context_system::instance()->id);
        $row = (object)[
            'id' => $taskid,
            'ruleid' => $ruleid,
            'nextruntime' => (int)$task->get_next_run_time(),
            'isactive' => (int)$rulerecord->isactive,
            'contextid' => (int)$rulerecord->contextid,
            'customdata' => json_encode($taskcustomdata),
        ];

        return $table->col_status($row);
    }

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Sets a deterministic timezone for date calculations in tests.
     *
     * @return void
     */
    private function setup_test_timezone(): void {
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');
        \core_date::set_default_server_timezone();
    }

    /**
     * Creates common booking test fixture.
     *
     * @return array
     */
    private function create_booking_fixture(): array {
        $bdata = self::booking_common_settings_provider();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $bdata['booking']['course'] = $course->id;
        $bdata['booking']['bookingmanager'] = $student->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        return [$bdata, $course, $student, $booking, $plugingenerator];
    }

    /**
     * End-to-end assertion helper for one rule/datefield combination.
     *
     * @param string $subject
     * @param string $template
     * @param string $rulename
     * @param string $ruledatajson
     * @param array $initialoverrides
     * @param array $updateoverrides
     * @return void
     */
    private function assert_rule_variant_sends_only_yes_message(
        string $subject,
        string $template,
        string $rulename,
        string $ruledatajson,
        array $initialoverrides,
        array $updateoverrides
    ): void {
        $this->setAdminUser();
        $this->setup_test_timezone();

        [$bdata, $course, $student, $booking, $plugingenerator] = $this->create_booking_fixture();

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"' . $subject . '","template":"' . $template . '","templateformat":"1"}';
        $plugingenerator->create_rule([
            'name' => $subject,
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["' . $student->id . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => $rulename,
            'ruledata' => $ruledatajson,
        ]);

        $record = (object)array_merge((array)$bdata['options'][0], [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
        ], $initialoverrides);
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'One task should be created');

        $status1 = $this->get_status_by_subject($subject);
        $this->assertEquals(get_string('yes'), $status1, 'Task should return yes before option update');

        $record->id = $option->id;
        foreach ($updateoverrides as $key => $value) {
            $record->{$key} = $value;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->cmid = $settings->cmid;
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        \cache_helper::purge_by_definition('mod_booking', 'scheduledmailscache');

        $status2 = $this->get_status_by_subject($subject);
        $this->assertEquals(get_string('no'), $status2, 'Task should return no after option update');

        $tasksafterupdate = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasksafterupdate, 'Expected old and new task after option update.');

        $yescount = 0;
        $nocount = 0;
        $latestruntime = 0;
        foreach ($tasksafterupdate as $taskafterupdate) {
            $status = $this->get_status_for_task($taskafterupdate);
            if ($status === get_string('yes')) {
                $yescount++;
            } else if ($status === get_string('no')) {
                $nocount++;
            }
            $latestruntime = max($latestruntime, (int)$taskafterupdate->get_next_run_time());
        }
        $this->assertEquals(1, $yescount, 'Exactly one task should still be valid (yes).');
        $this->assertEquals(1, $nocount, 'Exactly one task should be obsolete (no).');

        time_mock::set_mock_time($latestruntime + 60);
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $sentmessages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->close();

        $this->assertCount(1, $sentmessages, 'Only the yes-status message should be sent.');
    }

    /**
     * End-to-end assertion helper for rule updates (without changing booking option values).
     *
     * @param string $subject
     * @param string $template
     * @param string $rulename
     * @param string $initialruledatajson
     * @param string $updatedruledatajson
     * @param array $optionoverrides
     * @param string|null $updatedsubject
     *
     * @return void
     *
     */
    private function assert_rule_variant_turns_no_after_rule_update(
        string $subject,
        string $template,
        string $rulename,
        string $initialruledatajson,
        string $updatedruledatajson,
        array $optionoverrides,
        ?string $updatedsubject = null
    ): void {
        $this->setAdminUser();
        $this->setup_test_timezone();

        [$bdata, $course, $student, $booking, $plugingenerator] = $this->create_booking_fixture();

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"' . $subject . '","template":"' . $template . '","templateformat":"1"}';

        $rule = $plugingenerator->create_rule([
            'name' => $subject,
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["' . $student->id . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => $rulename,
            'ruledata' => $initialruledatajson,
        ]);

        $record = (object)array_merge((array)$bdata['options'][0], [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
        ], $optionoverrides);
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $tasks = \core\task\manager::get_adhoc_tasks('\\mod_booking\\task\\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'One task should be created before rule update.');
        $originaltaskids = array_map(static function ($task): int {
            return (int)(method_exists($task, 'get_id') ? $task->get_id() : $task->id);
        }, $tasks);

        $statusbefore = $this->get_status_by_subject($subject);
        $this->assertEquals(get_string('yes'), $statusbefore, 'Task should return yes before rule update.');

        $updatedruledata = json_decode($updatedruledatajson);
        $this->assertNotEmpty($updatedruledata, 'Updated ruledata JSON should be valid.');
        $this->update_rule_via_dynamic_form((int)$rule->id, $rulename, $updatedruledata, $updatedsubject);

        \cache_helper::purge_by_definition('mod_booking', 'scheduledmailscache');

        $taskafterupdate = array_values(
            \core\task\manager::get_adhoc_tasks('\\mod_booking\\task\\send_mail_by_rule_adhoc')
        );
        $this->assertCount(2, $taskafterupdate, 'Expected old and new task after rule update.');

        $yescount = 0;
        $nocount = 0;
        $latestruntime = 0;
        foreach ($taskafterupdate as $task) {
            $taskid = (int)(method_exists($task, 'get_id') ? $task->get_id() : $task->id);
            $status = $this->get_status_for_task($task);
            if (in_array($taskid, $originaltaskids, true)) {
                $this->assertEquals(get_string('no'), $status, 'Original task should be obsolete after rule update.');
            }
            if ($status === get_string('yes')) {
                $yescount++;
            } else if ($status === get_string('no')) {
                $nocount++;
            }
            $latestruntime = max($latestruntime, (int)$task->get_next_run_time());
        }

        $this->assertEquals(1, $yescount, 'Exactly one task should still be valid (yes).');
        $this->assertEquals(1, $nocount, 'Exactly one task should be obsolete (no).');

        time_mock::set_mock_time($latestruntime + 60);
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $sentmessages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->close();

        $this->assertCount(1, $sentmessages, 'Only the newly valid task should be sent after rule update.');
    }

    /**
     * Updates a booking rule through the dynamic form flow used by UI.
     *
     * @param int $ruleid
     * @param string $rulename
     * @param \stdClass $updatedruledata
     * @param ?string $updatedsubject
     * @return void
     */
    private function update_rule_via_dynamic_form(
        int $ruleid,
        string $rulename,
        \stdClass $updatedruledata,
        ?string $updatedsubject = null
    ): void {
        $formrequest = (object)['id' => $ruleid];
        $dataforform = rules_info::set_data_for_form($formrequest);

        $ajaxargs = (array)$dataforform;
        $ajaxargs['id'] = $ruleid;
        $ajaxargs['contextid'] = (int)($dataforform->contextid ?? 1);

        if ($rulename === 'rule_daysbefore') {
            $ajaxargs['rule_daysbefore_days'] = (string)($updatedruledata->days ?? '0');
            $ajaxargs['rule_daysbefore_datefield'] = (string)($updatedruledata->datefield ?? '');
        } else if ($rulename === 'rule_specifictime') {
            $seconds = (int)($updatedruledata->seconds ?? 0);
            $ajaxargs['rulespecifictimebeforeafter'] = $seconds < 0 ? -1 : 1;
            $ajaxargs['rulespecifictimeduration'] = [
                'number' => abs($seconds),
                'timeunit' => 1,
            ];
            $ajaxargs['rulespecifictimedatefield'] = (string)($updatedruledata->datefield ?? '');
        }

        if ($updatedsubject !== null) {
            $ajaxargs['action_send_mail_subject'] = $updatedsubject;
        }

        $submitdata = rulesform::mock_ajax_submit($ajaxargs);
        $mform = new rulesform(null, null, 'post', '', [], true, $submitdata, true);
        $mform->set_data_for_dynamic_submission();
        $this->assertTrue($mform->is_validated(), 'Dynamic rule form should validate in test update flow.');
        $mform->process_dynamic_submission();
    }

    /**
     * Test that col_status correctly reflects rule applicability.
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            '2daysbefore',
            'starts in 2 days',
            'rule_daysbefore',
            '{"days":"2","datefield":"coursestarttime","cancelrules":[]}',
            [
                'coursestarttime_0' => strtotime('+5 days', time()),
                'courseendtime_0' => strtotime('+5 days +1 hour', time()),
            ],
            [
                'coursestarttime_0' => strtotime('+15 days', time()),
                'courseendtime_0' => strtotime('+15 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test non-event rule type: rule_specifictime.
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update_specifictime_coursestarttime(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            'specifictime',
            'specific time mail',
            'rule_specifictime',
            '{"seconds":172800,"datefield":"coursestarttime"}',
            [
                'coursestarttime_0' => strtotime('+5 days', time()),
                'courseendtime_0' => strtotime('+5 days +1 hour', time()),
            ],
            [
                'coursestarttime_0' => strtotime('+15 days', time()),
                'courseendtime_0' => strtotime('+15 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test non-event rule type: rule_daysbefore with optiondatestarttime ("date" variant).
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update_daysbefore_optiondatestarttime(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            'daysbeforedate',
            'date in 2 days',
            'rule_daysbefore',
            '{"days":"2","datefield":"optiondatestarttime","cancelrules":[]}',
            [
                'coursestarttime_0' => strtotime('+5 days', time()),
                'courseendtime_0' => strtotime('+5 days +1 hour', time()),
            ],
            [
                'coursestarttime_0' => strtotime('+15 days', time()),
                'courseendtime_0' => strtotime('+15 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test non-event rule type: rule_specifictime with optiondatestarttime ("date" variant).
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update_specifictime_optiondatestarttime(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            'specificdate',
            'date specific time',
            'rule_specifictime',
            '{"seconds":172800,"datefield":"optiondatestarttime"}',
            [
                'coursestarttime_0' => strtotime('+5 days', time()),
                'courseendtime_0' => strtotime('+5 days +1 hour', time()),
            ],
            [
                'coursestarttime_0' => strtotime('+15 days', time()),
                'courseendtime_0' => strtotime('+15 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test non-event rule type: rule_daysbefore with bookingclosingtime.
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update_daysbefore_bookingclosingtime(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            'daysbeforeclosing',
            'closing in 2 days',
            'rule_daysbefore',
            '{"days":"2","datefield":"bookingclosingtime","cancelrules":[]}',
            [
                'restrictanswerperiodclosing' => 1,
                'bookingopeningtime' => strtotime('+1 day', time()),
                'bookingclosingtime' => strtotime('+5 days', time()),
                'coursestarttime_0' => strtotime('+6 days', time()),
                'courseendtime_0' => strtotime('+6 days +1 hour', time()),
            ],
            [
                'bookingclosingtime' => strtotime('+15 days', time()),
                'coursestarttime_0' => strtotime('+16 days', time()),
                'courseendtime_0' => strtotime('+16 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test non-event rule type: rule_specifictime with bookingclosingtime.
     *
     * @covers \mod_booking\table\scheduledmails_table::col_status
     */
    public function test_col_status_after_option_update_specifictime_bookingclosingtime(): void {
        $this->assert_rule_variant_sends_only_yes_message(
            'specificclosing',
            'specific closing time',
            'rule_specifictime',
            '{"seconds":172800,"datefield":"bookingclosingtime"}',
            [
                'restrictanswerperiodclosing' => 1,
                'bookingopeningtime' => strtotime('+1 day', time()),
                'bookingclosingtime' => strtotime('+5 days', time()),
                'coursestarttime_0' => strtotime('+6 days', time()),
                'courseendtime_0' => strtotime('+6 days +1 hour', time()),
            ],
            [
                'bookingclosingtime' => strtotime('+15 days', time()),
                'coursestarttime_0' => strtotime('+16 days', time()),
                'courseendtime_0' => strtotime('+16 days +1 hour', time()),
            ]
        );
    }

    /**
     * Test data provider.
     */
    public static function booking_common_settings_provider(): array {
        return [
            'booking' => [
                'name' => 'Rule Booking Test',
                'eventtype' => 'Test rules',
                'enablecompletion' => 1,
                'bookedtext' => ['text' => 'text'],
                'waitingtext' => ['text' => 'text'],
                'notifyemail' => ['text' => 'text'],
                'statuschangetext' => ['text' => 'text'],
                'deletedtext' => ['text' => 'text'],
                'pollurltext' => ['text' => 'text'],
                'pollurlteacherstext' => ['text' => 'text'],
                'notificationtext' => ['text' => 'text'],
                'userleave' => ['text' => 'text'],
                'tags' => '',
                'completion' => 2,
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            ],
            'options' => [
                0 => [
                    'text' => 'Option: in 5 days',
                    'description' => 'Will start in 5 days',
                    'chooseorcreatecourse' => 1,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('+5 days', time()),
                    'courseendtime_0' => strtotime('+6 days', time()),
                ],
            ],
        ];
    }
}
