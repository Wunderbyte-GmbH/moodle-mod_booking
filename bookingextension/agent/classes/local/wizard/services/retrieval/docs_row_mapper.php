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
 * Row mapper for the documentation embeddings area.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\embeddings_csv_repository_base;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_csv_repository;

/**
 * Translates documentation CSV rows (corpus_id / chunk_path / line_start / …) to and from the
 * generic embedding DTOs. Identity is (corpus_id, chunk_path, line_start).
 */
class docs_row_mapper implements embeddings_row_mapper {
    /** Area discriminator. */
    public const AREA = 'docs';

    /**
     * The area discriminator this mapper handles.
     *
     * @return string
     */
    public function area(): string {
        return self::AREA;
    }

    /**
     * The docs CSV repository bound to the given variant.
     *
     * @param string $emodel
     * @param int $edims
     * @return embeddings_csv_repository_base
     */
    public function repo_for_variant(string $emodel, int $edims): embeddings_csv_repository_base {
        return new docs_embeddings_csv_repository(
            null,
            embeddings_csv_repository_base::normalize_variant_key($emodel . '__' . $edims)
        );
    }

    /**
     * Convert a raw docs CSV row into an embedding_row.
     *
     * @param string[] $csvrow
     * @return embedding_row
     */
    public function to_row(array $csvrow): embedding_row {
        $embedding = json_decode((string)($csvrow['embedding_json'] ?? '[]'), true);
        $embedding = is_array($embedding) ? array_map('floatval', $embedding) : [];
        return new embedding_row(
            self::AREA,
            (string)($csvrow['corpus_id'] ?? ''),
            (string)($csvrow['chunk_path'] ?? ''),
            (int)($csvrow['line_start'] ?? 0),
            (string)($csvrow['chunk_title'] ?? ''),
            (string)($csvrow['embedding_model'] ?? ''),
            (int)($csvrow['embedding_dimensions'] ?? 0),
            (string)($csvrow['content_hash'] ?? ''),
            is_array($embedding) ? $embedding : [],
            (int)($csvrow['line_end'] ?? 0)
        );
    }

    /**
     * Convert an embedding_row back into a raw docs CSV row.
     *
     * @param embedding_row $row
     * @return string[]
     */
    public function to_csv(embedding_row $row): array {
        return [
            'corpus_id' => $row->owner,
            'chunk_path' => $row->refkey,
            'chunk_title' => $row->title,
            'line_start' => (string)$row->refindex,
            'line_end' => (string)($row->endindex ?? 0),
            'embedding_model' => $row->emodel,
            'embedding_dimensions' => (string)$row->edims,
            'content_hash' => $row->contenthash,
            'embedding_json' => json_encode($row->embedding),
        ];
    }

    /**
     * Convert a raw docs CSV row plus score into a retrieval hit.
     *
     * @param string[] $csvrow
     * @param float $score
     * @return embedding_hit
     */
    public function to_hit(array $csvrow, float $score): embedding_hit {
        return new embedding_hit(
            self::AREA,
            (string)($csvrow['corpus_id'] ?? ''),
            (string)($csvrow['chunk_path'] ?? ''),
            (int)($csvrow['line_start'] ?? 0),
            (string)($csvrow['chunk_title'] ?? ''),
            $score,
            null,
            null,
            null,
            null,
            (string)($csvrow['content_hash'] ?? '')
        );
    }

    /**
     * Identity key (corpus_id | chunk_path | line_start) for reuse on rebuild.
     *
     * @param string[] $csvrow
     * @return string
     */
    public function identity_key(array $csvrow): string {
        $corpus = trim((string)($csvrow['corpus_id'] ?? ''));
        $path = trim((string)($csvrow['chunk_path'] ?? ''));
        if ($corpus === '' || $path === '') {
            return '';
        }
        return $corpus . '|' . $path . '|' . (int)($csvrow['line_start'] ?? 0);
    }

    /**
     * Identity key (corpus_id | chunk_path | line_start) from an embedding_row.
     *
     * @param embedding_row $row
     * @return string
     */
    public function identity_key_for_row(embedding_row $row): string {
        $corpus = trim($row->owner);
        $path = trim($row->refkey);
        if ($corpus === '' || $path === '') {
            return '';
        }
        return $corpus . '|' . $path . '|' . $row->refindex;
    }
}
