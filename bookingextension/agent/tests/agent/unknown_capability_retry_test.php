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
 * A capability name the planner invented must come back as a recoverable lookup, not as a finished check.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Replay of the 2026-09-23 chat turn "which roles does <person> have, and may they edit activities in <course>".
 *
 * The constructor translated "edit activities" into `moodle/activity:manage`, a capability that does not exist.
 * core.diagnose_permissions answered with status=executed, a warn checklist row and the observation note
 * "state only the findings above" — so the planner treated the check as done, the user saw a developer card
 * ("Unknown capability … Did you mean: moodle/site:manageallmessaging, …") in the preview, and nobody ever
 * re-ran the check with the real name. The substring ranking did not even list the right capability
 * (`moodle/course:manageactivities`): "activity" is not a substring of "manageactivities".
 *
 * The read-only chat path has no preflight (thread 542), so the correction has to travel the way every other
 * recoverable read-only lookup does: an error row flagged RECOVERABLE_INPUT_ERROR whose observation carries the
 * candidates, after which run_loop re-plans (agent_runtime::run_loop_frame continues on every execution_result)
 * and reclassify_abandoned_run_as_error() leaves an honest "sufficient" alone.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 */
final class unknown_capability_retry_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The capability name the constructor invented in the triggering thread. */
    private const INVENTED = 'moodle/activity:manage';

    /** The capability that actually answers "may they edit activities". */
    private const REAL = 'moodle/course:manageactivities';

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
     * Round one (invented name) is a recoverable lookup with the real name among the candidates; round two
     * (the real name) is the executed check; the turn ends as sufficient, never as a failed run.
     */
    public function test_invented_capability_is_recoverable_and_the_corrected_call_runs(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('core.diagnose_permissions'),
            $this->constructor_skill_call('core.diagnose_permissions', [
                'userid' => (int)$this->student->id,
                'coursequery' => $this->course->fullname,
                'capability' => self::INVENTED,
            ]),
            // Round two: the planner picks the real name from the observation and re-runs the check.
            $this->selector_skill_call('core.diagnose_permissions'),
            $this->constructor_skill_call('core.diagnose_permissions', [
                'userid' => (int)$this->student->id,
                'coursequery' => $this->course->fullname,
                'capability' => self::REAL,
            ]),
            $this->planner_sufficient('Nein, im Kurs darf diese Person keine Aktivitäten bearbeiten.'),
        ]);

        $result = $this->chat(
            'Welche Rollen hat diese Person, und darf sie im Kurs Aktivitäten bearbeiten?',
            (int)$threadid,
            $store,
            $runtime
        );

        $steps = array_values((array)($result['loop_results'] ?? []));
        $this->assertCount(2, $steps, 'Exactly two executions: the invented name and the corrected one.');

        $first = $this->single_row($steps[0]);
        $this->assertNotSame(
            'executed',
            (string)($first['status'] ?? ''),
            'An unknown capability is not a finished check: ' . json_encode($first)
        );
        $this->assertContains(
            'RECOVERABLE_INPUT_ERROR',
            array_map('strval', (array)($first['issue_codes'] ?? [])),
            'The planner can fix the name, so the row must carry the recoverable marker.'
        );
        $this->assertArrayNotHasKey(
            'checklist_rows',
            $first,
            'No checklist for a check that never ran — nothing to render into the preview.'
        );
        $this->assertNull(
            (new diagnose_permissions_skill())->get_result_preview($first, $this->booking_contextid(), (int)$this->teacher->id),
            'The preview channel must stay empty for an unknown capability.'
        );
        $this->assertStringContainsString(
            self::REAL,
            (string)($steps[0]['observation'] ?? ''),
            'The observation must offer the capability that actually exists.'
        );

        $second = $this->single_row($steps[1]);
        $this->assertSame('executed', (string)($second['status'] ?? ''));
        $this->assertSame('capability', (string)($second['diagnosis']['mode'] ?? ''));

        $this->assertSame(
            'sufficient',
            (string)($result['response_type'] ?? ''),
            'The turn ends with the answer, not with an error: ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertNotContains('RUN_ABANDONED_ALL_STEPS_FAILED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * When no real capability resembles the invented one there is nothing to retry with: the skill completes
     * with the role picture instead and the turn ends after that single execution.
     */
    public function test_no_similar_capability_completes_without_a_retry_signal(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('core.diagnose_permissions'),
            $this->constructor_skill_call('core.diagnose_permissions', [
                'userid' => (int)$this->student->id,
                'coursequery' => $this->course->fullname,
                'capability' => 'moodle/qqzzxx:yyvvww',
            ]),
            // Whatever the planner does next, the script only offers a terminal answer.
            $this->planner_sufficient('Diese Berechtigung gibt es auf der Plattform nicht.'),
        ]);

        $result = $this->chat(
            'Darf diese Person im Kurs Kaffee kochen?',
            (int)$threadid,
            $store,
            $runtime
        );

        $steps = array_values((array)($result['loop_results'] ?? []));
        $this->assertCount(1, $steps, 'Nothing to correct, so nothing to re-run.');

        $row = $this->single_row($steps[0]);
        $this->assertSame('executed', (string)($row['status'] ?? ''));
        $this->assertSame('roles', (string)($row['diagnosis']['mode'] ?? ''));
        $this->assertNotContains(
            'RECOVERABLE_INPUT_ERROR',
            array_map('strval', (array)($row['issue_codes'] ?? [])),
            'Without candidates the row must not invite a retry.'
        );

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''));
        $this->assertNotContains('RUN_ABANDONED_ALL_STEPS_FAILED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * The one result row of a loop step.
     *
     * @param array $step
     * @return array
     */
    private function single_row(array $step): array {
        $rows = array_values(array_filter((array)($step['results'] ?? []), 'is_array'));
        $this->assertCount(1, $rows, 'One command per step: ' . json_encode($step));
        return $rows[0];
    }
}
