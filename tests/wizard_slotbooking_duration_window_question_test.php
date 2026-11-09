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
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The slot preflight question for a slot duration that equals the daily window is a language string.
 *
 * demo2 thread 24 (2026-11-04, #2764): a German user asked for one bookable option per weekday from
 * 18 to 19 h, the selector took the slot skill, and the preflight stopped with
 * SLOTBOOKING_DURATION_EQUALS_WINDOW rendered as a hard-coded English sentence. The question has to
 * come from the language pack in the requested output language, with the figures filled in.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_slotbooking_duration_window_question_test extends booking_advanced_testcase {
    /** @var string Issue code of the sanity check under test. */
    private const CODE = 'SLOTBOOKING_DURATION_EQUALS_WINDOW';

    /** @var string Language string identifier the question must come from. */
    private const STRING = 'agent_booking_create_slotbooking_duration_equals_window';

    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Slot Window Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Slot request with a daily window of one hour and the given slot duration.
     *
     * @param int $durationminutes
     * @param string $outputlang
     * @return array
     */
    private function slot_input(int $durationminutes, string $outputlang): array {
        return [
            'text' => 'Buchen mit X 1',
            'cmid' => $this->cmid,
            'outputlang' => $outputlang,
            'slot_opening_time' => '18:00',
            'slot_closing_time' => '19:00',
            'slot_duration_minutes' => $durationminutes,
            'slot_max_participants_per_slot' => 5,
            'slot_valid_from' => '2030-03-04',
            'slot_valid_until' => '2030-03-08',
            'slot_day_1' => true,
            'slot_day_2' => true,
            'slot_day_3' => true,
            'slot_day_4' => true,
            'slot_day_5' => true,
        ];
    }

    /**
     * Run the preflight and return the issues as plain arrays.
     *
     * @param array $input
     * @return array
     */
    private function preflight_issues(array $input): array {
        global $USER;
        $dto = (new create_slotbooking_option_skill())->preflight($input, $this->contextid, (int)$USER->id);
        return json_decode(json_encode($dto->issues), true) ?? [];
    }

    /**
     * The issue of the given code, or null.
     *
     * @param array $issues
     * @return array|null
     */
    private function window_issue(array $issues): ?array {
        foreach ($issues as $issue) {
            if (($issue['code'] ?? '') === self::CODE) {
                return $issue;
            }
        }
        return null;
    }

    /**
     * Expected question for the one-hour window in the given language.
     *
     * @param string $lang
     * @return string
     */
    private function expected_question(string $lang): string {
        $a = (object)['slotminutes' => 60, 'opening' => '18:00', 'closing' => '19:00', 'windowminutes' => 60];
        return get_string_manager()->get_string(self::STRING, 'booking', $a, $lang);
    }

    /**
     * A German user gets the German question with the figures filled in.
     */
    public function test_question_is_rendered_in_german(): void {
        $issue = $this->window_issue($this->preflight_issues($this->slot_input(60, 'de')));
        $this->assertNotNull($issue, 'duration == window must raise ' . self::CODE);
        $this->assertTrue(get_string_manager()->string_exists(self::STRING, 'booking'), 'the language string must exist');
        $question = (string)($issue['user_question'] ?? '');
        $this->assertSame($this->expected_question('de'), $question);
        $this->assertStringNotContainsString('{$a', $question);
        $this->assertStringContainsString('18:00', $question);
    }

    /**
     * An English user gets the English question from the same string.
     */
    public function test_question_is_rendered_in_english(): void {
        $issue = $this->window_issue($this->preflight_issues($this->slot_input(60, 'en')));
        $this->assertNotNull($issue, 'duration == window must raise ' . self::CODE);
        $this->assertSame($this->expected_question('en'), (string)($issue['user_question'] ?? ''));
    }

    /**
     * A shorter slot duration raises no such question.
     */
    public function test_shorter_duration_raises_no_question(): void {
        $this->assertNull($this->window_issue($this->preflight_issues($this->slot_input(30, 'de'))));
    }
}
