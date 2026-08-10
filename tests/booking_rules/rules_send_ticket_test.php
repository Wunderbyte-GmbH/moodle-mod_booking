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
 * Tests for the "Send ticket" booking rule action.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\ticket\ticket_template_installer;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test that a booking rule reacting on ticket_created delivers the ticket.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\actions\send_ticket
 * @covers \mod_booking\task\send_ticket_by_rule_adhoc
 */
final class rules_send_ticket_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        rules_info::$rulestocancel = [];
        booking_rules::$rules = [];
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
     * The action must be discoverable under exactly the name the rule form matches on.
     *
     * actions_info::add_actions_to_mform() compares get_name_of_action() with
     * get_string(str_replace('_', '', $classname)), so a mismatch silently breaks the form.
     */
    public function test_action_is_registered_under_matching_name(): void {
        $action = actions_info::get_action('send_ticket');

        $this->assertNotNull($action);
        $this->assertEquals(
            get_string('sendticket', 'mod_booking'),
            $action->get_name_of_action()
        );
        $this->assertEquals(
            $action->get_name_of_action(),
            get_string(str_replace('_', '', 'send_ticket'), 'mod_booking')
        );
    }

    /**
     * Booking an option with a ticket design fires ticket_created, the rule queues the
     * send_ticket task, and running it delivers exactly one message to the booked user.
     */
    public function test_rule_on_ticket_created_sends_the_ticket(): void {

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        set_config('bookingticketon', 1, 'booking');
        set_config('certificateon', 0, 'booking');
        $templateid = ticket_template_installer::ensure_installed();

        $bdata = [
            'name' => 'Ticket rule booking', 'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'], 'tags' => '',
            'course' => $course->id, 'bookingmanager' => $teacher->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\ticket_created"';
        $plugingenerator->create_rule([
            'name' => 'Send the ticket',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_ticket',
            'actiondata' => '{"subject":"Your ticket","template":"Here it is: {ticketcode}"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Ticketed option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->ticket = $templateid;
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $sink = $this->redirectMessages();

        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);

        // A ticket exists and its PDF was stored, so there is something to attach.
        $ticket = ticket_manager::find_valid_ticket($settings->id, $student->id);
        $this->assertNotNull($ticket, 'Booking should have created a ticket.');
        $this->assertNotNull(ticket_manager::get_file($ticket), 'The ticket PDF should be stored.');

        // The adhoc task mtraces its progress; keep it out of the test output.
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $ticketmessages = array_values(array_filter(
            $messages,
            fn($message) => (int) $message->useridto === (int) $student->id
                && strpos((string) $message->subject, 'Your ticket') !== false
        ));

        $this->assertCount(1, $ticketmessages, 'The rule should send exactly one ticket mail.');
        // The {ticketcode} placeholder resolved against the ticket of the recipient.
        $this->assertStringContainsString($ticket->code, (string) $ticketmessages[0]->fullmessage);
    }

    /**
     * Without a valid ticket the task bails out instead of sending an empty mail.
     */
    public function test_no_mail_without_a_ticket(): void {

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        // Ticketing is on, but the option gets no ticket design at all.
        set_config('bookingticketon', 1, 'booking');
        set_config('certificateon', 0, 'booking');

        $bdata = [
            'name' => 'No ticket booking', 'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'], 'tags' => '',
            'course' => $course->id, 'bookingmanager' => $teacher->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // React on the booking itself, so the rule fires even though no ticket exists.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked"';
        $plugingenerator->create_rule([
            'name' => 'Send the ticket',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_ticket',
            'actiondata' => '{"subject":"Your ticket","template":"Here it is"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Untickted option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $sink = $this->redirectMessages();
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        // The adhoc task mtraces its progress; keep it out of the test output.
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $ticketmessages = array_filter(
            $messages,
            fn($message) => strpos((string) $message->subject, 'Your ticket') !== false
        );
        $this->assertCount(0, $ticketmessages, 'No ticket mail may be sent when there is no ticket.');
    }

    /**
     * Build course, booking, ticketed option and a send_ticket rule on ticket_created.
     *
     * @return array [booking_option_settings $settings, stdClass $student]
     */
    private function build_ticket_rule_environment(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        set_config('bookingticketon', 1, 'booking');
        set_config('certificateon', 0, 'booking');
        $templateid = ticket_template_installer::ensure_installed();

        $bdata = [
            'name' => 'Ticket rule booking', 'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'], 'tags' => '',
            'course' => $course->id, 'bookingmanager' => $teacher->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\ticket_created"';
        $plugingenerator->create_rule([
            'name' => 'Send the ticket',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_ticket',
            'actiondata' => '{"subject":"Your ticket","template":"Here it is: {ticketcode}"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Ticketed option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->ticket = $templateid;
        $option = $plugingenerator->create_option($record);

        return [singleton_service::get_instance_of_booking_option_settings($option->id), $student];
    }

    /**
     * Ticket mails from a message sink, filtered by the rule's subject.
     *
     * @param array $messages
     * @return array
     */
    private function ticket_messages(array $messages): array {
        return array_values(array_filter(
            $messages,
            fn($message) => strpos((string) $message->subject, 'Your ticket') !== false
        ));
    }

    /**
     * The ticket is resolved at SEND time, not at queue time: after cancel + rebook,
     * the queued task delivers the current ticket's code, never the revoked one.
     */
    public function test_ticket_is_resolved_at_send_time(): void {
        [$settings, $student] = $this->build_ticket_rule_environment();

        $sink = $this->redirectMessages();

        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        $oldticket = ticket_manager::find_valid_ticket($settings->id, $student->id);
        $this->assertNotNull($oldticket);

        // Cancel (revokes the ticket) and rebook (mints a new one) BEFORE any task ran.
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_delete_response($student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);

        $newticket = ticket_manager::find_valid_ticket($settings->id, $student->id);
        $this->assertNotNull($newticket);
        $this->assertNotEquals($oldticket->code, $newticket->code, 'Rebooking must mint a fresh ticket.');

        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $ticketmessages = $this->ticket_messages($sink->get_messages());
        $sink->close();

        $this->assertNotEmpty($ticketmessages, 'The rule must deliver the ticket.');
        foreach ($ticketmessages as $message) {
            $this->assertStringContainsString(
                $newticket->code,
                (string) $message->fullmessage,
                'The mail must carry the CURRENT ticket code (resolved at send time).'
            );
            $this->assertStringNotContainsString(
                $oldticket->code,
                (string) $message->fullmessage,
                'The revoked ticket code must never be delivered.'
            );
        }
    }

    /**
     * A cancellation between queueing and sending suppresses the mail entirely.
     */
    public function test_cancellation_before_send_suppresses_the_mail(): void {
        [$settings, $student] = $this->build_ticket_rule_environment();

        $sink = $this->redirectMessages();

        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        $this->assertNotNull(ticket_manager::find_valid_ticket($settings->id, $student->id));

        // Cancel before the queued task runs: the ticket is revoked.
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_delete_response($student->id);
        $this->assertNull(ticket_manager::find_valid_ticket($settings->id, $student->id));

        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $ticketmessages = $this->ticket_messages($sink->get_messages());
        $sink->close();

        $this->assertCount(0, $ticketmessages, 'No ticket mail may go out after the booking was cancelled.');
    }

    /**
     * A missing PDF at send time is regenerated instead of aborting the delivery.
     */
    public function test_missing_pdf_is_regenerated_at_send_time(): void {
        [$settings, $student] = $this->build_ticket_rule_environment();

        $sink = $this->redirectMessages();

        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        $ticket = ticket_manager::find_valid_ticket($settings->id, $student->id);
        $this->assertNotNull(ticket_manager::get_file($ticket));

        // Simulate the race: the PDF vanished between creation and delivery.
        get_file_storage()->delete_area_files(
            \context_module::instance($settings->cmid)->id,
            'mod_booking',
            ticket_manager::FILEAREA,
            $ticket->id
        );
        $this->assertNull(ticket_manager::get_file($ticket));

        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $ticketmessages = $this->ticket_messages($sink->get_messages());
        $sink->close();

        $this->assertCount(1, $ticketmessages, 'The delivery must not abort on a missing PDF.');
        $this->assertNotNull(ticket_manager::get_file($ticket), 'The PDF must have been regenerated for sending.');
    }
}
