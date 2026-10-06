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

namespace bookingextension_agent\local\wizard\services\telemetry;

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wizard\services\discovery\family_ranker;
use bookingextension_agent\local\wizard\services\discovery\family_registry_service;
use bookingextension_agent\local\wizard\services\discovery\family_signal_ranker;

/**
 * Persist deterministic routing telemetry and shadow-discovery traces.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class routing_decision_log_service {
    /** @var string Thread metadata key for latest normalized routing telemetry. */
    private const META_ROUTING_TELEMETRY = 'routing_decision_telemetry';

    /** @var string Thread metadata key for latest shadow decision snapshot. */
    private const META_SHADOW_TELEMETRY = 'routing_shadow_decision';

    /** @var string Thread metadata key for telemetry log history. */
    private const META_TELEMETRY_LOG = 'routing_decision_log';

    /** @var int Maximum number of log entries kept in thread metadata. */
    private const LOG_LIMIT = 50;

    /**
     * Persist one routing telemetry snapshot for a thread.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param array $telemetry
     * @param array $flags
     * @param array $discoverycontext
     * @return void
     */
    public function persist_thread_routing_decision(
        conversation_store $store,
        int $threadid,
        array $telemetry,
        array $flags,
        array $discoverycontext = []
    ): void {
        $normalized = self::normalize_telemetry($telemetry);
        $shadow = self::build_shadow_result($normalized, $flags, $discoverycontext);
        $comparison = self::build_embeddings_comparison($normalized, $shadow, $flags);

        $entry = [
            'time' => time(),
            'live_result' => $normalized,
            'shadow_result' => $shadow,
            'embeddings_comparison' => $comparison,
            'flags' => $flags,
        ];

        $store->set_thread_metadata_value($threadid, self::META_ROUTING_TELEMETRY, $normalized);
        $store->set_thread_metadata_value($threadid, self::META_SHADOW_TELEMETRY, $shadow);
        $store->set_thread_metadata_value($threadid, 'routing_embeddings_comparison', $comparison);

        $existing = $store->get_thread_metadata_value($threadid, self::META_TELEMETRY_LOG);
        if (!is_array($existing)) {
            $existing = [];
        }

        $existing[] = $entry;
        if (count($existing) > self::LOG_LIMIT) {
            $existing = array_slice($existing, -self::LOG_LIMIT);
        }

        $store->set_thread_metadata_value($threadid, self::META_TELEMETRY_LOG, array_values($existing));
    }

    /**
     * Normalize routing telemetry to fixed schema and stable value domain.
     *
     * @param array $telemetry
     * @return array
     */
    public static function normalize_telemetry(array $telemetry): array {
        $catalogselectionmode = trim((string)($telemetry['catalogselectionmode'] ?? 'none'));
        if ($catalogselectionmode === '') {
            $catalogselectionmode = 'none';
        }

        $discoverystage = trim((string)($telemetry['discovery_stage'] ?? 'unknown'));
        if ($discoverystage === '') {
            $discoverystage = 'unknown';
        }

        $rawconfidence = $telemetry['confidence_score'] ?? null;
        $confidencescore = null;
        if ($rawconfidence !== null && $rawconfidence !== '') {
            $confidencescore = (float)$rawconfidence;
        }

        $escalationreason = trim((string)($telemetry['escalation_reason'] ?? 'none'));
        if ($escalationreason === '') {
            $escalationreason = 'none';
        }

        $embeddingpath = self::derive_embedding_path($catalogselectionmode);

        return [
            'catalogselectionmode' => $catalogselectionmode,
            'embedding_path' => $embeddingpath,
            'discovery_stage' => $discoverystage,
            'confidence_score' => $confidencescore,
            'escalation_reason' => $escalationreason,
        ];
    }

    /**
     * Build shadow-only decision output detached from live routing.
     *
     * @param array $normalized
     * @param array $flags
     * @param array $discoverycontext
     * @return array
     */
    public static function build_shadow_result(array $normalized, array $flags, array $discoverycontext = []): array {
        $familyenabled = !empty($flags[runtime_feature_flags::FAMILY_DISCOVERY_ENABLED]);
        $stagedenabled = !empty($flags[runtime_feature_flags::STAGED_DISCOVERY_ENABLED]);

        $stage = $familyenabled ? 'A' : 'disabled';
        $confidencescore = $familyenabled ? 0.5 : null;
        $escalationreason = 'shadow_not_enabled';

        if ($familyenabled && $stagedenabled) {
            $promptcontracts = (array)($discoverycontext['promptcontracts'] ?? []);
            $contextprior = (array)($discoverycontext['contextprior'] ?? []);
            $recentskillnames = (array)($discoverycontext['recent_skill_names'] ?? []);

            $discovery = (new family_registry_service())->discover($promptcontracts, $contextprior)->to_array();
            $families = (array)($discovery['families'] ?? []);
            $contextfamilies = (array)($discovery['context_families'] ?? []);
            $corefamilies = (array)($discovery['core_families'] ?? []);

            $signalscores = (new family_signal_ranker())->score_families($families, $contextprior, $recentskillnames);
            $ranked = (new family_ranker())->rank($families, $signalscores, []);
            $staged = (new discovery_stage_controller())->resolve($ranked, $contextfamilies, $corefamilies);

            $stage = (string)($staged['discovery_stage'] ?? 'A');
            $escalationreason = (string)($staged['escalation_reason'] ?? 'none');
            $confidencescore = $staged['confidence_score'] ?? 0.0;
        }

        return [
            'catalogselectionmode' => (string)($normalized['catalogselectionmode'] ?? 'none'),
            'embedding_path' => self::derive_embedding_path((string)($normalized['catalogselectionmode'] ?? 'none')),
            'discovery_stage' => $stage,
            'confidence_score' => $confidencescore,
            'escalation_reason' => $escalationreason,
            'live_routing_affected' => false,
        ];
    }

    /**
     * Build a compact comparison snapshot for live-vs-shadow embedding routing.
     *
     * @param array $normalized
     * @param array $shadow
     * @param array $flags
     * @return array
     */
    public static function build_embeddings_comparison(array $normalized, array $shadow, array $flags): array {
        $familyembeddingsenabled = !empty($flags[runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED]);

        $livecatalogmode = (string)($normalized['catalogselectionmode'] ?? 'none');
        $shadowcatalogmode = (string)($shadow['catalogselectionmode'] ?? 'none');
        $liveembeddingpath = (string)($normalized['embedding_path'] ?? self::derive_embedding_path($livecatalogmode));
        $shadowembeddingpath = (string)($shadow['embedding_path'] ?? self::derive_embedding_path($shadowcatalogmode));
        $livestage = (string)($normalized['discovery_stage'] ?? 'unknown');
        $shadowstage = (string)($shadow['discovery_stage'] ?? 'unknown');

        $liveconfidence = isset($normalized['confidence_score']) && $normalized['confidence_score'] !== ''
            ? (float)$normalized['confidence_score']
            : null;
        $shadowconfidence = isset($shadow['confidence_score']) && $shadow['confidence_score'] !== ''
            ? (float)$shadow['confidence_score']
            : null;

        $comparison = [
            'family_embeddings_enabled' => $familyembeddingsenabled,
            'comparison_type' => 'with_vs_without_embeddings',
            'live_catalogselectionmode' => $livecatalogmode,
            'shadow_catalogselectionmode' => $shadowcatalogmode,
            'catalogselectionmode_changed' => $livecatalogmode !== $shadowcatalogmode,
            'live_embedding_path' => $liveembeddingpath,
            'shadow_embedding_path' => $shadowembeddingpath,
            'embedding_path_changed' => $liveembeddingpath !== $shadowembeddingpath,
            'live_discovery_stage' => $livestage,
            'shadow_discovery_stage' => $shadowstage,
            'discovery_stage_changed' => $livestage !== $shadowstage,
            'live_confidence_score' => $liveconfidence,
            'shadow_confidence_score' => $shadowconfidence,
            'confidence_delta' => null,
        ];

        if ($liveconfidence !== null && $shadowconfidence !== null) {
            $comparison['confidence_delta'] = $liveconfidence - $shadowconfidence;
        }

        return $comparison;
    }

    /**
     * Derive a coarse embedding path label from catalog selection mode.
     *
     * @param string $catalogselectionmode
     * @return string
     */
    private static function derive_embedding_path(string $catalogselectionmode): string {
        if (str_starts_with($catalogselectionmode, 'embed_')) {
            return 'with_embeddings';
        }

        if ($catalogselectionmode === 'slim_all' || $catalogselectionmode === 'none') {
            return 'no_embeddings';
        }

        if ($catalogselectionmode === 'embed_topk_cache') {
            return 'with_embeddings';
        }

        return 'unknown';
    }
}
