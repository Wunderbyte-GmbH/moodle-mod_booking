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
 * Tests for the shared input_payload_pruner (audit 05-F02).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\input_payload_pruner;

/**
 * Locks the prune semantics shared by interpreter + parameter_constructor.
 *
 * @covers \bookingextension_agent\local\wizard\services\input_payload_pruner
 */
final class input_payload_pruner_test extends \advanced_testcase {
    /**
     * Drops blank strings and nulls; keeps 0/false and non-empty (untrimmed) strings.
     */
    public function test_drops_blanks_keeps_zero_false(): void {
        $out = input_payload_pruner::prune([
            'zero' => 0,
            'false' => false,
            'null' => null,
            'empty' => '',
            'blank' => '   ',
            'keep' => '  untrimmed  ',
            'float' => 0.0,
        ]);

        $this->assertSame(['zero' => 0, 'false' => false, 'keep' => '  untrimmed  ', 'float' => 0.0], $out);
    }

    /**
     * Recurses into arrays and drops nested empties; arrays that prune to empty are removed.
     */
    public function test_recurses_and_drops_empty_arrays(): void {
        $out = input_payload_pruner::prune([
            'm' => ['x' => '', 'y' => ['z' => null, 'w' => 'keep'], 'e' => []],
            'gone' => ['a' => '', 'b' => null],
        ]);

        $this->assertSame(['m' => ['y' => ['w' => 'keep']]], $out);
    }

    /**
     * Array keys are preserved (not re-indexed), even when a list entry is dropped.
     */
    public function test_preserves_keys_with_gaps(): void {
        $out = input_payload_pruner::prune(['l' => [0 => 'a', 1 => '', 2 => 'c']]);
        $this->assertSame(['l' => [0 => 'a', 2 => 'c']], $out);
    }
}
