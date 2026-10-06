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
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\options\skills\update_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The optiondates contract must be visible to the constructor, not a rumor.
 *
 * L6-C1: "add an extra date" died terminally because the validator demands
 * optiondates[{coursestarttime, courseendtime}] while the schema never declared the field
 * and the example carried no dates — the model had to guess the shape blind.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 */
final class wizard_optiondates_contract_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * The schema must declare optiondates with its key names spelled out.
     */
    public function test_schema_declares_the_optiondates_shape(): void {
        $schema = (new update_option_skill())->get_schema();
        $props = (array)($schema['properties'] ?? []);

        $this->assertArrayHasKey('optiondates', $props, 'the field the guidance references must exist on the form');
        $description = strtolower((string)($props['optiondates']['description'] ?? ''));
        $this->assertStringContainsString('coursestarttime', $description, 'the key names must be spelled out');
        $this->assertStringContainsString('courseendtime', $description);
        $this->assertArrayHasKey('optiondatesmode', $props, 'append/replace mode must be declared too');
    }

    /**
     * The example input must contain a date range that survives the REAL parser.
     */
    public function test_example_input_dates_survive_the_real_parser(): void {
        $example = (new update_option_skill())->get_example_input();

        $this->assertArrayHasKey('optiondates', $example, 'the example must anchor the date shape');
        // Wave 30 (UO-3): the example shows a PLACEHOLDER, never a copyable time. The shape stays pinned (#2272): the
        // keys are the parser's keys, and a real value in the placeholder's format survives the real parser.
        $range = (array)$example['optiondates'][0];
        $this->assertSame(['coursestarttime', 'courseendtime'], array_keys($range));
        $this->assertSame('YYYY-MM-DD HH:MM', $range['coursestarttime'], 'a placeholder, not a time');
        $example['optiondates'] = [[
            'coursestarttime' => str_replace(['YYYY', 'MM-DD', 'HH:MM'], ['2046', '09-30', '18:00'], $range['coursestarttime']),
            'courseendtime' => str_replace(['YYYY', 'MM-DD', 'HH:MM'], ['2046', '09-30', '20:00'], $range['courseendtime']),
        ]];
        $parsed = booking_skill_support::extract_optiondates($example);
        $this->assertNotEmpty($parsed, 'the example shape must be exactly what extract_optiondates accepts');
        $this->assertGreaterThan(0, (int)$parsed[0]['coursestarttime']);
        $this->assertGreaterThan((int)$parsed[0]['coursestarttime'], (int)$parsed[0]['courseendtime']);
    }
}
