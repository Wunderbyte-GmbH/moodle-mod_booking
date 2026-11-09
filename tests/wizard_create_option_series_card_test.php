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
use bookingextension_agent\local\wizard\skill_registry;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;
use mod_booking\local\wizard\options\skills\option_schema_definition;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The create_option card separates "n separately bookable events" from "one series booked once".
 *
 * demo2 2026-11-04 (#2762): five numbered events, one per weekday, came out as one option with five sessions
 * ("KI in Moodle 1, 2, 3, 4, 5"). The card told the selector "including recurring series" and gave the constructor
 * the family's generic sessions text, so both read n events as the sessions of one option. The card now carries an
 * explicit WHEN line for the selector and scopes the two constructor fields (title, sessions) to one event.
 * Structural checks only: the lines exist, stay within the registry caps and differ from the family defaults.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 */
final class wizard_create_option_series_card_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * The selector gets a WHEN line of its own, within the registry cap, and the IS line stays within its cap.
     */
    public function test_create_option_card_has_a_when_line_within_the_caps(): void {
        $schema = (new create_option_skill())->get_schema();
        $when = trim((string)($schema['when'] ?? ''));
        $this->assertNotSame('', $when, 'create_option must tell the selector when to plan several create steps');
        $this->assertLessThanOrEqual(skill_registry::WHEN_CAP, \core_text::strlen($when));
        $this->assertLessThanOrEqual(skill_registry::DISCRIMINATION_CAP, \core_text::strlen((string)($schema['is'] ?? '')));
        $this->assertLessThanOrEqual(skill_registry::DISCRIMINATION_CAP, \core_text::strlen((string)($schema['not'] ?? '')));
    }

    /**
     * The constructor sees a sessions text scoped to one option, not the family default, and a title text that
     * knows about a running number; both within the projection cut-off of 160 characters.
     */
    public function test_constructor_fields_are_scoped_to_one_event(): void {
        $schema = (new create_option_skill())->get_schema();
        $default = option_schema_definition::common_properties()['optiondates']['description'];
        $sessions = (string)($schema['properties']['optiondates']['description'] ?? '');
        $this->assertNotSame('', $sessions);
        $this->assertNotSame(trim($default), trim($sessions), 'create_option must scope the sessions field itself');
        $this->assertLessThanOrEqual(160, \core_text::strlen($sessions));
        $this->assertLessThanOrEqual(160, \core_text::strlen((string)($schema['properties']['text']['description'] ?? '')));
    }

    /**
     * The slot card fences the weekday case back to create_option and stays within the cap.
     */
    public function test_slot_card_names_create_option_within_the_cap(): void {
        $not = (string)((new create_slotbooking_option_skill())->get_schema()['not'] ?? '');
        $this->assertStringContainsString('create_option', $not);
        $this->assertLessThanOrEqual(skill_registry::DISCRIMINATION_CAP, \core_text::strlen($not));
    }
}
