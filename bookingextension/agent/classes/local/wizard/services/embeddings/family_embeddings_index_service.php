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
 * Index service for family-level skill catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper;
use context_system;

/**
 * Rebuilds and persists the skill-catalog embeddings index.
 */
class family_embeddings_index_service {
    /**
     * Rebuild the skill-catalog embeddings index from the current registry.
     *
     * Writes through the {@see \bookingextension_agent\local\wizard\services\retrieval\embeddings_store}
     * abstraction (CSV or DB backend, selected by the embeddingsstore flag) as an atomic generation
     * swap: unchanged anchors are reused by identity + content hash (no re-embed), changed/new anchors
     * are embedded, then the new generation is published atomically.
     *
     * @param skill_registry $registry
     * @param string|null $model
     * @param int|null $dimensions
     * @param bool $forcefullregen
     * @return array
     */
    public function rebuild_catalog(
        skill_registry $registry,
        ?string $model = null,
        ?int $dimensions = null,
        bool $forcefullregen = false
    ): array {
        if (!class_exists(\bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS)) {
            return [
                'status' => 'skipped',
                'reason' => 'embeddings_provider_unavailable',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
        }

        $resolved = (new embeddings_action_config_resolver())->resolve_with_overrides($model, $dimensions);
        $resolvedmodel = $resolved['model'];
        $resolveddimensions = $resolved['dimensions'];

        $builder = new embeddings_catalog_builder_service();
        $rows = $builder->build_full_catalog_rows($registry, $resolvedmodel, $resolveddimensions);
        if (empty($rows)) {
            return [
                'status' => 'empty',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
        }

        // Variant-scoped store (CSV or DB, selected by the embeddingsstore flag): the active model's
        // index lives under its own variant, so a model switch never invalidates the others. Respects
        // the model/dimensions overrides above.
        $store = embeddings_store_factory::instance();
        $mapper = new skill_row_mapper();
        $area = skill_row_mapper::AREA;

        // Multi-vector store (SKILL_REWORK.md §5): a skill spans several anchor rows. Reuse and state
        // tracking are keyed per ANCHOR (skill#anchor_index); the per-skill state is then aggregated.
        // One streaming pass over the committed index collects the per-anchor content hashes and the
        // per-skill anchor counts driving the skill-state summary; the full old row (with its vector)
        // is fetched lazily per anchor via reuse_existing() in the rebuild loop below.
        $existinghashbyanchor = [];
        $existingskillset = [];
        $existinganchorcount = [];
        foreach ($store->stream_rows($area, $resolvedmodel, $resolveddimensions) as $existing) {
            $skillname = trim($existing->owner);
            if ($skillname === '') {
                continue;
            }
            $existinghashbyanchor[$skillname . '#' . $existing->refindex] = $existing->contenthash;
            $existingskillset[$skillname] = true;
            $existinganchorcount[$skillname] = ($existinganchorcount[$skillname] ?? 0) + 1;
        }

        // A skill is 'untouched' only when EVERY current anchor matches a stored anchor by
        // content_hash AND the anchor count is unchanged (an added/removed utterance => 'updated').
        $currentanchorcount = [];
        $skillunchanged = [];
        $currentskillnames = [];
        foreach ($rows as $row) {
            $skillname = trim((string)($row['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            $currentskillnames[$skillname] = true;
            $currentanchorcount[$skillname] = ($currentanchorcount[$skillname] ?? 0) + 1;
            if (!array_key_exists($skillname, $skillunchanged)) {
                $skillunchanged[$skillname] = true;
            }
            $existinghash = $existinghashbyanchor[$this->anchor_key($row)] ?? null;
            if (
                $existinghash === null
                || trim($existinghash) !== trim((string)($row['content_hash'] ?? ''))
            ) {
                $skillunchanged[$skillname] = false;
            }
        }

        $skillstates = [];
        foreach (array_keys($currentskillnames) as $skillname) {
            if (empty($existingskillset[$skillname])) {
                $skillstates[$skillname] = 'created';
            } else if (
                $forcefullregen
                || empty($skillunchanged[$skillname])
                || (int)($existinganchorcount[$skillname] ?? 0) !== (int)($currentanchorcount[$skillname] ?? 0)
            ) {
                $skillstates[$skillname] = 'updated';
            } else {
                $skillstates[$skillname] = 'untouched';
            }
        }

        $currentskillnameslist = array_keys($currentskillnames);
        sort($currentskillnameslist);
        $removedskills = array_values(array_diff(array_keys($existingskillset), $currentskillnameslist));
        sort($removedskills);
        foreach ($removedskills as $skillname) {
            $skillstates[$skillname] = 'deleted';
        }

        $context = context_system::instance();
        $admin = get_admin();
        $userid = !empty($admin->id) ? (int)$admin->id : 2;
        // Counted per ANCHOR row (one skill can have some anchors reused and others re-embedded).
        $embeddedcount = 0;
        $reusedcount = 0;
        $llm = new llm_call_service(new conversation_store());

        // Atomic generation swap (same pattern as the docs index): every current anchor row is written
        // into a fresh, uncommitted generation — reused by identity + content hash where possible —
        // then published in one step. Readers only ever see the committed generation; anchors of
        // removed skills are simply not re-emitted (naturally pruned by the swap).
        $gen = $store->begin_generation($area, $resolvedmodel, $resolveddimensions);
        try {
            foreach ($rows as $row) {
                $contenthash = trim((string)($row['content_hash'] ?? ''));

                if (!$forcefullregen) {
                    $oldrow = $store->reuse_existing(
                        $area,
                        $resolvedmodel,
                        $resolveddimensions,
                        $mapper->identity_key($row)
                    );
                    if ($oldrow !== null && $oldrow->contenthash === $contenthash && !empty($oldrow->embedding)) {
                        $store->upsert($area, $gen, $oldrow);
                        $reusedcount++;
                        continue;
                    }
                }

                $embedding = [];
                $inputtext = (string)($row['_embedding_input'] ?? '');
                if ($inputtext !== '') {
                    $embeddingcall = $llm->invoke_embeddings_for_context(
                        0,
                        (int)$context->id,
                        $userid,
                        'idx|p=disc|st=emb|ac=emb|rt=wb',
                        $inputtext,
                        $resolveddimensions
                    );
                    if (!empty($embeddingcall['success'])) {
                        $embedding = (array)($embeddingcall['embedding'] ?? []);
                    }
                }
                if (!empty($embedding)) {
                    $embeddedcount++;
                }

                // A failed/empty embedding call still writes the anchor row — with an empty vector —
                // exactly like the previous CSV write path did: readiness treats the empty vector as
                // not ready, so the rebuild task fails its sanity check and retries with backoff.
                $store->upsert($area, $gen, new embedding_row(
                    $area,
                    trim((string)($row['skill'] ?? '')),
                    (string)($row['anchor_kind'] ?? ''),
                    (int)($row['anchor_index'] ?? 0),
                    (string)($row['anchor_text'] ?? ''),
                    $resolvedmodel,
                    $resolveddimensions,
                    $contenthash,
                    $embedding
                ));
            }

            $written = $store->commit_generation($area, $resolvedmodel, $resolveddimensions, $gen);
        } catch (\Throwable $e) {
            $store->discard_generation($area, $resolvedmodel, $resolveddimensions, $gen);
            throw $e;
        }

        return [
            'status' => 'written',
            'model' => $resolvedmodel,
            'dimensions' => $resolveddimensions,
            'written' => $written,
            'embedded' => $embeddedcount,
            'reused' => $reusedcount,
            'deleted' => count($removedskills),
            'skillstates' => $skillstates,
        ];
    }

    /**
     * Stable per-anchor identity key (skill + anchor_index) for multi-vector reuse.
     *
     * @param array $row
     * @return string
     */
    private function anchor_key(array $row): string {
        $skillname = trim((string)($row['skill'] ?? ''));
        if ($skillname === '') {
            return '';
        }
        return $skillname . '#' . (string)($row['anchor_index'] ?? '0');
    }
}
