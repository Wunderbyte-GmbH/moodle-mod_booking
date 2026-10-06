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
 * Contract test: the planner asks about skills, not about their fields.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\orchestrator;

/**
 * Run-22 finding F69, the second half of what run 15 started.
 *
 * Run 15 made the REQUIRED line of the catalogue truthful. What stayed was the rule that
 * consumed it: "missing required input for the selected skill -> clarification" made the
 * planner stop in front of a skill it had already identified correctly, and ask for the
 * field itself. The turn then counts as no skill reached, and the user gets a worse
 * question: the skill's own gate knows the field's alternatives and the candidates it
 * found, the planner knows neither.
 *
 * This is the same line wave 14 drew for gate rejections marked RECOVERABLE_INPUT_ERROR:
 * the skill asks, not the phase in front of it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\orchestrator
 */
final class selector_defers_missing_fields_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The default planner template hands a missing field to the skill's own gate.
     */
    public function test_a_missing_field_is_not_a_reason_to_stop_in_front_of_the_skill(): void {
        $template = (string)orchestrator::get_default_initial_prompt_template_for_action(
            \core_ai\aiactions\summarise_text::class
        );

        $this->assertNotSame('', $template, 'the planner template must not be empty');

        // The rule that produced F69 must be gone in that form.
        $this->assertStringNotContainsString(
            'missing required input for the selected skill',
            $template,
            'a missing field of an already identified skill is the gate\'s business, not the planner\'s'
        );

        // And the replacement must say where such a turn goes instead (frozen prompt spec, decision order case 4).
        $this->assertStringContainsString(
            'Choose it even when the user did not give every value it needs: the skill asks for missing values itself.',
            $template
        );

        // Clarification stays available for what the selector alone decides: no skill in the catalog fits (case 5).
        $this->assertStringContainsString('response_type=clarification', $template);

        // The rule must stay generic - no skill may be named in it (wave 13 lesson).
        $rule = substr($template, (int)strpos($template, '  3)'), 400);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(mod_booking|local_taskflow|wizard|course|question)\.[a-z_]+/',
            $rule,
            'the routing rules stay skill-agnostic'
        );
    }
}
