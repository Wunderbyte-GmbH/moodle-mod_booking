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
 * A constructor that answers instead of building a command has not constructed anything.
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
 * Baseline run 27 and re-run N18, LM-2: the selector chose wizard.list_memories, the constructor read the
 * MEMORY block of its own prompt and answered "sufficient" with the list instead of staging the call. The
 * interpreter passes "sufficient" through because the selector legitimately uses it, so the user got an
 * answer that never came from the skill and the turn counted as "no skill reached". The construction phase
 * is constructor-only: a "sufficient" here is a contract breach, and the engine decides from state what to
 * do with it - a read-only skill that accepts empty input is simply called, anything else becomes the
 * repairable question the existing repair round already handles.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class constructor_sufficient_is_not_an_answer_test extends abstract_agent_testcase {
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
     * A read-only skill that needs no input is called, whatever the constructor answered.
     */
    public function test_a_readonly_skill_without_input_is_called_anyway(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('wizard.list_memories'),
            // The defect under test: the constructor answers from its prompt instead of building the call.
            $this->planner_sufficient('Ich habe folgende Fakten gespeichert: Kostenstelle 4711.'),
            // Whatever comes after the skill ran: the synthesis may answer.
            $this->planner_sufficient('Hier sind deine gespeicherten Fakten.'),
        ]);

        $this->chat('Welche Fakten hast du dir über mich gemerkt?', (int)$threadid, $store, $runtime);

        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]);
        $this->assertNotEmpty($runs, 'The skill must have been called.');
        $commands = json_decode((string)reset($runs)->commandsjson, true);
        $this->assertSame('wizard.list_memories', (string)($commands[0]['skill'] ?? ''), 'The selected skill ran.');
    }

    /**
     * A mutating skill is never "done" because the constructor said so.
     */
    public function test_a_mutating_skill_is_not_reported_done(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Quiet Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        // Phase-aware (wave 30): the constructor's second answer is honest, however the engine gets there
        // (formerly a repair round, now a re-plan through the selector).
        $this->install_phase_scripted_planner(
            [
                $this->selector_skill_call('mod_booking.update_option'),
                $this->selector_skill_call('mod_booking.update_option'),
            ],
            [
                $this->planner_sufficient('Erledigt, die Option wurde umbenannt.'),
                $this->constructor_clarification('Auf welchen Titel soll die Option umbenannt werden?'),
            ]
        );

        $result = $this->chat('Benenn die Option "Quiet Target" um.', (int)$threadid, $store, $runtime);

        $this->assertNotSame('sufficient', (string)($result['response_type'] ?? ''), 'Nothing was done, so nothing is "done".');
        $this->assertSame(0, $DB->count_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]), 'No mutation was staged.');
    }
}
