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
 * Helper for the German gender-fair language variant "de_gs" (Deutsch mit Genderslash).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Builds the slash-spelled "de_gs" overrides from the standard German strings.
 *
 * Lang files must contain string definitions only (Moodle coding style, enforced by
 * moodle.Files.LangFilesOrdering), so the de_gs string file is GENERATED from 'de' by this
 * helper (see mod/booking/cli/generate_de_gs_lang.php) and checked for freshness by a unit test.
 */
class genderslash {
    /**
     * The gender-fair rewrite rules. The separator class [:*_] covers colon (Nutzer:innen),
     * Gendersternchen (Nutzer*innen) and Gender-Gap underscore (Nutzer_innen).
     *
     * @return array{0: string[], 1: string[]} [patterns, replacements]
     */
    public static function get_patterns(): array {
        $patterns = [
            '/([A-Za-zäöüßÄÖÜ])[:*_]innen/u' => '$1/innen',
            '/([A-Za-zäöüßÄÖÜ])[:*_]in\b/u' => '$1/in',
            '/([a-zäöüß])Innen/u' => '$1/innen',
            '/([a-zäöüß])In\b/u' => '$1/in',
            '/\b(ein|kein|Ein|Kein)[:*_]e\b/u' => '$1/e',
            '/([A-Za-zäöüß]*e)[:*_]r\b/u' => '$1/r',
            '/\bihm[:*_]ihr\b/u' => 'ihm/ihr',
            '/\bder[:*_]die\b/u' => 'der/die',
            '/\bjede[:*_]n\b/u' => 'jede/n',
        ];
        return [array_keys($patterns), array_values($patterns)];
    }

    /**
     * Rewrite a single string to the gender-fair slash spelling.
     *
     * @param string $value
     * @return string
     */
    public static function to_slash(string $value): string {
        [$search, $replace] = self::get_patterns();
        $result = preg_replace($search, $replace, $value);
        return $result ?? $value;
    }

    /**
     * Include a component lang file in isolation and return its $string array.
     *
     * @param string $file absolute path to a lang file
     * @return array
     */
    protected static function load_strings(string $file): array {
        if (!is_readable($file)) {
            return [];
        }
        $loader = static function (string $path): array {
            $string = [];
            include($path);
            return $string;
        };
        return $loader($file);
    }

    /**
     * Build the de_gs override map from the plugin's standard German strings.
     *
     * Contains only the keys whose slash spelling differs from 'de', sorted the same way the
     * lang-file ordering sniff expects (SORT_STRING / strcmp).
     *
     * @return array key => slash-spelled value
     */
    public static function build_overrides(): array {
        global $CFG;
        $de = self::load_strings($CFG->dirroot . '/mod/booking/lang/de/booking.php');
        $overrides = [];
        foreach ($de as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $slashed = self::to_slash($value);
            if ($slashed !== $value) {
                $overrides[$key] = $slashed;
            }
        }
        uksort($overrides, 'strcmp');
        return $overrides;
    }

    /**
     * Render the full content of lang/de_gs/booking.php (a plain, sorted $string list).
     *
     * @return string
     */
    public static function render_lang_file(): string {
        $out = self::file_header();
        foreach (self::build_overrides() as $key => $value) {
            $out .= '$string[' . var_export((string) $key, true) . '] = ' . var_export($value, true) . ";\n";
        }
        return $out;
    }

    /**
     * The fixed header for the generated lang file.
     *
     * @return string
     */
    protected static function file_header(): string {
        return <<<'HEADER'
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
         * Strings for the German gender-fair variant 'de_gs' (Deutsch mit Genderslash).
         *
         * GENERATED FILE - do not edit by hand. It is derived from lang/de/booking.php (gender forms
         * rewritten to slash spelling, e.g. "Nutzer:innen" -> "Nutzer/innen"). Child language of 'de';
         * only differing strings are listed here, everything else is inherited.
         *
         * Regenerate with: php mod/booking/cli/generate_de_gs_lang.php
         * A unit test (mod_booking\local\genderslash_test) fails if this file is out of date.
         *
         * @package     mod_booking
         * @category    string
         * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
         * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
         */

        defined('MOODLE_INTERNAL') || die();


        HEADER;
    }
}
