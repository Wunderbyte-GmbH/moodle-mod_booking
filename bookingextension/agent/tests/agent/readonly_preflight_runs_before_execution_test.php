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
 * A read-only command passes its skill's preflight before it executes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\skill_registry;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * F20 (findings history 2026-09-08; baseline runs 25-32): read-only (R0) commands were executed without
 * their skill's preflight - the developer guide promises execute() the PREPARED input, the decision service
 * handed it the raw one. Skills that resolve their targets in run_preflight() (every taskflow skill) had
 * to resolve again in execute() and, when nothing matched, ended as an execution error: "No user matches
 * ..." twelve times, "No message template name contains ..." ten times in eight runs, each an error where
 * a clarification with candidates and an issue code was ready one layer earlier. The read-only path now
 * runs the same preflight pipeline as the mutating path: a clarification issue ends the turn as a
 * clarification (nothing runs), a pass hands execute() the prepared input.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 */
final class readonly_preflight_runs_before_execution_test extends abstract_agent_testcase {
    /**
     * A read-only skill that resolves its target in preflight, as the developer guide describes.
     *
     * @return base_skill
     */
    private function make_lookup_skill(): base_skill {
        return new class extends base_skill {
            /**
             * Read-only, R0.
             */
            public function __construct() {
                parent::__construct(true, skill_risk_class::R0);
            }

            /**
             * Name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'core.lookup_probe';
            }

            /**
             * Schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return [
                    'version' => 1,
                    'description' => 'Probe: looks one thing up.',
                    'readonly' => true,
                    'input' => [
                        'type' => 'object',
                        'properties' => ['targetquery' => ['type' => 'string', 'description' => 'What to look up.']],
                        'required' => [],
                    ],
                ];
            }

            /**
             * The target resolves in preflight - or the preflight asks.
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            protected function run_preflight(array $input, int $contextid, int $userid): array {
                $query = trim((string)($input['targetquery'] ?? ''));
                if ($query !== 'known') {
                    return $this->invalid([[
                        'code' => 'PROBE_TARGET_UNRESOLVED',
                        'severity' => 'needs_clarification',
                        'message' => 'Which target do you mean?',
                    ]]);
                }
                return $this->pass(['targetid' => 42, 'outputlang' => (string)($input['outputlang'] ?? '')]);
            }

            /**
             * Records what it received.
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $input, int $contextid, int $userid): array {
                return ['status' => 'ok', 'detail' => 'probe ran', 'observation_full' => 'probe ran'];
            }
        };
    }

    /**
     * Registry that knows the probe skill.
     *
     * @return skill_registry
     */
    private function registry_with_probe(): skill_registry {
        $registry = skill_registry::make_default();
        $skill = $this->make_lookup_skill();
        $registry->register(new class ($skill) implements skill_provider_interface {
            /** @var base_skill */
            private base_skill $skill;

            /**
             * Carry the skill.
             *
             * @param base_skill $skill
             */
            public function __construct(base_skill $skill) {
                $this->skill = $skill;
            }

            /**
             * Component.
             *
             * @return string
             */
            public function get_component(): string {
                return 'bookingextension_agent';
            }

            /**
             * Skills.
             *
             * @return array
             */
            public function get_skills(): array {
                return [$this->skill];
            }

            /**
             * None.
             *
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }

            /**
             * None.
             *
             * @return null
             */
            public function get_issue_code_provider(): ?issue_code_provider_interface {
                return null;
            }

            /**
             * None.
             *
             * @return array
             */
            public function get_prompt_guidance(): array {
                return [];
            }
        });
        return $registry;
    }

    /**
     * Route one read-only command through the decision service.
     *
     * @param string $skillname
     * @param array $input
     * @param int $contextid
     * @return array{0:array,1:int}
     */
    private function decide(string $skillname, array $input, int $contextid): array {
        global $USER;
        // Admin, like readonly_target_ambiguity_test: the system context has no teacher role that
        // could hold the wizard capability, and governance is not what these tests measure.
        $this->setAdminUser();
        [$store, , $threadid] = $this->build_runtime();
        $service = new agent_decision_service(skill_registry::make_default(), $store, new authorization_service());
        $decision = $service->process([
            'response_type' => 'skill_call',
            'message' => '',
            'commands' => [[
                'skill' => $skillname,
                'version' => 1,
                'input' => $input,
                '_structural_validated' => true,
            ]],
        ], (int)$threadid, $contextid, (int)$USER->id, 'en');
        return [$decision, (int)$threadid];
    }

    /**
     * The pipeline hands a read-only command the PREPARED input of its preflight - and asks when it asks.
     */
    public function test_the_pipeline_prepares_a_readonly_command(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $pipeline = new preflight_pipeline($this->registry_with_probe(), $store);
        $command = ['skill' => 'core.lookup_probe', 'version' => 1, 'input' => ['targetquery' => 'known']];

        $result = $pipeline->run([$command], (int)$threadid, $this->booking_contextid(), (int)$this->teacher->id);
        $this->assertSame('pass', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertSame(42, (int)($result['prepared_commands'][0]['input']['targetid'] ?? 0), 'the prepared input');

        $command['input'] = ['targetquery' => 'unknown'];
        $result = $pipeline->run([$command], (int)$threadid, $this->booking_contextid(), (int)$this->teacher->id);
        $this->assertContains('PROBE_TARGET_UNRESOLVED', (array)($result['issue_codes'] ?? []), json_encode($result));
    }

    /**
     * A read-only skill whose preflight asks ends the turn as that clarification; nothing runs.
     *
     * mod_booking.search_options at the system context, naming an activity that does not exist: the
     * target falls back to the ambient context (read-only never blocks on it) and the skill's own
     * preflight says there is no booking activity here. Before wave 25 the command executed and the
     * same sentence came back as an execution ERROR result (readonly_target_ambiguity_test).
     */
    public function test_a_preflight_clarification_ends_the_turn_without_execution(): void {
        global $DB;
        [$decision, $threadid] = $this->decide(
            'mod_booking.search_options',
            ['activityquery' => 'No such activity'],
            (int)\context_system::instance()->id
        );

        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
        $this->assertNotEmpty((array)($decision['issue_codes'] ?? []), 'the skill\'s own issue code travels with the question');
        $this->assertSame(0, $DB->count_records('bx_agent_ai_runs', ['threadid' => $threadid]), 'nothing executed');
    }

    /**
     * A read-only command whose preflight passes still executes, once.
     */
    public function test_a_passing_preflight_executes(): void {
        global $DB;
        $this->create_option('Yoga Morning');
        [$decision, $threadid] = $this->decide('mod_booking.search_options', ['query' => 'Yoga'], $this->booking_contextid());

        $this->assertNotSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
        $this->assertSame(1, $DB->count_records('bx_agent_ai_runs', ['threadid' => $threadid]));
        $run = reset($DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid]));
        $this->assertStringNotContainsString('"error"', (string)$run->resultsjson, 'the skill ran cleanly');
    }
}
