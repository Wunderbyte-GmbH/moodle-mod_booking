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
 * Central factory for skill registry instances.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * Provides a shared per-request skill registry instance.
 */
class skill_registry_factory {
    /** @var skill_registry|null */
    private static ?skill_registry $registry = null;

    /** @var string */
    private static string $lastbuildwarning = '';

    /**
     * Return shared default skill registry.
     *
     * @return skill_registry
     */
    public static function get_default(): skill_registry {
        if (self::$registry === null) {
            self::$lastbuildwarning = '';
            try {
                self::$registry = skill_registry::make_default();
            } catch (\Throwable $e) {
                self::$registry = new skill_registry();
                self::$lastbuildwarning = 'Skill registry fallback activated after build failure: '
                    . get_class($e) . ': ' . $e->getMessage();
                debugging(self::$lastbuildwarning, DEBUG_DEVELOPER);
            }
        }

        return self::$registry;
    }

    /**
     * Reset cached registry instance.
     *
     * Intended for tests where component/skill set may change between cases.
     *
     * @return void
     */
    public static function reset(): void {
        self::$registry = null;
        self::$lastbuildwarning = '';
    }
}
