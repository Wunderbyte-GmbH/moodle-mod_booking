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
 * Write-time guard: a stored vector must match its declared dimensions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * Rejects embedding rows whose vector length contradicts the declared edims BEFORE they are persisted.
 *
 * Motivation (Wunderbyte-GmbH/Wunderbyte-GmbH#2225): the provider config declared 1536 dimensions
 * while the embeddings endpoint actually returned 3584-dim vectors. Both stores persisted the rows
 * unchecked; the read path (unpack/parse) then discarded every vector on the count mismatch, so the
 * whole skill catalog was silently unretrievable while rebuilds appeared to succeed. Failing hard at
 * write time surfaces the misconfiguration in the rebuild task with an actionable message instead.
 */
final class embeddings_dimension_guard {
    /**
     * Assert that a row's vector length matches its declared dimensions.
     *
     * Rows without a vector are allowed through: the read path already normalizes unusable blobs to
     * an empty vector, and some flows legitimately stage vectorless rows.
     *
     * @param embedding_row $row
     * @return void
     * @throws \coding_exception When the vector is non-empty and its length differs from edims.
     */
    public static function assert_row_matches_dims(embedding_row $row): void {
        $actual = count($row->embedding);
        if ($actual === 0 || $actual === $row->edims) {
            return;
        }
        throw new \coding_exception(
            'embeddings_dimension_guard: refusing to store a vector whose length does not match the '
            . 'declared dimensions (model "' . $row->emodel . '", declared edims ' . $row->edims
            . ', actual vector length ' . $actual . ', area "' . $row->area . '", owner "'
            . $row->owner . '"). Fix the embeddings action config (dimensions must match what the '
            . 'model actually returns) and rebuild the catalog.'
        );
    }
}
