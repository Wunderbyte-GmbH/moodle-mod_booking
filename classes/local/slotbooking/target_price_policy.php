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
 * Target-slot price policy for self-service rebooking.
 *
 * V1 restricts self-rebooking to price-equal target slots, so the feature works
 * without any payment or refund logic. V2 (SLOTBOOKING_REBOOKING_PRICE_CONCEPT.md)
 * will replace this policy with price-difference handling via shopping_cart; the
 * webservice depends only on this class so the swap is local.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

/**
 * Policy deciding which target slots are selectable for self-service rebooking.
 */
class target_price_policy {
    /**
     * Keep only target slots whose calculated price equals the price of one of the
     * participant's current slots (price-equal rebooking).
     *
     * Reselecting a current slot is therefore always allowed (its own price is in the
     * allowed set). This is the V1 rule; V2 will offer differently priced targets.
     *
     * @param int $optionid booking option id
     * @param int $userid the booking owner (for price-category resolution)
     * @param array $currentslots current slot ranges ([['start'=>int,'end'=>int], ...])
     * @param array $targetslots labelled target-slot entries (with start/end keys)
     * @return array<int, array<string, mixed>> filtered target slots
     */
    public static function filter_self_targets(
        int $optionid,
        int $userid,
        array $currentslots,
        array $targetslots
    ): array {
        $allowedprices = [];
        foreach ($currentslots as $slot) {
            $pricedata = slot_price::calculate_slot_price_data(
                $optionid,
                (int)($slot['start'] ?? 0),
                (int)($slot['end'] ?? 0),
                $userid
            );
            $allowedprices[self::price_key((float)($pricedata['price'] ?? 0))] = true;
        }

        $filtered = [];
        foreach ($targetslots as $target) {
            $pricedata = slot_price::calculate_slot_price_data(
                $optionid,
                (int)($target['start'] ?? 0),
                (int)($target['end'] ?? 0),
                $userid
            );
            if (!empty($allowedprices[self::price_key((float)($pricedata['price'] ?? 0))])) {
                $filtered[] = $target;
            }
        }

        return $filtered;
    }

    /**
     * Normalise a price to a stable comparison key (avoids float equality pitfalls).
     *
     * @param float $price slot price
     * @return string
     */
    private static function price_key(float $price): string {
        return number_format($price, 2, '.', '');
    }

    /**
     * Calculate the price difference of a move: sum(new slot prices) - sum(current slot prices).
     *
     * Positive = upgrade (pay the difference), negative = downgrade (refund the difference),
     * zero = price-neutral move. Reselected (kept) slots cancel out, so only genuinely changed
     * slots contribute. Used by slot_update_service to route the move (direct / refund / cart).
     *
     * @param int $optionid booking option id
     * @param int $userid booking owner (price-category resolution)
     * @param array $currentslots current slot ranges ([['start'=>int,'end'=>int], ...])
     * @param array $newslots target slot ranges (same shape)
     * @return float rounded price delta
     */
    public static function calculate_move_delta(
        int $optionid,
        int $userid,
        array $currentslots,
        array $newslots
    ): float {
        $sum = static function (array $slots) use ($optionid, $userid): float {
            $total = 0.0;
            foreach ($slots as $slot) {
                $pricedata = slot_price::calculate_slot_price_data(
                    $optionid,
                    (int)($slot['start'] ?? 0),
                    (int)($slot['end'] ?? 0),
                    $userid
                );
                $total += (float)($pricedata['price'] ?? 0);
            }
            return $total;
        };

        return round($sum($newslots) - $sum($currentslots), 2);
    }
}
