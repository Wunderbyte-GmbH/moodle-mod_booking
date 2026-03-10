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
use mod_booking\singleton_service;

/**
 * Helper class for slot booking price calculations.
 */
class slot_price {
    /**
     * Calculate the final price data for a single slot.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $userid user id for price category resolution
     * @return array{price:float,currency:string,pricecategoryidentifier:string}
     */
    public static function calculate_slot_price_data(
        int $optionid,
        int $slotstart,
        int $slotend,
        int $userid = 0
    ): array {
        $basedata = self::get_base_slot_price_data($optionid, $userid);

        if ($slotstart <= 0 || $slotend <= $slotstart) {
            return $basedata;
        }

        $basedata['price'] = round((float)slot_rules::apply_price_rules_to_slot_price(
            $optionid,
            $slotstart,
            $slotend,
            (float)$basedata['price'],
            (string)$basedata['pricecategoryidentifier']
        ), 2);

        return $basedata;
    }

    /**
     * Calculate the final slot booking price for a number of slots.
     *
     * @param int $optionid booking option id
     * @param int $numslots number of slots
     * @param int $userid user id for price category resolution
     * @param array<int, array{start:int, end:int}> $slots selected slots
     * @return float final total price
     */
    public static function calculate_price(int $optionid, int $numslots, int $userid = 0, array $slots = []): float {
        if ($numslots <= 0) {
            return 0.0;
        }

        $basedata = self::get_base_slot_price_data($optionid, $userid);
        $basepriceperslot = $basedata['price'];
        $pricecategoryidentifier = $basedata['pricecategoryidentifier'];

        if (empty($slots)) {
            return round($basepriceperslot * $numslots, 2);
        }

        $total = 0.0;
        foreach ($slots as $slot) {
            $slotstart = (int)($slot['start'] ?? 0);
            $slotend = (int)($slot['end'] ?? 0);
            if ($slotstart <= 0 || $slotend <= $slotstart) {
                continue;
            }

            $slotpricedata = self::calculate_slot_price_data($optionid, $slotstart, $slotend, $userid);
            $total += (float)$slotpricedata['price'];
        }

        return round($total, 2);
    }

    /**
     * Get base price per slot from standard option prices.
     *
     * @param int $optionid booking option id
     * @param int $userid user id for category-specific pricing
     * @return array{price:float,currency:string,pricecategoryidentifier:string}
     */
    private static function get_base_slot_price_data(int $optionid, int $userid = 0): array {
        $user = null;
        if ($userid > 0) {
            $user = singleton_service::get_instance_of_user($userid);
        }

        $resolvedprice = mod_booking_price::get_price('option', $optionid, $user);
        if (isset($resolvedprice['price']) && $resolvedprice['price'] !== '') {
            return [
                'price' => (float)$resolvedprice['price'],
                'currency' => (string)($resolvedprice['currency'] ?? ''),
                'pricecategoryidentifier' => (string)($resolvedprice['pricecategoryidentifier'] ?? 'default'),
            ];
        }

        $records = mod_booking_price::get_prices_from_cache_or_db('option', $optionid);
        if (empty($records)) {
            return [
                'price' => 0.0,
                'currency' => '',
                'pricecategoryidentifier' => 'default',
            ];
        }

        foreach ($records as $record) {
            $identifiers = array_map('trim', explode(',', (string)$record->pricecategoryidentifier));
            if (in_array('default', $identifiers, true)) {
                return [
                    'price' => (float)$record->price,
                    'currency' => (string)($record->currency ?? ''),
                    'pricecategoryidentifier' => (string)$record->pricecategoryidentifier,
                ];
            }
        }

        $first = reset($records);
        return [
            'price' => (float)($first->price ?? 0),
            'currency' => (string)($first->currency ?? ''),
            'pricecategoryidentifier' => (string)($first->pricecategoryidentifier ?? 'default'),
        ];
    }
}
