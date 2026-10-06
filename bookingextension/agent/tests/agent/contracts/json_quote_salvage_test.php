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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * A German quote closed with an ASCII mark must not kill a perfect answer.
 *
 * L6-E1: the model writes „Wort" (typographic opener, ASCII closer) inside JSON string
 * values — the straight quote terminates the string and a complete, correct reply dies as
 * CONTRACT_PARSE_ERROR (62% of German-quote responses in recent history carry the idiom).
 * The salvage runs ONLY after a normal parse failed and only rewrites the exact idiom.
 *
 * @covers \bookingextension_agent\local\wizard\interpreter
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class json_quote_salvage_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Run 16, thread 5173 (DAS-2): the answer mixes a CORRECTLY escaped quote with a broken one.
     *
     * The salvage used to heal both, which turned the valid \" into an invalid \“ escape — the reply still
     * failed to parse, the loop retried and the turn ended as `error` although the answer was complete.
     */
    public function test_run16_thread_5173_bytes_are_salvaged(): void {
        $raw = '{"response_type":"sufficient","message":"Die Zuweisung von ANON_USER_1_both '
            . '(Datenschutz-Unterweisung 2026, #4065) zeigt den Status „Verlängert\\", weil ANON_USER_2_both '
            . 'am 15.09.2026 um 16:18 Uhr eine manuelle Änderung vorgenommen hat: „Frist einmalig verlängert '
            . '(Krankenstand)". Dadurch wurde das Fälligkeitsdatum verschoben und der Status ist verlängert '
            . 'statt überfällig.","next_step_intent":"Frage beantwortet","lang":"de","user_lang":"de",'
            . '"planned_steps":[]}';

        $result = (new interpreter(skill_registry::make_default()))->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertStringContainsString('Verlängert', (string)($result['message'] ?? ''));
        $this->assertStringContainsString('Krankenstand', (string)($result['message'] ?? ''));
    }

    /**
     * An escaped quote inside a quoted German phrase stays escaped: the salvage must not touch it.
     */
    public function test_escaped_quote_is_left_alone(): void {
        $raw = '{"response_type":"sufficient","message":"Der Status „Verlängert\\" ist gesetzt.",'
            . '"commands":[],"planned_steps":[],"next_step_intent":"","lang":"de","user_lang":"de"}';

        $result = (new interpreter(skill_registry::make_default()))->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertStringContainsString('Verlängert', (string)($result['message'] ?? ''));
    }

    /**
     * The verbatim Lauf-6 thread-303 response must parse into its sufficient answer.
     */
    public function test_thread_303_bytes_are_salvaged(): void {
        $raw = '{"response_type":"sufficient","message":"Nein, ANON_USER_1_lastname kann keine '
            . 'Buchungsoptionen in „Alpinwandern" anlegen. Ihm fehlt die Berechtigung '
            . 'mod/booking:addoption auf der Buchungsaktivität.","commands":[],"planned_steps":[],'
            . '"next_step_intent":"Permission-Check-Ergebnis mitteilen","lang":"de","user_lang":"de"}';

        $interpreter = new interpreter(skill_registry::make_default());
        $result = $interpreter->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertStringContainsString('Alpinwandern', (string)($result['message'] ?? ''));
        $this->assertStringContainsString('Berechtigung', (string)($result['message'] ?? ''));
    }

    /**
     * Healthy JSON — escaped ASCII quotes and proper typographic pairs — parses untouched.
     */
    public function test_healthy_quotes_parse_byte_identically(): void {
        $raw = '{"response_type":"sufficient","message":"Die Option \"Kurs A\" und „Kurs B“ sind '
            . 'buchbar.","commands":[],"lang":"de","user_lang":"de"}';

        $interpreter = new interpreter(skill_registry::make_default());
        $result = $interpreter->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''));
        $this->assertSame('Die Option "Kurs A" und „Kurs B“ sind buchbar.', (string)($result['message'] ?? ''));
    }

    /**
     * Two broken idioms in one value are both healed.
     */
    public function test_multiple_broken_pairs_are_salvaged(): void {
        $raw = '{"response_type":"sufficient","message":"„Kurs A" und „Kurs B" wurden gefunden.",'
            . '"commands":[],"lang":"de","user_lang":"de"}';

        $interpreter = new interpreter(skill_registry::make_default());
        $result = $interpreter->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''));
        $this->assertStringContainsString('Kurs A', (string)($result['message'] ?? ''));
        $this->assertStringContainsString('Kurs B', (string)($result['message'] ?? ''));
    }
}
