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
 * A slot booking needs no validity dates: without them the slots are bookable from now on for a year.
 *
 * Wave-30 Nachlauf 34 (CSB-1 "bis Ende Oktober", CSB-4 no period at all): the structure check demanded
 * slot_valid_from or slot_valid_until and the field descriptions did not say what leaving them out means,
 * so the construction either asked for a start the user never meant to give or (run 39) invented one.
 * slot_availability treats an empty valid_from as "from now" and an empty valid_until as one year ahead;
 * the contract now says so and the check no longer demands a date (George 2026-09-25).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_slotbooking_open_validity_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * A complete slot request without any validity date.
     *
     * @return array
     */
    private function input_without_dates(): array {
        return [
            'text' => 'Beratungsgespräche',
            'slot_opening_time' => '10:00',
            'slot_closing_time' => '16:00',
            'slot_duration_minutes' => 45,
            'slot_max_participants_per_slot' => 1,
            'slot_day_1' => true,
            'slot_day_2' => true,
            'slot_day_3' => true,
            'slot_day_4' => true,
            'slot_day_5' => true,
            'slot_day_6' => false,
            'slot_day_7' => false,
        ];
    }

    /**
     * Without validity dates the structure is valid.
     */
    public function test_no_validity_date_is_valid(): void {
        $result = (new create_slotbooking_option_skill())->check_structure($this->input_without_dates());
        $this->assertTrue((bool)($result['valid'] ?? false), json_encode($result['errors'] ?? []));
    }

    /**
     * Only an end date ("bis Ende Oktober") is valid as well.
     */
    public function test_an_end_date_alone_is_valid(): void {
        $input = $this->input_without_dates() + ['slot_valid_until' => '2026-10-31'];
        $result = (new create_slotbooking_option_skill())->check_structure($input);
        $this->assertTrue((bool)($result['valid'] ?? false), json_encode($result['errors'] ?? []));
    }

    /**
     * The field descriptions say what leaving a date out means, so the construction neither asks nor invents.
     */
    public function test_descriptions_state_what_an_omitted_date_means(): void {
        $properties = (array)((new create_slotbooking_option_skill())->get_schema()['properties'] ?? []);
        $from = strtolower((string)($properties['slot_valid_from']['description'] ?? ''));
        $until = strtolower((string)($properties['slot_valid_until']['description'] ?? ''));
        $this->assertStringContainsString('leave it out', $from);
        $this->assertStringContainsString('from now on', $from);
        $this->assertStringContainsString('leave it out', $until);
        $this->assertStringContainsString('one year', $until);
    }
}
