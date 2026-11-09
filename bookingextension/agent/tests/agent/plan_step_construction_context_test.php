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
 * Thread-8947 replay: every construction of a multi-step plan knows which step it builds.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic replay of thread 8947 (2026-11-09, #2762) through the real chat entry.
 *
 * Live sequence: the selector planned five create steps and every constructor call received the whole user
 * message with no word about the step it was building (llm_debug 55058, 55062, ..., 55078); six constructions
 * returned "MooDuell 1" with five dates. The engine knows the step - the oldest planned placeholder is the one
 * the next real command binds - and hands it to the constructor as [CURRENT PLAN STEP].
 *
 * What the engine deliberately does NOT do (second iteration, 26 live selector outputs of 09.11.): it never
 * derives the current step, or drops a planned step, from next_step_intent. The selector uses that field for the
 * current step (contract), for the first future step, or for a collapsed rest; a guess there removed a real step
 * (thread 8997 lost "MooDuell 2"). The planned steps become placeholders exactly as declared.
 * Structural checks only: placeholder lists, prompt blocks by header and intent strings, queue binding.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 */
final class plan_step_construction_context_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** Header of the construction-only block that names the step under construction. */
    private const BLOCK = '[CURRENT PLAN STEP]';

    /** The four future steps of the MooDuell plan as the selector contract wants them (steps 2 to 5). */
    private const FUTURE = [
        'create_option_MooDuell_2',
        'create_option_MooDuell_3',
        'create_option_MooDuell_4',
        'create_option_MooDuell_5',
    ];

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
     * One create command of the series: the given numbered title with one session.
     *
     * @param int $n
     * @return string
     */
    private function create_step(int $n): string {
        $day = strtotime('next monday') + ($n - 1) * DAYSECS;
        return $this->constructor_confirmation_request('mod_booking.create_option', [
            'text' => 'MooDuell ' . $n,
            'maxanswers' => 40,
            'optiondates' => [[
                'coursestarttime' => date('Y-m-d', $day) . 'T11:00:00',
                'courseendtime' => date('Y-m-d', $day) . 'T13:00:00',
            ]],
        ]);
    }

    /**
     * The newest real queue item of create_option in a status.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $status
     * @return array
     */
    private function newest_real_item(queue_manager $queuesvc, int $threadid, string $status): array {
        $found = null;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['skill'] ?? '') === 'mod_booking.create_option' && (string)($item['status'] ?? '') === $status) {
                $found = $item;
            }
        }
        $this->assertIsArray($found, 'Expected a create_option item in ' . $status);
        return $found;
    }

    /**
     * The block of the constructor prompt that names the step, without the rest of the prompt.
     *
     * @param string $prompt
     * @return string
     */
    private function step_block(string $prompt): string {
        $start = strpos($prompt, self::BLOCK);
        if ($start === false) {
            return '';
        }
        $end = strpos($prompt, "\n\n[", $start + strlen(self::BLOCK));
        return $end === false ? substr($prompt, $start) : substr($prompt, $start, $end - $start);
    }

    /**
     * The prompts recorded since an index that reached a construction (newest last).
     *
     * @param int $since
     * @return string
     */
    private function newest_constructor_prompt(int $since): string {
        $found = '';
        foreach (array_slice($this->scriptedplannerprompts, $since) as $prompt) {
            if (strpos((string)$prompt, 'phase_handoff.selection=') !== false) {
                $found = (string)$prompt;
            }
        }
        $this->assertNotSame('', $found, 'A construction must have run.');
        return $found;
    }

    /**
     * Start a multi-step plan with the given selector output and the create command of step 1.
     *
     * @param array $planned The selector's planned_steps.
     * @param string $next The selector's next_step_intent.
     * @return array [store, threadid, contextid, queue_manager, turn result]
     */
    private function start_plan(array $planned, string $next): array {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option', $planned, $next),
            $this->create_step(1),
        ]);
        $turn = ai_send_message::execute(
            $contextid,
            'Nächste Woche haben wir fünf getrennt buchbare Veranstaltungen, fortlaufend nummeriert "MooDuell 1" etc., '
                . 'an jedem Werktag von 11 bis 13h, 40 Leute dürfen kommen.',
            $threadid
        );
        $this->assertSame('confirmation_request', (string)($turn['response_type'] ?? ''), json_encode($turn));
        return [$store, $threadid, $contextid, new queue_manager($store), $turn];
    }

    /**
     * Full replay with the selector as the contract wants it: the first construction is told it builds the first
     * step with four steps to follow, the confirmed step is followed by a construction told that step 2 is current,
     * and the second real command binds the second placeholder.
     */
    public function test_each_construction_of_a_plan_knows_its_step(): void {
        global $DB;
        [$store, $threadid, $contextid, $queuesvc] = $this->start_plan(self::FUTURE, 'create_option_MooDuell_1');

        $this->assertSame(self::FUTURE, $queuesvc->get_planned_placeholder_intents($threadid), 'four future steps are owed');

        $this->assertStringNotContainsString(self::BLOCK, (string)$this->scriptedplannerprompts[0], 'construction-only');
        $block = $this->step_block((string)$this->scriptedplannerprompts[1]);
        $this->assertNotSame('', $block, 'The first construction must be told that it builds one step of a plan.');
        $this->assertStringContainsString('the first step of the plan', $block);
        foreach (self::FUTURE as $future) {
            $this->assertStringContainsString($future, $block, 'The remaining steps are listed as separate steps.');
        }

        // Confirm step 1; the follow-up plans step 2 with its own construction.
        $item1 = $this->newest_real_item($queuesvc, $threadid, 'blocked_confirmation');
        $this->assertSame('', (string)($item1['realizes_placeholder'] ?? ''), 'The first command is the current step, not bound.');
        $promptsbefore = count($this->scriptedplannerprompts);
        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option', [], self::FUTURE[0]),
            $this->create_step(2),
        ]);
        $confirm = (new confirm_run_service(skill_registry::make_default(), $store, new authorization_service()))
            ->confirm($contextid, 0, $threadid, (int)$this->teacher->id, (string)$item1['queue_item_id'], false);
        $this->assertTrue((bool)($confirm['success'] ?? false), 'Step 1 must execute: ' . json_encode($confirm));
        $this->assertTrue($DB->record_exists('booking_options', ['bookingid' => (int)$this->booking->id, 'text' => 'MooDuell 1']));

        $block2 = $this->step_block($this->newest_constructor_prompt($promptsbefore));
        $this->assertNotSame('', $block2);
        $this->assertStringContainsString('Current step: ' . self::FUTURE[0], $block2, 'Step 2 is the current step.');
        $this->assertLessThan(strpos($block2, self::FUTURE[1]), strpos($block2, self::FUTURE[0]), 'current before remaining');
        $this->assertStringContainsString(self::FUTURE[3], $block2, 'The last step is still listed as remaining.');

        $item2 = $this->newest_real_item($queuesvc, $threadid, 'blocked_confirmation');
        $bound = $queuesvc->get_queue_item($threadid, (string)($item2['realizes_placeholder'] ?? ''));
        $this->assertIsArray($bound, 'The follow-up command must bind a placeholder.');
        $this->assertSame(self::FUTURE[0], (string)($bound['input']['intent'] ?? ''), 'It binds the second step.');
        $this->assertSame(queue_status_policy::realizing_status(), (string)($bound['status'] ?? ''));
        $this->assertSame(array_slice(self::FUTURE, 1), $queuesvc->get_planned_placeholder_intents($threadid));
    }

    /**
     * The selector of thread 8997 named the FIRST FUTURE step in next_step_intent: the engine must not take that
     * as the current step nor drop it from the plan - the step would be lost.
     */
    public function test_next_step_intent_never_removes_or_names_a_planned_step(): void {
        [, $threadid, , $queuesvc] = $this->start_plan(self::FUTURE, self::FUTURE[0]);
        $this->assertSame(self::FUTURE, $queuesvc->get_planned_placeholder_intents($threadid), 'all declared steps stay owed');
        $block = $this->step_block((string)$this->scriptedplannerprompts[1]);
        $this->assertStringContainsString('the first step of the plan', $block);
        $this->assertStringNotContainsString('Current step: ' . self::FUTURE[0], $block, 'step 2 is not the current step');
        $this->assertStringContainsString(self::FUTURE[0], $block, 'step 2 is listed as a remaining step');
    }

    /**
     * The selector of thread 8947 listed the current step among planned_steps: the placeholders are the declared
     * steps, exactly; the engine does not guess which one is a duplicate.
     */
    public function test_planned_steps_become_placeholders_as_declared(): void {
        $declared = array_merge(['create_option_MooDuell_1'], self::FUTURE);
        [, $threadid, , $queuesvc] = $this->start_plan($declared, 'create_option_MooDuell_1');
        $this->assertSame($declared, $queuesvc->get_planned_placeholder_intents($threadid));
    }

    /**
     * Negative: a single-step turn gets no step block.
     */
    public function test_single_step_construction_carries_no_step_block(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [, , $threadid] = $this->build_runtime();
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option', [], 'Schweißseminar anlegen'),
            $this->constructor_confirmation_request('mod_booking.create_option', ['text' => 'Schweißseminar', 'maxanswers' => 12]),
        ]);
        $turn = ai_send_message::execute($contextid, 'Wir brauchen ein Schweißseminar, maximal 12 Leute.', (int)$threadid);
        $this->assertSame('confirmation_request', (string)($turn['response_type'] ?? ''));
        $this->assertCount(2, $this->scriptedplannerprompts);
        $this->assertStringNotContainsString(self::BLOCK, (string)$this->scriptedplannerprompts[1]);
    }
}
