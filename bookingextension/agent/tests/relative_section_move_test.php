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
 * "One section down" is expressed relative to the current position, not as an absolute number.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\course\skills\update_activity_skill;

/**
 * Tests for the relative section move of course.update_activity.
 *
 * Baseline runs 15 and 16, prompt UA-4 ("Schieb die Seite mit den Übungsdaten einen Abschnitt nach unten"):
 * the schema only had the absolute `section`, so the constructor had to know the current section number. It
 * does not, and it cannot look it up — the selector plans no read step and the constructor may not route. Over
 * five repeats the turn never produced a command; twice the model asked the user which section the activity is
 * in. A relative field removes the dependency: the model states the movement, the skill does the arithmetic on
 * the resolved activity.
 *
 * @covers \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 */
final class relative_section_move_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The schema offers a relative field next to the absolute one.
     */
    public function test_schema_offers_a_relative_section_field(): void {
        $this->resetAfterTest();

        $schema = (array)(new update_activity_skill())->get_schema();
        $properties = (array)($schema['properties'] ?? []);

        $this->assertArrayHasKey('sectiondelta', $properties);
        $this->assertSame('integer', $properties['sectiondelta']['type']);
        $this->assertEmpty($properties['sectiondelta']['required'] ?? false);
    }

    /**
     * A positive delta moves down, a negative one up, counted from the activity's current section.
     *
     * @dataProvider delta_provider
     * @param int $current Current section of the activity.
     * @param int $delta Requested movement.
     * @param int $expected Section the activity must end up in.
     */
    public function test_delta_is_applied_to_the_current_section(int $current, int $delta, int $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, update_activity_skill::target_section_for_delta($current, $delta, 4));
    }

    /**
     * Movements the course cannot satisfy.
     *
     * @return array[]
     */
    public static function delta_provider(): array {
        return [
            'one down' => [1, 1, 2],
            'one up' => [2, -1, 1],
            'two down' => [0, 2, 2],
            'clamped at the top' => [0, -1, 0],
            'clamped at the last section' => [4, 1, 4],
        ];
    }

    /**
     * Structural validation rejects a delta that is not a whole number, and accepts zero as "stay".
     */
    public function test_structure_check_rejects_a_non_integer_delta(): void {
        $this->resetAfterTest();

        $skill = new update_activity_skill();
        $bad = $skill->check_structure(['activityquery' => 'Übungsdaten', 'sectiondelta' => 'down']);
        $this->assertFalse($bad['valid']);

        $good = $skill->check_structure(['activityquery' => 'Übungsdaten', 'sectiondelta' => -1]);
        $this->assertTrue($good['valid'], json_encode($good));
    }

    /**
     * Absolute and relative at the same time is a contradiction the user has to resolve.
     */
    public function test_absolute_and_relative_together_are_rejected(): void {
        $this->resetAfterTest();

        $result = (new update_activity_skill())->check_structure([
            'activityquery' => 'Übungsdaten',
            'section' => 2,
            'sectiondelta' => 1,
        ]);

        $this->assertFalse($result['valid']);
    }
}
