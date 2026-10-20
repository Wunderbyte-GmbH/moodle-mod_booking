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
use bookingextension_agent\local\wizard\services\skill_input_schema_projection;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;

/**
 * The dated create skill shows the constructor a location and an address field (Wunderbyte-GmbH/Wunderbyte-GmbH#2678).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_create_option_location_field_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip without the agent extension.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Without the two fields a room named in the request, or stored as a default in the user memory, landed in the
     * description or nowhere while the card promised it; update_option and the other create skills carried it all along.
     *
     * @covers \mod_booking\local\wizard\options\skills\create_option_skill::get_schema
     */
    public function test_location_and_address_are_prompt_facing_on_create_like_on_update(): void {
        $create = new create_option_skill();
        $update = new update_option_skill();
        foreach (['location', 'address'] as $field) {
            foreach ([$create, $update] as $skill) {
                $properties = (array)($skill->get_schema()['properties'] ?? []);
                $this->assertArrayHasKey($field, $properties, $skill->get_name() . '.' . $field);
                $this->assertFalse((bool)($properties[$field]['required'] ?? false), $skill->get_name() . '.' . $field);
                $description = (string)($properties[$field]['description'] ?? '');
                $this->assertLessThanOrEqual(159, \core_text::strlen($description), $skill->get_name() . '.' . $field);
            }
        }

        $lines = skill_input_schema_projection::for_skill($create);
        $names = array_map(static fn(string $line): string => explode(' ', $line)[0], $lines);
        $this->assertContains('location', $names, implode("\n", $lines));
        $this->assertContains('address', $names, implode("\n", $lines));
        // The slimming of the create contract stays: the inherited availability fields are not shown.
        $this->assertLessThanOrEqual(25, count($lines), implode("\n", $lines));
    }
}
