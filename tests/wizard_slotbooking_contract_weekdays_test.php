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
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The slot skill's prompt contract exposes every weekday flag the constructor has to fill.
 *
 * Write-path baseline W1 (2026-09-15, thread 1630 CSB-1, finding W6, #2399): "Tuesdays and
 * Thursdays" produced slot_day_2=true, slot_day_4=false. The persistence path is correct; the
 * contract did not list a single weekday in minimal_input and its example contradicted the base
 * example, so the constructor had no structural cue to set the second day.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_slotbooking_contract_weekdays_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * All seven slot_day_N keys are part of minimal_input and of the example, with boolean values.
     */
    public function test_contract_lists_every_weekday_flag(): void {
        $contract = (new create_slotbooking_option_skill())->get_prompt_contract()->to_array();
        $minimal = (array)($contract['minimal_input'] ?? []);
        $example = (array)($contract['example_input'] ?? []);

        for ($day = 1; $day <= 7; $day++) {
            $key = 'slot_day_' . $day;
            $this->assertContains($key, $minimal, $key . ' must be part of minimal_input');
            $this->assertArrayHasKey($key, $example, $key . ' must be part of the example');
            $this->assertIsBool($example[$key], $key . ' example value must be boolean');
        }
        $this->assertContains(true, $example, 'the example must show at least one active day');
        $this->assertContains(false, $example, 'the example must show at least one inactive day');
    }

    /**
     * Every weekday flag stays a declared schema property, so the contract never names a ghost field.
     */
    public function test_weekday_flags_are_schema_properties(): void {
        $schema = (new create_slotbooking_option_skill())->get_schema();
        $properties = (array)($schema['properties'] ?? []);
        for ($day = 1; $day <= 7; $day++) {
            $this->assertArrayHasKey('slot_day_' . $day, $properties);
        }
    }
}
