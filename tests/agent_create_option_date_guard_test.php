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
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\booking\support\booking_mutation_validation;
use mod_booking\local\wizard\options\skills\create_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Guards against the silent date coercion of thread 545.
 *
 * The constructor delivered optiondates items with alias keys (timestart/timeend) and bare
 * clock strings ("10:00"/"18:00"): normalization silently dropped the items to [], the empty
 * key disabled the datetime validation, and parse_datetime anchored the clock strings to
 * TODAY — five wrong sessions, one confirm click away, invisible in the preview. Each hole
 * is pinned here: unknown item keys reject, an empty optiondates key no longer disables the
 * checks, and a bare clock time is refused instead of coerced.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\booking\support\booking_mutation_validation
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support::parse_datetime
 */
final class agent_create_option_date_guard_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Unknown keys inside optiondates items reject with the schema-mismatch repair path
     * instead of being silently dropped by alias normalization (thread 545 hole 1).
     */
    public function test_unknown_optiondate_item_keys_reject(): void {
        $this->resetAfterTest();
        $skill = new create_option_skill();

        $result = $skill->check_structure([
            'text' => 'Tag 1',
            'maxanswers' => 20,
            'optiondates' => [['timestart' => 1749808800, 'timeend' => 1749837600]],
        ]);

        $this->assertFalse($result['valid']);
        $observation = (string)($result['observation_full'] ?? implode(' ', (array)$result['errors']));
        $this->assertStringContainsString('optiondates[].timestart', $observation);
        $this->assertStringContainsString('optiondates[].timeend', $observation);
    }

    /**
     * Canonical and alias item keys keep passing (the guard only rejects genuinely unknown keys).
     */
    public function test_canonical_and_alias_optiondate_items_pass(): void {
        $this->resetAfterTest();
        $skill = new create_option_skill();

        $result = $skill->check_structure([
            'text' => 'Tag 1',
            'optiondates' => [
                ['coursestarttime' => '2045-11-10T10:00:00', 'courseendtime' => '2045-11-10T18:00:00'],
                ['starttime' => '2045-11-11T10:00:00', 'endtime' => '2045-11-11T18:00:00'],
            ],
        ]);

        $this->assertTrue($result['valid'], implode(' ', (array)$result['errors']));
    }

    /**
     * An optiondates key holding an EMPTY array no longer disables the coursestarttime/
     * courseendtime validation (thread 545 hole 2) — combined with hole 3 the bare clock
     * strings now surface as invalid dates.
     */
    public function test_empty_optiondates_does_not_disable_datetime_validation(): void {
        $this->resetAfterTest();

        $validation = booking_mutation_validation::validate_common([
            'text' => 'Tag 1',
            'optiondates' => [],
            'coursestarttime' => '10:00',
            'courseendtime' => '18:00',
        ], 0, 'mod_booking.create_option');
        $errors = (array)($validation['errors'] ?? []);

        $this->assertNotEmpty($errors, 'Empty optiondates must not bypass the datetime checks.');
        $this->assertStringContainsString(
            get_string('agent_validation_coursestarttime_invalid', 'booking'),
            implode(' ', $errors)
        );
    }

    /**
     * A bare clock time has no date and is refused instead of being anchored to TODAY
     * (thread 545 hole 3); real datetimes and relative phrases keep parsing.
     */
    public function test_parse_datetime_refuses_bare_clock_times(): void {
        $this->assertFalse(booking_skill_support::parse_datetime('10:00'));
        $this->assertFalse(booking_skill_support::parse_datetime('9:05'));
        $this->assertFalse(booking_skill_support::parse_datetime('18:00:30'));

        $this->assertNotFalse(booking_skill_support::parse_datetime('2045-11-10T10:00:00'));
        $this->assertNotFalse(booking_skill_support::parse_datetime('next monday 10:00'));
        $this->assertNotFalse(booking_skill_support::parse_datetime(1749808800));
    }
}
