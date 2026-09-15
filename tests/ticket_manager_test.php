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
use mod_booking\external\reject_ticket;
use mod_booking\external\verify_ticket;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\ticket\ticket_template_installer;
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
        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN, $this->current_presence());
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
        $this->assertCount(1, $presencechanged, 'The answer-level presence is changed only once.');
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
}
