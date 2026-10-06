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
use bookingextension_agent\local\wizard\services\issue_code_normalizer;
use bookingextension_agent\local\wizard\services\phase_trace_normalizer;

/**
 * Tests for the consolidated issue-code and phase-trace normalizers (S10a).
 *
 * Locks the exact semantics that were previously copied across four / two services — including the
 * subtle difference between {@see issue_code_normalizer::normalize()} (coerces) and
 * {@see issue_code_normalizer::from_result()} (array-guarded), and the "first entry wins per phase"
 * fallback of the phase trace.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\issue_code_normalizer
 * @covers     \bookingextension_agent\local\wizard\services\phase_trace_normalizer
 */
final class result_normalizers_test extends advanced_testcase {
    /**
     * normalize(): uppercase, trim, drop empties, de-duplicate, preserve first-seen order.
     */
    public function test_issue_codes_normalize_core(): void {
        $this->assertSame(
            ['SYNC_DRIFT', 'CONTRACT_X', 'PROVIDER_ERROR'],
            issue_code_normalizer::normalize([' sync_drift ', 'CONTRACT_X', 'sync_drift', '', '  ', 'provider_error'])
        );
        // Scalars are stringified.
        $this->assertSame(['1', 'TRUE'], issue_code_normalizer::normalize([1, 'true', 1]));
        $this->assertSame([], issue_code_normalizer::normalize([]));
    }

    /**
     * from_result(): reads the issue_codes key, array-guarded (a non-array value yields []), and
     * does NOT coerce a scalar — this is the behavioral difference from normalize().
     */
    public function test_issue_codes_from_result_is_array_guarded(): void {
        $this->assertSame(['A', 'B'], issue_code_normalizer::from_result(['issue_codes' => ['a', 'b', 'a']]));
        $this->assertSame([], issue_code_normalizer::from_result([]), 'Missing key → empty.');
        $this->assertSame(
            [],
            issue_code_normalizer::from_result(['issue_codes' => 'CONTRACT_X']),
            'A scalar issue_codes must yield [] (not be coerced into a one-element list).'
        );
        $this->assertSame([], issue_code_normalizer::from_result(['issue_codes' => null]));
    }

    /**
     * phase trace: canonical three-phase skeleton with array values kept; non-array values ignored.
     */
    public function test_phase_trace_keyed_values(): void {
        $out = phase_trace_normalizer::normalize([
            'discovery' => ['step' => 1],
            'selection' => 'not-an-array',
        ]);

        $this->assertSame(['discovery', 'selection', 'parameter_construction'], array_keys($out));
        $this->assertSame(['step' => 1], $out['discovery']);
        $this->assertSame([], $out['selection'], 'A non-array keyed phase is ignored (stays empty).');
        $this->assertSame([], $out['parameter_construction']);
    }

    /**
     * phase trace flat-entry fallback: the FIRST entry of each phase fills its (still-empty) bucket,
     * and a keyed value already present is never overridden by a later flat entry.
     */
    public function test_phase_trace_first_entry_wins(): void {
        $out = phase_trace_normalizer::normalize([
            'discovery' => ['kept' => true],
            ['phase' => 'selection', 'n' => 1],
            ['phase' => 'selection', 'n' => 2],
            ['phase' => 'discovery', 'n' => 99],
            'garbage-scalar',
        ]);

        $this->assertSame(['kept' => true], $out['discovery'], 'Pre-filled keyed phase must not be overridden.');
        $this->assertSame(['phase' => 'selection', 'n' => 1], $out['selection'], 'First selection entry wins.');
        $this->assertSame([], $out['parameter_construction']);
    }
}
