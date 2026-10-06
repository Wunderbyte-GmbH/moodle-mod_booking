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

use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for synchronizer input shaping.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_input_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Thread 23502 (2026-09-30): wizard.list_skills ran and its result stood in OBSERVATION 1, the selector's second pass
     * said "Hier ist die Uebersicht der verfuegbaren Skills." and gpt-oss copied that PLANNER_TEXT word for word instead
     * of rendering the result (A/B on the exact live prompt: 0/3 with the planner text, 3/3 without). With an executed
     * result in the turn the planner text is no observation; the skill results are the facts (REM-2 stays: without a
     * result the planner text is the only information and is kept, see frozen_prompts_test).
     */
    public function test_planner_text_is_dropped_when_a_skill_result_exists(): void {
        $builder = new synchronizer_input_builder();
        $result = [
            'response_type' => 'sufficient',
            'message' => 'Hier ist die Uebersicht der verfuegbaren Skills.',
            'results' => [],
            'loop_results' => [[
                'step' => 0,
                'tool_calls' => [['skill' => 'wizard.list_skills', 'version' => 1, 'input' => []]],
                'results' => [[
                    'status' => 'executed', 'skill' => 'wizard.list_skills', 'detail' => 'names', 'observation_full' => '## a',
                ]],
                'observation' => "Step 1: ## bookingextension/agent [readonly]\n- core.get_current_user",
            ]],
        ];
        $observations = $builder->build_observations($result);

        $joined = implode("\n---\n", $observations);
        $this->assertStringContainsString('core.get_current_user', $joined, 'the skill result stays');
        $this->assertStringNotContainsString('PLANNER_TEXT', $joined);
        $this->assertStringNotContainsString('Uebersicht der verfuegbaren', $joined);

        // Thread 23481 turn 2: the executed result lives in results[], the step observation is empty.
        $result['loop_results'] = [];
        $result['results'] = [['status' => 'executed', 'skill' => 'mod_booking.search_options', 'detail' => '46 Optionen']];
        $joined = implode("\n---\n", $builder->build_observations($result));
        $this->assertStringNotContainsString('PLANNER_TEXT', $joined);

        // REM-2: nothing ran - the planner text is kept, marked as not a result.
        $result['results'] = [['status' => 'failed', 'skill' => 'wizard.remember', 'detail' => 'x']];
        $joined = implode("\n---\n", $builder->build_observations($result));
        $this->assertStringContainsString('PLANNER_TEXT (not a result)', $joined);
        unset($result['results']);
        $joined = implode("\n---\n", $builder->build_observations($result));
        $this->assertStringContainsString('PLANNER_TEXT (not a result)', $joined);
    }

    /**
     * PHASE_TRACE should keep only minimal telemetry and exclude discovery payloads.
     */
    public function test_phase_trace_excludes_skill_discovery_payload(): void {
        $builder = new synchronizer_input_builder();

        $observations = $builder->build_observations([
            'phase_trace' => [
                'discovery' => [
                    'phase' => 'discovery',
                    'response_type' => 'ok',
                    'issue_codes' => ['a', 'B'],
                    'errors' => ['x'],
                    'selected_families' => ['mod_booking.options'],
                    'ranked_families' => [['family' => 'mod_booking.options', 'score' => 0.9]],
                    'catalogselectionmode' => 'embed_topk',
                    'embeddingstatus' => 'applied',
                ],
                'selection' => [
                    'phase' => 'selection',
                    'response_type' => 'clarification',
                    'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                    'errors' => [],
                    'runtimecatalog' => [['skill' => 'mod_booking.create_option']],
                ],
                'parameter_construction' => [
                    'phase' => 'parameter_construction',
                    'response_type' => 'skill_call',
                    'issue_codes' => [],
                    'errors' => [],
                    'commands' => [['skill' => 'mod_booking.create_option']],
                ],
            ],
        ]);

        $phasetraceobs = '';
        foreach ($observations as $observation) {
            if (is_string($observation) && str_starts_with($observation, 'PHASE_TRACE' . "\n")) {
                $phasetraceobs = $observation;
                break;
            }
        }

        $this->assertNotSame('', $phasetraceobs);
        $json = substr($phasetraceobs, strlen('PHASE_TRACE' . "\n"));
        $payload = json_decode($json, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('discovery', $payload);
        $this->assertArrayHasKey('selection', $payload);
        $this->assertArrayHasKey('parameter_construction', $payload);

        $this->assertSame(['A', 'B'], $payload['discovery']['issue_codes']);
        $this->assertArrayNotHasKey('selected_families', $payload['discovery']);
        $this->assertArrayNotHasKey('ranked_families', $payload['discovery']);
        $this->assertArrayNotHasKey('runtimecatalog', $payload['selection']);
        $this->assertArrayNotHasKey('commands', $payload['parameter_construction']);
    }
}
