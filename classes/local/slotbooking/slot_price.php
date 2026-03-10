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
 * Slot price calculation helpers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use mod_booking\price as mod_booking_price;

/**
 * Helper class for slot booking price calculations.
 */
class slot_price {
    /**
     * Calculate the final slot booking price for a number of slots.
     *
     * @param int $optionid booking option id
     * @param int $numslots number of slots
     * @return float final total price
     */
    public static function calculate_price(int $optionid, int $numslots): float {
        if ($numslots <= 0) {
            return 0.0;
        }

        $basepriceperslot = self::get_base_slot_price_per_slot($optionid);
        return round($basepriceperslot * $numslots, 2);
    }

    /**
     * Get base price per slot from standard option prices.
     *
     * @param int $optionid booking option id
     * @return float
     */
    private static function get_base_slot_price_per_slot(int $optionid): float {
        $records = mod_booking_price::get_prices_from_cache_or_db('option', $optionid);
        if (empty($records)) {
            return 0.0;
        }

        foreach ($records as $record) {
            $identifiers = array_map('trim', explode(',', (string)$record->pricecategoryidentifier));
            if (in_array('default', $identifiers, true)) {
                return (float)$record->price;
            }
        }

        $first = reset($records);
        return (float)($first->price ?? 0);
    }
}
