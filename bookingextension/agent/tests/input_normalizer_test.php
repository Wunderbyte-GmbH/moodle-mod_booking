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
 * Tests for the shared input_normalizer (audit 03-F03).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\input_normalizer;

/**
 * Locks the two normalization presets so the compact (prompt-blob) and signature
 * (dedupe) behaviours can never be silently merged into one again.
 *
 * @covers \bookingextension_agent\local\wizard\services\input_normalizer
 */
final class input_normalizer_test extends \advanced_testcase {
    /** @var array Compact preset (completed_command_history_service). */
    private const COMPACT = [
        'dropkeys' => ['confirmed', 'outputlang', 'lang', 'user_lang', 'sessiontoken', 'sesskey'],
        'capstring' => 160,
        'caplist' => 20,
        'dropempty' => true,
    ];

    /** @var array Signature preset (execution_observation_ledger). */
    private const SIGNATURE = [
        'dropkeys' => ['confirmed', 'outputlang', 'lang', 'user_lang', 'sessiontoken', 'sesskey'],
        'ksort' => true,
    ];

    /**
     * Compact preset: drops noise keys, trims + caps strings, drops empties, keeps 0/false.
     */
    public function test_compact_caps_and_drops(): void {
        $out = input_normalizer::normalize([
            'confirmed' => 1,
            'sesskey' => 'x',
            'keep' => '  v  ',
            'empty' => '   ',
            'long' => str_repeat('a', 250),
            'n' => null,
            'zero' => 0,
            'f' => false,
        ], self::COMPACT);

        $this->assertArrayNotHasKey('confirmed', $out);
        $this->assertArrayNotHasKey('sesskey', $out);
        $this->assertArrayNotHasKey('empty', $out);
        $this->assertArrayNotHasKey('n', $out);
        $this->assertSame('v', $out['keep']);
        $this->assertSame(160, \core_text::strlen($out['long']));
        $this->assertSame(0, $out['zero']);
        $this->assertFalse($out['f']);
    }

    /**
     * Compact preset: arrays are capped at 20 entries.
     */
    public function test_compact_caps_list_at_20(): void {
        $out = input_normalizer::normalize(['items' => range(1, 30)], self::COMPACT);
        $this->assertCount(20, $out['items']);
    }

    /**
     * Signature preset: ksorts keys (top + nested maps) and keeps every value verbatim.
     */
    public function test_signature_ksorts_and_keeps_everything(): void {
        $out = input_normalizer::normalize([
            'z' => 1,
            'a' => ['y' => 2, 'b' => ['n' => null, 'm' => str_repeat('a', 250)]],
            'lang' => 'de',
        ], self::SIGNATURE);

        $this->assertSame(['a', 'z'], array_keys($out));
        $this->assertArrayNotHasKey('lang', $out);
        $this->assertSame(['b', 'y'], array_keys($out['a']));
        $this->assertNull($out['a']['b']['n']);
        $this->assertSame(250, \core_text::strlen($out['a']['b']['m']));
    }

    /**
     * Signature preset: list order is preserved (lists are not sorted).
     */
    public function test_signature_preserves_list_order(): void {
        $out = input_normalizer::normalize(['l' => [3, 1, 2]], self::SIGNATURE);
        $this->assertSame([3, 1, 2], $out['l']);
    }
}
