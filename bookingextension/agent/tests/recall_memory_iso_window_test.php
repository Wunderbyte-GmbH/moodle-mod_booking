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

use bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill;

/**
 * recall_memory takes its date window as ISO dates - it parses no phrase in any language.
 *
 * F80 (baseline runs 31-33, RM-3 "De quoi avons-nous parlé la semaine dernière, vendredi je crois ?"): the skill
 * turned "vendredi dernier" into an error because its date parser knew "last friday" and "letzten Freitag" - a
 * word list, and an English one where the list ended. In run 31 the planner then retried with "2026-09-18" and
 * succeeded: it knows now_iso and can compute the date in any language. So the contract asks for the date, not
 * for the phrase: date_from / date_to (YYYY-MM-DD), validated structurally, nothing else.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill
 */
final class recall_memory_iso_window_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The contract carries ISO date fields and no phrase field.
     */
    public function test_the_contract_asks_for_dates_not_phrases(): void {
        $schema = (new recall_memory_skill())->get_schema();
        $properties = (array)($schema['properties'] ?? ($schema['input']['properties'] ?? []));
        $this->assertArrayHasKey('date_from', $properties);
        $this->assertArrayHasKey('date_to', $properties);
        $this->assertArrayNotHasKey('date_hint', $properties);
    }

    /**
     * Wave 32 (A3): the card sentence about date_from is one sentence, not "ISO date.mandatory." — every word
     * stays separated from the next by whitespace after a full stop (constructor request of thread 10894).
     */
    public function test_the_description_has_no_glued_sentences(): void {
        $description = (string)((new recall_memory_skill())->get_schema()['description'] ?? '');
        $this->assertDoesNotMatchRegularExpression('/\.[A-Za-z]/', $description, $description);
        $this->assertStringContainsString('date_from', $description);
    }

    /**
     * Run 33 bytes: a phrase in a date field is refused as a recoverable input error, an ISO date is accepted.
     */
    public function test_a_phrase_is_refused_and_an_iso_date_accepted(): void {
        $this->resetAfterTest();
        $skill = new recall_memory_skill();

        $phrase = $skill->check_structure(['mode' => 'date_window', 'date_from' => 'vendredi dernier']);
        $this->assertFalse((bool)($phrase['valid'] ?? true));
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($phrase['issue_codes'] ?? []));

        $missing = $skill->check_structure(['mode' => 'date_window']);
        $this->assertFalse((bool)($missing['valid'] ?? true));

        $iso = $skill->check_structure(['mode' => 'date_window', 'date_from' => '2026-09-18']);
        $this->assertTrue((bool)($iso['valid'] ?? false), json_encode($iso));

        $range = $skill->check_structure(['mode' => 'date_window', 'date_from' => '2026-09-14', 'date_to' => '2026-09-18']);
        $this->assertTrue((bool)($range['valid'] ?? false), json_encode($range));
    }

    /**
     * No date parser survives in the skill: not a weekday word in any language, not strtotime.
     */
    public function test_the_skill_carries_no_phrase_parser(): void {
        $method = new \ReflectionMethod(recall_memory_skill::class, 'resolve_date_window');
        $lines = file((string)$method->getFileName());
        $body = strtolower(implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        )));
        $this->assertStringNotContainsString('friday', $body, 'no weekday word in any language');
        $this->assertStringNotContainsString('freitag', $body);
        $this->assertStringNotContainsString('strtotime', $body);
        $this->assertStringNotContainsString('->modify(', $body, 'no relative phrase arithmetic');
    }
}
