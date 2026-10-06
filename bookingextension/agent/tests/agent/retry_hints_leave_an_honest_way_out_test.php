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
 * Retry hints and the construction contract never push the model into inventing, and name what it needs.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\orchestrator;

/**
 * Wave-30 Nachlauf 31, paired raw-prompt analysis (engine text only - no user text is inspected):
 *  - UO-3 thread 11896: the structural-mismatch hint said "map the user's values" and the repair "optiondates needs
 *    a date range"; with no honest way out the model invented 18:00-20:00.
 *  - DMD-2 thread 11903: the choices hint said only "if clearly exactly one"; the model asked between "Antrag
 *    eröffnet" (onrequestcreated) and "Antrag geschlossen" for "request opened".
 *  - GQ-1 thread 11915: the re-plan hint "ask for a value only the user can give" licensed "which file?".
 *  - DMD-2 thread 11878: "skill_fits" stood only in a rule far above the output contract and was left out.
 *  - DWL-2 thread 11872: rule 20 "exactly the user's words" beat "no article"; "die Wanderung" missed the option.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\orchestrator
 */
final class retry_hints_leave_an_honest_way_out_test extends \advanced_testcase {
    /**
     * The retry hint for a code.
     *
     * @param string $code
     * @return string
     */
    private function hint(string $code): string {
        $method = new \ReflectionMethod(agent_runtime::class, 'build_framework_retry_observation');
        $method->setAccessible(true);
        $runtime = (new \ReflectionClass(agent_runtime::class))->newInstanceWithoutConstructor();
        return (string)$method->invoke($runtime, $code);
    }

    /**
     * A hint that asks for values always offers the honest way out and forbids inventing.
     */
    public function test_hints_that_ask_for_values_forbid_inventing(): void {
        // Wave 32 (frozen appendix A.3): a hint states the fact and carries no rule of its own - the former hints told
        // the constructor to ask for any missing value, against its rule 2. The honest way out and the ban on invented
        // values stand once, in the constructor template (rules 1 and 2).
        foreach (['CONTRACT_STRUCTURAL_MISMATCH', 'CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION'] as $code) {
            $hint = $this->hint($code);
            $this->assertStringContainsString('Nothing was executed', $hint, $code);
            $this->assertStringNotContainsString('response_type=', $hint, $code);
        }
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('Never invent a time, a date, a number, an id, a URL or a name.', $template);
        $this->assertStringContainsString('Return a clarification only when a field in required_input', $template);
    }

    /**
     * The choices hint says how to match: by meaning in any language and by the attributes, into the named field.
     */
    public function test_the_choices_hint_says_how_to_match(): void {
        $hint = $this->hint('PREFLIGHT_CHOICES_OFFERED');
        $this->assertStringContainsString('by meaning or attributes', $hint);
        $this->assertStringContainsString('CHOICES for', $hint);
        $this->assertStringContainsString('by its id', $hint);
    }

    /**
     * An id picked from the choices counts as given by the user (constructor rule 1 allows only user values).
     *
     * L47 UOT-2 (15843), BU-1 (15849): the selector picked the option from the choices, the constructor then refused
     * the id ("Fill a field only with a value the user gave ... never invent an id") and asked the user. Rule 1 has the
     * same exception for USER MEMORY. A/B at the recorded constructor calls, 20 runs: UOT-2 16 -> 20, BU-1 12 -> 18.
     */
    public function test_a_choice_id_counts_as_given_by_the_user(): void {
        $this->assertStringContainsString('counts as given by the user', $this->hint('PREFLIGHT_CHOICES_OFFERED'));
    }

    /**
     * One fitting choice is taken, not asked about (wave 36).
     *
     * L48 DBI-4 (16682): fifty options listed, exactly one "Excel" among them - the planner asked "is this the one?".
     * N45 FOR-3 (18181): three memories, one about morning slots - asked. PM-4 (18280): three templates, one completion
     * confirmation - asked. A/B at the recorded re-plan calls (planner action, 20 runs): DBI-4 takes the id 4 -> 11,
     * FOR-3 asks 2 -> 0, PM-4 19 -> 19.
     */
    public function test_one_fitting_choice_is_taken(): void {
        $this->assertStringContainsString('exactly one choice fits, take it', $this->hint('PREFLIGHT_CHOICES_OFFERED'));
    }

    /**
     * Rule 20 keeps "no article"; the unfit flag is part of the constructor's output contract.
     */
    public function test_rule_20_and_the_output_contract(): void {
        // Wave 32 (frozen prompt spec): the target-name rule (rule 3) and the unfit flag (rule 5, OUTPUT CONTRACT) stand
        // in the constructor template; the engine adds no second contract block.
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('without an article or salutation', $template);
        $this->assertStringContainsString('"skill_fits": false', $template);

        $builderclass = \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder::class;
        $builder = (new \ReflectionClass($builderclass))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($builderclass, 'build_output_contract_block');
        $method->setAccessible(true);
        $this->assertSame('', (string)$method->invoke($builder, 'parameter_construction'));
    }
}
