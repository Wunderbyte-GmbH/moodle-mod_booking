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

namespace mod_booking\local\slotbooking;

use mod_booking\utils\wb_payment;

/**
 * Single source of truth for whether the slot booking feature is available.
 *
 * Slot booking requires BOTH the PRO licence AND the global admin toggle
 * (booking/slotbookingactive, settings.php) to be on. Every gate in the plugin
 * (option type form/validation/resolver, the prepage condition, the agent skill,
 * the slot entry scripts and webservices) goes through this helper, so enabling or
 * disabling the whole feature is a single, consistent decision.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_feature {
    /**
     * Whether the slot booking feature is available on this site.
     *
     * @return bool True only when PRO is active and the admin toggle is on.
     */
    public static function is_enabled(): bool {
        if (!wb_payment::pro_version_is_activated()) {
            return false;
        }
        $active = get_config('booking', 'slotbookingactive');
        // Default-on: when the toggle was never written (e.g. right after the upgrade that introduces
        // it) treat it as enabled, so existing PRO sites keep slot booking until an admin turns it off.
        return ($active === false) || (bool)$active;
    }
}
