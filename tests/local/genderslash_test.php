<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the gender-fair "de_gs" helper.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Tests for {@see \mod_booking\local\genderslash}.
 *
 * @covers \mod_booking\local\genderslash
 */
final class genderslash_test extends \advanced_testcase {
    /**
     * The slash rewrite covers colon, asterisk, underscore and Binnen-I forms.
     *
     * @dataProvider to_slash_provider
     * @param string $input
     * @param string $expected
     */
    public function test_to_slash(string $input, string $expected): void {
        $this->assertSame($expected, genderslash::to_slash($input));
    }

    /**
     * Data provider for {@see test_to_slash}.
     *
     * @return array<string, array{string, string}>
     */
    public static function to_slash_provider(): array {
        return [
            'colon plural' => ['Nutzer:innen', 'Nutzer/innen'],
            'asterisk plural' => ['Nutzer*innen', 'Nutzer/innen'],
            'underscore plural' => ['Nutzer_innen', 'Nutzer/innen'],
            'binnen-i plural' => ['NutzerInnen', 'Nutzer/innen'],
            'colon singular' => ['Nutzer:in', 'Nutzer/in'],
            'binnen-i singular' => ['NutzerIn', 'Nutzer/in'],
            'article colon' => ['ein:e', 'ein/e'],
            'article underscore' => ['der_die', 'der/die'],
            'adjective colon' => ['welche:r', 'welche/r'],
            'pronoun underscore' => ['jede_n', 'jede/n'],
            'article accusative colon' => ['eine:n Entwickler:in', 'eine/n Entwickler/in'],
            'article accusative negated' => ['keine:n', 'keine/n'],
            'plain word unchanged' => ['jeden', 'jeden'],
            'no gender form unchanged' => ['Information', 'Information'],
            'already slash unchanged' => ['Nutzer/innen', 'Nutzer/innen'],
        ];
    }

    /**
     * The committed lang/de_gs/booking.php must match what the generator produces from 'de'.
     *
     * If this fails, a German string was added/changed without regenerating the variant. Run:
     *   php mod/booking/cli/generate_de_gs_lang.php
     */
    public function test_de_gs_lang_file_is_up_to_date(): void {
        global $CFG;

        $expected = genderslash::build_overrides();

        $string = [];
        include($CFG->dirroot . '/mod/booking/lang/de_gs/booking.php');

        $this->assertEquals(
            $expected,
            $string,
            'lang/de_gs/booking.php is out of date - regenerate it with: '
                . 'php mod/booking/cli/generate_de_gs_lang.php'
        );
    }

    /**
     * Every gender form in the German strings must be covered by the slash rewrite.
     *
     * Catches typos like "Teilnehmer:innnen" that the rewrite rules do not match, so the string
     * would silently stay in colon spelling in de_gs (and tempt someone to patch de_gs by hand).
     */
    public function test_all_de_gender_forms_are_converted(): void {
        global $CFG;

        $string = [];
        include($CFG->dirroot . '/mod/booking/lang/de/booking.php');

        // Colon/asterisk followed by "in...", underscore gap forms, and Binnen-I.
        $leftover = '/[A-Za-zäöüß](?:[:*]in|_innen\b|_in\b)|[a-zäöüß]In(?:n|e?n?\b)/u';

        $unconverted = [];
        foreach ($string as $key => $value) {
            $slashed = genderslash::to_slash((string) $value);
            if (preg_match_all($leftover, $slashed, $matches)) {
                $unconverted[] = $key . ': ' . implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame(
            [],
            $unconverted,
            'German strings with gender forms the de_gs rewrite does not handle - fix the spelling '
                . 'in lang/de/booking.php (or extend genderslash::get_patterns()) and regenerate de_gs.'
        );
    }
}
