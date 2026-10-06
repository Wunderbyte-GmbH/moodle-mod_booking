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

namespace mod_booking;

use basic_testcase;

/**
 * Every user-facing wizard/agent string shipped in English must exist in the German pack too.
 *
 * Baseline run 9 (2026-09-16, preview audit P4, Wunderbyte-GmbH#2408): the confirm cards of the
 * booking wizard skills rendered mixed-language ("Update booking option ... (Kurs: ai)",
 * "Passende Optionen = 176 option(s)") because 77 preview strings existed only in lang/en.
 * The preview builders resolve labels with get_string(..., $lang) and silently fall back to
 * English, which the hard rule "every user text in all shipped language packs" forbids.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\option_preview_builder
 */
final class wizard_preview_strings_language_packs_test extends basic_testcase {
    /**
     * Load the string array of one language pack file.
     *
     * @param string $lang Language code (folder under lang/).
     * @return array<string,string>
     */
    private function load_pack(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/booking.php');
        return $string;
    }

    /**
     * Wizard strings (preview_*, agent_*, wizard_*) present in en must be present in de.
     */
    public function test_wizard_strings_exist_in_german_pack(): void {
        $en = $this->load_pack('en');
        $de = $this->load_pack('de');
        $missing = [];
        foreach (array_keys($en) as $key) {
            if (preg_match('/^(preview|agent_|wizard_)/', $key) && !array_key_exists($key, $de)) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'wizard strings missing in lang/de/booking.php: ' . implode(', ', $missing));
    }
}
