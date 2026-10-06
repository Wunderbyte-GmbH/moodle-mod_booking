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
 * "A week later" is expressed relative to the existing sessions, not as an absolute timestamp.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wizard\options\skills;

/**
 * Tests for the relative date shift of mod_booking.update_option.
 *
 * Baseline run 15, prompt UO-1 ("The Töpferkurs moved — new venue is Room 12, and push the start back a week"):
 * the schema only accepted absolute timestamps, so the constructor asked the user for the current start date —
 * data that sits in the database and that the model cannot see. Same failure shape as UA-4 with sections.
 *
 * @covers \mod_booking\local\wizard\options\skills\update_option_skill
 */
final class wizard_relative_date_shift_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The schema offers the relative field.
     */
    public function test_schema_offers_a_relative_shift_field(): void {
        $this->resetAfterTest();

        $properties = (array)((array)(new update_option_skill())->get_schema())['properties'];

        $this->assertArrayHasKey('shiftdays', $properties);
        $this->assertSame('integer', $properties['shiftdays']['type']);
        $this->assertEmpty($properties['shiftdays']['required'] ?? false);
    }

    /**
     * Every session moves by the same number of days, start and end alike.
     */
    public function test_sessions_move_by_whole_days(): void {
        $this->resetAfterTest();

        $sessions = [
            (object)['coursestarttime' => 1790000000, 'courseendtime' => 1790007200],
            (object)['coursestarttime' => 1790600000, 'courseendtime' => 1790607200],
        ];

        $shifted = update_option_skill::shifted_sessions($sessions, 7);

        $this->assertCount(2, $shifted);
        $this->assertSame(1790000000 + 7 * DAYSECS, (int)$shifted[0]['coursestarttime']);
        $this->assertSame(1790007200 + 7 * DAYSECS, (int)$shifted[0]['courseendtime']);
        $this->assertSame(1790600000 + 7 * DAYSECS, (int)$shifted[1]['coursestarttime']);
    }

    /**
     * A negative shift moves the sessions earlier.
     */
    public function test_negative_shift_moves_earlier(): void {
        $this->resetAfterTest();

        $shifted = update_option_skill::shifted_sessions(
            [(object)['coursestarttime' => 1790000000, 'courseendtime' => 1790007200]],
            -3
        );

        $this->assertSame(1790000000 - 3 * DAYSECS, (int)$shifted[0]['coursestarttime']);
    }

    /**
     * Sessions without a usable start are left out rather than moved to 1970.
     */
    public function test_sessions_without_a_start_are_skipped(): void {
        $this->resetAfterTest();

        $shifted = update_option_skill::shifted_sessions(
            [(object)['coursestarttime' => 0, 'courseendtime' => 0]],
            7
        );

        $this->assertSame([], $shifted);
    }

    /**
     * Structural validation: a shift must be a whole number and must not be combined with explicit dates.
     */
    public function test_structure_check_guards_the_shift(): void {
        $this->resetAfterTest();

        $skill = new update_option_skill();

        $this->assertFalse($skill->check_structure(['optionquery' => 'Töpferkurs', 'shiftdays' => 'a week'])['valid']);
        $this->assertFalse($skill->check_structure([
            'optionquery' => 'Töpferkurs',
            'shiftdays' => 7,
            'optiondates' => [['coursestarttime' => '2026-10-01 18:00', 'courseendtime' => '2026-10-01 20:00']],
        ])['valid']);
        $this->assertTrue($skill->check_structure(['optionquery' => 'Töpferkurs', 'shiftdays' => 7])['valid']);
    }
}
