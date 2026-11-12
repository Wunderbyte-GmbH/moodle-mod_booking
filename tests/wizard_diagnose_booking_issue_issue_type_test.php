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

use advanced_testcase;
use context_module;
use stdClass;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;

/**
 * The issue the booking diagnosis checks comes from the explicit 'issue' field only; without it every check
 * runs. The question text is never read for it (Wunderbyte-GmbH#2675: no keyword lists).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_issue_type_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * A course, a booking instance, one option and an enrolled student who is not booked.
     *
     * @return array{0:stdClass,1:int,2:stdClass} [booking module, option id, student]
     */
    private function setup_option(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Issue type booking',
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Issue type option',
            'chooseorcreatecourse' => 0,
            'maxanswers' => 5,
        ]);
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        return [$booking, (int)$option->id, $student];
    }

    /**
     * Run the diagnosis as admin for the student with the given input.
     *
     * @param stdClass $booking
     * @param int $optionid
     * @param stdClass $student
     * @param array $input
     * @return array
     */
    private function diagnose(stdClass $booking, int $optionid, stdClass $student, array $input): array {
        global $USER;
        $this->setAdminUser();
        $result = (new diagnose_booking_issue_skill())->execute(
            $input + ['optionid' => $optionid, 'targetuserid' => (int)$student->id],
            (int)context_module::instance($booking->cmid)->id,
            (int)$USER->id
        );
        $this->assertSame('executed', $result['status']);
        return $result;
    }

    /**
     * An explicit issue is honoured as before: the mail check alone leaves the status line out.
     */
    public function test_explicit_issue_is_honoured(): void {
        [$booking, $optionid, $student] = $this->setup_option();
        $statusline = get_string('agent_booking_diagnose_reason_status_notbooked_other', 'booking');

        $mail = $this->diagnose($booking, $optionid, $student, ['issue' => 'missing_email', 'question' => 'x']);
        $this->assertSame('missing_email', $mail['diagnosis']['issue']);
        $this->assertNotContains($statusline, $mail['diagnosis']['reasons']);

        $status = $this->diagnose($booking, $optionid, $student, ['issue' => 'booking_status', 'question' => 'x']);
        $this->assertSame('booking_status', $status['diagnosis']['issue']);
        $this->assertContains($statusline, $status['diagnosis']['reasons']);
    }

    /**
     * Without an issue the wording of the question changes nothing: a German "kann nicht buchen", a mail
     * question and a status question all run every check and give the same reasons.
     */
    public function test_without_issue_every_check_runs_whatever_the_wording(): void {
        [$booking, $optionid, $student] = $this->setup_option();
        $statusline = get_string('agent_booking_diagnose_reason_status_notbooked_other', 'booking');

        $german = $this->diagnose($booking, $optionid, $student, ['question' => 'Warum kann sie nicht buchen?']);
        $mail = $this->diagnose($booking, $optionid, $student, ['question' => 'Why did she get no confirmation mail?']);
        $plain = $this->diagnose($booking, $optionid, $student, ['question' => 'Why am I not booked?']);

        foreach ([$german, $mail, $plain] as $result) {
            $this->assertSame('', $result['diagnosis']['issue']);
            $this->assertContains($statusline, $result['diagnosis']['reasons']);
            $this->assertStringContainsString('(all checks)', (string)$result['observation_full']);
        }
        $this->assertSame($german['diagnosis']['reasons'], $mail['diagnosis']['reasons']);
        $this->assertSame($german['diagnosis']['reasons'], $plain['diagnosis']['reasons']);
    }

    /**
     * The field text tells the constructor what to pass and fits the constructor window.
     */
    public function test_issue_field_text_names_the_three_values_and_fits(): void {
        $schema = (new diagnose_booking_issue_skill())->get_schema();
        $description = (string)($schema['properties']['issue']['description'] ?? '');
        foreach (['missing_email', 'cannot_book', 'booking_status'] as $value) {
            $this->assertStringContainsString($value, $description);
        }
        $this->assertLessThanOrEqual(159, strlen($description));
    }
}
