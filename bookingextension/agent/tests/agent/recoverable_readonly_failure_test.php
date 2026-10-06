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
 * A read-only step that failed on an unresolvable target must not turn the whole turn into an error.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Replay of baseline run 17/18, thread 5426 (UTP-3) and 5449 (TDP-4).
 *
 * The planner had already written the honest answer — "user 999999 does not exist in the system, so there is
 * no account whose permissions could be inspected" — and the turn still went out as response_type=error with
 * RUN_ABANDONED_ALL_STEPS_FAILED. agent_runtime::reclassify_abandoned_run_as_error() keys on nothing but
 * status === 'error' and cannot tell a provider outage from a name the user mistyped.
 *
 * That reclassification exists for a real case (a run where every step failed and the planner still claimed
 * success), so it stays — it just must not fire when every failing row is flagged RECOVERABLE_INPUT_ERROR,
 * the engine's existing neutral marker for "the user can fix this by rephrasing". No wording is inspected.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\execution\execution_feedback_service
 */
final class recoverable_readonly_failure_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A read-only lookup for a target that does not exist leaves the planner's honest answer standing.
     */
    public function test_unresolvable_target_does_not_abandon_the_run(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.get_option_details'),
            $this->constructor_skill_call('mod_booking.get_option_details', [
                'optionquery' => 'Ein Angebot das es nicht gibt',
            ]),
            // Second round: the planner sees the failed lookup and answers honestly.
            $this->planner_sufficient('Eine Buchungsoption mit diesem Namen gibt es hier nicht.'),
        ]);

        $result = $this->chat(
            'Erzähl mir was über "Ein Angebot das es nicht gibt".',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertNotSame(
            'error',
            (string)($result['response_type'] ?? ''),
            'An unresolvable target is a recoverable input problem, not a failed run: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertNotContains(
            'RUN_ABANDONED_ALL_STEPS_FAILED',
            (array)($result['issue_codes'] ?? []),
            'The honest answer the planner already wrote must survive.'
        );
    }

    /**
     * The guard still fires for a failure the user cannot fix: the run really was abandoned.
     */
    public function test_non_recoverable_failure_still_abandons_the_run(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $runtime2 = $runtime;
        $result = $this->reclassify_probe($runtime2, [
            ['results' => [['status' => 'error', 'detail' => 'Provider unreachable', 'issue_codes' => []]]],
        ]);

        $this->assertSame('error', (string)($result['response_type'] ?? ''));
        $this->assertContains('RUN_ABANDONED_ALL_STEPS_FAILED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Drive the reclassification directly with a prepared loop result.
     *
     * @param object $runtime
     * @param array $loopresults
     * @return array
     */
    private function reclassify_probe(object $runtime, array $loopresults): array {
        $method = new \ReflectionMethod($runtime, 'reclassify_abandoned_run_as_error');
        $method->setAccessible(true);
        return (array)$method->invoke($runtime, [
            'response_type' => 'sufficient',
            'message' => 'Alles erledigt.',
            'loop_results' => $loopresults,
        ]);
    }
}
