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
 * Tests for the SofaTicket entry-ticket system (create, cancel, verify/check-in).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\external\verify_ticket;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\ticket\ticket_template_installer;
use mod_booking\event\ticket_created;
use mod_booking\event\ticket_scanned;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test the SofaTicket entry-ticket flow end to end.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\ticket\ticket_manager
 * @covers \mod_booking\external\verify_ticket
 */
final class ticket_manager_test extends booking_advanced_testcase {
    /** @var stdClass Course. */
    protected $course;

    /** @var stdClass Booking module instance. */
    protected $booking;

    /** @var booking_option_settings Option settings. */
    protected $settings;

    /** @var stdClass Student who books. */
    protected $student;

    /** @var stdClass Entry staff (has mod/booking:scanticket via editingteacher). */
    protected $teacher;

    /** @var int The ticket template id. */
    protected $templateid;

    /**
     * The tool_certificate generator.
     *
     * @return \component_generator_base
     */
    protected function get_certificate_generator() {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    /**
     * Build a course + booking instance + one option + an enrolled student and teacher,
     * and configure the SofaTicket feature with a ticket certificate template.
     *
     * @param bool $enablefeature Whether to switch the ticket feature on.
     * @param bool $assigntemplate Whether the option gets a ticket design.
     * @param array $optionextra Additional fields for the booking option record.
     *
     * @return void
     */
    protected function build_environment(
        bool $enablefeature = true,
        bool $assigntemplate = true,
        array $optionextra = []
    ): void {

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->teacher = $this->getDataGenerator()->create_user();

        // Use the shipped ticket design: it has a page with elements, so a real PDF is produced.
        $this->templateid = ticket_template_installer::ensure_installed();

        // Deliberately leave certificateon OFF to prove tickets do not need it, and keep
        // presencestatustoissuecertificate away from CHECKEDIN so a scan never issues a certificate.
        set_config('certificateon', 0, 'booking');
        set_config('presencestatustoissuecertificate', MOD_BOOKING_PRESENCE_STATUS_COMPLETE, 'booking');
        set_config('bookingticketon', $enablefeature ? 1 : 0, 'booking');
        set_config('bookingticketcheckinstatus', MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, 'booking');

        $bdata = [
            'name' => 'Test Booking', 'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'], 'tags' => '',
            'course' => $this->course->id, 'bookingmanager' => $this->teacher->username,
        ];
        $this->booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $record = (object) array_merge([
            'bookingid' => $this->booking->id,
            'text' => 'Test option',
            'chooseorcreatecourse' => 1,
            'courseid' => $this->course->id,
            'description' => 'Test description',
            'ticket' => $assigntemplate ? $this->templateid : 0,
        ], $optionextra);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $this->settings = singleton_service::get_instance_of_booking_option_settings($option->id);
    }

    /**
     * Book the student on the option (double bookit call: confirm, then commit).
     *
     * @return void
     */
    protected function book_student(): void {
        $this->setAdminUser();
        booking_bookit::bookit('option', $this->settings->id, $this->student->id);
        booking_bookit::bookit('option', $this->settings->id, $this->student->id);
    }

    /**
     * Current presence status stored for the student on the option.
     *
     * @return int
     */
    protected function current_presence(): int {
        global $DB;
        return (int) $DB->get_field_select(
            'booking_answers',
            'status',
            'optionid = :optionid AND userid = :userid AND waitinglist < 2',
            ['optionid' => $this->settings->id, 'userid' => $this->student->id],
            IGNORE_MULTIPLE
        );
    }

    /**
     * All tickets a user holds for the option, valid or cancelled.
     *
     * @return array
     */
    protected function all_tickets(): array {
        global $DB;
        return $DB->get_records('booking_tickets', [
            'optionid' => $this->settings->id,
            'userid' => $this->student->id,
        ]);
    }

    /**
     * A ticket is created exactly once on booking, creation is idempotent,
     * and no tool_certificate issue is written.
     *
     * @covers \mod_booking_observer::bookingoption_booked
     */
    public function test_create_on_booking_is_idempotent(): void {
        global $DB;
        $this->build_environment();

        $issuesbefore = $DB->count_records('tool_certificate_issues');

        // Note: no event sink around the booking. redirectEvents() would stop the observer that
        // creates the ticket from running at all. The event itself is asserted further down.
        $this->book_student();

        $this->assertCount(1, $this->all_tickets(), 'Exactly one ticket should be created on booking.');

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertNotNull($ticket);
        $this->assertEquals(ticket_manager::STATUS_VALID, $ticket->status);
        $this->assertEquals($this->templateid, (int) $ticket->templateid);
        $this->assertNotEmpty($ticket->code);

        // This is the core regression guard of the whole refactor: tickets are not certificate issues.
        $this->assertEquals(
            $issuesbefore,
            $DB->count_records('tool_certificate_issues'),
            'Creating a ticket must not write a tool_certificate issue.'
        );

        // Calling create again must not create a second ticket.
        $again = ticket_manager::create_ticket($this->settings->id, $this->student->id);
        $this->assertEquals((int) $ticket->id, (int) $again->id);
        $this->assertCount(1, $this->all_tickets());
    }

