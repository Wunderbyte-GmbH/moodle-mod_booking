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
 * Language-pack coverage of the anonymizer collision strings.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;

/**
 * The collision clarification and its decision chips were English-only: the German pack lacked
 * the keys, Moodle fell back to English, the chips stayed English and the synchronizer LLM
 * translated the clarification on the fly (taskflow baseline, threads 1301/1303). Every
 * agent_anon_* string must exist in every shipped language pack and keep its placeholder, so
 * get_string renders the clarification and the chips in the user's language without an LLM.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class privacy_collision_strings_lang_test extends advanced_testcase {
    /**
     * Raw string array of one shipped language pack (read from disk: get_string() would
     * silently fall back to English for a missing key).
     *
     * @param string $lang
     * @return array<string,string>
     */
    private function pack(string $lang): array {
        $string = [];
        include(\core_component::get_component_directory('bookingextension_agent') . "/lang/$lang/bookingextension_agent.php");
        return $string;
    }

    /**
     * Every English agent_anon_* key exists in every other shipped pack with the same placeholder.
     */
    public function test_every_collision_string_is_translated(): void {
        $en = $this->pack('en');
        $keys = array_values(array_filter(array_keys($en), static fn(string $k): bool => str_starts_with($k, 'agent_anon_')));
        $this->assertNotEmpty($keys);

        $langdir = \core_component::get_component_directory('bookingextension_agent') . '/lang';
        $langs = array_values(array_diff(scandir($langdir), ['.', '..', 'en']));
        $this->assertNotEmpty($langs, 'At least one translation pack is shipped.');

        foreach ($langs as $lang) {
            $pack = $this->pack($lang);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $pack, "lang/$lang is missing $key");
                $this->assertSame(
                    substr_count($en[$key], '{$a}'),
                    substr_count($pack[$key], '{$a}'),
                    "lang/$lang $key must keep the {\$a} placeholder of the English string"
                );
            }
        }
    }

    /**
     * The German clarification is a real translation that names the word (PHPUnit installs only
     * English, so the pack is checked directly instead of through get_string in German).
     */
    public function test_german_clarification_is_a_translation(): void {
        $this->resetAfterTest();
        $pack = $this->pack('de');

        $expected = str_replace('{$a}', 'Kurz', $pack['agent_anon_person_reference_clarify']);
        $this->assertStringContainsString('Kurz', $expected);
        $this->assertNotSame(
            str_replace('{$a}', 'Kurz', $this->pack('en')['agent_anon_person_reference_clarify']),
            $expected,
            'The German clarification must not be the English fallback.'
        );
    }
}
