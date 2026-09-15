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
 * Strategy contract for combining two adjacent slots' buffer zones.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

/**
 * Decides the minimum gap required between two adjacent bookings, given the cooldown
 * trailing the earlier one and the warmup leading the later one.
 *
 * This is the single point where the "may buffers overlap, or must they be summed"
 * setting is expressed as an algorithm, instead of as a branch scattered through the
 * collision-checking code.
 */
interface buffer_combination_strategy {
    /**
     * Minimum required gap (in minutes) between the end of the earlier booking's core
     * time and the start of the later booking's core time.
     *
     * @param int $cooldownminutes cooldown of the earlier booking (minutes after its end)
     * @param int $warmupminutes warmup of the later booking (minutes before its start)
     * @return int minimum required gap in minutes
     */
    public function required_gap(int $cooldownminutes, int $warmupminutes): int;
}
