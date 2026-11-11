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
 * Every cache definition in db/caches.php needs its cachedef_ language string.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

/**
 * Every cache definition in db/caches.php needs its cachedef_ language string.
 *
 * Moodle shows the name of a cache definition with get_string('cachedef_<name>', '<component>'),
 * but only on the admin pages that list the definitions (Plugins > Caching > Configuration).
 * Using the cache works without the string, so a forgotten one is not noticed during development.
 *
 * @coversNothing
 */
final class cache_definition_strings_test extends \basic_testcase {
    /**
     * Each definition has a non-empty cachedef_ string in the given language pack of the plugin.
     *
     * @dataProvider lang_provider
     * @param string $lang directory name below lang/
     */
    public function test_every_cache_definition_has_its_string(string $lang): void {
        $definitions = self::load_array(self::plugin_dir() . '/db/caches.php', 'definitions');
        $strings = self::load_array(self::plugin_dir() . "/lang/$lang/booking.php", 'string');

        $this->assertNotEmpty($definitions, 'No cache definitions found in db/caches.php.');

        $missing = [];
        foreach (array_keys($definitions) as $name) {
            if (trim($strings["cachedef_$name"] ?? '') === '') {
                $missing[] = "cachedef_$name";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "lang/$lang/booking.php lacks the name of cache definitions from db/caches.php."
        );
    }

    /**
     * The language packs that have to be complete: en is the fallback of all others, de is maintained with it.
     *
     * @return array
     */
    public static function lang_provider(): array {
        return [
            'en' => ['en'],
            'de' => ['de'],
        ];
    }

    /**
     * Includes a PHP file that fills an array variable and returns that array.
     *
     * The include happens in the scope of this method, so the variable does not leak.
     *
     * @param string $file absolute path
     * @param string $variable name of the array the file fills, e.g. "string" for $string
     * @return array
     */
    private static function load_array(string $file, string $variable): array {
        $$variable = [];
        include($file);
        return $$variable;
    }

    /**
     * Directory of the plugin.
     *
     * @return string
     */
    private static function plugin_dir(): string {
        return dirname(__DIR__);
    }
}
