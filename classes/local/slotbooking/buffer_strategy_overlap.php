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
 * Buffer combination strategy: adjacent buffers may overlap each other.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

/**
 * The cooldown of the earlier booking and the warmup of the later booking are allowed to
 * share the same minutes (e.g. cleaning and next-slot preparation happen concurrently).
 * The required gap is therefore only as large as the longer of the two buffers.
 */
class buffer_strategy_overlap implements buffer_combination_strategy {
    /**
     * Calculate the required gap between two bookings.
     * @param int $cooldownminutes cooldown of the earlier booking (minutes after its end)
     * @param int $warmupminutes warmup of the later booking (minutes before its start)
     * @return int minimum required gap in minutes
     */
    public function required_gap(int $cooldownminutes, int $warmupminutes): int {
        return max($cooldownminutes, $warmupminutes);
    }
}
