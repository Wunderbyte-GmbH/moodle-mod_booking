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
 * Tests for the SofaTicket entry-ticket system (issue, revoke, verify/check-in).
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\external\verify_ticket;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\event\ticket_scanned;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test the SofaTicket entry-ticket flow end to end.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
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

    /** @var int The master ticket template id. */
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
     * and configure the SofaTicket feature with a master certificate template.
     *
     * @param bool $enablefeature Whether to switch the ticket feature on.
     *
     * @return void
     */
    protected function build_environment(bool $enablefeature = true): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->teacher = $this->getDataGenerator()->create_user();

        $template = $this->get_certificate_generator()->create_template((object) ['name' => 'Ticket master']);
        $this->templateid = (int) $template->get_id();

        // Configure the ticket feature. Deliberately leave certificateon OFF to prove tickets don't need it,
        // and keep presencestatustoissuecertificate away from CHECKEDIN so a scan never double-issues (testcase 14).
        set_config('certificateon', 0, 'booking');
        set_config('presencestatustoissuecertificate', MOD_BOOKING_PRESENCE_STATUS_COMPLETE, 'booking');
        set_config('bookingticketon', $enablefeature ? 1 : 0, 'booking');
        set_config('bookingtickettemplateid', $this->templateid, 'booking');
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

        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = 'Test option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $this->course->id;
        $record->description = 'Test description';

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
     * A ticket is issued exactly once on booking, and issuing is idempotent.
     *
     * @covers \mod_booking_observer::bookingoption_booked
     */
    public function test_issue_on_booking_is_idempotent(): void {
        $this->build_environment();

        $this->book_student();

        $issues = ticket_manager::find_all_issues($this->settings->id, $this->student->id);
        $this->assertCount(1, $issues, 'Exactly one ticket should be issued on booking.');

        $active = ticket_manager::find_active_issue($this->settings->id, $this->student->id);
        $this->assertNotNull($active);

        // Calling issue again must not create a second ticket.
        $again = ticket_manager::issue_ticket($this->settings->id, $this->student->id);
        $this->assertEquals((int) $active->id, $again);
        $this->assertCount(1, ticket_manager::find_all_issues($this->settings->id, $this->student->id));
    }

    /**
     * When the feature is disabled, no ticket is issued.
     */
    public function test_no_issue_when_disabled(): void {
        $this->build_environment(false);
        $this->book_student();
        $this->assertCount(0, ticket_manager::find_all_issues($this->settings->id, $this->student->id));
        $this->assertEquals(0, ticket_manager::issue_ticket($this->settings->id, $this->student->id));
    }

    /**
     * Cancellation is a soft-cancel: the issue row is kept (data retained), marked invalid, idempotent.
     *
     * @covers \mod_booking_observer::bookinganswer_cancelled
     */
    public function test_cancel_keeps_data_and_is_idempotent(): void {
        global $DB;
        $this->build_environment();
        $this->book_student();

        $active = ticket_manager::find_active_issue($this->settings->id, $this->student->id);
        $this->assertNotNull($active);
        $issueid = (int) $active->id;

        $count = ticket_manager::cancel_ticket($this->settings->id, $this->student->id);
        $this->assertEquals(1, $count);

        // Row still exists (data retained for verifiability), but is now invalid.
        $issue = $DB->get_record('tool_certificate_issues', ['id' => $issueid]);
        $this->assertNotFalse($issue, 'Cancelled ticket issue must be kept, not deleted.');
        $this->assertTrue(ticket_manager::is_cancelled($issue));
        $this->assertGreaterThan(0, ticket_manager::get_cancelledtime($issue));
        $this->assertEquals(1, (int) $issue->archived);
        $this->assertLessThanOrEqual(time(), (int) $issue->expires);
        $this->assertNull(ticket_manager::find_active_issue($this->settings->id, $this->student->id));

        // Cancelling again is a harmless no-op.
        $this->assertEquals(0, ticket_manager::cancel_ticket($this->settings->id, $this->student->id));
    }

    /**
     * A valid scan checks the participant in exactly once and fires the ticket_scanned event;
     * a second scan reports "already present" without changing anything (testcase 14: no second issue).
     */
    public function test_verify_valid_checks_in_once(): void {
        $this->build_environment();
        $this->book_student();

        $active = ticket_manager::find_active_issue($this->settings->id, $this->student->id);
        $code = $active->code;

        $this->assertEquals(MOD_BOOKING_PRESENCE_STATUS_NOTSET, $this->current_presence());

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        $result = verify_ticket::execute($code);

        $this->assertEquals('valid', $result['status']);
        $this->assertFalse($result['alreadypresent']);
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

        // Testcase 14: the check-in scan did not create a second certificate/ticket.
        $this->assertCount(1, ticket_manager::find_all_issues($this->settings->id, $this->student->id));
    }

    /**
     * Scanning a cancelled ticket reports revoked with the cancellation time and never sets presence.
     */
    public function test_verify_revoked_never_sets_presence(): void {
        $this->build_environment();
        $this->book_student();

        $active = ticket_manager::find_active_issue($this->settings->id, $this->student->id);
        $code = $active->code;
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

        $active = ticket_manager::find_active_issue($this->settings->id, $this->student->id);
        $code = $active->code;

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        verify_ticket::execute($code);
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
        // The counter string resolved from lang with the 0/0 default params.
        $this->assertStringContainsString('0 / 0', $html);
    }
}
