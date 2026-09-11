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
 * targeted cache-invalidation helpers (entities::get_allocation_mode(),
 * entities::get_all_dates_for_entity(), entitiesrelation_handler::purge_dates_cache()) were added
 * to local_entities incrementally. A plain class_exists() check is not enough — older installs have
 * the classes but NOT those methods — and, as the released local_entities 0.5.0 (2026070300) shows,
 * a version compare is not enough either: it reports a recent version yet still lacks some of them.
 * Callers use {@see self::has_capacity_support()}, which probes for the API's marker method on the
 * autoload-safe entitiesrelation_handler, and fall back to the pre-capacity behaviour when false.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entities_compat {
    /**
     * Installed local_entities version from which autoloading local_entities\entities is safe in a
     * non-isolated PHPUnit run. Used only as a cheap pre-filter — method presence is verified
     * separately because the version number alone does not guarantee the capacity/cache API.
     */
    public const MIN_VERSION = 2026070100;

    /**
     * Whether local_entities is installed and actually exposes the capacity/equipment and targeted
     * cache-invalidation methods mod_booking calls.
     *
     * When false, callers must keep the pre-capacity behaviour (no entity occupancy checks, no
     * targeted date-cache purge) so mod_booking runs without — or with an older or partial —
     * local_entities.
     *
     * @return bool
     */
    public static function has_capacity_support(): bool {
        // Cheap config short-circuit: bail on an absent or clearly too-old local_entities without
        // touching a class at all.
        if ((int)get_config('local_entities', 'version') < self::MIN_VERSION) {
            return false;
        }

        // A version compare is NOT sufficient: released local_entities 0.5.0 (2026070300) reports a
        // version >= MIN_VERSION yet does not ship the capacity/cache API at all. Probe the real
        // method instead — but probe entitiesrelation_handler, NOT local_entities\entities: the
        // latter's file-scope require of lib/externallib.php throws require_phpunit_isolation() the
        // moment it is autoloaded in a non-isolated PHPUnit run, whereas entitiesrelation_handler
        // only pulls formslib and is safe to load. The capacity and targeted cache helpers ship
        // together, so purge_dates_cache() is a reliable marker for the whole set; slot_availability
        // reaches entities::get_allocation_mode()/get_all_dates_for_entity() only once this returns
        // true, so local_entities\entities is never autoloaded on an install that lacks the API.
        return class_exists('local_entities\entitiesrelation_handler')
            && method_exists('local_entities\entitiesrelation_handler', 'purge_dates_cache');
    }
}
