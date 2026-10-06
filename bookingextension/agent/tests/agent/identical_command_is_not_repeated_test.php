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
 * A command that already ran in this turn with the same input is not run again.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * F77 (baseline run 31, thread 9469, DP-2): after core.diagnose_permissions had run, the selector re-issued the
 * identical command {userid: 7164} five times in a row, each time receiving the identical observation, before it
 * answered. Nothing in the engine compared a new command with what the turn had already executed. The state
 * knows every executed command; a command whose skill and input already ran in this turn is a loop, not a plan:
 * the planner gets one retry hint to answer from the observation or change the input, and when it repeats
 * itself again the turn ends as a synthesis from what exists - the command never runs a second time.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class identical_command_is_not_repeated_test extends abstract_agent_testcase {
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
     * The identical command runs once; the turn still ends with an answer.
     */
    public function test_the_same_command_runs_once(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('wizard.list_memories'),
            $this->constructor_skill_call('wizard.list_memories', []),
            // Thread 9469: the planner asks for exactly the same thing again ...
            $this->selector_skill_call('wizard.list_memories'),
            $this->constructor_skill_call('wizard.list_memories', []),
            // ... and again after the retry hint.
            $this->selector_skill_call('wizard.list_memories'),
            $this->constructor_skill_call('wizard.list_memories', []),
            // The synthesis answers from the observation that exists.
            $this->planner_sufficient('Du hast keine gespeicherten Fakten.'),
        ]);

        $result = $this->chat('Welche Fakten hast du dir über mich gemerkt?', (int)$threadid, $store, $runtime);

        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]);
        $executed = 0;
        foreach ($runs as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(1, $executed, 'the identical command ran exactly once');
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertContains(
            'LOOP_IDENTICAL_COMMAND_REPEATED',
            array_map('strval', (array)($result['issue_codes'] ?? [])),
            'the loop is named by its state, not hidden'
        );
    }

    /**
     * A different input is a different command and runs.
     */
    public function test_a_changed_input_still_runs(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Quiet Target');
        $this->create_option('Loud Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.get_option_details'),
            $this->constructor_skill_call('mod_booking.get_option_details', ['optionquery' => 'Quiet Target']),
            $this->selector_skill_call('mod_booking.get_option_details'),
            $this->constructor_skill_call('mod_booking.get_option_details', ['optionquery' => 'Loud Target']),
            $this->planner_sufficient('Beide Optionen sind da.'),
        ]);

        $this->chat('Zeig mir Quiet Target und Loud Target.', (int)$threadid, $store, $runtime);

        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]);
        $executed = 0;
        foreach ($runs as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(2, $executed, 'two different inputs are two commands');
    }
}
