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
use context_course;
use context_module;
use context_system;
use context_user;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\user_memory_service;

/**
 * The runtime context block must be generic and site-wide: it emits a "context_name" line for every
 * context level and never the legacy booking-specific "booking_name" (the agent is no longer
 * booking-only).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runtime_context_block_builder_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Build the service with its (simple) dependency chain.
     *
     * @param conversation_store $store
     * @return runtime_context_block_builder
     */
    private function builder(conversation_store $store): runtime_context_block_builder {
        return new runtime_context_block_builder(
            $store,
            new completed_command_history_service($store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
    }

    /**
     * A course context yields the course name under the generic context_name label, never booking_name.
     */
    public function test_course_context_emits_generic_context_name(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $block = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION);

        // The generic context name carries the Moodle context name ("Course: Algebra 101"), under the
        // context_name label — never the legacy booking_name.
        $this->assertMatchesRegularExpression('/^context_name: .*Algebra 101/m', $block['volatile']);
        // It must NOT sit in the cached [SYSTEM_RUNTIME] half (it would bust the catalog cache), and
        // the legacy label must be gone everywhere.
        $this->assertStringNotContainsString('context_name', $block['stable']);
        $this->assertStringNotContainsString('booking_name', $block['stable']);
        $this->assertStringNotContainsString('booking_name', $block['volatile']);
    }

    /**
     * A user context (where the agent now also runs) must NOT be mislabelled as a booking instance.
     */
    public function test_user_context_is_not_labelled_as_booking(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $ctxid = (int)context_user::instance($user->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $block = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION);

        $this->assertStringContainsString('context_name:', $block['volatile']);
        $this->assertStringNotContainsString('booking_name', $block['stable']);
        $this->assertStringNotContainsString('booking_name', $block['volatile']);
    }

    /**
     * Selection must receive the structured moodle_context (so the CONTEXT-AWARE PLANNING rule is
     * data-backed), but in the VOLATILE half so it never busts the cached skill-catalog prefix.
     */
    /**
     * The requester's own identity reaches BOTH planner phases.
     *
     * Baseline run 16, BKU-2 ("Trag mich beim Nähcafé ein"): the selector asked the requester for their own
     * name. The identity block existed, but only for the construction phase, so the selector read the request
     * as missing input. Anonymized tokens only — the clear-text identity never travels to the model.
     */
    public function test_requester_identity_reaches_selection_and_construction(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;
        $builder = $this->builder($store);

        foreach ([orchestrator::PHASE_SELECTION, orchestrator::PHASE_PARAMETER_CONSTRUCTION] as $phase) {
            $block = $builder->build($threadid, $ctxid, $phase);
            $this->assertStringContainsString(
                'current_user',
                $block['volatile'],
                'the requester identity is missing in phase ' . $phase
            );
        }
    }

    public function test_selection_gets_moodle_context_in_the_volatile_half(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $block = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION);

        $this->assertStringContainsString('moodle_context:', $block['volatile']);
        $this->assertStringContainsString('Algebra 101', $block['volatile']);
        // Must stay OUT of the cached prefix (would otherwise break cross-context catalog caching).
        $this->assertStringNotContainsString('moodle_context:', $block['stable']);
    }

    /**
     * User memory is per-user, so it must live in the VOLATILE half — keeping it out of the cached
     * prefix lets the static catalog cache across different users, not just one user's contexts.
     */
    public function test_user_memory_is_emitted_in_the_volatile_half(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        (new user_memory_service())->add(
            (int)$user->id,
            'Always reply in German',
            [user_memory_service::SCOPE_SELECTION]
        );

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $block = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION);

        $this->assertStringContainsString('USER MEMORY', $block['volatile']);
        $this->assertStringContainsString('Always reply in German', $block['volatile']);
        $this->assertStringNotContainsString('USER MEMORY', $block['stable']);
    }

    /**
     * A module (cm) context is the richest grounding: the structured block must carry the resolved
     * course: AND module: sub-blocks (fullname, cmid, modname, instance_id) so construction can fill
     * course/activity targets without a clarification round-trip. This is the most-used production path
     * and was previously untested.
     */
    public function test_module_context_emits_course_and_module_subblocks(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'fullname'  => 'Algebra 101',
            'shortname' => 'ALG101',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $cm = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name'   => 'Sprechstunde',
        ]);
        $ctxid = (int)context_module::instance((int)$cm->cmid)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $vol = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile'];

        $this->assertStringContainsString('moodle_context:', $vol);
        $this->assertStringContainsString('context_level: "Module"', $vol);
        // Course sub-block.
        $this->assertStringContainsString('course:', $vol);
        $this->assertStringContainsString('Algebra 101', $vol);
        $this->assertStringContainsString('ALG101', $vol);
        // Module sub-block.
        $this->assertStringContainsString('module:', $vol);
        $this->assertStringContainsString('cmid: ' . (int)$cm->cmid, $vol);
        $this->assertStringContainsString('modname: "booking"', $vol);
        $this->assertStringContainsString('instance_id: ' . (int)$cm->id, $vol);
    }

    /**
     * The dashboard/admin (system) context has no course: the block must render gracefully — a
     * System-level moodle_context with NO course/module sub-block — and never throw. Previously untested.
     */
    public function test_system_context_has_no_course_block_and_does_not_crash(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $ctxid = (int)context_system::instance()->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;

        $vol = $this->builder($store)->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile'];

        $this->assertStringContainsString('moodle_context:', $vol);
        $this->assertStringContainsString('context_level: "System"', $vol);
        // A system context resolves to no course, so neither sub-block is emitted.
        $this->assertStringNotContainsString('course:', $vol);
        $this->assertStringNotContainsString('module:', $vol);
    }

    /**
     * Regression guard for the benchmark harness (benchmark_run_service): it once passed the raw cmid
     * where the orchestrator expects a CONTEXT id (context::instance_by_id). The two are different
     * integers, and only the resolved context id grounds the agent in its course — feeding the bare cmid
     * lands the agent in an unrelated/absent context (that is how the "context_level: User" benchmark
     * prompts arose). This pins the distinction so a re-introduction fails fast.
     */
    public function test_benchmark_cmid_must_be_resolved_to_a_context_id(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $cm = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name'   => 'Sprechstunde',
        ]);
        $cmid  = (int)$cm->cmid;
        $ctxid = (int)context_module::instance($cmid)->id;

        // The crux of the bug: a course_modules id and its context id are distinct integers.
        $this->assertNotSame($cmid, $ctxid, 'cmid and its module context id must differ');

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$user->id, $ctxid)->id;
        $builder  = $this->builder($store);

        // Correct wiring (resolved context id): Module level + real course grounding.
        $correct = $builder->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile'];
        $this->assertStringContainsString('context_level: "Module"', $correct);
        $this->assertStringContainsString('Algebra 101', $correct);

        // The bug (bare cmid used as a context id): rendered gracefully (no throw) but WITHOUT the
        // course grounding — the agent never sees "Algebra 101".
        $buggy = $builder->build($threadid, $cmid, orchestrator::PHASE_SELECTION)['volatile'];
        $this->assertStringNotContainsString('Algebra 101', $buggy);
    }
}
