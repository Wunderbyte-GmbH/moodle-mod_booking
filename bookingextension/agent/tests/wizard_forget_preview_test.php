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
use bookingextension_agent\local\wizard\wizard\skills\forget_skill;

/**
 * The wizard.forget confirm card must show the memory text, not the internal id.
 *
 * Baseline run 9 (2026-09-16, preview audit P5, Wunderbyte-GmbH#2410), thread 2138: the card read
 * "Gespeicherte Information löschen — Ziel = 5" although the prepared input already carried the
 * resolved text ("B12 ist der Standardraum ...").
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\forget_skill
 */
final class wizard_forget_preview_test extends advanced_testcase {
    /**
     * Resolved memory text is shown; the bare id is not the only value on the card.
     */
    public function test_card_shows_the_memory_text_not_the_id(): void {
        $this->resetAfterTest();
        $memory = 'B12 ist der Standardraum für alle zukünftigen Buchungen.';
        $descriptor = (new forget_skill())->describe_proposed_action(['id' => 5, 'memory' => $memory, 'outputlang' => 'de']);
        $this->assertNotNull($descriptor);
        $values = array_map(static fn($row) => (string)($row['value'] ?? ''), (array)($descriptor['rows'] ?? []));
        $this->assertContains($memory, $values, json_encode($descriptor, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('5', $values, 'the internal memory id is not a user-facing value');
    }

    /**
     * Without a resolved text the id remains the fallback (never an empty card).
     */
    public function test_card_falls_back_to_the_id_without_text(): void {
        $this->resetAfterTest();
        $descriptor = (new forget_skill())->describe_proposed_action(['id' => 5, 'outputlang' => 'de']);
        $values = array_map(static fn($row) => (string)($row['value'] ?? ''), (array)($descriptor['rows'] ?? []));
        $this->assertSame(['5'], $values);
    }
}
