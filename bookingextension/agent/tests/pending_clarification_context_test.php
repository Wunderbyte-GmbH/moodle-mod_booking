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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * M1 (#2220): correction-turn context for the selector.
 *
 * Pins the two halves of the measure: the engine-state record of the action an open blocking
 * clarification is about (thread metadata lifecycle), and its rendering as the advisory
 * [PENDING CLARIFICATION CONTEXT] block of the SELECTION prompt. Measured baseline without it:
 * 3/6 misroutes (embed_topk) and 2/8 (slim_all replay) on the duplicate→rename scenario — see
 * Blueprint selector_correction_turn_2220_2026-08-21.md §9.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_clarification_context_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Invoke the private metadata maintainer on a real runtime + store.
     *
     * @param agent_runtime $runtime
     * @param int $threadid
     * @param array $result
     * @return void
     */
    private function maintain(agent_runtime $runtime, int $threadid, array $result): void {
        $m = (new \ReflectionClass($runtime))->getMethod('maintain_clarification_origin_task');
        $m->setAccessible(true);
        $m->invoke($runtime, $threadid, $result);
    }

    /**
     * A runtime over real collaborators (no LLM is called by the metadata maintainer).
     *
     * @param conversation_store $store
     * @return agent_runtime
     */
    private function runtime(conversation_store $store): agent_runtime {
        $registry = skill_registry::make_default();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);
        return new agent_runtime($registry, $orchestrator, $store, new authorization_service());
    }

    /**
     * Lifecycle: set on a blocking clarification with an attempted skill, preserved across
     * follow-up clarifications without one, cleared when a turn resolves.
     */
    public function test_pending_action_metadata_lifecycle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $store = new conversation_store();
        $runtime = $this->runtime($store);
        $thread = $store->create_fresh_thread((int)$USER->id, 1);
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Create a course named X');

        // Blocking clarification about an attempted create: record is written.
        $this->maintain($runtime, $threadid, [
            'response_type' => 'clarification',
            'message' => 'A course with this exact name already exists. Create another one anyway?',
            'issue_codes' => ['DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED'],
            'attempted_skills' => ['course.create_course'],
        ]);
        $raw = (string)$store->get_thread_metadata_value($threadid, 'clarification_pending_action');
        $decoded = json_decode($raw, true);
        $this->assertSame('course.create_course', $decoded['skill']);
        $this->assertSame(['DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED'], $decoded['issue_codes']);
        $this->assertStringContainsString('already exists', $decoded['question']);

        // Follow-up blocking clarification WITHOUT an attempted skill keeps the record.
        $this->maintain($runtime, $threadid, [
            'response_type' => 'clarification',
            'message' => 'Which category do you mean?',
            'issue_codes' => ['CREATE_COURSE_NAME_REQUIRED'],
            'attempted_skills' => [],
        ]);
        $kept = json_decode((string)$store->get_thread_metadata_value($threadid, 'clarification_pending_action'), true);
        $this->assertSame('course.create_course', $kept['skill']);

        // A resolving turn clears the record (and the origin task).
        $this->maintain($runtime, $threadid, [
            'response_type' => 'confirmation_request',
            'message' => 'Create it?',
            'issue_codes' => [],
            'attempted_skills' => ['course.create_course'],
        ]);
        $this->assertSame('', (string)$store->get_thread_metadata_value($threadid, 'clarification_pending_action'));
        $this->assertSame('', (string)$store->get_thread_metadata_value($threadid, 'clarification_origin_task'));
    }

    /**
     * Rendering: the selection prompt carries the advisory block iff a pending action exists,
     * and the construction prompt never does.
     */
    public function test_selection_prompt_carries_pending_clarification_block(): void {
        $this->resetAfterTest();
        $registry = $this->createMock(skill_registry::class);
        $builder = new phase_prompt_bundle_builder($registry, new orchestrator_prompt_profile_service());

        $pending = [
            'skill' => 'course.create_course',
            'issue_codes' => ['DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED'],
            'question' => 'A course with this exact name already exists. Create another one anyway?',
        ];
        $messages = [(object)['role' => 'user', 'content' => 'No, call it Y instead']];

        $selection = $builder->build_prompt(
            'SYS',
            $messages,
            [],
            orchestrator_prompt_profile_service::PHASE_SELECTION,
            '',
            [],
            false,
            [],
            '',
            null,
            $pending
        );
        $this->assertStringContainsString('[PENDING CLARIFICATION CONTEXT]', $selection);
        $this->assertStringContainsString('attempted skill: course.create_course', $selection);
        $this->assertStringContainsString('DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED', $selection);
        $this->assertStringContainsString('select course.create_course again', $selection);
        // Not a lock: the decline-and-new-request escape hatch must be part of the block.
        $this->assertStringContainsString('route the new request', $selection);

        // Empty record → no block.
        $none = $builder->build_prompt(
            'SYS',
            $messages,
            [],
            orchestrator_prompt_profile_service::PHASE_SELECTION,
            '',
            [],
            false,
            [],
            '',
            null,
            []
        );
        $this->assertStringNotContainsString('[PENDING CLARIFICATION CONTEXT]', $none);

        // Construction phase never renders it, even when passed.
        $construction = $builder->build_prompt(
            'SYS',
            $messages,
            [],
            orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION,
            '',
            [],
            false,
            [],
            '',
            null,
            $pending
        );
        $this->assertStringNotContainsString('[PENDING CLARIFICATION CONTEXT]', $construction);
    }
}
