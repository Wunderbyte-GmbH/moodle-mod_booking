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
 * Per-area translation between the generic embedding DTOs and the concrete CSV columns.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\embeddings_csv_repository_base;

/**
 * Maps one area's storage rows to and from the storage-agnostic {@see embedding_row} /
 * {@see embedding_hit} DTOs, and provides the variant-scoped CSV repository for that area.
 *
 * Keeping this per-area knowledge behind a small interface is what lets {@see csv_embeddings_store}
 * serve docs and skills (and, later, site content) without hard-coding any column names.
 */
interface embeddings_row_mapper {
    /**
     * The area discriminator this mapper handles ('docs' | 'skills' | 'site_content').
     *
     * @return string
     */
    public function area(): string;

    /**
     * The CSV repository bound to the given (model, dimensions) variant.
     *
     * @param string $emodel
     * @param int $edims
     * @return embeddings_csv_repository_base
     */
    public function repo_for_variant(string $emodel, int $edims): embeddings_csv_repository_base;

    /**
     * Convert a raw CSV row into an embedding_row (decoding the vector).
     *
     * @param string[] $csvrow
     * @return embedding_row
     */
    public function to_row(array $csvrow): embedding_row;

    /**
     * Convert an embedding_row back into a raw CSV row (encoding the vector).
     *
     * @param embedding_row $row
     * @return string[]
     */
    public function to_csv(embedding_row $row): array;

    /**
     * Convert a raw CSV row plus its score into a retrieval hit (no vector).
     *
     * @param string[] $csvrow
     * @param float $score
     * @return embedding_hit
     */
    public function to_hit(array $csvrow, float $score): embedding_hit;

    /**
     * Stable identity key for a raw CSV row, used for hash-based reuse on rebuild.
     *
     * @param string[] $csvrow
     * @return string Empty to skip the row.
     */
    public function identity_key(array $csvrow): string;

    /**
     * Stable identity key for an embedding_row — the same string {@see identity_key()} yields for the
     * equivalent CSV row, but computed from the DTO.
     *
     * The DB store hashes this at write time to index a row for reuse, then matches it against the
     * caller's key on rebuild; the two forms must agree exactly.
     *
     * @param embedding_row $row
     * @return string Empty to skip the row.
     */
    public function identity_key_for_row(embedding_row $row): string;
}
