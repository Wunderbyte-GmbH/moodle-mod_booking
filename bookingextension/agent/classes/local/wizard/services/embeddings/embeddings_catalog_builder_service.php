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
 * Builder for full skill-catalog embeddings input rows.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\skill_registry;

/**
 * Builds canonical embedding rows from the full prompt catalog.
 */
class embeddings_catalog_builder_service {
    /**
     * Build embedding row payloads from full catalog contracts.
     *
     * @param skill_registry $registry
     * @param string $model
     * @param int $dimensions
     * @return array[]
     */
    public function build_full_catalog_rows(skill_registry $registry, string $model, int $dimensions): array {
        $rows = [];
        $contracts = $registry->get_all_prompt_contracts();

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $description = trim((string)($contract['description'] ?? ''));

            // Multi-vector discovery: the skill is represented by several anchors — the description
            // (anchor #0) plus each example_utterance — and EACH anchor is embedded separately so a
            // query can match the single closest phrasing. See SKILL_REWORK.md §5.
            $anchors = $this->build_anchor_list($description, (array)($contract['example_utterances'] ?? []));

            // The CSV stores ONLY hashed data (skill + anchor identity + anchor text) and the vector.
            // The planner card metadata (intent/readonly/description/minimal_input/example_input/
            // message_triggers) is intentionally NOT persisted here — it is re-joined LIVE from the
            // skill registry per skill in planner_catalog_service::sanitize_runtime_catalog_for_prompt,
            // so a skill schema change reaches the planner without an embeddings rebuild (fix Y).
            foreach ($anchors as $anchor) {
                $canonical = [
                    'skill' => $skill,
                    'anchor_index' => (string)$anchor['index'],
                    'anchor_kind' => $anchor['kind'],
                    'anchor_text' => $anchor['text'],
                ];

                $rows[] = [
                    'skill' => $skill,
                    'anchor_index' => (string)$anchor['index'],
                    'anchor_kind' => $anchor['kind'],
                    'anchor_text' => $anchor['text'],
                    'embedding_model' => $model,
                    'embedding_dimensions' => (string)$dimensions,
                    'content_hash' => $this->compute_content_hash($canonical, $model, $dimensions),
                    'embedding_json' => '',
                    '_embedding_input' => $this->to_embedding_input($canonical),
                ];
            }
        }

        return $rows;
    }

    /**
     * Build the ordered anchor list for one skill: description (#0) then deduplicated utterances.
     *
     * @param string $description
     * @param mixed[] $utterances
     * @return array[]
     */
    private function build_anchor_list(string $description, array $utterances): array {
        $anchors = [];
        $index = 0;

        $description = trim($description);
        if ($description !== '') {
            $anchors[] = ['index' => $index++, 'kind' => 'description', 'text' => $description];
        }

        $seen = [];
        foreach ($utterances as $utterance) {
            $text = trim((string)$utterance);
            if ($text === '') {
                continue;
            }
            $key = \core_text::strtolower($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $anchors[] = ['index' => $index++, 'kind' => 'utterance', 'text' => $text];
        }

        return $anchors;
    }

    /**
     * Compute stable hash for one row payload.
     *
     * @param array $canonicalrow
     * @param string $model
     * @param int $dimensions
     * @return string
     */
    public function compute_content_hash(array $canonicalrow, string $model, int $dimensions): string {
        $payload = [
            'model' => $model,
            'dimensions' => $dimensions,
            'row' => $canonicalrow,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Embedding input text for ONE anchor: the anchor text alone.
     *
     * Deliberately just the phrase — a description sentence or a single example utterance — so the
     * vector matches a real user intent. The old single-vector blob (skill/intent/readonly/json +
     * message_triggers + contextual_prompt_packs) diluted the signal and dragged non-English trigger
     * text into the vector. See SKILL_REWORK.md §5.
     *
     * @param array $canonicalrow
     * @return string
     */
    public function to_embedding_input(array $canonicalrow): string {
        return trim((string)($canonicalrow['anchor_text'] ?? ''));
    }
}
