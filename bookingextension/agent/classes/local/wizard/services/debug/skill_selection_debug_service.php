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

namespace bookingextension_agent\local\wizard\services\debug;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\embeddings\vector_math;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\wb_action_names;
use context_module;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Selection-debug helper: simulate skill selection and inspect collisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_selection_debug_service {
    /** Embeddings action class for wunderbyte provider. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = wb_action_names::GENERATE_EMBEDDINGS;

    /** Plugin config key persisting the collision analysis (JSON; survives cache purges). */
    private const COLLISION_CACHE_CONFIG = 'skillcollisioncache';

    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->registry = skill_registry_factory::get_default();
    }

    /**
     * Return selection simulation for a user input.
     *
     * @param string $input
     * @param int $userid
     * @param int $cmid
     * @param int $topk
     * @param bool $includeunavailable
     * @return array
     */
    public function simulate_selection(
        string $input,
        int $userid,
        int $cmid,
        int $topk = 10,
        bool $includeunavailable = true
    ): array {
        $topk = max(1, min(50, $topk));
        $contextid = $this->resolve_contextid_from_cmid($cmid);
        $contracts = $this->get_prompt_contracts_for_context($userid, $contextid, $includeunavailable);

        // Lexical scores are computed for REFERENCE ONLY (a column in the table) — they MUST NOT drive
        // the ranking. Live discovery is SEMANTIC-ONLY (embedding top-k; no lexical / intent-trigger
        // component — see the binding DISCO_RULE in the flowchart), so this simulator ranks by embedding
        // only to reflect the live selection 1:1. A previous hybrid score (embedding*0.75 + lexical*0.25)
        // mis-ranked skills here that live never surfaces (thread 5: scaffold_skill appeared #1 only via
        // its lexical "create … skill" match, while live, semantic-only, did not list it).
        $lexical = $this->build_lexical_ranking($input, $contracts, $topk);
        $embedding = $this->build_embedding_ranking($input, $userid, $cmid, $topk);

        // Pool the live planner actually ranks over (executable skills for this user/context unless
        // unavailable are explicitly included), so an embedded-but-out-of-scope skill is not shown.
        $poolskills = [];
        foreach ($contracts as $contract) {
            $name = (string)($contract['skill'] ?? '');
            if ($name !== '') {
                $poolskills[$name] = true;
            }
        }

        $lexicalbyskill = [];
        foreach ($lexical as $row) {
            $skill = (string)($row['skill'] ?? '');
            if ($skill === '') {
                continue;
            }
            $lexicalbyskill[$skill] = [
                'lexical_score' => (float)($row['lexical_score'] ?? 0.0),
                'match_terms' => (array)($row['match_terms'] ?? []),
                'readonly' => !empty($row['readonly']),
                'intent' => (string)($row['intent'] ?? ''),
            ];
        }

        $embeddingenabled = !empty($embedding);
        $candidates = $this->rank_simulation_candidates($embedding, $lexicalbyskill, $poolskills, $topk);

        return [
            'input' => $input,
            'contextid' => $contextid,
            'cmid' => $cmid,
            'selected_skill' => (string)($candidates[0]['skill'] ?? ''),
            'candidates' => $candidates,
            'contracts_count' => count($contracts),
            'embedding_enabled' => $embeddingenabled,
        ];
    }

    /**
     * Assemble the ranked candidate list, mirroring the live selection 1:1.
     *
     * When embeddings are present the ranking is EMBEDDING-ONLY (the live semantic top-k order),
     * restricted to the planner pool; lexical scores ride along only as a reference column. When
     * embeddings are absent there is no live semantic ranking to mirror (live would show the full
     * unranked slim catalog), so the lexical ordering is returned purely as a debugging aid.
     *
     * Pure (no DB/provider) so it is unit-testable without a live embedding provider.
     *
     * @param array $embedding Embedding top-k rows (skill, score, anchor...).
     * @param array $lexicalbyskill Lexical info keyed by skill.
     * @param array $poolskills Skill names the live planner ranks over.
     * @param int $topk
     * @return array
     */
    public function rank_simulation_candidates(
        array $embedding,
        array $lexicalbyskill,
        array $poolskills,
        int $topk
    ): array {
        $candidates = [];

        if (!empty($embedding)) {
            // Embedding-only ranking = the live semantic top-k order.
            foreach ($embedding as $row) {
                $skill = (string)($row['skill'] ?? '');
                if ($skill === '' || empty($poolskills[$skill])) {
                    continue;
                }
                $score = (float)($row['score'] ?? 0.0);
                $lex = $lexicalbyskill[$skill] ?? null;
                $candidates[] = [
                    'skill' => $skill,
                    'lexical_score' => $lex !== null ? (float)$lex['lexical_score'] : 0.0,
                    'embedding_score' => $score,
                    'combined_score' => $score,
                    'match_terms' => $lex !== null ? (array)$lex['match_terms'] : [],
                    'readonly' => ((string)($row['readonly'] ?? '0') === '1'),
                    'intent' => (string)($row['intent'] ?? ''),
                    'source' => 'embedding',
                    // Multi-vector: which anchor (description/utterance + the exact phrase) won this skill.
                    'matched_anchor_kind' => (string)($row['matched_anchor_kind'] ?? ($row['anchor_kind'] ?? '')),
                    'matched_anchor_text' => (string)($row['matched_anchor_text'] ?? ($row['anchor_text'] ?? '')),
                ];
            }
            usort($candidates, static function (array $a, array $b): int {
                return ((float)$b['embedding_score']) <=> ((float)$a['embedding_score']);
            });
        } else {
            // Embeddings unavailable: live would fall back to the full UNRANKED slim catalog (every
            // skill visible, no top-k). There is no semantic ranking to mirror, so we surface the
            // lexical ordering purely as a debugging aid — flagged via embedding_enabled=false — and
            // never present it as the live selection.
            foreach ($lexicalbyskill as $skill => $lex) {
                $candidates[] = [
                    'skill' => (string)$skill,
                    'lexical_score' => (float)$lex['lexical_score'],
                    'embedding_score' => null,
                    'combined_score' => (float)$lex['lexical_score'],
                    'match_terms' => (array)$lex['match_terms'],
                    'readonly' => !empty($lex['readonly']),
                    'intent' => (string)$lex['intent'],
                    'source' => 'lexical',
                ];
            }
            usort($candidates, static function (array $a, array $b): int {
                return ((float)$b['lexical_score']) <=> ((float)$a['lexical_score']);
            });
        }

        return array_slice($candidates, 0, $topk);
    }

    /**
     * Analyze pairwise skill collisions using embedding vectors.
     *
     * @param int $limit
     * @return array
     */
    public function analyze_collisions(int $limit = 50): array {
        $limit = max(1, min(500, $limit));
        // Committed anchor rows of the active variant, via the store abstraction (CSV or DB backend,
        // selected by the embeddingsstore flag). Vectors arrive pre-decoded; only the fields the
        // pairwise loop needs are kept in memory.
        $settings = (new embeddings_action_config_resolver())->resolve();
        $store = embeddings_store_factory::instance();
        $rows = [];
        foreach (
            $store->stream_rows(
                skill_row_mapper::AREA,
                (string)($settings['model'] ?? 'text-embedding-3-small'),
                (int)($settings['dimensions'] ?? 1536)
            ) as $storedrow
        ) {
            $rows[] = ['skill' => $storedrow->owner, 'embedding' => $storedrow->embedding];
        }
        $pairs = [];

        for ($i = 0; $i < count($rows); $i++) {
            $a = $rows[$i];
            $av = $a['embedding'];
            if (empty($av)) {
                continue;
            }

            for ($j = $i + 1; $j < count($rows); $j++) {
                $b = $rows[$j];
                // Anchors of the SAME skill are expected to be similar — they say nothing about
                // cross-skill collision risk and would flood the report with self-pairs.
                if ((string)($a['skill'] ?? '') === (string)($b['skill'] ?? '')) {
                    continue;
                }
                $bv = $b['embedding'];
                if (empty($bv)) {
                    continue;
                }

                $score = vector_math::cosine_similarity($av, $bv);
                $pairs[] = [
                    'skill_a' => (string)($a['skill'] ?? ''),
                    'skill_b' => (string)($b['skill'] ?? ''),
                    'similarity' => $score,
                    'risk' => $this->classify_collision_risk($score),
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            return ((float)$b['similarity']) <=> ((float)$a['similarity']);
        });

        // Never truncate genuine collisions: keep ALL high/warn pairs regardless of $limit, then fill up
        // to $limit with the next-highest low-risk ('ok') pairs for context. A pure top-N slice could hide
        // real collisions behind many slightly-higher-scoring pairs — and made the debug view (limit 40)
        // disagree with the governance view (limit 250) for the same catalog.
        $risky = [];
        $rest = [];
        foreach ($pairs as $pair) {
            if ($pair['risk'] === 'high' || $pair['risk'] === 'warn') {
                $risky[] = $pair;
            } else {
                $rest[] = $pair;
            }
        }
        $fill = max(0, $limit - count($risky));
        $slice = array_merge($risky, array_slice($rest, 0, $fill));

        return [
            'has_embeddings' => !empty($rows),
            'skill_count' => count($rows),
            'pairs' => $slice,
        ];
    }

    /**
     * Run the collision analysis and persist the result.
     *
     * The O(N²) pairwise cosine pass over every anchor pair is far too expensive to run on each
     * governance page load, and it only changes when the embeddings catalog changes — so it runs
     * once in the catalog rebuild task (and on the debug page's explicit recompute button) and is
     * persisted in plugin config (survives cache purges, unlike MUC).
     *
     * @param int $limit
     * @return array The persisted payload: has_embeddings/skill_count/pairs + computedat.
     */
    public function compute_and_cache_collisions(int $limit = 250): array {
        $result = $this->analyze_collisions($limit);
        $result['computedat'] = time();
        set_config(self::COLLISION_CACHE_CONFIG, json_encode($result), 'bookingextension_agent');
        return $result;
    }

    /**
     * The persisted collision analysis, or null when never computed (or unreadable).
     *
     * @return array|null
     */
    public function get_cached_collisions(): ?array {
        $raw = get_config('bookingextension_agent', self::COLLISION_CACHE_CONFIG);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('pairs', $decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Get active prompt contracts for context.
     *
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array[]
     */
    private function get_prompt_contracts_for_context(int $userid, int $contextid, bool $includeunavailable): array {
        if ($contextid <= 0) {
            return $this->registry->get_all_prompt_contracts();
        }

        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        return $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, $includeunavailable);
    }

    /**
     * Build lexical ranking over prompt contracts.
     *
     * @param string $input
     * @param array[] $contracts
     * @param int $topk
     * @return array[]
     */
    private function build_lexical_ranking(string $input, array $contracts, int $topk): array {
        $inputtokens = $this->tokenize($input);
        $result = [];

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $searchcorpus = [];
            $searchcorpus[] = (string)($contract['skill'] ?? '');
            $searchcorpus[] = (string)($contract['description'] ?? '');

            foreach ((array)($contract['minimal_input'] ?? []) as $entry) {
                $searchcorpus[] = is_scalar($entry) ? (string)$entry : (string)json_encode($entry);
            }
            foreach ((array)($contract['example_input'] ?? []) as $entry) {
                // Example_input values can be nested arrays (e.g. optiondates) — flatten to JSON
                // instead of casting an array to string (PHP "Array to string conversion" warning).
                $searchcorpus[] = is_scalar($entry) ? (string)$entry : (string)json_encode($entry);
            }
            foreach ((array)($contract['message_triggers'] ?? []) as $trigger) {
                if (!is_array($trigger)) {
                    continue;
                }
                $searchcorpus[] = (string)($trigger['id'] ?? '');
                $searchcorpus[] = (string)($trigger['description'] ?? '');
                foreach ((array)($trigger['examples'] ?? []) as $example) {
                    $searchcorpus[] = (string)$example;
                }
            }

            $corpus = implode(' ', $searchcorpus);
            $corpustokens = $this->tokenize($corpus);
            if (empty($corpustokens)) {
                continue;
            }

            $intersect = array_values(array_intersect($inputtokens, $corpustokens));
            $score = 0.0;
            if (!empty($inputtokens)) {
                $score += (count($intersect) / max(1, count(array_unique($inputtokens)))) * 0.7;
            }

            $skillparts = $this->tokenize(str_replace(['.', '_'], ' ', $skill));
            $skillhits = array_values(array_intersect($inputtokens, $skillparts));
            if (!empty($skillhits)) {
                $score += 0.3;
            }

            if ($score <= 0.0) {
                continue;
            }

            $result[] = [
                'skill' => $skill,
                'lexical_score' => min(1.0, $score),
                'match_terms' => array_slice(array_values(array_unique($intersect)), 0, 8),
                'readonly' => !empty($contract['readonly']),
                'intent' => (string)($contract['intent'] ?? ''),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return ((float)$b['lexical_score']) <=> ((float)$a['lexical_score']);
        });

        return array_slice($result, 0, $topk);
    }

    /**
     * Build embedding ranking from skill catalog vectors.
     *
     * @param string $input
     * @param int $userid
     * @param int $cmid
     * @param int $topk
     * @return array[]
     */
    private function build_embedding_ranking(string $input, int $userid, int $cmid, int $topk): array {
        if ($cmid <= 0 || trim($input) === '') {
            return [];
        }

        if (!class_exists(self::WB_ACTION_GENERATE_EMBEDDINGS)) {
            return [];
        }

        $contextid = $this->resolve_contextid_from_cmid($cmid);
        if ($contextid <= 0) {
            return [];
        }

        $readiness = new embeddings_readiness_service();
        if (!$readiness->is_wunderbyte_embeddings_available()) {
            return [];
        }

        $resolver = new embeddings_action_config_resolver();
        $settings = $resolver->resolve();
        $model = (string)($settings['model'] ?? 'text-embedding-3-small');
        $dimensions = (int)($settings['dimensions'] ?? 1536);

        $status = $readiness->get_catalog_status($this->registry, $model, $dimensions);
        if (empty($status['ready']) || empty($status['rows']) || !is_array($status['rows'])) {
            return [];
        }

        // Cross-language bridge (SKILL_REWORK.md §5.7, Weg B): match the live discovery path by
        // embedding an English-normalised query. Fail-open.
        $embedinput = (new \bookingextension_agent\local\wizard\services\llm\query_english_normalizer())
            ->to_english($input, (int)$contextid, (int)$userid);
        $queryembedding = $this->generate_query_embedding($contextid, $userid, $embedinput, $dimensions);
        if (empty($queryembedding)) {
            return [];
        }

        $retrieval = new embeddings_retrieval_service();
        // Multi-vector: top-k distinct skills (rows carry matched_anchor_kind/text + score for debug).
        return $retrieval->search_top_k_skills($queryembedding, (array)$status['rows'], $topk);
    }

    /**
     * Generate query embedding using configured provider action.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $input
     * @param int $dimensions
     * @return array
     */
    private function generate_query_embedding(int $contextid, int $userid, string $input, int $dimensions): array {
        try {
            $manager = di::get(ai_manager::class);
            $actionclass = self::WB_ACTION_GENERATE_EMBEDDINGS;
            $action = new $actionclass(
                contextid: $contextid,
                userid: $userid,
                inputtext: $input,
                dimensions: $dimensions,
            );

            $response = $manager->process_action($action);
            $data = (array)$response->get_response_data();
            $embedding = (array)($data['embedding'] ?? []);
            if (empty($embedding)) {
                return [];
            }

            return $embedding;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve module context id from course module id.
     *
     * @param int $cmid
     * @return int
     */
    private function resolve_contextid_from_cmid(int $cmid): int {
        if ($cmid <= 0) {
            return 0;
        }

        try {
            return (int)context_module::instance($cmid)->id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Normalize and tokenize text.
     *
     * @param string $text
     * @return string[]
     */
    private function tokenize(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9_\s\.\-]/ui', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Classify collision risk by similarity threshold.
     *
     * @param float $similarity
     * @return string
     */
    private function classify_collision_risk(float $similarity): string {
        if ($similarity >= 0.90) {
            return 'high';
        }
        if ($similarity >= 0.82) {
            return 'warn';
        }
        return 'ok';
    }
}
