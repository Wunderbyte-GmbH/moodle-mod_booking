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
 * CSV repository for documentation chunk embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\embeddings_csv_repository_base;

/**
 * Handles storage and retrieval of documentation chunk embeddings in CSV format.
 *
 * Each row represents one documentation chunk (one .md heading/size chunk). The corpus_id field
 * groups chunks by their source corpus (e.g. 'mod_booking').
 *
 * Parsing, validation and the atomic round-trip-verified write are inherited from
 * {@see embeddings_csv_repository_base}; this class adds corpus-scoped read/delete helpers.
 */
class docs_embeddings_csv_repository extends embeddings_csv_repository_base {
    /** Ordered CSV header columns. */
    public const HEADERS = [
        'corpus_id',
        'chunk_path',
        'chunk_title',
        'line_start',
        'line_end',
        'embedding_model',
        'embedding_dimensions',
        'content_hash',
        'embedding_json',
    ];

    /**
     * Build a repository bound to the currently active embeddings variant (model + dimensions).
     *
     * Read paths (lookup, readiness) use this so they open the same file the rebuild writes for the
     * active model.
     *
     * @return self
     */
    public static function for_active_variant(): self {
        return new self(null, (new embeddings_action_config_resolver())->variant_key());
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
        return ['corpus_id', 'chunk_path', 'content_hash'];
    }

    /**
     * Short label for corruption diagnostics.
     *
     * @return string
     */
    protected function store_label(): string {
        return 'documentation embeddings';
    }

    /**
     * Default (un-suffixed) CSV path; the variant suffix is applied by the base.
     *
     * @return string
     */
    protected function default_csv_path(): string {
        $dir = make_temp_directory('bookingextension_agent/wizard');
        return $dir . '/docs_embeddings.csv';
    }

    /**
     * Read rows filtered by corpus_id.
     *
     * @param string $corpusid
     * @return array[]
     */
    public function read_rows_for_corpus(string $corpusid): array {
        $rows = $this->read_rows();
        if ($corpusid === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($corpusid): bool {
            return trim((string)($row['corpus_id'] ?? '')) === $corpusid;
        }));
    }
}
