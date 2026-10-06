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
 * Ambiguous targets clarify on the read-only chat path (flowchart PP_RUN).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Thread 1304: two booking activities are called "booking". The read-only command resolved its
 * target late, at the executor, whose ambient fallback dropped the ambiguity — the planner only
 * ever saw a generic list without the second activity and retried 21 times. Read-only commands
 * still never block on resolution (thread 515: not found / unsupported stay ambient), but a
 * genuinely ambiguous target now ends the turn as a clarification listing every candidate with
 * its cmid, exactly as the preflight does.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers     \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class readonly_target_ambiguity_test extends advanced_testcase {
    /** @var int[] cmids of the two activities that share one name. */
    private array $dupcmids = [];

    /** @var int cmid of the uniquely named activity. */
    private int $solocmid = 0;

    /**
     * Two courses with an activity called "Dup Booking" each, one course with "Solo Booking".
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();

        foreach (['First course', 'Second course'] as $fullname) {
            $course = $this->getDataGenerator()->create_course(['fullname' => $fullname]);
            $this->dupcmids[] = (int)$this->getDataGenerator()->create_module(
                'booking',
                ['course' => $course->id, 'name' => 'Dup Booking']
            )->cmid;
        }
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Third course']);
        $this->solocmid = (int)$this->getDataGenerator()->create_module(
            'booking',
            ['course' => $course->id, 'name' => 'Solo Booking']
        )->cmid;
    }

    /**
     * Run one read-only search_options command through the decision service (system context by default).
     *
     * @param array $input
     * @param int $contextid Ambient context id; 0 = system context.
     * @return array{0: array, 1: int} Decision result and the thread id.
     */
    private function decide(array $input, int $contextid = 0): array {
        global $USER;
        $store = new conversation_store();
        $contextid = $contextid > 0 ? $contextid : (int)\context_system::instance()->id;
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, $contextid)->id;
        $service = new agent_decision_service(skill_registry::make_default(), $store, new authorization_service());
        $decision = $service->process([
            'response_type' => 'skill_call',
            'message' => '',
            'commands' => [[
                'skill' => 'mod_booking.search_options',
                'version' => 1,
                'input' => $input,
                '_structural_validated' => true,
            ]],
        ], $threadid, $contextid, (int)$USER->id, 'en');
        return [$decision, $threadid];
    }

    /**
     * Number of runs recorded for the thread (a clarified turn executes nothing).
     *
     * @param int $threadid
     * @return int
     */
    private function run_count(int $threadid): int {
        global $DB;
        return $DB->count_records('bx_agent_ai_runs', ['threadid' => $threadid]);
    }

    /**
     * Two activities with the same name: clarification listing both cmids, nothing executes.
     */
    public function test_ambiguous_activity_clarifies_with_every_candidate(): void {
        [$decision, $threadid] = $this->decide(['activityquery' => 'Dup Booking']);

        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
        $this->assertContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        $message = (string)($decision['message'] ?? '');
        foreach ($this->dupcmids as $cmid) {
            $this->assertStringContainsString('cmid ' . $cmid, $message, 'Every candidate is listed with its cmid.');
        }
        $this->assertStringContainsString('First course', $message);
        $this->assertStringContainsString('Second course', $message);
        $this->assertSame(0, $this->run_count($threadid), 'An ambiguous target must not execute at the ambient context.');
    }

    /**
     * Working inside one of the same-named activities is a deliberate pick: no clarification,
     * the command executes there (architecture ch. 09 §2b).
     */
    public function test_ambient_candidate_is_kept_without_clarification(): void {
        $ambient = (int)\context_module::instance($this->dupcmids[0])->id;
        [$decision, $threadid] = $this->decide(['activityquery' => 'Dup Booking'], $ambient);

        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        $this->assertSame(1, $this->run_count($threadid));
    }

    /**
     * No target named (live thread 1314: the constructor put the activity name into the option
     * search "query"): the selector is empty, so there is no NAMED ambiguity — no engine
     * clarification, the skill runs and its own no-instance guard lists the activities.
     */
    public function test_unnamed_target_is_no_engine_clarification(): void {
        [$decision, $threadid] = $this->decide(['query' => 'Dup Booking']);

        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        // Wave 25: read-only commands pass their skill's preflight first. At the system context the
        // skill's own no-instance guard asks BEFORE execution (it used to answer the same sentence as an
        // execution error after a run). The engine still never blocks on the target.
        $this->assertSame(0, $this->run_count($threadid));
        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
    }

    /**
     * A unique name resolves and executes as before.
     */
    public function test_unique_activity_executes(): void {
        [$decision, $threadid] = $this->decide(['activityquery' => 'Solo Booking']);

        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        $this->assertSame(1, $this->run_count($threadid));
    }

    /**
     * Thread-515 semantics stay: an unknown name never blocks a read-only skill.
     */
    public function test_unknown_activity_still_falls_back_to_the_ambient_context(): void {
        [$decision, $threadid] = $this->decide(['activityquery' => 'No such activity']);

        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        // Wave 25: the ambient (system) context has no booking instance, so the skill's own preflight
        // guard asks instead of the engine blocking on the unknown target; nothing runs.
        $this->assertSame(0, $this->run_count($threadid));
        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
    }

    /**
     * The answer to the clarification — the cmid of one candidate — picks that activity.
     */
    public function test_cmid_picks_one_of_the_candidates(): void {
        [$decision, $threadid] = $this->decide(['cmid' => $this->dupcmids[1]]);

        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', (array)($decision['issue_codes'] ?? []));
        $this->assertSame(1, $this->run_count($threadid));
    }
}
