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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\embeddings\vector_math;

/**
 * Tests for the shared cosine-similarity helper (LR3 dedup).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\vector_math
 */
final class vector_math_test extends advanced_testcase {
    /**
     * Identical direction → 1.0; orthogonal → 0.0; opposite → -1.0.
     */
    public function test_known_angles(): void {
        $this->assertEqualsWithDelta(1.0, vector_math::cosine_similarity([1, 2, 3], [2, 4, 6]), 1e-9);
        $this->assertEqualsWithDelta(0.0, vector_math::cosine_similarity([1, 0], [0, 1]), 1e-9);
        $this->assertEqualsWithDelta(-1.0, vector_math::cosine_similarity([1, 1], [-1, -1]), 1e-9);
    }

    /**
     * Empty or zero-magnitude vectors return 0.0 (no direction).
     */
    public function test_degenerate_inputs(): void {
        $this->assertSame(0.0, vector_math::cosine_similarity([], [1, 2]));
        $this->assertSame(0.0, vector_math::cosine_similarity([0, 0], [1, 2]));
    }

    /**
     * Only the shared leading dimensions are compared (extra tail ignored).
     */
    public function test_uses_shared_leading_dimensions(): void {
        $this->assertEqualsWithDelta(
            1.0,
            vector_math::cosine_similarity([1, 2, 3], [1, 2, 3, 999]),
            1e-9
        );
    }
}
