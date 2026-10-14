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
use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;
use bookingextension_agent\local\wizard\course\skills\add_activity_skill;
use bookingextension_agent\local\wizard\course\skills\create_course_skill;
use bookingextension_agent\local\wizard\course\skills\diagnose_user_in_course_skill;
use bookingextension_agent\local\wizard\course\skills\enrol_user_skill;
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;
use bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill;
use bookingextension_agent\local\wizard\wizard\skills\list_skills_skill;
use bookingextension_agent\local\wizard\wizard\skills\remember_skill;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\book_users_skill;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;
use mod_booking\local\wizard\options\skills\get_option_details_skill;
use mod_booking\local\wizard\options\skills\list_option_properties_skill;
use mod_booking\local\wizard\options\skills\search_options_skill;

/**
 * The field descriptions of the shortened skills fit the constructor window whole (Wunderbyte-GmbH/Wunderbyte-GmbH#2582).
 *
 * The constructor prompt cuts every field description after 159 characters (skill_input_schema_projection). These
 * skills had descriptions over that window; each was shortened to a complete statement and the change was A/B-tested on
 * recorded constructor calls with the production model before it was taken (no case worse by two or more). Structural
 * invariant only (length), no wording check.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 * @covers     \mod_booking\local\wizard\options\skills\book_users_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @covers     \mod_booking\local\wizard\options\skills\get_option_details_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\enrol_user_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\create_course_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\list_skills_skill
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\diagnose_user_in_course_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\remember_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_activity_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_option_properties_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class wizard_field_descriptions_fit_constructor_window_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** Longest field description the constructor shows whole (skill_input_schema_projection cuts at 160). */
    private const WINDOW = 160;

    /**
     * Load the engine aliases the skill classes extend.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        engine_component::ensure_engine_aliases();
    }

    /**
     * Every field description of the shortened skills fits the window.
     */
    public function test_shortened_skills_fit_the_constructor_window(): void {
        $skills = [
            new search_options_skill(),
            new configure_booking_instance_skill(),
            new book_users_skill(),
            new diagnose_cancellation_issue_skill(),
            new diagnose_user_booking_skill(),
            new get_option_details_skill(),
            new enrol_user_skill(),
            new create_course_skill(),
            new list_skills_skill(),
            new diagnose_permissions_skill(),
            new diagnose_user_in_course_skill(),
            new update_activity_skill(),
            new explain_docs_skill(),
        ];
        foreach ($skills as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertNotEmpty($properties, get_class($skill));
            foreach ($properties as $field => $definition) {
                $text = trim((string)preg_replace('/\s+/u', ' ', (string)($definition['description'] ?? '')));
                $this->assertLessThanOrEqual(
                    self::WINDOW,
                    \core_text::strlen($text),
                    get_class($skill) . '.' . $field . ': ' . $text
                );
            }
        }
    }

    /**
     * Single fields shortened in round 3, in skills whose other long fields were kept (A/B on Gemma, #2582).
     */
    public function test_shortened_single_fields_fit_the_constructor_window(): void {
        $fields = [
            [new remember_skill(), 'relevant_for'],
            [new add_activity_skill(), 'section'],
            [new diagnose_booking_issue_skill(), 'userquery'],
            [new list_option_properties_skill(), 'cmid'],
            [new create_option_skill(), 'headerimage_token'],
        ];
        foreach ($fields as [$skill, $field]) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey($field, $properties, get_class($skill));
            $text = trim((string)preg_replace('/\s+/u', ' ', (string)($properties[$field]['description'] ?? '')));
            $this->assertNotSame('', $text, get_class($skill) . '.' . $field);
            $this->assertLessThanOrEqual(self::WINDOW, \core_text::strlen($text), get_class($skill) . '.' . $field . ': ' . $text);
        }
    }
}