    /**
     * Creating a ticket fires ticket_created, which is what booking rules react on.
     */
    public function test_ticket_created_event_is_fired(): void {
        $this->build_environment();

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');

        $sink = $this->redirectEvents();
        $ticket = ticket_manager::create_ticket($this->settings->id, $other->id);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof ticket_created));
        $sink->close();

        $this->assertNotNull($ticket);
        $this->assertCount(1, $events);
        $this->assertEquals((int) $ticket->id, (int) $events[0]->objectid);
        $this->assertEquals((int) $other->id, (int) $events[0]->relateduserid);
        // The booking rules engine resolves the option from other[optionid].
        $this->assertEquals((int) $this->settings->id, (int) $events[0]->other['optionid']);
        $this->assertEquals($ticket->code, $events[0]->other['code']);
    }

    /**
     * When the feature is globally disabled, no ticket is created.
     */
    public function test_no_ticket_when_globally_disabled(): void {
        $this->build_environment(false);
        $this->book_student();

        $this->assertCount(0, $this->all_tickets());
        $this->assertNull(ticket_manager::create_ticket($this->settings->id, $this->student->id));
    }

    /**
     * When the option has no ticket design, no ticket is created even though the feature is on.
     */
    public function test_no_ticket_without_option_template(): void {
        $this->build_environment(true, false);
        $this->book_student();

        $this->assertFalse(ticket_manager::is_enabled_for_option($this->settings->id));
        $this->assertCount(0, $this->all_tickets());
        $this->assertNull(ticket_manager::create_ticket($this->settings->id, $this->student->id));
    }

    /**
     * The ticket PDF is stored in the module context and can be regenerated after deletion.
     */
    public function test_pdf_is_stored_and_regenerated(): void {
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $file = ticket_manager::get_file($ticket);
        $this->assertNotNull($file, 'The ticket PDF should be stored on creation.');
        $this->assertEquals($ticket->code . '.pdf', $file->get_filename());
        $this->assertEquals(\context_module::instance($this->settings->cmid)->id, $file->get_contextid());
        $this->assertNotNull(ticket_manager::get_file_url($ticket));

        // Delete the file and let the manager rebuild it.
        get_file_storage()->delete_area_files(
            $file->get_contextid(),
            'mod_booking',
            ticket_manager::FILEAREA,
            $ticket->id
        );
        $this->assertNull(ticket_manager::get_file($ticket));

        $this->assertNotNull(ticket_manager::regenerate_pdf((int) $ticket->id));
        $this->assertNotNull(ticket_manager::get_file($ticket));
    }

    /**
     * Cancellation keeps the record but marks it invalid, and is idempotent.
     *
     * @covers \mod_booking_observer::bookinganswer_cancelled
     */
    public function test_cancel_keeps_record_and_is_idempotent(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertNotNull($ticket);
        $ticketid = (int) $ticket->id;

        $this->assertEquals(1, ticket_manager::cancel_ticket($this->settings->id, $this->student->id));

        $cancelled = $DB->get_record('booking_tickets', ['id' => $ticketid]);
        $this->assertNotFalse($cancelled, 'A cancelled ticket must be kept, not deleted.');
        $this->assertEquals(ticket_manager::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue(ticket_manager::is_cancelled($cancelled));
        $this->assertGreaterThan(0, (int) $cancelled->timerevoked);
        $this->assertNull(ticket_manager::find_valid_ticket($this->settings->id, $this->student->id));

        // Cancelling again is a harmless no-op.
        $this->assertEquals(0, ticket_manager::cancel_ticket($this->settings->id, $this->student->id));
    }

    /**
     * A ticket can be resolved by its verification code.
     */
    public function test_find_by_code(): void {
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $found = ticket_manager::find_by_code($ticket->code);

        $this->assertNotNull($found);
        $this->assertEquals((int) $ticket->id, (int) $found->id);
        $this->assertNull(ticket_manager::find_by_code('NOTAREALCODE1'));
    }

    /**
     * A valid scan checks the participant in exactly once and fires the ticket_scanned event;
     * a second scan reports "already present" without changing anything.
     */
    public function test_verify_valid_checks_in_once(): void {
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $code = $ticket->code;

        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        $result = verify_ticket::execute($code);

        $this->assertEquals('valid', $result['status']);
        $this->assertFalse($result['alreadypresent']);
        $this->assertFalse($result['requiresconfirmation']);
        $this->assertEquals(1, $result['presentcount']);
        $this->assertEquals(1, $result['bookedcount']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, $this->current_presence());

        $scanned = array_filter($sink->get_events(), fn($e) => $e instanceof ticket_scanned);
        $this->assertCount(1, $scanned);
        $sink->close();

        // Second scan: already present, nothing changes.
        $result2 = verify_ticket::execute($code);
        $this->assertEquals('valid', $result2['status']);
        $this->assertTrue($result2['alreadypresent']);
        $this->assertGreaterThan(0, $result2['presenttime']);
        $this->assertEquals(1, $result2['presentcount']);

        // The check-in scan did not create a second ticket.
        $this->assertCount(1, $this->all_tickets());
    }

    /**
     * When the option demands an identity check, a scan does not check anybody in
     * until entry staff confirmed the holder.
     */
    public function test_verify_waits_for_identity_confirmation(): void {
        $this->build_environment(true, true, ['ticketconfirmidentity' => 1]);
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $code = $ticket->code;

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($code);

        $this->assertEquals('valid', $result['status']);
        $this->assertTrue($result['requiresconfirmation']);
        $this->assertTrue($result['pendingconfirmation']);
        $this->assertNotEmpty($result['fullname']);
        $this->assertEquals(
            MOD_BOOKING_PRESENCE_STATUS_NOTSET,
            $this->current_presence(),
            'No check-in may happen before the identity was confirmed.'
        );

        // Now staff confirms.
        $confirmed = verify_ticket::execute($code, true, true);
        $this->assertEquals('valid', $confirmed['status']);
        $this->assertFalse($confirmed['pendingconfirmation']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, $this->current_presence());
    }

    /**
     * Scanning a cancelled ticket reports revoked with the cancellation time and never sets presence.
     */
    public function test_verify_revoked_never_sets_presence(): void {
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $code = $ticket->code;
        ticket_manager::cancel_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($code);

        $this->assertEquals('revoked', $result['status']);
        $this->assertGreaterThan(0, $result['revokedtime']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
        $this->assertEquals(0, $result['presentcount']);
    }

    /**
     * An unknown / foreign code returns notfound and never errors.
     */
    public function test_verify_notfound(): void {
        $this->build_environment();
        $this->book_student();

        $this->setUser($this->teacher);
        $result = verify_ticket::execute('NOTAREALCODE1');
        $this->assertEquals('notfound', $result['status']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
    }

    /**
     * A user without the scan capability is denied.
     */
    public function test_verify_requires_capability(): void {
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        verify_ticket::execute($ticket->code);
    }

    /**
     * The scanner template compiles and renders with its live counter and control regions.
     */
    public function test_scanner_template_renders(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $PAGE->set_url('/mod/booking/scan.php');

        $html = $OUTPUT->render_from_template('mod_booking/scanner', ['cmid' => 42]);

        $this->assertStringContainsString('data-region="scanner"', $html);
        $this->assertStringContainsString('data-region="scanner-video"', $html);
        $this->assertStringContainsString('data-action="scanner-start"', $html);
        $this->assertStringContainsString('data-action="scanner-confirm"', $html);
        $this->assertStringContainsString('data-region="scanner-result-picture"', $html);
        // The counter string resolved from lang with the 0/0 default params.
        $this->assertStringContainsString('0 / 0', $html);
    }

    /**
     * Deleting the booking option removes its tickets: DB rows and PDF files.
     *
     * @covers \mod_booking\local\ticket\ticket_manager::delete_tickets_for_option
     */
    public function test_tickets_and_files_deleted_with_option(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertNotNull(ticket_manager::get_file($ticket), 'Precondition: the ticket PDF exists.');
        $contextid = \context_module::instance($this->settings->cmid)->id;

        $option = singleton_service::get_instance_of_booking_option($this->settings->cmid, $this->settings->id);
        $option->delete_booking_option();

        $this->assertEquals(0, $DB->count_records('booking_tickets', ['optionid' => $this->settings->id]));
        $files = get_file_storage()->get_area_files($contextid, 'mod_booking', ticket_manager::FILEAREA, $ticket->id);
        $this->assertEmpty($files, 'The ticket PDF must be deleted with the option.');
    }

    /**
     * The privacy provider covers tickets: user in context, export metadata,
     * and per-user deletion removes rows and PDF files.
     *
     * @covers \mod_booking\privacy\provider
     */
    public function test_privacy_provider_covers_tickets(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();

        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertNotNull($ticket);
        $context = \context_module::instance($this->settings->cmid);

        // Metadata declares the table.
        $collection = new \core_privacy\local\metadata\collection('mod_booking');
        $collection = \mod_booking\privacy\provider::get_metadata($collection);
        $tables = array_map(
            fn($item) => method_exists($item, 'get_name') ? $item->get_name() : '',
            $collection->get_collection()
        );
        $this->assertContains('booking_tickets', $tables);

        // The ticket holder appears in the context list.
        $contextlist = \mod_booking\privacy\provider::get_contexts_for_userid((int) $this->student->id);
        $this->assertContains($context->id, array_map('intval', $contextlist->get_contextids()));

        // Per-user deletion removes rows and files.
        $approved = new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($this->student->id),
            'mod_booking',
            [$context->id]
        );
        \mod_booking\privacy\provider::delete_data_for_user($approved);

        $this->assertEquals(0, $DB->count_records('booking_tickets', ['userid' => $this->student->id]));
        $files = get_file_storage()->get_area_files($context->id, 'mod_booking', ticket_manager::FILEAREA, $ticket->id);
        $this->assertEmpty($files, 'The ticket PDF must be deleted with the user data.');
    }
}
