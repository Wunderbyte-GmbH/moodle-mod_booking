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
 * The course skills fence off their booking neighbours and declare when to choose them.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\course\skills\analyze_course_structure_skill;
use bookingextension_agent\local\wizard\course\skills\create_course_skill;
use bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill;

/**
 * A question about what a named course contains reads like a booking option on a booking page, and
 * "make X a complete course" reads like creating one; the cards say which skill owns each case.
 *
 * @covers \bookingextension_agent\local\wizard\course\skills\analyze_course_structure_skill
 * @covers \bookingextension_agent\local\wizard\course\skills\scaffold_course_content_skill
 * @covers \bookingextension_agent\local\wizard\course\skills\create_course_skill
 */
final class course_skill_card_fences_test extends \advanced_testcase {
    /** @var int Characters the selector card shows of a NOT line. */
    private const NOT_CAP = 160;

    /** @var int Characters the selector card shows of a WHEN line. */
    private const WHEN_CAP = 180;

    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The course analysis names the option-details skill that reads like it on a booking page, but not the option
     * search: naming the search in this fence pulled plain "which options are there" requests away from it.
     */
    public function test_the_course_analysis_fences_off_the_option_details_skill_only(): void {
        $not = (string)((new analyze_course_structure_skill())->get_schema()['not'] ?? '');

        $this->assertStringContainsString('mod_booking.get_option_details', $not);
        $this->assertStringNotContainsString('mod_booking.search_options', $not);
        $this->assertLessThanOrEqual(self::NOT_CAP, \core_text::strlen($not), $not);
    }

    /**
     * The course analysis says what it answers, including the hidden parts, instead of borrowing its trigger.
     */
    public function test_the_course_analysis_declares_when_to_choose_it(): void {
        $when = trim((string)((new analyze_course_structure_skill())->get_schema()['when'] ?? ''));

        $this->assertNotSame('', $when);
        $this->assertMatchesRegularExpression('/hidden/i', $when);
        $this->assertLessThanOrEqual(self::WHEN_CAP, \core_text::strlen($when), $when);
    }

    /**
     * Filling a named course and creating a new one each declare their situation, and the new-course
     * card hands the "make a named course complete" case to the scaffold skill.
     */
    public function test_scaffold_and_create_declare_when_to_choose_them(): void {
        $scaffold = trim((string)((new scaffold_course_content_skill())->get_schema()['when'] ?? ''));
        $create = trim((string)((new create_course_skill())->get_schema()['when'] ?? ''));

        $this->assertMatchesRegularExpression('/complete|ready-made/i', $scaffold);
        $this->assertLessThanOrEqual(self::WHEN_CAP, \core_text::strlen($scaffold), $scaffold);
        $this->assertMatchesRegularExpression('/new/i', $create);
        $this->assertStringContainsString('scaffold_course_content', $create);
        $this->assertLessThanOrEqual(self::WHEN_CAP, \core_text::strlen($create), $create);
    }
}
