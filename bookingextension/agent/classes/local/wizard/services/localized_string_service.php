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
 * Shared localized string resolver.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Resolves localized string values with temporary language switching.
 */
class localized_string_service {
    /**
     * Resolve a localized string in the requested language.
     *
     * @param string $identifier
     * @param string $component
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    public static function get(string $identifier, string $component, $a = null, string $lang = ''): string {
        $currentlang = current_language();
        $targetlang = trim($lang);
        $switched = $targetlang !== '' && $targetlang !== $currentlang;

        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return get_string($identifier, $component, $a);
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }
}
