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

declare(strict_types=1);

namespace mod_booking\local;

/**
 * Version gate for optional local_entities integration.
 *
 * mod_booking works with or without local_entities. The capacity/equipment feature set and the
 * targeted cache-invalidation helpers (entities::reset_caches(), get_allocation_mode(),
 * get_all_dates_for_entity(), entitiesrelation_handler::purge_dates_cache()) ship with
 * local_entities 0.5.0. Older installs have the local_entities classes but NOT those methods, so a
 * plain class_exists() check is not enough — calling the new methods there would fatal. Callers use
 * {@see self::has_capacity_support()} and fall back to the pre-capacity behaviour when it is false.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entities_compat {
    /**
     * Minimum installed local_entities version that ships the capacity/equipment API and the
     * reset_caches()/purge_dates_cache() cache-invalidation helpers (local_entities 0.5.0).
     */
    public const MIN_VERSION = 2026070100;

    /**
     * Whether local_entities is installed at a version providing the capacity/equipment API.
     *
     * When false, callers must keep the pre-capacity behaviour (no entity occupancy checks, no
     * targeted date-cache purge) so mod_booking runs without — or with an older — local_entities.
     *
     * @return bool
     */
    public static function has_capacity_support(): bool {
        // Check the cheap installed-version config FIRST and short-circuit on it. Only when a
        // capacity-grade local_entities is installed do we touch the class. This avoids autoloading
        // local_entities\entities when the plugin is absent or older than MIN_VERSION — important
        // because some local_entities versions pull lib/externallib.php at file scope, which throws
        // require_phpunit_isolation() in a non-isolated PHPUnit run (and would otherwise break every
        // booking option created in such a test).
        return (int)get_config('local_entities', 'version') >= self::MIN_VERSION
            && class_exists('local_entities\entities');
    }
}
