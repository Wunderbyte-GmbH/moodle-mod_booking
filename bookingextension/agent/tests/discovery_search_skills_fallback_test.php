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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\discovery_phase_service;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

/**
 * The ONE sanctioned exception to semantic-only discovery: wizard.search_skills (the RAG fallback
 * meta-skill) is always force-added to the planner catalog, in addition to the semantic top-k, and
 * never duplicated.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\discovery_phase_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class discovery_search_skills_fallback_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Build the service with its (mostly inert for this method) dependency chain.
     *
     * @return discovery_phase_service
     */
    private function service(): discovery_phase_service {
        $store = new conversation_store();
        $registry = skill_registry_factory::get_default();
        $catalogsvc = new planner_catalog_service(new assistant_state_guidance_service());
        return new discovery_phase_service(
            $store,
            $registry,
            new orchestrator_routing_service('planner'),
            new orchestrator_prompt_profile_service(),
            $catalogsvc,
            new runtime_context_block_builder($store, new completed_command_history_service($store), $catalogsvc),
            new phase_prompt_bundle_builder($registry, new orchestrator_prompt_profile_service())
        );
    }

    /**
     * Invoke the protected ensure_search_skills_fallback method via reflection.
     *
     * @param discovery_phase_service $svc
     * @param array $catalog
     * @param array $contracts
     * @return array
     */
    private function invoke(discovery_phase_service $svc, array $catalog, array $contracts): array {
        $m = new \ReflectionMethod($svc, 'ensure_search_skills_fallback');
        $m->setAccessible(true);
        return (array)$m->invoke($svc, $catalog, $contracts);
    }

    /**
     * A semantic top-k that does NOT contain search_skills gets it appended.
     */
    public function test_search_skills_force_added_when_missing(): void {
        $this->resetAfterTest();
        $topk = [['skill' => 'mod_booking.create_option'], ['skill' => 'mod_booking.book_users']];
        $contracts = [
            ['skill' => 'mod_booking.create_option', 'description' => 'Create an option.'],
            ['skill' => 'wizard.search_skills', 'description' => 'Search the skill registry.'],
        ];

        $out = $this->invoke($this->service(), $topk, $contracts);

        $names = array_map(static fn(array $e): string => (string)($e['skill'] ?? ''), $out);
        $this->assertContains('wizard.search_skills', $names);
        $this->assertCount(3, $out, 'top-k (2) + the forced fallback');
    }

    /**
     * When the user's roles lack the fallback capability, its contract is absent and the
     * force-add has nothing to append — the fallback vanishes silently (finding F39b).
     * This pin documents the mechanism the FALLBACK POLICY below compensates for.
     */
    public function test_fallback_vanishes_when_contract_is_filtered(): void {
        $this->resetAfterTest();
        $topk = [['skill' => 'mod_booking.create_option']];
        $contracts = [['skill' => 'mod_booking.create_option', 'description' => 'Create an option.']];

        $out = $this->invoke($this->service(), $topk, $contracts);

        $names = array_map(static fn(array $e): string => (string)($e['skill'] ?? ''), $out);
        $this->assertNotContains('wizard.search_skills', $names);
    }

    /**
     * A missing fallback injects the honest-degradation policy into the volatile runtime
     * block: unroutable requests become a clarification, never an invented permission error.
     */
    public function test_missing_fallback_appends_honesty_policy(): void {
        $this->resetAfterTest();
        $svc = $this->service();
        $m = new \ReflectionMethod($svc, 'append_missing_fallback_policy');
        $m->setAccessible(true);

        $blocks = ['stable' => 'S', 'volatile' => 'V'];
        $without = (array)$m->invoke($svc, $blocks, [['skill' => 'mod_booking.create_option']], false);
        $this->assertStringContainsString('[FALLBACK POLICY]', (string)$without['volatile']);
        $this->assertStringContainsString('response_type=clarification', (string)$without['volatile']);

        $withfallback = (array)$m->invoke($svc, $blocks, [['skill' => 'wizard.search_skills']], false);
        $this->assertStringNotContainsString('[FALLBACK POLICY]', (string)$withfallback['volatile']);

        $static = (array)$m->invoke($svc, $blocks, [['skill' => 'mod_booking.create_option']], true);
        $this->assertStringNotContainsString('[FALLBACK POLICY]', (string)$static['volatile']);
    }

    /**
     * If search_skills already ranked into the top-k, it must NOT be duplicated.
     */
    public function test_no_duplicate_when_already_present(): void {
        $this->resetAfterTest();
        $topk = [['skill' => 'wizard.search_skills'], ['skill' => 'mod_booking.book_users']];
        $contracts = [['skill' => 'wizard.search_skills', 'description' => 'Search the skill registry.']];

        $out = $this->invoke($this->service(), $topk, $contracts);

        $count = count(array_filter($out, static fn(array $e): bool => ($e['skill'] ?? '') === 'wizard.search_skills'));
        $this->assertSame(1, $count, 'search_skills must appear exactly once');
        $this->assertCount(2, $out);
    }
}
