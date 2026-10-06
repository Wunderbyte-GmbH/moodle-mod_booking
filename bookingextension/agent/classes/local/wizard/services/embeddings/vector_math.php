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
 * Small vector-math helpers shared by the embedding retrieval/debug services.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

/**
 * Stateless vector operations for embedding similarity.
 */
class vector_math {
    /**
     * Cosine similarity of two numeric vectors over their shared leading dimensions.
     *
     * Returns 0.0 for empty input or a zero-magnitude vector (no direction to compare).
     *
     * @param array $a
     * @param array $b
     * @return float Similarity in [-1, 1].
     */
    public static function cosine_similarity(array $a, array $b): float {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $norma = 0.0;
        $normb = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $av = (float)$a[$i];
            $bv = (float)$b[$i];
            $dot += $av * $bv;
            $norma += $av * $av;
            $normb += $bv * $bv;
        }

        if ($norma <= 0.0 || $normb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($norma) * sqrt($normb));
    }
}
