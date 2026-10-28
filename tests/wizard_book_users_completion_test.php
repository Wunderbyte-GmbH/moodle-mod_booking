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
use mod_booking\local\wizard\options\skills\book_users_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;

/**
 * The completion flag of book_users marks the booking completed, and update_option accepts the bookusersquery
 * field it advertises (Wunderbyte-GmbH#2620).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_book_users_completion_test extends advanced_testcase {
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
     * bookuserscompleted = true books the person and sets completed = 1 on the answer, whatever second the
     * booking was written in (the old lookup by timebooked matched only within the same second).
     */
    public function test_completed_flag_marks_the_booking_completed(): void {
        global $DB, $USER;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Completion booking',
            'eventtype' => 'Webinar',
            'bookingmanager' => 'admin',
        ]);
        $contextid = (int)context_module::instance((int)$booking->cmid)->id;
        $student = $this->getDataGenerator()->create_user(['email' => 'completed.student@example.com']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => (int)$booking->id,
            'text' => 'Completion option',
            'maxanswers' => 5,
            'type' => 0,
        ]);

        $skill = new book_users_skill();
        $dto = $skill->preflight(
            ['optionid' => (int)$option->id, 'bookusersquery' => 'completed.student@example.com', 'bookuserscompleted' => true],
            $contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $result = $skill->execute((array)$dto->preparedinput, $contextid, (int)$USER->id);

        $this->assertSame('executed', (string)($result['status'] ?? ''), (string)($result['detail'] ?? ''));
        $this->assertEmpty($result['issue_codes'] ?? [], json_encode($result['issue_codes'] ?? []));
        $answer = $DB->get_record(
            'booking_answers',
            ['optionid' => (int)$option->id, 'userid' => (int)$student->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(1, (int)$answer->completed);
        $this->assertGreaterThan(0, (int)$answer->completeddate);
    }

    /**
     * update_option advertises bookusersquery and must not reject it with the bulk skill's text.
     */
    public function test_update_option_accepts_the_bookusersquery_field(): void {
        $result = (new update_option_skill())->check_structure(['optionid' => 5, 'bookusersquery' => 'anna@example.com']);

        $this->assertTrue((bool)($result['valid'] ?? false), implode(' | ', (array)($result['errors'] ?? [])));
    }
}
