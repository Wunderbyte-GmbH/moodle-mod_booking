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
use mod_booking\local\wizard\options\skills\book_users_skill;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;

/**
 * The option reference is the first field the constructor sees in the skills that book, staff or diagnose an option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\book_users_skill
 * @covers \mod_booking\local\wizard\options\skills\update_option_trainer_skill
 * @covers \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 * @covers \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 */
final class wizard_option_reference_first_field_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Listed behind the activity field (or the id, or the question), a short option name was read as the activity
     * and the constructor asked which option was meant instead of passing the name on.
     */
    public function test_the_option_reference_is_the_first_field_and_fits_the_constructor_window(): void {
        $skills = [
            new book_users_skill(),
            new update_option_trainer_skill(),
            new diagnose_cancellation_issue_skill(),
            new diagnose_booking_issue_skill(),
        ];
        foreach ($skills as $skill) {
            $lines = \bookingextension_agent\local\wizard\services\skill_input_schema_projection::for_skill($skill);
            $this->assertStringStartsWith('optionquery (', (string)$lines[0], $skill->get_name());
            $description = (string)$skill->get_schema()['properties']['optionquery']['description'];
            $this->assertLessThanOrEqual(159, \core_text::strlen($description), $skill->get_name());
        }
    }
}
