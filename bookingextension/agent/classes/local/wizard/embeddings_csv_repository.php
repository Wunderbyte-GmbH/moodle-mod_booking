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
 * CSV repository for skill-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * Handles storage and retrieval of skill-catalog embeddings in CSV format.
 *
 * Parsing, validation and the atomic round-trip-verified write live in
 * {@see embeddings_csv_repository_base}; this class only declares the schema and storage location.
 */
class embeddings_csv_repository extends embeddings_csv_repository_base {
    /**
     * Ordered CSV header columns.
     *
     * Multi-vector store (SKILL_REWORK.md §5): one row PER ANCHOR, so a skill spans several rows
     * keyed by (skill, anchor_index). anchor_kind is 'description' (#0) or 'utterance'; anchor_text
     * is the exact embedded phrase.
     *
     * The store holds ONLY hashed data (the columns that feed content_hash) plus the vector. The
     * planner card metadata (intent/readonly/description/minimal_input/example_input/message_triggers)
     * is NOT stored — it is re-joined LIVE from the skill registry per skill when the planner catalog
     * is built (planner_catalog_service::sanitize_runtime_catalog_for_prompt), so a skill schema
     * change reaches the planner without an embeddings rebuild. Only an anchor-text change (which
     * alters the vector) requires a rebuild.
     */
    public const HEADERS = [
        'skill',
        'anchor_index',
        'anchor_kind',
        'anchor_text',
        'embedding_model',
        'embedding_dimensions',
        'content_hash',
        'embedding_json',
    ];

    /**
     * Repository bound to the currently active embeddings variant (model + dimensions).
     *
     * @return self
     */
    public static function for_active_variant(): self {
        return new self(null, (new embeddings_action_config_resolver())->variant_key());
    }

    /**
     * Repository bound to a specific model/dimensions variant.
     *
     * @param string $model
     * @param int    $dimensions
     * @return self
     */
    public static function for_variant(string $model, int $dimensions): self {
        return new self(null, self::normalize_variant_key($model . '__' . $dimensions));
    }

    /**
     * Ordered CSV header columns.
     *
     * @return string[]
     */
    protected function headers(): array {
        return self::HEADERS;
    }

    /**
     * Columns that must be non-empty for a row to be valid.
     *
     * @return string[]
     */
    protected function required_nonempty_columns(): array {
        return ['skill', 'content_hash'];
    }

    /**
     * Short label for corruption diagnostics.
     *
     * @return string
     */
    protected function store_label(): string {
        return 'skill-catalog embeddings';
    }

    /**
     * Default CSV path (fixture under PHPUNIT, temp store otherwise).
     *
     * @return string
     */
    protected function default_csv_path(): string {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            global $CFG;
            return $CFG->dirroot . '/mod/booking/bookingextension/agent/tests/agent/fixtures/skill_catalog_embeddings.csv';
        }

        $dir = make_temp_directory('bookingextension_agent/wizard');
        return $dir . '/skill_catalog_embeddings.csv';
    }
}
