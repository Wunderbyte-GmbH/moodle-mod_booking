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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

/**
 * Prompt-cache stability guard.
 *
 * The agent builds its planner prompt cache-friendly: a per-thread-stable [SYSTEM_RUNTIME] block sits
 * in the cacheable prefix (right after the static [SYSTEM] block), while volatile per-request state
 * goes below the history as [SYSTEM_RUNTIME_STATE]. For the upstream LLM prompt cache to actually hit,
 * that stable prefix MUST be byte-identical no matter which context (course/activity/user) the agent
 * runs in — otherwise every context is a cache miss.
 *
 * This test pins that invariant for BOTH skill-catalog states:
 *   - stale / static catalog (slim_all, embeddings NOT ready) → catalog joins the cached prefix,
 *   - ready / dynamic catalog (embeddings top-k)             → catalog stays in the volatile half.
 * In both states the stable prefix is required to be context-invariant.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_cache_stability_test extends advanced_testcase {
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
     * A small, deterministic, context-independent catalog (its rendering must be identical everywhere).
     *
     * @return array[]
     */
    private function probe_catalog(): array {
        return [
            ['skill' => 'test.cache_probe_one', 'description' => 'First probe skill.', 'readonly' => true],
            ['skill' => 'test.cache_probe_two', 'description' => 'Second probe skill.', 'readonly' => false],
        ];
    }

    /**
     * Build the runtime blocks for two DISTINCT contexts with otherwise identical inputs.
     *
     * @param bool $catalogisstatic
     * @return array
     */
    private function build_two_contexts(bool $catalogisstatic): array {
        $user = $this->getDataGenerator()->create_user();
        $coursea = $this->getDataGenerator()->create_course(['fullname' => 'Context Alpha']);
        $courseb = $this->getDataGenerator()->create_course(['fullname' => 'Context Beta']);
        $this->getDataGenerator()->enrol_user($user->id, $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $courseb->id, 'editingteacher');
        $ctxa = (int)context_course::instance($coursea->id)->id;
        $ctxb = (int)context_course::instance($courseb->id)->id;

        $store = new conversation_store();
        $threada = (int)$store->get_or_create_thread((int)$user->id, $ctxa)->id;
        $threadb = (int)$store->get_or_create_thread((int)$user->id, $ctxb)->id;

        $catalog = $this->probe_catalog();
        $builder = $this->builder($store);

        // Same inputs for both; only the context (and its thread) differs.
        $blocka = $builder->build(
            $threada,
            $ctxa,
            orchestrator::PHASE_SELECTION,
            false,
            false,
            $catalog,
            [],
            [],
            '',
            [],
            $catalogisstatic
        );
        $blockb = $builder->build(
            $threadb,
            $ctxb,
            orchestrator::PHASE_SELECTION,
            false,
            false,
            $catalog,
            [],
            [],
            '',
            [],
            $catalogisstatic
        );

        return [$blocka, $blockb];
    }

    /**
     * Stale / static catalog: the cacheable prefix (catalog included) is identical across contexts,
     * and the context-specific name stays out of it (it would otherwise bust the cache).
     */
    public function test_static_catalog_cacheable_prefix_is_context_invariant(): void {
        $this->resetAfterTest();
        [$a, $b] = $this->build_two_contexts(true);

        $this->assertSame(
            $a['stable'],
            $b['stable'],
            'Static catalog: the cacheable [SYSTEM_RUNTIME] prefix must be byte-identical across contexts.'
        );

        // The static catalog genuinely lives in the cached prefix...
        $this->assertStringContainsString('SKILL CATALOG:', $a['stable']);
        $this->assertStringContainsString('test.cache_probe_one', $a['stable']);

        // ...but nothing context-specific does — that sits in the volatile half, where A and B differ.
        $this->assertStringNotContainsString('Context Alpha', $a['stable']);
        $this->assertStringNotContainsString('Context Beta', $b['stable']);
        $this->assertStringContainsString('Context Alpha', $a['volatile']);
        $this->assertStringContainsString('Context Beta', $b['volatile']);
        $this->assertNotSame(
            $a['volatile'],
            $b['volatile'],
            'Sanity: the volatile half is exactly where context-specific state is allowed to differ.'
        );
    }

    /**
     * Ready / dynamic catalog: a per-query catalog must NOT enter the cached prefix (it would change
     * every turn); it lives in the volatile half, and the stable prefix stays context-invariant.
     */
    public function test_dynamic_catalog_keeps_prefix_invariant_and_catalog_out_of_cache(): void {
        $this->resetAfterTest();
        [$a, $b] = $this->build_two_contexts(false);

        $this->assertSame(
            $a['stable'],
            $b['stable'],
            'Dynamic catalog: the cacheable [SYSTEM_RUNTIME] prefix must be byte-identical across contexts.'
        );

        $this->assertStringNotContainsString(
            'SKILL CATALOG:',
            $a['stable'],
            'A per-query (dynamic) catalog must never enter the cached prefix.'
        );
        $this->assertStringContainsString('SKILL CATALOG:', $a['volatile']);
        $this->assertStringContainsString('test.cache_probe_one', $a['volatile']);
    }
}
