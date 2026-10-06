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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The duplicate-title question must be fully rendered: no unfilled placeholder reaches the user.
 *
 * Write-path baseline W1 (2026-09-15, threads 1626 CO-3 and 1633 CSB-4, finding W5, #2397): the
 * user_question of DUPLICATE_TITLE_CONFIRM_REQUIRED was built without the $a parameter, so the
 * engine showed "(id={$a})" verbatim. The engine prefers user_question over message, which is
 * why the correctly rendered message never reached the user.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_duplicate_title_user_question_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    /** @var int Id of the pre-existing option whose title is reused. */
    private int $existingid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Duplicate Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => (int)$booking->id, 'text' => 'Pilates am Abend', 'maxanswers' => 10, 'type' => 0,
        ]);
        $this->existingid = (int)$option->id;
    }

    /**
     * Every user-facing field of every issue must be free of unfilled lang placeholders.
     *
     * @param array $issues
     * @return void
     */
    private function assert_no_unfilled_placeholder(array $issues): void {
        $this->assertNotEmpty($issues, 'the duplicate title must raise an issue');
        foreach ($issues as $issue) {
            foreach (['message', 'user_question'] as $field) {
                $text = (string)($issue[$field] ?? '');
                $this->assertStringNotContainsString('{$a', $text, $field . ' carries an unfilled placeholder: ' . $text);
            }
        }
    }

    /**
     * The single-duplicate question names the existing option id instead of "{$a}".
     */
    public function test_create_option_duplicate_question_is_rendered(): void {
        global $USER;
        $dto = (new create_option_skill())->preflight(
            ['text' => 'Pilates am Abend', 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $issues = json_decode(json_encode($dto->issues), true);
        $codes = array_column($issues, 'code');
        $this->assertContains('DUPLICATE_TITLE_CONFIRM_REQUIRED', $codes, json_encode($issues));
        $this->assert_no_unfilled_placeholder($issues);

        foreach ($issues as $issue) {
            if (($issue['code'] ?? '') === 'DUPLICATE_TITLE_CONFIRM_REQUIRED') {
                $this->assertStringContainsString(
                    (string)$this->existingid,
                    (string)($issue['user_question'] ?? ''),
                    'the question must name the existing option id'
                );
            }
        }
    }

    /**
     * The slot skill inherits the duplicate check and must render the same question.
     */
    public function test_create_slotbooking_option_duplicate_question_is_rendered(): void {
        global $USER;
        $dto = (new create_slotbooking_option_skill())->preflight(
            [
                'text' => 'Pilates am Abend',
                'cmid' => $this->cmid,
                'slot_opening_time' => '10:00',
                'slot_closing_time' => '12:00',
                'slot_duration_minutes' => 30,
                'slot_valid_from' => '2030-01-01',
                'slot_valid_until' => '2030-01-31',
                'slot_max_participants_per_slot' => 1,
                'slot_day_2' => true,
            ],
            $this->contextid,
            (int)$USER->id
        );
        $issues = json_decode(json_encode($dto->issues), true);
        $this->assertContains('DUPLICATE_TITLE_CONFIRM_REQUIRED', array_column($issues, 'code'), json_encode($issues));
        $this->assert_no_unfilled_placeholder($issues);
    }
}
