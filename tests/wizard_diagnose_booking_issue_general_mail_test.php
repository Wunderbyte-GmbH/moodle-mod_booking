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
 * A mail question about a person without a named option is answered by the person-level notification check,
 * not with "which option do you mean?" (Wunderbyte-GmbH#2674); booking-specific issues still need the option.
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_general_mail_test extends advanced_testcase {
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
     * A booking instance with one option and a student who switched all e-mail off.
     *
     * @return array{0:stdClass,1:stdClass,2:stdClass} [booking, option, student]
     */
    private function setup_booking(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['emailstop' => 1, 'email' => 'nomail.student@example.com']);
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Mail booking']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Mail option',
            'chooseorcreatecourse' => 0,
            'maxanswers' => 5,
        ]);
        return [$booking, $option, $student];
    }

    /**
     * No option named, question about mail: preflight passes and execute returns the notification check for
     * the person (emailstop named), with the person declared for the anonymizer.
     */
    public function test_mail_question_without_option_runs_the_notification_check(): void {
        global $USER;
        [$booking, $option, $student] = $this->setup_booking();
        $contextid = (int)context_module::instance($booking->cmid)->id;
        $skill = new diagnose_booking_issue_skill();

        $dto = $skill->preflight(
            ['question' => 'Warum bekommt sie keine E-Mails?', 'userquery' => 'nomail.student@example.com'],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertTrue((bool)($dto->preparedinput['generalmailcheck'] ?? false));

        $result = $skill->execute((array)$dto->preparedinput, $contextid, (int)$USER->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertSame([(int)$student->id], $result['observation_persons']);
        $this->assertStringContainsString('User disabled all e-mail', (string)$result['observation_full']);
        $this->assertStringNotContainsString(
            get_string('agent_booking_diagnose_ambiguity_option_required', 'booking'),
            (string)$result['observation_full']
        );
        $this->assertTrue((bool)($result['diagnosis']['generalmailcheck'] ?? false));
    }

    /**
     * A booking-specific issue without an option still asks which option is meant.
     */
    public function test_cannot_book_without_option_still_asks_for_the_option(): void {
        global $USER;
        [$booking, $option, $student] = $this->setup_booking();

        $dto = (new diagnose_booking_issue_skill())->preflight(
            ['question' => 'Warum kann sie nicht buchen?', 'userquery' => 'nomail.student@example.com', 'issue' => 'cannot_book'],
            (int)context_module::instance($booking->cmid)->id,
            (int)$USER->id
        );

        $this->assertNotSame('pass', $dto->to_array()['status']);
        $this->assertContains('OPTION_REFERENCE_REQUIRED', (array)($dto->to_array()['issue_codes'] ?? []));
    }
}
