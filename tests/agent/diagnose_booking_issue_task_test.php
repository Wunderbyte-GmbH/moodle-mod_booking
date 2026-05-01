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

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\booking\tasks\diagnose_booking_issue_task;
use mod_booking\singleton_service;

/**
 * Tests for booking.diagnose_booking_issue.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wbagent\booking\tasks\diagnose_booking_issue_task
 */
final class diagnose_booking_issue_task_test extends abstract_agent_testcase {
    /**
     * Create a booking option through the plugin generator.
     *
     * @param string $name
     * @param array $extra
     * @return \stdClass
     */
    private function create_generated_option(string $name, array $extra = []): \stdClass {
        $result = $this->exec_command('booking.create_option', array_merge([
            'text' => $name,
            'maxanswers' => 5,
            'coursestarttime' => '2045-03-15T09:00:00',
            'courseendtime' => '2045-03-15T17:00:00',
            'teacherquery' => 'current',
            'location' => 'Room 1',
        ], $extra));

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        singleton_service::destroy_instance();
        return $this->get_option_from_db((int)$result['resultid']);
    }

    /**
     * Book a user into an option using the plugin generator.
     *
     * @param int $userid
     * @param int $optionid
     * @return void
     */
    private function book_user_in_option(int $userid, int $optionid): void {
        $result = $this->gen->create_answer([
            'optionid' => $optionid,
            'userid' => $userid,
        ]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Validate asks follow-up question when option is missing.
     */
    public function test_validate_requests_option_reference(): void {
        $task = new diagnose_booking_issue_task();

        $result = $task->validate([
            'question' => 'Warum kann ich mich nicht eintragen?',
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['ambiguities']);
        $this->assertStringContainsString('Which booking option do you mean?', (string)$result['ambiguities'][0]);
    }

    /**
     * Validate recognizes "nicht mehr anmelden" as cannot-book issue.
     */
    public function test_validate_recognizes_nicht_mehr_anmelden(): void {
        $task = new diagnose_booking_issue_task();
        $option = $this->create_generated_option('Anmeldecheck Option');

        $result = $task->validate([
            'question' => 'Warum kann ich mich nicht mehr anmelden für die buchungsoption "Anmeldecheck Option"?',
            'optionid' => (int)$option->id,
        ], (int)$this->booking->cmid);

        $this->assertTrue($result['valid'], implode('; ', array_merge($result['errors'], $result['ambiguities'])));
        $this->assertEmpty($result['ambiguities']);
    }

    /**
     * Diagnose reports confirmed booking for booking-status questions.
     */
    public function test_diagnose_booking_status_reports_booked_user(): void {
        global $DB;

        $option = $this->create_generated_option('Diagnosis Alpha');
        $now = time();
        $DB->insert_record('booking_answers', [
            'bookingid' => (int)$this->booking->id,
            'userid' => (int)$this->teacher->id,
            'optionid' => (int)$option->id,
            'timemodified' => $now,
            'timecreated' => $now,
            'timebooked' => $now,
            'waitinglist' => 0,
            'status' => 0,
            'places' => 1,
            'startdate' => 0,
            'enddate' => 0,
        ]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Warum bin ich bei Diagnosis Alpha nicht eingetragen?',
            'optionquery' => 'Diagnosis Alpha',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$option->id, (int)$result['resultid']);
        $this->assertContains((int)$option->id, $result['previewoptionids'] ?? []);
        $this->assertStringContainsString(
            'You are already booked, so another normal booking is not available.',
            (string)($result['diagnosis']['reasons'][1] ?? '')
        );
        $this->assertSame('booking_status', (string)($result['diagnosis']['issue'] ?? ''));
        $this->assertSame('booked', (string)($result['diagnosis']['userstatus'] ?? ''));
    }

    /**
     * Diagnose reports full option as a reason for cannot-book questions.
     */
    public function test_diagnose_cannot_book_reports_full_option(): void {
        global $DB;

        $option = $this->create_generated_option('Diagnosis Full', ['maxanswers' => 1]);

        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otheruser->id, $this->course->id, 'student');

        $now = time();
        $DB->insert_record('booking_answers', [
            'bookingid' => (int)$this->booking->id,
            'userid' => (int)$otheruser->id,
            'optionid' => (int)$option->id,
            'timemodified' => $now,
            'timecreated' => $now,
            'timebooked' => $now,
            'waitinglist' => 0,
            'status' => 0,
            'places' => 1,
            'startdate' => 0,
            'enddate' => 0,
        ]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Warum kann ich mich bei Diagnosis Full nicht eintragen?',
            'optionquery' => 'Diagnosis Full',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString(
            'The option is currently fully booked.',
            (string)($result['diagnosis']['reasons'][0] ?? '')
        );
        $this->assertSame('cannot_book', (string)($result['diagnosis']['issue'] ?? ''));
        $reasons = (array)($result['diagnosis']['reasons'] ?? []);
        $this->assertNotEmpty(
            array_filter($reasons, static fn(string $line): bool => stripos($line, 'fully booked') !== false)
        );
    }

    /**
     * Diagnose missing-email questions explains the limited certainty of mail checks.
     */
    public function test_diagnose_missing_email_reports_limitations(): void {
        $option = $this->create_generated_option('Diagnosis Mail');

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Wieso habe ich keine Mail von Diagnosis Mail bekommen?',
            'optionquery' => 'Diagnosis Mail',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame('missing_email', (string)($result['diagnosis']['issue'] ?? ''));
        $this->assertStringContainsString(
            'This self-service check cannot prove whether an email was actually sent or delivered.',
            (string)($result['diagnosis']['reasons'][1] ?? '')
        );
    }

    /**
     * Diagnose output is localized to German when current language is de.
     */
    public function test_diagnose_output_is_localized_for_german(): void {
        $option = $this->create_generated_option('Diagnose Deutsch');

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Warum kann ich mich bei Diagnose Deutsch nicht eintragen?',
            'optionquery' => 'Diagnose Deutsch',
            'outputlang' => 'de',
        ]);

        $this->assertSame('executed', $result['status']);
        $detail = (string)($result['detail'] ?? '');
        $expectedintro = get_string_manager()->get_string(
            'agent_booking_diagnose_intro_checked_option',
            'mod_booking',
            'Diagnose Deutsch',
            'de'
        );
        $this->assertNotEmpty($expectedintro);
        $this->assertIsString($detail);
    }

    /**
     * Validation ambiguity is localized to German when current language is de.
     */
    public function test_validate_ambiguity_is_localized_for_german(): void {
        $task = new diagnose_booking_issue_task();

        $result = $task->validate([
            'question' => 'Warum kann ich mich nicht eintragen?',
            'outputlang' => 'de',
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['ambiguities']);
        $expectedambiguity = get_string_manager()->get_string(
            'agent_booking_diagnose_ambiguity_option_required',
            'mod_booking',
            null,
            'de'
        );
        $this->assertSame($expectedambiguity, (string)$result['ambiguities'][0]);
    }

    /**
     * Diagnose output honors explicit outputlang in task input.
     */
    public function test_diagnose_output_honors_outputlang_override(): void {
        $option = $this->create_generated_option('Diagnose Outputlang');

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Warum kann ich mich bei Diagnose Outputlang nicht eintragen?',
            'optionquery' => 'Diagnose Outputlang',
            'outputlang' => 'de',
        ]);

        $this->assertSame('executed', $result['status']);
        $expectedintro = get_string_manager()->get_string(
            'agent_booking_diagnose_intro_checked_option',
            'mod_booking',
            'Diagnose Outputlang',
            'de'
        );
        $this->assertNotEmpty($expectedintro);
        $this->assertIsString((string)($result['detail'] ?? ''));
    }

