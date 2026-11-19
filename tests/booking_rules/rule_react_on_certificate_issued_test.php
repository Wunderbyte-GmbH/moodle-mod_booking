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
 * Tests for booking rules reacting on the event certificate_issued.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use core\task\manager;
use mod_booking\booking_rules\conditions\select_user_from_event;
use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\certificate_issued;
use mod_booking\local\certificateclass;
use mod_booking\placeholders\placeholders\certificateurl;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

/**
 * Rules reacting on "Certificate issued" have to find the booking option of the certificate, can
 * address the user who received it and are not cut off some days after the end of the option.
 *
 * The event is triggered with {@see certificateclass::trigger_certificate_issued()}, so most tests
 * run without tool_certificate.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_react_on_certificate_issued_test extends advanced_testcase {
    /** @var string Full name of the event the rules react on. */
    private const EVENT = '\\mod_booking\\event\\certificate_issued';

    /**
     * Reset the collected rules.
     */
    protected function tearDown(): void {
        rules_info::destroy_singletons();
        parent::tearDown();
    }

    /**
     * Create a booking instance with one option which ended the given number of seconds ago.
     *
     * @param int $endedago
     * @return array{0:stdClass,1:stdClass} [booking module, option]
     */
    private function create_option(int $endedago): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
        ]);

        $end = time() - $endedago;
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with certificate';
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = $end - 2 * HOURSECS;
        $record->courseendtime_0 = $end;
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$booking, $option];
    }

    /**
     * Create a mail rule reacting on the given event for the given user of the event.
     *
     * @param stdClass $booking
     * @param string $userfromeventtype "relateduserid" or "userid"
     * @param string $eventname
     * @return stdClass the record of the rule
     */
    private function create_rule(stdClass $booking, string $userfromeventtype, string $eventname = self::EVENT): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_rule([
            'contextid' => (int)context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => 'Certificate mail',
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"' . $userfromeventtype . '"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"Your certificate","template":"Certificate for {title}","templateformat":"1"}',
            'eventname' => $eventname,
            // The default of the rule form. The option of the tests ended more than one day ago.
            'aftercompletion' => 1,
        ]);
    }

    /**
     * Trigger the event for an issue whose id is not the id of any booking option.
     *
     * @param stdClass $option
     * @param stdClass $recipient
     * @return int the id used for the issue
     */
    private function trigger_event(stdClass $option, stdClass $recipient): int {
        // The bug: the id of the issue was taken for the id of the booking option.
        $issueid = (int)$option->id + 1000;
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        certificateclass::trigger_certificate_issued($settings, $issueid, (int)$recipient->id);
        // Without tool_certificate the table of the issues is unknown to the event, which is reported as debugging.
        $this->resetDebugging();
        return $issueid;
    }

    /**
     * The event carries the id of the booking option where the rules look for it.
     *
     * @covers \mod_booking\local\certificateclass::trigger_certificate_issued
     */
    public function test_event_carries_optionid(): void {
        $this->resetAfterTest();
        [, $option] = $this->create_option(5 * DAYSECS);
        $recipient = $this->getDataGenerator()->create_user();

        $sink = $this->redirectEvents();
        $issueid = $this->trigger_event($option, $recipient);
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof certificate_issued));
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame($issueid, (int)$event->objectid);
        $this->assertSame((int)$recipient->id, (int)$event->relateduserid);
        $this->assertSame((int)$option->id, $event->other['optionid']);
        // The old key stays for readers of the log.
        $this->assertEquals($option->id, $event->other['bookingoption_id']);
    }

    /**
     * A rule for the user who received the certificate queues one mail for this user and the
     * booking option of the certificate, also days after the end of the option.
     *
     * @covers \mod_booking\booking_rules\rules_info::collect_rules_for_execution
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::get_time_window_end
     * @covers \mod_booking\booking_rules\conditions\select_user_from_event::execute
     */
    public function test_rule_mails_recipient_of_certificate(): void {
        $this->resetAfterTest();
        [$booking, $option] = $this->create_option(5 * DAYSECS);
        $recipient = $this->getDataGenerator()->create_user();
        $this->create_rule($booking, 'relateduserid');

        // The admin issues the certificate.
        $this->trigger_event($option, $recipient);

        $tasks = array_values(manager::get_adhoc_tasks(send_mail_by_rule_adhoc::class));
        $this->assertCount(1, $tasks);
        $customdata = $tasks[0]->get_custom_data();
        $this->assertEquals($recipient->id, $customdata->userid);
        $this->assertEquals($option->id, $customdata->optionid);
        $this->assertEquals($booking->cmid, $customdata->cmid);

        // The task checks the rule again before it sends: the mail really goes out.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($recipient->id, $message->useridto);
        $this->assertSame('Your certificate', $message->subject);
        $this->assertStringContainsString('Certificate for Option with certificate', $message->fullmessage);
    }

    /**
     * A rule for the user who triggered the event still addresses the user who issued the certificate.
     *
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_user_from_event::execute
     */
    public function test_rule_mails_issuer_of_certificate(): void {
        global $USER;
        $this->resetAfterTest();
        [$booking, $option] = $this->create_option(5 * DAYSECS);
        $recipient = $this->getDataGenerator()->create_user();
        $this->create_rule($booking, 'userid');

        $this->trigger_event($option, $recipient);

        $tasks = array_values(manager::get_adhoc_tasks(send_mail_by_rule_adhoc::class));
        $this->assertCount(1, $tasks);
        $customdata = $tasks[0]->get_custom_data();
        $this->assertEquals($USER->id, $customdata->userid);
        $this->assertEquals($option->id, $customdata->optionid);
    }

    /**
     * Only rules for "Certificate issued" ignore the days after the end of the booking option.
     *
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::check_if_rule_still_applies
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::get_time_window_end
     */
    public function test_only_certificate_rules_ignore_time_window(): void {
        global $DB;
        $this->resetAfterTest();
        [$booking, $option] = $this->create_option(5 * DAYSECS);
        $user = $this->getDataGenerator()->create_user();
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);

        $expected = [
            self::EVENT => true,
            '\\mod_booking\\event\\bookingoption_completed' => false,
        ];
        foreach ($expected as $eventname => $applies) {
            $created = $this->create_rule($booking, 'relateduserid', $eventname);
            $record = $DB->get_record('booking_rules', ['id' => $created->id], '*', MUST_EXIST);
            $rule = rules_info::get_rule('rule_react_on_event');
            $rule->set_ruledata($record);

            $this->assertSame($applies, $rule->check_if_rule_still_applies((int)$option->id, (int)$user->id, 0), $eventname);
            $windowend = rule_react_on_event::get_time_window_end(json_decode($record->rulejson), $settings);
            $this->assertSame($applies, $windowend === null, $eventname);
        }
    }

    /**
     * The rule form offers the user who received the certificate.
     *
     * @covers \mod_booking\booking_rules\conditions\select_user_from_event::add_userselect_to_mform
     */
    public function test_form_offers_recipient_of_certificate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new MoodleQuickForm('conditionformtest' . uniqid(), 'post', '');
        select_user_from_event::add_userselect_to_mform($mform, 'certificate_issued');
        $types = array_column(
            array_column($mform->getElement('condition_select_user_from_event_type')->_options, 'attr'),
            'value'
        );

        $this->assertContains('relateduserid', $types);
        $this->assertContains('userid', $types);
    }

    /**
     * The placeholder for the URL of the certificate is filled for rules reacting on "Certificate issued".
     *
     * @covers \mod_booking\placeholders\placeholders\certificateurl::return_value
     */
    public function test_certificateurl_placeholder_for_certificate_issued(): void {
        global $DB;
        if (!class_exists('tool_certificate\certificate')) {
            $this->markTestSkipped('tool_certificate is not installed.');
        }
        $this->resetAfterTest();
        [$booking, $option] = $this->create_option(5 * DAYSECS);
        $recipient = $this->getDataGenerator()->create_user();

        $template = $this->getDataGenerator()->get_plugin_generator('tool_certificate')
            ->create_template((object)['name' => 'Certificate 1']);
        $issueid = $template->issue_certificate($recipient->id);
        $issue = $DB->get_record('tool_certificate_issues', ['id' => $issueid], '*', MUST_EXIST);

        $rulejson = json_encode([
            'datafromevent' => [
                'eventname' => self::EVENT,
                'objectid' => $issueid,
                'relateduserid' => $recipient->id,
                'other' => ['optionid' => $option->id],
            ],
        ]);
        $text = '';
        $params = [];
        $url = certificateurl::return_value(
            (int)$booking->cmid,
            (int)$option->id,
            (int)$recipient->id,
            0,
            0,
            0,
            $text,
            $params,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            $rulejson
        );

        $this->assertStringContainsString($issue->code, (string)$url);
    }
}
