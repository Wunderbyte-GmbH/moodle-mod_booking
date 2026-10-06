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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\prompt_policy_builder;
use advanced_testcase;

/**
 * Tests for planner policy extraction and phase-specific response contracts.
 *
 * @covers \bookingextension_agent\local\wizard\prompt_policy_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_policy_builder_test extends advanced_testcase {
    /**
     * Verifies that planner policies stay free of final-synthesis text.
     */
    public function test_planner_policies_do_not_contain_legacy_finalization_policy(): void {
        $plannerpolicies = prompt_policy_builder::build_planner_policies('selection', false, false);

        $this->assertStringNotContainsString('SYNTHESIS RESPONSE POLICY', $plannerpolicies);
        // Wave 32 (frozen prompt spec): the selector template states the decision order once; no policy restates it.
        $this->assertSame('', $plannerpolicies);
    }

    /**
     * Discovery phase must never encourage command-bearing output.
     */
    public function test_discovery_policy_forbids_command_bearing_response_types(): void {
        $plannerpolicies = prompt_policy_builder::build_planner_policies('discovery', false, false);

        $expectedtypes = 'Allowed response_type values: clarification, '
            . 'confirm_pending, sufficient, error.';
        $this->assertStringContainsString($expectedtypes, $plannerpolicies);
        $this->assertStringContainsString('commands MUST always be [] in this phase.', $plannerpolicies);
        $this->assertStringContainsString('Never emit skill_call or confirmation_request in this phase.', $plannerpolicies);
    }

    /**
     * Selection phase must behave like a tool selector with exactly one command.
     */
    public function test_selection_policy_requires_single_selector_command(): void {
        // Wave 32 (frozen prompt spec): the single-command selector contract stands in the selector template only
        // (pinned in frozen_prompts_test); the selection policy adds nothing without the scope restriction.
        $this->assertSame('', prompt_policy_builder::build_planner_policies('selection', false, false));
        $this->assertSame('', prompt_policy_builder::build_planner_policies('selection', true, true));
    }

    /**
     * Parameter construction must keep exactly one command for command-bearing types.
     */
    public function test_parameter_construction_policy_requires_one_or_more_commands(): void {
        // Wave 32 (frozen prompt spec): the constructor contract stands in the constructor template only.
        $this->assertSame('', prompt_policy_builder::build_planner_policies('parameter_construction', false, false));
    }

    /**
     * Discovery routing determinism must explicitly preserve selector/constructor role split.
     */
    public function test_discovery_routing_policy_describes_two_call_roles(): void {
        $plannerpolicies = prompt_policy_builder::build_planner_policies('discovery', false, false);

        $this->assertStringContainsString(
            'Respect strict 2-call roles: discovery stays non-command, selection does skill choice, construction does parameters.',
            $plannerpolicies
        );
    }
}