    /**
     * Cross-user diagnosis is denied when caller lacks bookforothers capability.
     */
    public function test_cross_user_diagnosis_denied_without_capability(): void {
        $option = $this->create_generated_option('Cross User Booking Denied Option');
        $this->setUser($this->student);
        $task = new diagnose_booking_issue_task();

        $result = $task->execute([
            'question' => 'Kann Maxima in "Cross User Booking Denied Option" buchen?',
            'optionquery' => 'Cross User Booking Denied Option',
            'targetuserid' => (int)$this->teacher->id,
        ], (int)$this->booking->cmid, (int)$this->student->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            get_string('agent_booking_diagnose_other_user_permission_denied', 'mod_booking'),
            (string)($result['detail'] ?? '')
        );
    }

    /**
     * Cross-user diagnosis succeeds for privileged users and uses the requested target user.
     */
    public function test_cross_user_diagnosis_uses_target_user_when_allowed(): void {
        $option = $this->create_generated_option('Cross User Booking Allowed Option');
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Maxima',
            'lastname' => 'Allowed',
            'email' => 'maxima.allowed@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        $result = $this->exec_command('booking.diagnose_booking_issue', [
            'question' => 'Kann Maxima Allowed in "Cross User Booking Allowed Option" buchen?',
            'optionquery' => 'Cross User Booking Allowed Option',
            'targetuserid' => (int)$target->id,
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$target->id, (int)($result['diagnosis']['userid'] ?? 0));
        $this->assertSame((int)$option->id, (int)$result['resultid']);
    }
}
