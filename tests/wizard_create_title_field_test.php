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
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;

/**
 * The three create skills describe the title the same way, and the line fits the constructor's field window.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 * @covers \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_create_title_field_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Described differently from its sisters, the dated create skill left the constructor asking for a title the
     * request already carried as the user's word for the offer.
     */
    public function test_the_title_field_is_described_identically_and_fits_the_constructor_window(): void {
        $skills = [
            new create_selflearning_option_skill(),
            new create_slotbooking_option_skill(),
            new create_option_skill(),
        ];
        $descriptions = [];
        foreach ($skills as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('text', $properties, $skill->get_name());
            $this->assertTrue((bool)($properties['text']['required'] ?? false), $skill->get_name());
            $description = (string)($properties['text']['description'] ?? '');
            $this->assertLessThanOrEqual(159, \core_text::strlen($description), $skill->get_name());
            $descriptions[$skill->get_name()] = $description;
            $lines = \bookingextension_agent\local\wizard\services\skill_input_schema_projection::for_skill($skill);
            $this->assertStringStartsWith('text (', (string)$lines[0], $skill->get_name());
        }
        $this->assertCount(1, array_unique(array_values($descriptions)), json_encode($descriptions));
    }
}
