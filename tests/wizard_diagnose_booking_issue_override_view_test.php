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
 * The booking diagnosis describes a condition from the diagnosed person's point of view: the staff wording
 * "you have the right to book anyway" appears only when that person may override conditions, not because
 * the acting user is an admin (Wunderbyte-GmbH#2673).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_override_view_test extends advanced_testcase {
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
     * A course, a booking instance, a basic option and an advanced option that requires the basic one.
     *
     * @return array{0:stdClass,1:int,2:int,3:stdClass} [booking module, basic option id, advanced option id, student]
     */
    private function setup_options(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Override view booking',
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $basic = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'KI im Unterricht - Grundlagen',
            'chooseorcreatecourse' => 0,
            'maxanswers' => 10,
        ]);
        $advanced = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'KI im Unterricht - Aufbaukurs',
            'chooseorcreatecourse' => 0,
            'maxanswers' => 10,
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => [(int)$basic->id],
        ]);
        singleton_service::destroy_instance();
        \cache_helper::purge_all();

        return [$booking, (int)$basic->id, (int)$advanced->id, $student];
    }

    /**
     * The sentence the staff variant adds to the plain condition text, taken from the language strings.
     *
     * @return string
     */
    private function override_sentence(): string {
        $link = (object)['url' => 'https://example.com/option', 'title' => 'Basic'];
        $plain = trim(strip_tags(get_string('bocondpreviouslybookednotavailable', 'mod_booking', $link)));
        $full = trim(strip_tags(get_string('bocondpreviouslybookedfullnotavailable', 'mod_booking', $link)));
        $sentence = trim(str_replace($plain, '', $full));
        $this->assertNotSame('', $sentence, 'the staff variant adds a sentence');
        return $sentence;
    }

    /**
     * Run the diagnosis as admin for the given person on the advanced option.
     *
     * @param stdClass $booking
     * @param int $advancedid
     * @param int $targetuserid
     * @return array
     */
    private function diagnose(stdClass $booking, int $advancedid, int $targetuserid): array {
        global $USER;
        $this->setAdminUser();
        $result = (new diagnose_booking_issue_skill())->execute(
            ['optionid' => $advancedid, 'targetuserid' => $targetuserid, 'issuetype' => 'cannot_book'],
            (int)context_module::instance($booking->cmid)->id,
            (int)$USER->id
        );
        $this->assertSame('executed', $result['status']);
        return $result;
    }

    /**
     * An admin diagnosing a student without override rights gets the condition in the student's wording.
     */
    public function test_student_without_override_right_gets_the_plain_condition(): void {
        [$booking, $basicid, $advancedid, $student] = $this->setup_options();

        $result = $this->diagnose($booking, $advancedid, (int)$student->id);

        $observation = (string)($result['observation_full'] ?? '');
        $reasons = implode("\n", (array)($result['diagnosis']['reasons'] ?? []));
        $this->assertStringContainsString('KI im Unterricht - Grundlagen', $reasons, 'the required option is named');
        $this->assertStringNotContainsString($this->override_sentence(), $reasons);
        $this->assertStringNotContainsString($this->override_sentence(), $observation);
    }

    /**
     * A person who may override conditions gets the staff wording, whoever asks.
     */
    public function test_person_with_override_right_gets_the_staff_wording(): void {
        [$booking, $basicid, $advancedid, $student] = $this->setup_options();
        $context = context_module::instance($booking->cmid);
        $roleid = create_role('Override role', 'overrider', 'May override booking conditions');
        assign_capability('mod/booking:overrideboconditions', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $student->id, $context->id);

        $result = $this->diagnose($booking, $advancedid, (int)$student->id);

        $reasons = implode("\n", (array)($result['diagnosis']['reasons'] ?? []));
        $this->assertStringContainsString('KI im Unterricht - Grundlagen', $reasons);
        $this->assertStringContainsString($this->override_sentence(), $reasons);
    }

    /**
     * A student who has booked the required option is not told the condition as a reason.
     */
    public function test_satisfied_condition_is_not_a_reason(): void {
        [$booking, $basicid, $advancedid, $student] = $this->setup_options();
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_answer(['optionid' => $basicid, 'userid' => (int)$student->id]);
        singleton_service::destroy_booking_answers($basicid);

        $result = $this->diagnose($booking, $advancedid, (int)$student->id);

        $reasons = implode("\n", (array)($result['diagnosis']['reasons'] ?? []));
        $this->assertStringNotContainsString('KI im Unterricht - Grundlagen', $reasons);
    }
}
