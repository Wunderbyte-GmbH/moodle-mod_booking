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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\discovery\discovery_budget_policy;
use bookingextension_agent\local\wizard\services\discovery\discovery_confidence_policy;
use bookingextension_agent\local\wizard\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\discovery\family_ranker;
use bookingextension_agent\local\wizard\services\discovery\family_registry_service;
use bookingextension_agent\local\wizard\services\discovery\family_signal_ranker;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for phase-2 staged discovery policies and escalation.
 *
 * @covers \bookingextension_agent\local\wizard\services\discovery\discovery_budget_policy
 * @covers \bookingextension_agent\local\wizard\services\discovery\discovery_confidence_policy
 * @covers \bookingextension_agent\local\wizard\services\discovery\family_signal_ranker
 * @covers \bookingextension_agent\local\wizard\services\discovery\family_ranker
 * @covers \bookingextension_agent\local\wizard\services\discovery\context_prior_builder
 * @covers \bookingextension_agent\local\wizard\services\discovery\family_registry_service
 * @covers \bookingextension_agent\local\wizard\services\discovery\discovery_stage_controller
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase2_discovery_staging_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Budget policy must enforce monotonic stage budgets.
     */
    public function test_budget_policy_stage_caps_are_monotonic(): void {
        $policy = new discovery_budget_policy();

        $this->assertGreaterThan(0, $policy->get_stage_budget('A'));
        $this->assertGreaterThan($policy->get_stage_budget('A'), $policy->get_stage_budget('B'));
        $this->assertGreaterThan($policy->get_stage_budget('B'), $policy->get_stage_budget('C'));
    }

    /**
     * Confidence policy must normalize and threshold scores deterministically.
     */
    public function test_confidence_policy_thresholds(): void {
        $policy = new discovery_confidence_policy();

        $this->assertFalse($policy->is_sufficient(0.30, 'A'));
        $this->assertTrue($policy->is_sufficient(0.65, 'A'));
        $this->assertFalse($policy->is_sufficient(0.30, 'B'));
        $this->assertTrue($policy->is_sufficient(0.50, 'B'));
        $this->assertSame(1.0, $policy->normalize_score(1.5));
        $this->assertSame(0.0, $policy->normalize_score(-1.0));
    }

    /**
     * Signal ranker must prefer namespace-hinted families.
     */
    public function test_signal_ranker_prefers_namespace_hint(): void {
        $ranker = new family_signal_ranker();
        $scores = $ranker->score_families(
            ['mod_booking.options', 'core.general', 'local_entities.general'],
            ['namespace_hint' => 'mod_booking'],
            []
        );

        $this->assertGreaterThan($scores['core.general'], $scores['mod_booking.options']);
        $this->assertGreaterThan($scores['local_entities.general'], $scores['mod_booking.options']);
    }

    /**
     * Signal ranker should apply centrally configured weights.
     */
    public function test_signal_ranker_supports_configurable_weights(): void {
        $ranker = new family_signal_ranker([
            'base' => 0.10,
            'core' => 0.00,
            'namespace_hint' => 0.60,
            'recent_namespace' => 0.25,
        ]);

        $scores = $ranker->score_families(
            ['mod_booking.options', 'core.general'],
            ['namespace_hint' => 'mod_booking'],
            ['mod_booking.create_option']
        );

        // Base + namespace_hint + recent_namespace.
        $this->assertEqualsWithDelta(0.95, $scores['mod_booking.options'], 0.001);
        // Base only (core weight overridden to 0).
        $this->assertEqualsWithDelta(0.10, $scores['core.general'], 0.001);
    }

    /**
     * Signal ranker must ignore language tokens that are not skill namespaces.
     */
    public function test_signal_ranker_ignores_language_tokens_without_skill_namespace_shape(): void {
        $ranker = new family_signal_ranker();

        $baseline = $ranker->score_families(
            ['mod_booking.options', 'core.general'],
            ['namespace_hint' => 'mod_booking'],
            []
        );
        $withlanguagetokens = $ranker->score_families(
            ['mod_booking.options', 'core.general'],
            ['namespace_hint' => 'mod_booking'],
            ['deutsch bitte', 'english please', 'bitte hilf mir']
        );

        $this->assertSame($baseline, $withlanguagetokens);
    }

    /**
     * Context prior must remain a soft ranking hint payload.
     */
    public function test_context_prior_builder_emits_soft_prior_payload(): void {
        $prior = (new context_prior_builder())->build(77, [
            'userid' => 99,
            'namespace_hint' => 'mod_booking',
            'page_type' => 'course-module',
        ]);

        $this->assertSame(77, $prior['contextid']);
        $this->assertSame('mod_booking', $prior['namespace_hint']);
        $this->assertSame('course-module', $prior['page_type']);
        $this->assertFalse((bool)($prior['is_hard_filter'] ?? true));
        $this->assertTrue((bool)($prior['user_state']['is_authenticated'] ?? false));
    }

    /**
     * Discovery must not apply hard context exclusion when namespace hint misses.
     */
    public function test_family_registry_uses_prior_without_hard_exclusion(): void {
        $promptcontracts = [
            ['skill' => 'mod_booking.create_option', 'family' => 'mod_booking.options'],
            ['skill' => 'local_entities.create_entity', 'family' => 'local_entities.general'],
            ['skill' => 'core.search', 'family' => 'core.general'],
        ];
        $prior = (new context_prior_builder())->build(123, [
            'userid' => 42,
            'namespace_hint' => 'non_matching_namespace',
        ]);

        $result = (new family_registry_service())->discover($promptcontracts, $prior)->to_array();

        $families = (array)($result['families'] ?? []);
        $contextfamilies = (array)($result['context_families'] ?? []);
        $this->assertContains('mod_booking.options', $families);
        $this->assertContains('local_entities.general', $families);
        $this->assertContains('core.general', $families);
        // No hard exclusion on a namespace miss: every passed family survives into the context set.
        $this->assertEqualsCanonicalizing(
            ['mod_booking.options', 'local_entities.general', 'core.general'],
            $contextfamilies
        );
        // The merged family set is a superset of the context families plus the always-on core
        // baseline (core_family_set seeds wizard.general), so it is never narrower than the context set.
        foreach ($contextfamilies as $contextfamily) {
            $this->assertContains($contextfamily, $families);
        }
        $this->assertContains('wizard.general', $families);
        $this->assertSame($prior, (array)($result['context_prior'] ?? []));
    }

    /**
     * Family ranker should be the authoritative deterministic scoring source.
     */
    public function test_family_ranker_merges_signal_and_semantic_scores_deterministically(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'core.general', 'local_entities.general'],
            ['mod_booking.options' => 0.70, 'core.general' => 0.60, 'local_entities.general' => 0.60],
            ['mod_booking.options' => 0.20, 'core.general' => 0.90, 'local_entities.general' => 0.40]
        );

        $families = array_map(static fn(array $row): string => (string)$row['family'], $ranked);
        $this->assertSame(['core.general', 'mod_booking.options', 'local_entities.general'], $families);
        $this->assertEqualsWithDelta(0.69, (float)$ranked[0]['score'], 0.001);
        $this->assertEqualsWithDelta(0.55, (float)$ranked[1]['score'], 0.001);
    }

    /**
     * Stage controller should forward a controlled low-score tail to selection.
     */
    public function test_stage_controller_forwards_controlled_low_score_tail(): void {
        $ranked = [
            ['family' => 'mod_booking.options', 'score' => 0.90],
            ['family' => 'core.general', 'score' => 0.40],
            ['family' => 'local_entities.general', 'score' => 0.35],
            ['family' => 'mod_booking.notifications', 'score' => 0.25],
            ['family' => 'mod_booking.reports', 'score' => 0.20],
            ['family' => 'mod_booking.ignored', 'score' => 0.10],
        ];

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], []);
        $selected = (array)($result['selected_families'] ?? []);

        $this->assertContains('mod_booking.options', $selected);
        $this->assertContains('core.general', $selected);
        $this->assertContains('local_entities.general', $selected);
        $this->assertNotContains('mod_booking.ignored', $selected);
        $this->assertLessThanOrEqual(3, count(array_intersect(
            ['core.general', 'local_entities.general', 'mod_booking.notifications', 'mod_booking.reports'],
            $selected
        )));
    }

    /**
     * Stage controller should stay in A when confidence is sufficient.
     */
    public function test_stage_controller_stays_in_a_when_confident(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'core.general'],
            ['mod_booking.options' => 0.80, 'core.general' => 0.30],
            []
        );

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], ['core.general']);

        $this->assertSame('A', $result['discovery_stage']);
        $this->assertSame('none', $result['escalation_reason']);
        $this->assertGreaterThanOrEqual(0.60, (float)$result['confidence_score']);
    }

    /**
     * Stage controller should escalate to C when A/B confidence is insufficient.
     */
    public function test_stage_controller_escalates_to_c_when_confidence_is_low(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'core.general', 'local_entities.general'],
            ['mod_booking.options' => 0.20, 'core.general' => 0.15, 'local_entities.general' => 0.10],
            []
        );

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], ['core.general']);

        $this->assertSame('C', $result['discovery_stage']);
        $this->assertSame('stage_b_low_confidence', $result['escalation_reason']);
        $this->assertIsArray($result['selected_families']);
        $this->assertNotEmpty($result['selected_families']);
    }

    /**
     * On a namespace match the matched namespace is the Stage A prior, but the candidate
     * universe must still contain cross-namespace families (context = prior, not hard filter).
     */
    public function test_family_registry_keeps_full_universe_on_namespace_match(): void {
        $promptcontracts = [
            ['skill' => 'mod_booking.create_option', 'family' => 'mod_booking.options'],
            ['skill' => 'course.add_quiz', 'family' => 'course.general'],
            ['skill' => 'core.diagnose_permissions', 'family' => 'core.general'],
        ];
        $prior = (new context_prior_builder())->build(123, [
            'userid' => 42,
            'namespace_hint' => 'mod_booking',
        ]);

        $result = (new family_registry_service())->discover($promptcontracts, $prior)->to_array();
        $families = (array)($result['families'] ?? []);
        $contextfamilies = (array)($result['context_families'] ?? []);

        // Stage A prior is narrowed to the matched namespace ...
        $this->assertSame(['mod_booking.options'], $contextfamilies);
        // ... but the ranking universe keeps every family so intent can surface them.
        $this->assertContains('mod_booking.options', $families);
        $this->assertContains('course.general', $families);
        $this->assertContains('core.general', $families);
    }

    /**
     * A high context signal must NOT short-circuit Stage A when the strongest semantic
     * (intent) match is a family outside Stage A — escalate so it stays discoverable.
     */
    public function test_stage_controller_escalates_when_semantic_intent_is_outside_stage_a(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'course.general', 'core.general'],
            ['mod_booking.options' => 0.90, 'course.general' => 0.10, 'core.general' => 0.20],
            ['mod_booking.options' => 0.10, 'course.general' => 0.95, 'core.general' => 0.10]
        );

        $result = (new discovery_stage_controller())->resolve(
            $ranked,
            ['mod_booking.options'],
            ['core.general']
        );

        $this->assertNotSame('A', $result['discovery_stage']);
        $this->assertSame('stage_a_intent_outside', $result['escalation_reason']);
        $this->assertContains('course.general', (array)$result['selected_families']);
    }

    /**
     * When the strongest semantic match is already inside Stage A, the guard must not
     * over-escalate: a confident in-context query still resolves at Stage A.
     */
    public function test_stage_controller_stays_in_a_when_semantic_intent_is_inside_stage_a(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'course.general'],
            ['mod_booking.options' => 0.80, 'course.general' => 0.20],
            ['mod_booking.options' => 0.90, 'course.general' => 0.30]
        );

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], ['core.general']);

        $this->assertSame('A', $result['discovery_stage']);
        $this->assertSame('none', $result['escalation_reason']);
    }
}
