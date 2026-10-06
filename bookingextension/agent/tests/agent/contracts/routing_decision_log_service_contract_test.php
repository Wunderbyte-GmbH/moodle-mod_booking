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

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for routing decision telemetry normalization and shadow mode.
 *
 * @covers \bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class routing_decision_log_service_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Telemetry fields must be normalized with stable fallback values.
     */
    public function test_normalize_telemetry_stable_defaults(): void {
        $normalized = routing_decision_log_service::normalize_telemetry([]);

        $this->assertSame('none', $normalized['catalogselectionmode']);
        $this->assertSame('no_embeddings', $normalized['embedding_path']);
        $this->assertSame('unknown', $normalized['discovery_stage']);
        $this->assertNull($normalized['confidence_score']);
        $this->assertSame('none', $normalized['escalation_reason']);
    }

    /**
     * Embedding path labels must remain stable for both embedding and non-embedding modes.
     */
    public function test_normalize_telemetry_embedding_path_modes(): void {
        $with = routing_decision_log_service::normalize_telemetry([
            'catalogselectionmode' => 'embed_topk',
        ]);
        $without = routing_decision_log_service::normalize_telemetry([
            'catalogselectionmode' => 'none',
        ]);

        $this->assertSame('with_embeddings', $with['embedding_path']);
        $this->assertSame('no_embeddings', $without['embedding_path']);
    }

    /**
     * Shadow mode must never affect live routing path.
     */
    public function test_shadow_result_never_affects_live_routing(): void {
        $normalized = routing_decision_log_service::normalize_telemetry([
            'catalogselectionmode' => 'slim_all',
            'discovery_stage' => 'A',
            'confidence_score' => 0.75,
            'escalation_reason' => 'none',
        ]);

        $shadow = routing_decision_log_service::build_shadow_result($normalized, [
            runtime_feature_flags::FAMILY_DISCOVERY_ENABLED => true,
            runtime_feature_flags::STAGED_DISCOVERY_ENABLED => true,
        ], [
            'promptcontracts' => [
                ['skill' => 'mod_booking.create_option', 'family' => 'mod_booking.options', 'namespace' => 'mod_booking'],
                ['skill' => 'wizard.recall_memory', 'family' => 'core.general', 'namespace' => 'core'],
            ],
            'contextprior' => ['namespace_hint' => 'mod_booking'],
            'recent_skill_names' => ['mod_booking.create_option'],
        ]);

        $this->assertFalse($shadow['live_routing_affected']);
        $this->assertSame('slim_all', $shadow['catalogselectionmode']);
        $this->assertSame('no_embeddings', $shadow['embedding_path']);
        // The shadow now names WHY it escalates instead of reporting 'none'.
        $this->assertSame('stage_a_low_confidence', $shadow['escalation_reason']);

        // Stage B, not A: the prior above carries only namespace_hint, which is catalogue POPULARITY — the
        // namespace with the most registered skills, identical on every page of the site. Since 2026-09-20 that
        // no longer buys confidence (its weight fell from 0.35 to 0.10), so the shadow escalates instead of
        // settling. Before that date it was the strongest signal of all and produced a confident 'A' from a
        // fact that says nothing about where the user is.
        $this->assertSame('B', $shadow['discovery_stage']);
    }

    /**
     * The REAL context signal restores confidence: the plugin owning the current page settles the stage.
     *
     * Same input as above plus context_namespace, which context_owner_resolver derives from the Moodle context
     * and page type. This is the pair that shows the weights now sit on the signal that knows the context
     * rather than on the one that does not.
     */
    public function test_the_page_owner_restores_discovery_confidence(): void {
        $normalized = routing_decision_log_service::normalize_telemetry([
            'catalogselectionmode' => 'slim_all',
            'discovery_stage' => 'A',
            'confidence_score' => 0.75,
            'escalation_reason' => 'none',
        ]);

        $shadow = routing_decision_log_service::build_shadow_result($normalized, [
            runtime_feature_flags::FAMILY_DISCOVERY_ENABLED => true,
            runtime_feature_flags::STAGED_DISCOVERY_ENABLED => true,
        ], [
            'promptcontracts' => [
                ['skill' => 'mod_booking.create_option', 'family' => 'mod_booking.options', 'namespace' => 'mod_booking'],
                ['skill' => 'wizard.recall_memory', 'family' => 'core.general', 'namespace' => 'core'],
            ],
            'contextprior' => ['namespace_hint' => 'mod_booking', 'context_namespace' => 'mod_booking'],
            'recent_skill_names' => ['mod_booking.create_option'],
        ]);

        $this->assertFalse($shadow['live_routing_affected']);
        $this->assertSame('A', $shadow['discovery_stage']);
    }

    /**
     * Disabled family discovery must keep shadow stage in disabled mode.
     */
    public function test_shadow_result_disabled_when_family_discovery_flag_is_off(): void {
        $normalized = routing_decision_log_service::normalize_telemetry([
            'catalogselectionmode' => 'none',
        ]);

        $shadow = routing_decision_log_service::build_shadow_result($normalized, [
            runtime_feature_flags::FAMILY_DISCOVERY_ENABLED => false,
            runtime_feature_flags::STAGED_DISCOVERY_ENABLED => false,
        ], []);

        $this->assertSame('disabled', $shadow['discovery_stage']);
        $this->assertNull($shadow['confidence_score']);
        $this->assertSame('shadow_not_enabled', $shadow['escalation_reason']);
        $this->assertFalse($shadow['live_routing_affected']);
    }
}
