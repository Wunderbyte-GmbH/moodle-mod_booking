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
use mod_booking\booking_option;
use mod_booking\external\reject_ticket;
use mod_booking\external\search_ticketscanners;
use mod_booking\external\verify_ticket;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\ticket\ticket_template_installer;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\manageusers_table;
use mod_booking\local\bookingstracker\columns_helper;
use mod_booking\event\bookinganswer_presencechanged;
use mod_booking\event\ticket_created;
use mod_booking\event\ticket_rejected;
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
 * @covers \mod_booking\external\reject_ticket
 * @covers \mod_booking\external\search_ticketscanners
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

    /**
     * Build the environment with a booked student on an option that has three dates:
     * one two days ago, one running right now and one in two days.
     *
     * @param array $optionextra Additional fields for the booking option record.
     *
     * @return stdClass[] The three sessions in chronological order.
     */
    protected function build_dated_environment(array $optionextra = []): array {
        $now = time();
        $dates = [
            1 => [$now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS],
            2 => [$now - 10 * MINSECS, $now + HOURSECS],
            3 => [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ];
        $extra = [];
        foreach ($dates as $i => [$start, $end]) {
            $extra["optiondateid_$i"] = "0";
            $extra["daystonotify_$i"] = "0";
            $extra["coursestarttime_$i"] = $start;
            $extra["courseendtime_$i"] = $end;
        }
        $this->build_environment(true, true, array_merge($extra, $optionextra));
        $this->book_student();

        $sessions = array_values(array_filter($this->settings->sessions, fn($s) => !empty($s->id)));
        $this->assertCount(3, $sessions, 'Precondition: the option has three real dates.');
        return $sessions;
    }

    /**
     * Per-date presence rows of the student on the option, keyed by optiondate id.
     *
     * @return array
     */
    protected function date_presence(): array {
        global $DB;
        $rows = $DB->get_records('booking_optiondates_answers', [
            'optionid' => $this->settings->id,
            'userid' => $this->student->id,
        ]);
        $bydate = [];
        foreach ($rows as $row) {
            $bydate[(int) $row->optiondateid] = (int) $row->status;
        }
        return $bydate;
    }

    /**
     * A lookup (checkin=false) reports the dates and the nearest date and writes nothing.
     */
    public function test_verify_lookup_does_not_write(): void {
        [$past, $running, $future] = $this->build_dated_environment();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, false);

        $this->assertEquals('valid', $result['status']);
        $this->assertEquals((int) $running->id, $result['optiondateid'], 'The running date is the nearest one.');
        $this->assertEquals($this->settings->id, $result['optionid']);
        $this->assertCount(3, $result['dates']);
        $this->assertEquals(
            [(int) $past->id, (int) $running->id, (int) $future->id],
            array_column($result['dates'], 'optiondateid')
        );
        $this->assertNotEmpty($result['eventdatelabel']);
        $this->assertFalse($result['alreadypresent']);
        $this->assertFalse($result['pendingconfirmation']);
        $this->assertEquals(0, $result['presentcount']);
        $this->assertEquals(1, $result['bookedcount']);
        $this->assertSame([], $this->date_presence());
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());

        // An explicit date that belongs to the option is respected, a foreign one falls back to nearest.
        $explicit = verify_ticket::execute($ticket->code, false, false, (int) $future->id);
        $this->assertEquals((int) $future->id, $explicit['optiondateid']);
        $foreign = verify_ticket::execute($ticket->code, false, false, 999999);
        $this->assertEquals((int) $running->id, $foreign['optiondateid']);
    }

    /**
     * A confirmed check-in writes the per-date presence for the selected date, sets the answer
     * status on the first admission only, and reports "already present" per date.
     */
    public function test_verify_checkin_writes_per_date_row(): void {
        [$past, $running, $future] = $this->build_dated_environment();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $code = $ticket->code;

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();

        // Confirm on the future date explicitly.
        $written = verify_ticket::execute($code, true, true, (int) $future->id);
        $this->assertEquals('valid', $written['status']);
        $this->assertEquals((int) $future->id, $written['optiondateid']);
        $this->assertFalse($written['alreadypresent']);
        $this->assertGreaterThan(0, $written['presenttime']);
        $this->assertEquals(1, $written['presentcount'], 'Present count is per date.');
        $this->assertEquals(
            [(int) $future->id => MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN],
            $this->date_presence()
        );
        $this->assertEquals(
            MOD_BOOKING_PRESENCE_STATUS_NOTSET,
            $this->current_presence(),
            'With dates only the scanned session is marked, the booking status stays untouched.'
        );
        $present = array_column($written['dates'], 'present', 'optiondateid');
        $this->assertTrue($present[(int) $future->id]);
        $this->assertFalse($present[(int) $running->id]);

        $scanned = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof ticket_scanned));
        $this->assertCount(1, $scanned);
        $this->assertEquals((int) $future->id, $scanned[0]->other['optiondateid']);

        // Same date again: already present, nothing written.
        $again = verify_ticket::execute($code, true, true, (int) $future->id);
        $this->assertTrue($again['alreadypresent']);
        $this->assertGreaterThan(0, $again['presenttime']);
        $this->assertCount(1, $this->date_presence());

        // The nearest (running) date without an explicit id: a second row, answer status untouched.
        $second = verify_ticket::execute($code, true, true);
        $this->assertEquals((int) $running->id, $second['optiondateid']);
        $this->assertFalse($second['alreadypresent']);
        $this->assertEquals(1, $second['presentcount']);
        $this->assertEquals(
            [
                (int) $future->id => MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN,
                (int) $running->id => MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN,
            ],
            $this->date_presence()
        );
        $presencechanged = array_filter($sink->get_events(), fn($e) => $e instanceof bookinganswer_presencechanged);
        $this->assertCount(0, $presencechanged, 'The answer-level presence is never changed for options with dates.');
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
        $scanned = array_filter($sink->get_events(), fn($e) => $e instanceof ticket_scanned);
        $this->assertCount(2, $scanned);
        $sink->close();

        // The past date is still open.
        $past = verify_ticket::execute($code, false, false, (int) $past->id);
        $this->assertFalse($past['alreadypresent']);
        $this->assertEquals(0, $past['presentcount']);
    }

    /**
     * The nearest date: running first, then the next upcoming, then the most recent past one.
     *
     * @covers \mod_booking\local\ticket\ticket_manager::pick_nearest_optiondate
     */
    public function test_pick_nearest_optiondate(): void {
        $now = 1_800_000_000;
        $session = function (int $id, int $start, int $end): stdClass {
            return (object) ['id' => $id, 'coursestarttime' => $start, 'courseendtime' => $end];
        };
        $past = $session(1, $now - 3 * DAYSECS, $now - 3 * DAYSECS + HOURSECS);
        $recentpast = $session(2, $now - DAYSECS, $now - DAYSECS + HOURSECS);
        $soon = $session(3, $now + HOURSECS, $now + 2 * HOURSECS);
        $later = $session(4, $now + 5 * DAYSECS, $now + 5 * DAYSECS + HOURSECS);
        $running = $session(5, $now - 10 * MINSECS, $now + HOURSECS);
        $legacy = $session(0, $now - 10 * MINSECS, $now + HOURSECS);

        $this->assertEquals(5, ticket_manager::pick_nearest_optiondate([$past, $later, $running, $soon], $now));
        // A session starting within the lead time counts as running.
        $this->assertEquals(3, ticket_manager::pick_nearest_optiondate([$later, $recentpast, $soon], $now));
        $this->assertEquals(4, ticket_manager::pick_nearest_optiondate([$past, $recentpast, $later], $now));
        $this->assertEquals(2, ticket_manager::pick_nearest_optiondate([$past, $recentpast], $now));
        $this->assertEquals(0, ticket_manager::pick_nearest_optiondate([$legacy], $now));
        $this->assertEquals(0, ticket_manager::pick_nearest_optiondate([], $now));
    }

    /**
     * The identity data follows the site setting, including custom profile fields, and is only
     * returned for personalised tickets or options requiring an identity confirmation.
     *
     * @covers \mod_booking\local\ticket\ticket_manager::get_identity_fields
     */
    public function test_identity_fields_follow_setting(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'birthdate',
            'name' => 'Birth date',
            'datatype' => 'datetime',
            'param1' => 1950,
            'param2' => 2030,
            'param3' => 0,
        ]);
        $birthdate = make_timestamp(1990, 6, 15);
        $DB->insert_record('user_info_data', [
            'userid' => $this->student->id,
            'fieldid' => $field->id,
            'data' => $birthdate,
            'dataformat' => 0,
        ]);
        set_config('bookingticketidentityfields', 'picture,fullname,profile_birthdate,email,profile_missing', 'booking');

        $fields = ticket_manager::get_identity_fields($this->student->id);
        $this->assertEquals(['fullname', 'profile_birthdate', 'email'], array_column($fields, 'shortname'));
        $bykey = array_column($fields, 'value', 'shortname');
        $this->assertEquals(fullname($this->student), $bykey['fullname']);
        $this->assertEquals($this->student->email, $bykey['email']);
        $this->assertStringContainsString('1990', $bykey['profile_birthdate']);
        $this->assertEquals('Birth date', array_column($fields, 'name', 'shortname')['profile_birthdate']);

        $choices = ticket_manager::get_identity_field_choices();
        $this->assertArrayHasKey('picture', $choices);
        $this->assertArrayHasKey('profile_birthdate', $choices);

        // Personalised ticket (default): the webservice delivers the identity data.
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, false);
        $this->assertTrue($result['personalized']);
        $this->assertEquals(['fullname', 'profile_birthdate', 'email'], array_column($result['identityfields'], 'shortname'));
        $this->assertNotEmpty($result['userpictureurl']);
    }

    /**
     * A transferable ticket without identity confirmation carries no identity data;
     * the option flag brings it back.
     */
    public function test_identity_fields_hidden_for_transferable_tickets(): void {
        $this->build_environment(true, true, ['ticketpersonalized' => 0]);
        $this->book_student();
        set_config('bookingticketidentityfields', 'fullname,email', 'booking');

        $this->assertFalse(ticket_manager::is_personalized($this->settings->id));
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertEquals(0, (int) $ticket->personalized);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, false);
        $this->assertFalse($result['personalized']);
        $this->assertSame([], $result['identityfields']);
    }

    /**
     * Rejecting a ticket fires the ticket_rejected event and writes no presence.
     */
    public function test_reject_ticket_fires_event_only(): void {
        [, $running] = $this->build_dated_environment();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        $result = reject_ticket::execute($ticket->code, (int) $running->id);
        $this->assertEquals('rejected', $result['status']);

        $rejected = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof ticket_rejected));
        $this->assertCount(1, $rejected);
        $this->assertEquals($this->student->id, $rejected[0]->relateduserid);
        $this->assertEquals((int) $running->id, $rejected[0]->other['optiondateid']);
        $this->assertStringContainsString((string) $this->settings->id, $rejected[0]->get_description());
        $sink->close();

        $this->assertSame([], $this->date_presence());
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());

        $this->assertEquals('notfound', reject_ticket::execute('NOTAREALCODE1')['status']);

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        reject_ticket::execute($ticket->code);
    }

    /**
     * A scanned date shows up in the bookings tracker presence counter when the counted
     * status equals the check-in status.
     */
    public function test_presence_counter_counts_scanned_date(): void {
        global $DB;
        [, $running] = $this->build_dated_environment();
        set_config('bookingstrackerpresencecounter', 1, 'booking');
        set_config('bookingstrackerpresencecountervaluetocount', MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, 'booking');
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        verify_ticket::execute($ticket->code, true, true, (int) $running->id);

        $scope = new \mod_booking\booking_answers\scopes\option();
        [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users(
            'option',
            $this->settings->id,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertCount(1, $rows);
        $this->assertEquals(1, (int) reset($rows)->presencecount);
    }

    /**
     * The "ticket" column of the options overview renders a download button with the ticket icon
     * for the ticket holder, and nothing for users without a ticket.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_ticket
     */
    public function test_ticket_column_renders_button(): void {
        $this->build_environment();
        $this->book_student();
        $row = (object) ['id' => $this->settings->id];

        $this->setUser($this->student);
        $table = new bookingoptions_wbtable('ticketcolumntest');
        $html = $table->col_ticket($row);
        $this->assertStringContainsString('fa-ticket', $html);
        $this->assertStringContainsString('btn', $html);
        $this->assertStringContainsString(get_string('ticketbutton', 'mod_booking'), $html);
        $this->assertStringContainsString('/mod_booking/tickets/', $html);

        $this->setUser($this->teacher);
        $this->assertSame('', (new bookingoptions_wbtable('ticketcolumntest2'))->col_ticket($row));

        // A booking made before the ticket design was chosen: the column creates the ticket on first sight.
        global $DB;
        $DB->delete_records('booking_tickets', ['optionid' => $this->settings->id]);
        $this->setUser($this->student);
        $html = (new bookingoptions_wbtable('ticketcolumntest4'))->col_ticket($row);
        $this->assertStringContainsString('fa-ticket', $html);
        $this->assertCount(1, $this->all_tickets());

        set_config('bookingticketon', 0, 'booking');
        $this->setUser($this->student);
        $this->assertSame('', (new bookingoptions_wbtable('ticketcolumntest3'))->col_ticket($row));
    }

    /**
     * The bookings tracker and the manage responses page offer the ticket column and render the
     * button for participants holding a ticket.
     *
     * @covers \mod_booking\table\manageusers_table::col_ticket
     * @covers \mod_booking\local\bookingstracker\columns_helper::display_columns
     */
    public function test_tracker_ticket_column(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();
        $DB->set_field('booking', 'responsesfields', 'fullname,ticket,status', ['id' => $this->booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int) $this->settings->cmid);

        $columns = columns_helper::display_columns((int) $this->settings->cmid, $this->settings->id);
        $this->assertArrayHasKey('ticket', $columns);

        $this->setUser($this->teacher);
        $table = new manageusers_table('trackertickettest');
        $row = (object) ['userid' => $this->student->id, 'optionid' => $this->settings->id];
        $html = $table->col_ticket($row);
        $this->assertStringContainsString('fa-ticket', $html);
        $this->assertStringContainsString('/mod_booking/tickets/', $html);

        // No ticket for a user who is not booked, and no lazy creation in participant lists.
        $this->assertSame('', $table->col_ticket((object) ['userid' => $this->teacher->id, 'optionid' => $this->settings->id]));
        $this->assertCount(1, $DB->get_records('booking_tickets'));

        set_config('bookingticketon', 0, 'booking');
        $this->assertArrayNotHasKey('ticket', columns_helper::display_columns((int) $this->settings->cmid, $this->settings->id));
        $this->assertSame('', $table->col_ticket($row));
    }

    /**
     * The action column of the options overview shows the ticket button left of the booking
     * confirmation button for the booked user, and the confirmation only when configured.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_action_column_buttons(): void {
        $this->build_environment();
        $this->book_student();
        // The real table rows carry the option status (0 = active), which the action menu reads.
        $row = (object) ['id' => $this->settings->id, 'status' => 0];

        $this->setUser($this->student);
        $table = new bookingoptions_wbtable('actioncolumntest');
        $table->showticketbutton = true;
        $table->showbookingconfirmation = true;
        $html = $table->col_action($row);
        $ticketpos = strpos($html, 'mod-booking-ticket-link');
        $confirmationpos = strpos($html, 'viewconfirmation.php');
        $this->assertNotFalse($ticketpos);
        $this->assertNotFalse($confirmationpos);
        $this->assertLessThan($confirmationpos, $ticketpos, 'The ticket button comes before the confirmation.');
        $this->assertStringContainsString('mod-booking-confirmation-link', $html);
        $this->assertStringNotContainsString('editoptions.php', $html, 'Students cannot edit the option.');

        $table = new bookingoptions_wbtable('actioncolumntest2');
        $table->showticketbutton = false;
        $table->showbookingconfirmation = false;
        $html = $table->col_action($row);
        $this->assertStringNotContainsString('mod-booking-ticket-link', $html);
        $this->assertStringNotContainsString('viewconfirmation.php', $html);

        // Editors get the edit button; a user who is not booked gets no ticket / confirmation.
        $this->setAdminUser();
        $table = new bookingoptions_wbtable('actioncolumntest3');
        $table->showticketbutton = true;
        $html = $table->col_action($row);
        $this->assertStringContainsString('mod-booking-editoption-link', $html);
        $this->assertStringNotContainsString('mod-booking-ticket-link', $html);
        $this->assertStringNotContainsString('viewconfirmation.php', $html);
    }

    /**
     * Store a scanner user list / thresholds directly and drop the cached option settings.
     *
     * @param array $json Keys to merge into the option json.
     *
     * @return void
     */
    protected function set_option_json(array $json): void {
        global $DB;
        $record = $DB->get_record('booking_options', ['id' => $this->settings->id], 'id, json');
        $data = json_decode($record->json ?: '{}', true) ?: [];
        foreach ($json as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $DB->set_field('booking_options', 'json', json_encode($data), ['id' => $this->settings->id]);
        // The settings live in a MUC cache and the singleton: purge both, like a real save does.
        booking_option::purge_cache_for_option($this->settings->id);
        $this->settings = singleton_service::get_instance_of_booking_option_settings($this->settings->id);
    }

    /**
     * can_scan(): the capability, or the option's staff list - and nothing else.
     *
     * @covers \mod_booking\local\ticket\ticket_manager::can_scan
     */
    public function test_can_scan(): void {
        $this->build_environment();
        $cmid = (int) $this->settings->cmid;
        $optionid = $this->settings->id;
        $staff = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($staff->id, $this->course->id, 'student');

        // Capability holder (editingteacher): instance and option.
        $this->assertTrue(ticket_manager::can_scan($cmid, 0, $this->teacher->id));
        $this->assertTrue(ticket_manager::can_scan($cmid, $optionid, $this->teacher->id));
        // Plain student: nothing.
        $this->assertFalse(ticket_manager::can_scan($cmid, 0, $staff->id));
        $this->assertFalse(ticket_manager::can_scan($cmid, $optionid, $staff->id));

        // Picked as entry staff: this option only, never the instance-wide scanner.
        $this->set_option_json([ticket_manager::JSON_SCANNERS => [(string) $staff->id]]);
        $this->assertEquals([(int) $staff->id], ticket_manager::get_scanner_userids($optionid));
        $this->assertTrue(ticket_manager::can_scan($cmid, $optionid, $staff->id));
        $this->assertFalse(ticket_manager::can_scan($cmid, 0, $staff->id));
        $this->assertFalse(ticket_manager::can_scan($cmid, $optionid + 1000, $staff->id));
        $this->assertFalse(ticket_manager::can_scan($cmid, $optionid, (int) guest_user()->id));
        $this->setUser(null);
        $this->assertFalse(ticket_manager::can_scan($cmid, $optionid), 'Nobody logged in.');

        // Current user default.
        $this->setUser($staff);
        $this->assertTrue(ticket_manager::can_scan($cmid, $optionid));
        $this->expectException(\required_capability_exception::class);
        ticket_manager::require_can_scan($cmid, 0);
    }

    /**
     * The availability window around each date.
     *
     * @covers \mod_booking\local\ticket\ticket_manager::get_scan_window
     */
    public function test_get_scan_window(): void {
        [$past, $running, $future] = $this->build_dated_environment();
        $optionid = $this->settings->id;
        $now = time();

        // No thresholds: always open.
        $this->assertTrue(ticket_manager::get_scan_window($optionid, $now)['open']);

        // One hour before / after each date: open during the running date.
        $this->set_option_json([ticket_manager::JSON_SCANBEFORE => HOURSECS, ticket_manager::JSON_SCANAFTER => HOURSECS]);
        $window = ticket_manager::get_scan_window($optionid, $now);
        $this->assertTrue($window['open']);
        $this->assertEquals((int) $running->courseendtime + HOURSECS, $window['closesat']);

        // Between the dates: closed, next opening one hour before the future date.
        $between = (int) $running->courseendtime + 2 * HOURSECS;
        $window = ticket_manager::get_scan_window($optionid, $between);
        $this->assertFalse($window['open']);
        $this->assertEquals((int) $future->coursestarttime - HOURSECS, $window['nextopen']);

        // Only "after" set: open from the beginning of time until the last date's end + after.
        $this->set_option_json([ticket_manager::JSON_SCANBEFORE => null]);
        $this->assertTrue(ticket_manager::get_scan_window($optionid, $between)['open']);
        $this->assertTrue(ticket_manager::get_scan_window($optionid, (int) $past->coursestarttime - 10 * DAYSECS)['open']);
        $this->assertFalse(ticket_manager::get_scan_window($optionid, (int) $future->courseendtime + 2 * HOURSECS)['open']);

        // Only "before" set: closed until one hour before the first date, open ever after.
        $this->set_option_json([ticket_manager::JSON_SCANBEFORE => HOURSECS, ticket_manager::JSON_SCANAFTER => null]);
        $this->assertFalse(ticket_manager::get_scan_window($optionid, (int) $past->coursestarttime - 2 * HOURSECS)['open']);
        $this->assertTrue(ticket_manager::get_scan_window($optionid, (int) $future->courseendtime + 10 * DAYSECS)['open']);

        // All dates in the past with both thresholds: closed, no next opening.
        $this->set_option_json([ticket_manager::JSON_SCANAFTER => HOURSECS]);
        $window = ticket_manager::get_scan_window($optionid, (int) $future->courseendtime + 2 * HOURSECS);
        $this->assertFalse($window['open']);
        $this->assertEquals(0, $window['nextopen']);
    }

    /**
     * Options without dates are always open, whatever the thresholds say.
     */
    public function test_get_scan_window_without_dates(): void {
        $this->build_environment();
        $this->set_option_json([ticket_manager::JSON_SCANBEFORE => 60, ticket_manager::JSON_SCANAFTER => 60]);
        $this->assertTrue(ticket_manager::get_scan_window($this->settings->id)['open']);
    }

    /**
     * Option mode: a ticket of another option is refused with "wrongoption" and no holder data,
     * the permission is checked on the option the scanner was started for.
     */
    public function test_verify_wrong_option(): void {
        $this->build_environment();
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        // A second option in the same instance the scanner is started for.
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $other = $plugingenerator->create_option((object) [
            'bookingid' => $this->booking->id,
            'text' => 'Other option',
            'chooseorcreatecourse' => 1,
            'courseid' => $this->course->id,
            'description' => 'Other',
            'ticket' => $this->templateid,
        ]);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, true, true, 0, (int) $other->id);
        $this->assertEquals('wrongoption', $result['status']);
        $this->assertEquals($this->settings->id, $result['optionid']);
        $this->assertStringContainsString('Test option', $result['eventname']);
        $this->assertStringContainsString('Other option', $result['expectedeventname']);
        $this->assertSame('', $result['fullname']);
        $this->assertEquals(0, $result['userid']);
        $this->assertSame([], $result['identityfields']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());

        // Rejecting a foreign ticket in option mode works for the same staff.
        $this->assertEquals('rejected', reject_ticket::execute($ticket->code, 0, (int) $other->id)['status']);

        // The right option checks in as usual.
        $result = verify_ticket::execute($ticket->code, true, true, 0, $this->settings->id);
        $this->assertEquals('valid', $result['status']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, $this->current_presence());
    }

    /**
     * Outside the availability window the webservices answer "closed" and write nothing.
     */
    public function test_verify_closed(): void {
        global $DB;
        [, $running] = $this->build_dated_environment();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        // Move the running date into the future so "now" lies between two dates, then one minute thresholds.
        $DB->set_field('booking_optiondates', 'coursestarttime', time() + 3 * HOURSECS, ['id' => $running->id]);
        $DB->set_field('booking_optiondates', 'courseendtime', time() + 4 * HOURSECS, ['id' => $running->id]);
        booking_option::purge_cache_for_option($this->settings->id);
        $this->set_option_json([ticket_manager::JSON_SCANBEFORE => 60, ticket_manager::JSON_SCANAFTER => 60]);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, true, true);
        $this->assertEquals('closed', $result['status']);
        $this->assertGreaterThan(time(), $result['nextopen']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
        $this->assertSame([], $this->date_presence());
        $this->assertEquals('closed', reject_ticket::execute($ticket->code)['status']);
    }

    /**
     * Picked entry staff without the capability may verify and reject in option mode only.
     */
    public function test_listed_scanner_without_capability(): void {
        $this->build_environment();
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $staff = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($staff->id, $this->course->id, 'student');
        $this->set_option_json([ticket_manager::JSON_SCANNERS => [$staff->id]]);

        $this->setUser($staff);
        $result = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id);
        $this->assertEquals('valid', $result['status']);
        $this->assertEquals('rejected', reject_ticket::execute($ticket->code, 0, $this->settings->id)['status']);
        $written = verify_ticket::execute($ticket->code, true, true, 0, $this->settings->id);
        $this->assertEquals('valid', $written['status']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, $this->current_presence());

        // Instance mode stays capability-only.
        $this->expectException(\required_capability_exception::class);
        verify_ticket::execute($ticket->code, false);
    }

    /**
     * The entry staff search: a teacher only gets users of the course they may view, a manager everyone.
     */
    public function test_search_ticketscanners(): void {
        $this->build_environment();
        $cmid = (int) $this->settings->cmid;
        $incourse = $this->getDataGenerator()->create_user(['firstname' => 'Zelda', 'lastname' => 'Incourse']);
        $outside = $this->getDataGenerator()->create_user(['firstname' => 'Zelda', 'lastname' => 'Outside']);
        $this->getDataGenerator()->enrol_user($incourse->id, $this->course->id, 'student');

        $this->setUser($this->teacher);
        $result = search_ticketscanners::execute('Zelda', $cmid);
        $ids = array_map('intval', array_keys($result['list']));
        $this->assertContains((int) $incourse->id, $ids);
        $this->assertNotContains((int) $outside->id, $ids);

        $this->setAdminUser();
        $result = search_ticketscanners::execute('Zelda', $cmid);
        $ids = array_map('intval', array_keys($result['list']));
        $this->assertContains((int) $incourse->id, $ids);
        $this->assertContains((int) $outside->id, $ids);

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        search_ticketscanners::execute('Zelda', $cmid);
    }

    /**
     * The scanner template in option mode renders the option title and its dates.
     */
    public function test_scanner_template_renders_option_mode(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $PAGE->set_url('/mod/booking/scan.php');

        $html = $OUTPUT->render_from_template('mod_booking/scanner', [
            'cmid' => 42,
            'optionid' => 7,
            'optionname' => 'Concert',
            'hasdates' => true,
            'dates' => [['optiondateid' => 3, 'label' => 'Tonight', 'present' => false, 'selected' => true]],
        ]);
        $this->assertStringContainsString('data-optionid="7"', $html);
        $this->assertStringContainsString('Concert', $html);
        $this->assertStringContainsString('<option value="3" selected>Tonight</option>', $html);
    }

    /**
     * The ticket PDF file area refuses other students: only the holder, report viewers and entry staff pass.
     *
     * @covers ::booking_pluginfile
     */
    public function test_pluginfile_refuses_foreign_students(): void {
        $this->build_environment();
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $cm = get_coursemodule_from_id('booking', $this->settings->cmid);
        $context = \context_module::instance($this->settings->cmid);
        $args = [(int) $ticket->id, $ticket->code . '.pdf'];
        // Booking initialised the page theme; require_login() inside the gate needs a fresh page.
        global $PAGE;
        $PAGE = new \moodle_page();

        // Another student in the same course: refused.
        $this->setUser($other);
        $this->assertFalse(booking_pluginfile($this->course, $cm, $context, 'tickets', $args, false, []));

        // Unknown ticket id: refused even for the holder.
        $this->setUser($this->student);
        $this->assertFalse(booking_pluginfile($this->course, $cm, $context, 'tickets', [999999, 'x.pdf'], false, []));

        // Entry staff picked on the option (no capability) may open the PDF: the gate lets them through
        // to the file (send_stored_file() would end the request, so only the gate decision is asserted).
        $this->set_option_json([ticket_manager::JSON_SCANNERS => [$other->id]]);
        $this->assertTrue(ticket_manager::can_scan((int) $this->settings->cmid, $this->settings->id, (int) $other->id));
    }

    /**
     * A confirmed scan sets the presence status, and both participant lists show it: the legacy
     * "Manage responses" table used to render the scanner's "Checked in" status as an empty cell.
     *
     * @covers \mod_booking\all_userbookings::col_status
     * @covers \mod_booking\table\manageusers_table::col_status
     */
    public function test_checkin_presence_is_stored_and_displayed(): void {
        global $DB;
        // An option without dates: the presence is stored on the booking answer itself.
        $this->build_environment();
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, true, true, 0, $this->settings->id);
        $this->assertEquals('valid', $result['status']);

        // Stored on the booking answer.
        $answer = $DB->get_record_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist < 2',
            ['optionid' => $this->settings->id, 'userid' => $this->student->id]
        );
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, (int) $answer->status);
        $this->assertSame([], $this->date_presence());

        // Displayed: bookings tracker and legacy manage responses table render the stored value.
        $label = get_string('statuscheckedin', 'mod_booking');
        $row = (object) ['status' => $answer->status, 'userid' => $answer->userid, 'optionid' => $answer->optionid];
        $this->assertEquals($label, (new manageusers_table('presencedisplaytest'))->col_status($row));

        $cm = get_coursemodule_from_id('booking', $this->settings->cmid);
        $option = singleton_service::get_instance_of_booking_option((int) $this->settings->cmid, $this->settings->id);
        $legacytable = new \mod_booking\all_userbookings('presencedisplaylegacy', $option, $cm, $this->settings->id);
        $colstatus = new \ReflectionMethod($legacytable, 'col_status');
        $colstatus->setAccessible(true);
        $this->assertEquals($label, $colstatus->invoke($legacytable, $row));
        // Every defined presence status has a label there, "not set" stays empty.
        foreach (booking::get_array_of_possible_presence_statuses() as $status => $expected) {
            $shown = $colstatus->invoke($legacytable, (object) ['status' => $status]);
            $this->assertEquals($status === MOD_BOOKING_PRESENCE_STATUS_NOTSET ? '' : $expected, $shown);
        }
    }

    /**
     * A ticket that is neither personalised nor identity-checked is checked in by the lookup itself
     * when the scanner allows it; personalised tickets always wait for the staff decision.
     */
    public function test_autocheckin_for_transferable_tickets(): void {
        [, $running] = $this->build_dated_environment(['ticketpersonalized' => 0]);
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);
        $this->assertEquals(0, (int) $ticket->personalized);

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();

        // A plain lookup never writes.
        $lookup = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id);
        $this->assertTrue($lookup['autocheckin']);
        $this->assertFalse($lookup['autocheckedin']);
        $this->assertSame([], $this->date_presence());

        // The scanner's lookup allows the automatic check-in.
        $auto = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id, true);
        $this->assertEquals('valid', $auto['status']);
        $this->assertTrue($auto['autocheckedin']);
        $this->assertFalse($auto['alreadypresent']);
        $this->assertGreaterThan(0, $auto['presenttime']);
        $this->assertEquals([(int) $running->id => MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN], $this->date_presence());
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
        $this->assertCount(1, array_filter($sink->get_events(), fn($e) => $e instanceof ticket_scanned));

        // Scanned again: already present, nothing new.
        $again = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id, true);
        $this->assertTrue($again['alreadypresent']);
        $this->assertFalse($again['autocheckedin']);
        $this->assertCount(1, array_filter($sink->get_events(), fn($e) => $e instanceof ticket_scanned));
        $sink->close();
    }

    /**
     * Personalised tickets and identity-checked options are never checked in automatically.
     */
    public function test_no_autocheckin_when_staff_must_decide(): void {
        $this->build_environment();
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id, true);
        $this->assertEquals('valid', $result['status']);
        $this->assertTrue($result['personalized']);
        $this->assertFalse($result['autocheckin']);
        $this->assertFalse($result['autocheckedin']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
    }

    /**
     * A transferable ticket on an option demanding an identity check still waits for the confirmation.
     */
    public function test_no_autocheckin_with_identity_confirmation(): void {
        $this->build_environment(true, true, ['ticketpersonalized' => 0, 'ticketconfirmidentity' => 1]);
        $this->book_student();
        $ticket = ticket_manager::find_valid_ticket($this->settings->id, $this->student->id);

        $this->setUser($this->teacher);
        $result = verify_ticket::execute($ticket->code, false, false, 0, $this->settings->id, true);
        $this->assertFalse($result['autocheckin']);
        $this->assertFalse($result['autocheckedin']);
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());
    }
}
