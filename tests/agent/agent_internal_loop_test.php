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
 * Internal agent loop tests.
 *
 * Verifies that run_loop() implements a true internal agent loop:
 * - Multiple internal steps occur before a response is returned to the user.
 * - Tool results (observations) are fed back into the next LLM call.
 * - No intermediate assistant messages are persisted between steps.
 * - Only ONE final message is persisted once the loop terminates.
 * - The final result is the last non-execution response from the orchestrator.
 *
 * The orchestrator is mocked so tests are fully deterministic and do not
 * require a live LLM.  The executor runs against the real DB so that
 * read-only task execution (booking.search_options) is exercised.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\agent_runtime;
use mod_booking\local\wbagent\agent_state;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\task_registry;

/**
 * Internal agent loop tests — mock orchestrator, real executor.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @covers \mod_booking\local\wbagent\agent_runtime
 * @covers \mod_booking\local\wbagent\agent_state
 */
final class agent_internal_loop_test extends abstract_agent_testcase {

    // -------------------------------------------------------------------------
    // agent_state unit tests.

    /**
     * agent_state::make() creates a fresh state with correct max_steps.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_make_returns_clean_state(): void {
        $this->resetAfterTest();
        $state = agent_state::make(4);

        $this->assertSame(4, $state->max_steps);
        $this->assertSame(0, $state->current_step);
        $this->assertSame(0, $state->step_count());
        $this->assertEmpty($state->get_observations());
        $this->assertEmpty($state->get_steps());
        $this->assertFalse($state->has_observations());
    }

    /**
     * agent_state::make() clamps max_steps to at least 1.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_make_clamps_min_steps(): void {
        $this->resetAfterTest();
        $state = agent_state::make(0);
        $this->assertSame(1, $state->max_steps);

        $state2 = agent_state::make(-5);
        $this->assertSame(1, $state2->max_steps);
    }

    /**
     * record_step() accumulates steps and observations correctly.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_record_step_accumulates(): void {
        $this->resetAfterTest();
        $state = agent_state::make(5);
        $state->current_step = 1;

        $state->record_step(
            [['task' => 'booking.search_options']],
            [['options' => [['name' => 'Yoga']]]],
            'Step 1: Found 1 booking option(s): Yoga.'
        );

        $this->assertSame(1, $state->step_count());
        $this->assertTrue($state->has_observations());
        $this->assertCount(1, $state->get_observations());
        $this->assertStringContainsString('Yoga', $state->get_observations()[0]);

        // Second step.
        $state->current_step = 2;
        $state->record_step([], [], 'Step 2: Found 3 users.');

        $this->assertSame(2, $state->step_count());
        $this->assertCount(2, $state->get_observations());
        $this->assertStringContainsString('users', $state->get_observations()[1]);
    }

    /**
     * Blank observation strings are not added to the observations list.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_blank_observation_is_ignored(): void {
        $this->resetAfterTest();
        $state = agent_state::make(3);
        $state->current_step = 1;
        $state->record_step([], [], '   ');

        $this->assertSame(1, $state->step_count());
        $this->assertEmpty($state->get_observations(), 'Blank observation must not be added');
        $this->assertFalse($state->has_observations());
    }

    // -------------------------------------------------------------------------
    // run_loop() internal loop tests — mock orchestrator.

    /**
     * run_loop() calls the orchestrator twice and accumulates observations.
     *
     * Scenario:
     *   Step 1: orchestrator returns task_call → executor runs booking.search_options
     *           → decide() produces execution_result → loop continues.
     *   Step 2: orchestrator returns clarification → loop stops.
     *
     * Assertions:
     *   - orchestrator::process() called exactly twice.
     *   - First call has no observations.
     *   - Second call has at least one observation.
     *   - Only ONE assistant message persisted in DB.
     *   - Final result has response_type = 'clarification'.
     *   - Final result carries loop_step = 2.
     */
    public function test_run_loop_accumulates_observations_between_steps(): void {
        global $DB;

        $this->setUser($this->teacher);

        // Create a real booking option so search_options finds something.
        $this->exec_command('booking.create_option', [
            'text'            => 'Yoga Class Loop Test',
            'maxanswers'      => 10,
            'coursestarttime' => '2045-06-01T09:00:00',
            'duration'        => 60,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        // Step 1 result: task_call for a read-only search.
        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching options.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Yoga'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        // Step 2 result: clarification (final answer).
        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'I found booking options for your query.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount             = 0;
        $capturedobservations  = [];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(
                function (
                    int $threadid,
                    int $cmid,
                    int $userid,
                    array $observations = []
                ) use (&$callcount, &$capturedobservations, $step1, $step2): array {
                    $callcount++;
                    $capturedobservations[$callcount] = $observations;
                    return $callcount === 1 ? $step1 : $step2;
                }
            );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Zeige mir alle Yoga Kurse');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Orchestrator was called exactly twice.
        $this->assertSame(2, $callcount, 'Orchestrator must be called twice (1 tool step + 1 final step)');

        // First call: no observations.
        $this->assertEmpty(
            $capturedobservations[1],
            'First orchestrator call must receive no observations'
        );

        // Second call: observation from step 1.
        $this->assertNotEmpty(
            $capturedobservations[2],
            'Second orchestrator call must receive observation(s) from step 1'
        );
        $observationtext = implode(' ', $capturedobservations[2]);
        $this->assertStringContainsString(
            'option',
            strtolower($observationtext),
            'Observation must mention booking options'
        );

        // Exactly ONE assistant message was persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(
            1,
            $assistantmessages,
            'Exactly one assistant message must be persisted after the loop'
        );

        // Final result is the clarification from step 2.
        $this->assertSame('clarification', $result['response_type']);

        // loop_step reflects the terminating step number.
        $this->assertArrayHasKey('loop_step', $result);
        $this->assertSame(2, (int)$result['loop_step']);
    }

    /**
     * run_loop() stops immediately when the first response is not execution_result.
     *
     * Scenario: orchestrator returns confirmation_request on the first call.
     * The loop must stop immediately, persist ONE message, and not call the
     * orchestrator a second time.
     */
    public function test_run_loop_stops_immediately_on_confirmation_request(): void {
        $this->setUser($this->teacher);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $confirmresult = [
            'response_type'     => 'confirmation_request',
            'lang'              => 'en',
            'message'           => 'Shall I create the Pilates option?',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.create_option',
                'version' => 1,
                'input'   => ['text' => 'Pilates', 'maxanswers' => 5],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount = 0;

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(function () use (&$callcount, $confirmresult): array {
                $callcount++;
                return $confirmresult;
            });

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Erstelle eine Pilates-Klasse');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Only one orchestrator call.
        $this->assertSame(1, $callcount, 'Orchestrator must be called once for confirmation_request');

        // Loop terminated at step 1.
        $this->assertSame(1, (int)$result['loop_step']);

        // Exactly ONE assistant message persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'One assistant message must be persisted');
    }

    /**
     * run_loop() terminates at max_steps and returns MAX_STEPS_EXCEEDED.
     *
     * The orchestrator always returns execution_result via a task_call, causing
     * the loop to keep going.  Once max_steps is reached, run_loop() must:
     * - Return error with issue_code MAX_STEPS_EXCEEDED.
     * - Persist exactly ONE assistant message.
     */
    public function test_run_loop_terminates_at_max_steps(): void {
        $this->setUser($this->teacher);

        // Create an option so search_options actually succeeds.
        $this->exec_command('booking.create_option', [
            'text'            => 'MaxSteps Test Option',
            'maxanswers'      => 3,
            'coursestarttime' => '2045-07-01T10:00:00',
            'duration'        => 30,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        // Always returns a read-only task_call — loop would never stop naturally.
        $readonlycall = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'MaxSteps'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturn($readonlycall);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Suche MaxSteps');

        $result = $runtime->run_loop(
            $threadid,
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            2   // Override to 2 steps so the test is fast.
        );

        // Terminated with MAX_STEPS_EXCEEDED error.
        $this->assertSame('error', $result['response_type']);
        $this->assertContains('MAX_STEPS_EXCEEDED', $result['issue_codes'] ?? []);

        // Exactly ONE assistant message persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'One assistant message must be persisted even at max steps');
    }

    /**
     * run() (single-turn) still works and persists exactly one message.
     *
     * run() must remain backward-compatible as the entry point used by
     * ai_send_message.php for each user request.
     */
    public function test_run_single_turn_persists_exactly_one_message(): void {
        $this->setUser($this->teacher);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $clarification = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'What would you like to create?',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturn($clarification);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Hilf mir');

        $result = $runtime->run($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('clarification', $result['response_type']);

        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'run() must persist exactly one assistant message');
    }

    /**
     * Observations from step 1 appear in the second orchestrator call.
     *
     * Directly verifies that the observation string built by
     * build_observation_from_result() contains meaningful content about
     * the executed tool results.
     */
    public function test_run_loop_observations_contain_result_data(): void {
        $this->setUser($this->teacher);

        // Create a recognisably-named option.
        $this->exec_command('booking.create_option', [
            'text'            => 'Pottery Workshop Obs Test',
            'maxanswers'      => 7,
            'coursestarttime' => '2045-08-15T14:00:00',
            'duration'        => 90,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $callcount            = 0;
        $capturedobservations = [];

        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching for Pottery options.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Pottery'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'Found it.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(
                function (
                    int $threadid,
                    int $cmid,
                    int $userid,
                    array $observations = []
                ) use (&$callcount, &$capturedobservations, $step1, $step2): array {
                    $callcount++;
                    $capturedobservations[$callcount] = $observations;
                    return $callcount === 1 ? $step1 : $step2;
                }
            );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Zeige mir Pottery Kurse');

        $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Orchestrator was called twice.
        $this->assertSame(2, $callcount);

        // The observation passed to call 2 must mention "Pottery" (the found option title).
        $obs = implode(' ', $capturedobservations[2]);
        $this->assertStringContainsString(
            'Pottery',
            $obs,
            'Observation injected into step 2 must contain the found option title "Pottery"'
        );

        // The observation must mention "option" so the LLM knows what kind of result it is.
        $this->assertStringContainsString(
            'option',
            strtolower($obs),
            'Observation must indicate it is about booking options'
        );
    }
}
