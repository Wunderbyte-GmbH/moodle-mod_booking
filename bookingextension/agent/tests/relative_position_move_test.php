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
 * Moving an activity within its section: one up, one down, to the top, to the bottom.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\course\skills\update_activity_skill;

/**
 * Tests for the ordering move of course.update_activity.
 *
 * `sectiondelta` moves an activity BETWEEN sections. The other axis — where it sits inside its section — had no
 * field at all, so "move the page up" or "put it at the top" could only be expressed by rebuilding the whole
 * section. Same reasoning as the section delta: the model states the movement, the skill reads the current
 * order and computes the target.
 *
 * @covers \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 */
final class relative_position_move_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The schema offers the ordering field with its four movements.
     */
    public function test_schema_offers_the_ordering_field(): void {
        $this->resetAfterTest();

        $properties = (array)((array)(new update_activity_skill())->get_schema())['properties'];

        $this->assertArrayHasKey('position', $properties);
        $this->assertSame('string', $properties['position']['type']);
        $this->assertSame(['up', 'down', 'top', 'bottom'], $properties['position']['enum']);
        $this->assertEmpty($properties['position']['required'] ?? false);
    }

    /**
     * The target index is computed from the current order of the section.
     *
     * @dataProvider position_provider
     * @param string $move
     * @param int $expected
     */
    public function test_target_index(string $move, int $expected): void {
        $this->resetAfterTest();

        // Section holds four activities; the one we move (55) sits third.
        $order = [11, 22, 55, 77];
        $this->assertSame($expected, update_activity_skill::target_position_index($order, 55, $move));
    }

    /**
     * Movements and where they land for the module at index 2 of four.
     *
     * @return array[]
     */
    public static function position_provider(): array {
        return [
            'up' => ['up', 1],
            'down' => ['down', 3],
            'top' => ['top', 0],
            'bottom' => ['bottom', 3],
        ];
    }

    /**
     * At the edges the movement does nothing rather than falling off the list.
     */
    public function test_edges_are_clamped(): void {
        $this->resetAfterTest();

        $order = [11, 22, 33];
        $this->assertSame(0, update_activity_skill::target_position_index($order, 11, 'up'));
        $this->assertSame(2, update_activity_skill::target_position_index($order, 33, 'down'));
        $this->assertNull(update_activity_skill::target_position_index($order, 99, 'up'));
    }

    /**
     * Structural validation: only the four movements are accepted, and not together with a section move.
     */
    public function test_structure_check_guards_the_position(): void {
        $this->resetAfterTest();

        $skill = new update_activity_skill();

        $this->assertFalse($skill->check_structure(['activityquery' => 'Übungsdaten', 'position' => 'sideways'])['valid']);
        $this->assertTrue($skill->check_structure(['activityquery' => 'Übungsdaten', 'position' => 'top'])['valid']);
        $this->assertTrue($skill->check_structure(['activityquery' => 'Übungsdaten', 'position' => 'down'])['valid']);
    }
}
