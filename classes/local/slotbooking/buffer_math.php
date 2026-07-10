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
 * Pure warmup/cooldown buffer collision math, independent of slots or the DB.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

/**
 * Stateless helper that decides whether two bookings' warmup/cooldown buffers collide.
 *
 * Core-time overlap (two bookings occupying the same minutes) is a separate, already
 * existing concern (capacity/count_bookings) and is deliberately out of scope here: this
 * class only judges the gap between two bookings whose core times do not overlap.
 */
class buffer_math {
    /** @var string Adjacent buffers may share the same minutes. */
    public const MODE_OVERLAP = 'overlap';

    /** @var string Adjacent buffers are stacked (added together). */
    public const MODE_SUMMED = 'summed';

    /**
     * Build the strategy instance for a stored combination-mode value.
     *
     * Kept as a small factory so callers (form validation, slot_availability) never need
     * to know the concrete strategy classes, only the stored mode string.
     *
     * @param string $mode one of self::MODE_OVERLAP / self::MODE_SUMMED
     * @return buffer_combination_strategy
     */
    public static function create_strategy(string $mode): buffer_combination_strategy {
        switch ($mode) {
            case self::MODE_SUMMED:
                return new buffer_strategy_summed();
            case self::MODE_OVERLAP:
                return new buffer_strategy_overlap();
            default:
                throw new \coding_exception('buffer_math: unknown combination mode "' . $mode . '"');
        }
    }

    /**
     * Whether booking A's and booking B's warmup/cooldown buffers collide.
     *
     * Only the boundary between the two bookings is judged: whichever one starts later is
     * the "later" booking for the purpose of $strategy->required_gap(), and its warmup is
     * weighed against the earlier booking's cooldown. If the core times already overlap,
     * this function returns false (that case is not a buffer conflict, it is a capacity
     * conflict handled elsewhere).
     *
     * @param int $starta booking A core start (unix timestamp)
     * @param int $enda booking A core end (unix timestamp)
     * @param int $warmupa booking A warmup in minutes (before $starta)
     * @param int $cooldowna booking A cooldown in minutes (after $enda)
     * @param int $startb booking B core start (unix timestamp)
     * @param int $endb booking B core end (unix timestamp)
     * @param int $warmupb booking B warmup in minutes (before $startb)
     * @param int $cooldownb booking B cooldown in minutes (after $endb)
     * @param buffer_combination_strategy $strategy combination-mode strategy
     * @return bool true if the buffers collide
     */
    public static function collides(
        int $starta,
        int $enda,
        int $warmupa,
        int $cooldowna,
        int $startb,
        int $endb,
        int $warmupb,
        int $cooldownb,
        buffer_combination_strategy $strategy
    ): bool {
        if ($enda <= $startb) {
            // A ends, then B starts: A's cooldown meets B's warmup.
            $gapminutes = ($startb - $enda) / MINSECS;
            return $gapminutes < $strategy->required_gap($cooldowna, $warmupb);
        }

        if ($endb <= $starta) {
            // B ends, then A starts: B's cooldown meets A's warmup.
            $gapminutes = ($starta - $endb) / MINSECS;
            return $gapminutes < $strategy->required_gap($cooldownb, $warmupa);
        }

        // Core times overlap (or touch without a gap in between); not a buffer conflict.
        return false;
    }
}
