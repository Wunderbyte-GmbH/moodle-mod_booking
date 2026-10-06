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
 * A constructor question must not lose the selected skill for the answer turn.
 *
 * F21: the selector re-runs on every turn, so the answer to "which period?" re-decides the
 * skill from a five-word reply. The engine holds the selection (selected_skill) the whole
 * time — recording it into the pending-action note lets the next selection see the
 * [PENDING CLARIFICATION CONTEXT] prior that already exists for preflight questions.
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
 * Deterministic selector-continuity tests via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class clarification_skill_continuity_test extends abstract_agent_testcase {
    use scripted_llm_trait;

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

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A constructor clarification asking for a missing detail.
     *
     * @return string
     */
    private function constructor_clarification(): string {
        return json_encode([
            'response_type' => 'clarification',
            'message' => 'Für welchen Zeitraum sollen die Slots gelten?',
            'commands' => [],
            'user_lang' => 'de',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Turn 1 ends in a constructor question: the pending-action note must carry the
     * selected skill, and the origin task must be recorded for the discovery channel.
     */
    public function test_constructor_clarification_records_the_selected_skill(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_slotbooking_option'),
            $this->constructor_clarification(),
        ]);

        $result = $this->chat(
            'Beratungsgespräche: Mo–Fr je 10–16 Uhr, 45-Minuten-Fenster, eine Person gleichzeitig.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));

        $pendingraw = (string)$store->get_thread_metadata_value((int)$threadid, 'clarification_pending_action');
        $this->assertStringContainsString(
            'mod_booking.create_slotbooking_option',
            $pendingraw,
            'the constructor question must record the skill the turn already selected'
        );

        $origintask = (string)$store->get_thread_metadata_value((int)$threadid, 'clarification_origin_task');
        $this->assertStringContainsString(
            'Beratungsgespräche',
            $origintask,
            'the origin task must be recorded so the answer turn keeps the task semantics'
        );
    }

    /**
     * Turn 2: the answer-turn selection prompt must carry the continuity prior with the skill.
     */
    public function test_answer_turn_selection_sees_the_pending_context(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            // Turn 1: selection + constructor question.
            $this->selector_skill_call('mod_booking.create_slotbooking_option'),
            $this->constructor_clarification(),
            // Turn 2: the scripted selection re-picks; content irrelevant for the prompt pin.
            $this->selector_skill_call('mod_booking.create_slotbooking_option'),
            $this->constructor_clarification(),
        ]);

        $this->chat(
            'Beratungsgespräche: Mo–Fr je 10–16 Uhr, 45-Minuten-Fenster, eine Person gleichzeitig.',
            (int)$threadid,
            $store,
            $runtime
        );
        $this->chat('Ab morgen bis Ende des Jahres.', (int)$threadid, $store, $runtime);

        $this->assertGreaterThanOrEqual(3, count($this->scriptedplannerprompts), 'turn 2 selection must have run');
        $turn2selection = (string)$this->scriptedplannerprompts[2];
        $this->assertStringContainsString(
            '[PENDING CLARIFICATION CONTEXT]',
            $turn2selection,
            'the answer-turn selection must see the continuity prior'
        );
        $this->assertStringContainsString('mod_booking.create_slotbooking_option', $turn2selection);
    }
}
