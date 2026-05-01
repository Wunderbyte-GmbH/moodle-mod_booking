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

use mod_booking\local\wbagent\booking\tasks\diagnose_cancellation_issue_task;
use mod_booking\singleton_service;

/**
 * Tests for booking.diagnose_cancellation_issue.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wbagent\booking\tasks\diagnose_cancellation_issue_task
 */
final class diagnose_cancellation_issue_task_test extends abstract_agent_testcase {
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
     * Validate asks follow-up question when option reference is missing.
     */
    public function test_validate_requests_option_reference(): void {
        $task = new diagnose_cancellation_issue_task();

        $result = $task->validate([
            'question' => 'Why can I not cancel?',
        ], (int)$this->booking->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['ambiguities']);
        $this->assertStringContainsString('Which booking option do you mean?', (string)$result['ambiguities'][0]);
    }

    /**
     * Diagnose reports instance cancancelbook disabled as a concrete blocker.
     */
    public function test_diagnose_reports_instance_cancancelbook_disabled(): void {
        global $DB;

        $option = $this->create_generated_option('Cancel Lock Option');
        $this->book_user_in_option((int)$this->teacher->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'Why can I not cancel my booking in option "Cancel Lock Option"?',
            'optionquery' => 'Cancel Lock Option',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$option->id, (int)$result['resultid']);
        $this->assertSame('cannot_cancel', (string)($result['diagnosis']['issue'] ?? ''));
        $this->assertContains((int)$option->id, (array)($result['previewoptionids'] ?? []));

        $reasons = (array)($result['diagnosis']['reasons'] ?? []);
        $this->assertNotEmpty(
            array_filter($reasons, static fn(string $line): bool => str_contains($line, 'cancancelbook'))
        );
        $this->assertSame(0, (int)($result['diagnosis']['stats']['instance_cancancelbook_value'] ?? -1));
    }

    /**
     * Diagnose marks not-booked users with explicit state reason.
     */
    public function test_diagnose_reports_not_booked_state(): void {
        global $DB;

        $option = $this->create_generated_option('Cancel Not Booked Option');
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'Why can I not cancel my booking in Cancel Not Booked Option?',
            'optionquery' => 'Cancel Not Booked Option',
        ]);

        $this->assertSame('executed', $result['status']);
        $reasons = (array)($result['diagnosis']['reasons'] ?? []);
        $this->assertNotEmpty(
            array_filter($reasons, static fn(string $line): bool => str_contains($line, 'bookinginformation.notbooked is set'))
        );
    }

    /**
     * Cross-user diagnosis is denied when caller lacks bookforothers capability.
     */
    public function test_cross_user_diagnosis_denied_without_capability(): void {
        $option = $this->create_generated_option('Cross User Denied Option');
        $this->setUser($this->student);
        $task = new diagnose_cancellation_issue_task();

        $result = $task->execute([
            'question' => 'Why can this user not cancel?',
            'optionquery' => 'Cross User Denied Option',
            'targetuserid' => (int)$this->teacher->id,
        ], (int)$this->booking->cmid, (int)$this->student->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            get_string('agent_booking_diagnose_cancel_other_user_permission_denied', 'mod_booking'),
            (string)($result['detail'] ?? '')
        );
    }

    /**
     * Cross-user diagnosis succeeds for privileged users and uses the requested target user.
     */
    public function test_cross_user_diagnosis_uses_target_user_when_allowed(): void {
        global $DB;

        $option = $this->create_generated_option('Cross User Allowed Option');
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teachy',
            'email' => 'billy.teachy@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');
        $this->book_user_in_option((int)$target->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'Why can Billy Teachy not cancel?',
            'optionquery' => 'Cross User Allowed Option',
            'targetuserid' => (int)$target->id,
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$target->id, (int)($result['diagnosis']['userid'] ?? 0));
    }

    /**
     * Direct task execution with explicit target user id is deterministic.
     */
    public function test_direct_execute_with_explicit_target_userid_is_deterministic(): void {
        global $DB;

        $option = $this->create_generated_option('Fallback User Option');
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Billyfallbackuniq',
            'lastname' => 'Teachy',
            'email' => 'billy.fallback@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');
        $this->book_user_in_option((int)$target->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();
        $task = new diagnose_cancellation_issue_task();

        $result = $task->execute([
            'question' => 'Why can this user not cancel in Fallback User Option?',
            'optionquery' => 'Fallback User Option',
            'targetuserid' => (int)$target->id,
        ], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$target->id, (int)($result['diagnosis']['userid'] ?? 0));
    }

    /**
     * Reasons no longer expose higher-condition technical hints.
     */
    public function test_reasons_do_not_include_higher_condition_hints(): void {
        global $DB;

        $option = $this->create_generated_option('No Condition Hint Option');
        $this->book_user_in_option((int)$this->teacher->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'Why can I not cancel my booking in No Condition Hint Option?',
            'optionquery' => 'No Condition Hint Option',
        ]);

        $this->assertSame('executed', $result['status']);
        $reasons = implode("\n", (array)($result['diagnosis']['reasons'] ?? []));
        $this->assertStringNotContainsString('Highest blocking condition', $reasons);
        $this->assertStringNotContainsString('Condition-ID', $reasons);
        $this->assertStringNotContainsString('blockierende Condition', $reasons);
    }

    /**
     * Internal LLM-facing concrete hints stay English even with German output language.
     */
    public function test_concrete_hints_are_english_for_outputlang_de(): void {
        global $DB;

        $option = $this->create_generated_option('English Hint Option');
        $this->book_user_in_option((int)$this->teacher->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'Warum kann ich bei English Hint Option nicht stornieren?',
            'optionquery' => 'English Hint Option',
            'outputlang' => 'de',
        ]);

        $this->assertSame('executed', $result['status']);
        $reasons = implode("\n", (array)($result['diagnosis']['reasons'] ?? []));
        $this->assertStringContainsString('Concrete setting: booking.cancancelbook != 1.', $reasons);
        $this->assertStringNotContainsString('Konkretes Setting:', $reasons);
    }

    /**
     * Option lookup works even when privacy display marker is present in optionquery.
     */
    public function test_optionquery_with_privacy_marker_is_resolved(): void {
        global $DB;

        $option = $this->create_generated_option('Lesung mit Georg');
        $this->book_user_in_option((int)$this->teacher->id, (int)$option->id);
        $DB->set_field('booking', 'cancancelbook', 0, ['id' => (int)$this->booking->id]);
        singleton_service::destroy_instance();

        $result = $this->exec_command('booking.diagnose_cancellation_issue', [
            'question' => 'findest du Lesung mit Georg? Kann ich da buchen?',
            'optionquery' => 'Lesung mit Georg 👤',
        ]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$option->id, (int)$result['resultid']);
        $this->assertSame('cannot_cancel', (string)($result['diagnosis']['issue'] ?? ''));
    }
}
