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
 * Engine implementation of semantic skill discovery (embedding retrieval over the skill catalog).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\discovery;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\interfaces\skill_discovery_provider_interface;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Embeds a capability query and retrieves the most relevant registered skills from the catalog.
 *
 * This holds the embeddings/LLM RAG machinery that previously lived inside the search_skills skill,
 * so the skill can depend on {@see skill_discovery_provider_interface} instead.
 */
class skill_discovery_service implements skill_discovery_provider_interface {
    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     *
     * @param skill_registry|null $registry
     */
    public function __construct(?skill_registry $registry = null) {
        $this->registry = $registry ?? skill_registry_factory::get_default();
    }

    /**
     * Discover registered skills matching a capability query.
     *
     * @param string $query
     * @param int    $contextid
     * @param int    $userid
     * @param int    $topk
     * @return array{status: string, discovered_skills: array<int,array{skill:string,schema:array<string,mixed>}>}
     */
    public function discover(string $query, int $contextid, int $userid, int $topk = 5): array {
        $readiness = new embeddings_readiness_service();
        if (!$readiness->is_wunderbyte_embeddings_available()) {
            return ['status' => self::STATUS_EMBEDDINGS_UNAVAILABLE, 'discovered_skills' => []];
        }

        $embeddingsettings = (new embeddings_action_config_resolver())->resolve();
        $embeddingmodel = (string)($embeddingsettings['model'] ?? 'text-embedding-3-small');
        $embeddingdimensions = (int)($embeddingsettings['dimensions'] ?? 1536);

        $status = $readiness->get_catalog_status($this->registry, $embeddingmodel, $embeddingdimensions);
        if (empty($status['ready']) || empty($status['rows']) || !is_array($status['rows'])) {
            return ['status' => self::STATUS_CATALOG_NOT_READY, 'discovered_skills' => []];
        }

        $llm = new llm_call_service(new conversation_store());
        // Cross-language bridge (SKILL_REWORK.md §5.7, Weg B): normalise the query to English before
        // embedding so non-English requests match the English-only anchors. Fail-open.
        $query = (new \bookingextension_agent\local\wizard\services\llm\query_english_normalizer())
            ->to_english($query, (int)$contextid, (int)$userid);
        $embeddingcall = $llm->invoke_embeddings_for_context(
            0, // Thread ID 0 indicates internal retrieval lookup without thread context.
            $contextid,
            $userid,
            'wizard.search_skills',
            $query,
            $embeddingdimensions
        );

        if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
            return ['status' => self::STATUS_EMBEDDING_FAILED, 'discovered_skills' => []];
        }

        // Multi-vector: aggregate anchor scores to the MAX per skill, return top-k distinct skills.
        $toprows = (new embeddings_retrieval_service())->search_top_k_skills(
            (array)$embeddingcall['embedding'],
            $status['rows'],
            max(1, $topk)
        );

        // The embeddings index is availability-agnostic, so retrieval output is gated by the same
        // executability verdicts as the selectable catalog (issue #2223): a stale row for a removed
        // skill (get_skill() null) or a denied skill (inactive, missing capability, PRO, …) must
        // never be advertised to the planner as discoverable.
        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        $discovered = [];
        foreach ($toprows as $row) {
            $skillname = trim((string)($row['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }
            $skill = $this->registry->get_skill($skillname);
            if ($skill === null) {
                continue;
            }
            $evaluation = $evaluator->evaluate_skill($skillname, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                continue;
            }
            try {
                $discovered[] = [
                    'skill' => $skillname,
                    'schema' => $skill->get_schema(),
                ];
            } catch (\Exception $e) {
                // Ignore skills whose schema cannot be produced.
                unset($e);
            }
        }

        return ['status' => self::STATUS_OK, 'discovered_skills' => $discovered];
    }
}
