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
use mod_booking\local\wizard\options\skills\add_price_category_skill;

/**
 * The name is the first field the constructor sees for a new price category, and its line fits the field window.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\add_price_category_skill
 */
final class wizard_price_category_name_first_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Listed behind the technical key, the group the user named for the category was not read as its name and the
     * constructor asked for one.
     */
    public function test_the_name_is_the_first_field_and_fits_the_constructor_window(): void {
        $skill = new add_price_category_skill();
        $lines = \bookingextension_agent\local\wizard\services\skill_input_schema_projection::for_skill($skill);
        $this->assertStringStartsWith('name (', (string)$lines[0]);
        $properties = (array)($skill->get_schema()['properties'] ?? []);
        foreach (['name', 'identifier'] as $field) {
            $this->assertLessThanOrEqual(159, \core_text::strlen((string)($properties[$field]['description'] ?? '')), $field);
        }
    }
}
