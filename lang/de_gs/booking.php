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
 * Instead of maintaining a fixed list of overrides, this derives the gender-fair (slash) spelling
 * from the parent language 'de' at load time. Every German string is transformed, e.g.
 * "Nutzer:innen" / "NutzerInnen" / "Nutzer*innen" -> "Nutzer/innen", "ein:e" -> "ein/e",
 * "der:die" -> "der/die". New gendered strings added to 'de' are therefore covered automatically -
 * no manual upkeep of this file is needed.
 *
 * How it works (see core_string_manager_standard::load_component_strings()):
 * - parentlanguage is 'de', so by the time Moodle includes this file the merged 'de' values are
 *   already present in $string; we only rewrite them in place.
 * - Values identical to the English source are skipped, so untranslated English fallbacks
 *   (e.g. "opt_in") are never mangled.
 * - The resulting array is cached by the string manager, so this code runs only on cache rebuild.
 *
 * @package     mod_booking
 * @category    string
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Rewrite the inherited 'de' strings to slash spelling. Wrapped in a closure so no helper
// variables leak into the cached string set, and $string is modified by reference.
(static function (array &$string): void {

    // Patterns that turn gender forms without a slash into the slash spelling.
    // The separator class [:*_] covers all three German notations: colon (Nutzer:innen),
    // asterisk/Gendersternchen (Nutzer*innen) and underscore/Gender-Gap (Nutzer_innen).
    $patterns = [
        '/([A-Za-zäöüßÄÖÜ])[:*_]innen/u'   => '$1/innen', // Nutzer:innen / Nutzer*innen / Nutzer_innen.
        '/([A-Za-zäöüßÄÖÜ])[:*_]in\b/u'    => '$1/in',    // Nutzer:in / Nutzer*in / Nutzer_in.
        '/([a-zäöüß])Innen/u'              => '$1/innen', // Binnen-I: NutzerInnen.
        '/([a-zäöüß])In\b/u'               => '$1/in',    // Binnen-I: NutzerIn.
        '/\b(ein|kein|Ein|Kein)[:*_]e\b/u' => '$1/e',     // ein:e / ein*e / ein_e (auch kein...).
        '/([A-Za-zäöüß]*e)[:*_]r\b/u'      => '$1/r',     // welche:r / zugewiesene_r / betroffene*r.
        '/\bihm[:*_]ihr\b/u'               => 'ihm/ihr',  // ihm:ihr / ihm_ihr.
        '/\bder[:*_]die\b/u'               => 'der/die',  // der:die / der_die.
        '/\bjede[:*_]n\b/u'                => 'jede/n',   // jede:n / jede*n / jede_n.
    ];
    $search = array_keys($patterns);
    $replace = array_values($patterns);

    // English source strings of this component, loaded scope-isolated (the include sets its own
    // local $string), so we can skip values that are not actually translated to German.
    $enloader = static function (string $file): array {
        $string = [];
        include $file;
        return $string;
    };
    $enfile = __DIR__ . '/../en/booking.php';
    $en = is_readable($enfile) ? $enloader($enfile) : [];

    foreach ($string as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        // Skip untranslated strings (still English) to avoid mangling e.g. "opt_in".
        if (isset($en[$key]) && $en[$key] === $value) {
            continue;
        }
        $slashed = preg_replace($search, $replace, $value);
        if ($slashed !== null && $slashed !== $value) {
            $string[$key] = $slashed;
        }
    }
})($string);
