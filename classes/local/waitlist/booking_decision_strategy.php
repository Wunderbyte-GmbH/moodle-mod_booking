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
 * Strategy interface for a waiting-list candidate's booking decision
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.1). Isolates the price question (K3/K4/P1/P2)
 * completely from the reconciler's order/capacity logic, and makes it testable in isolation
 * (no DB/reconciler needed).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Decides whether a candidate should be autobooked or offered a seat.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface booking_decision_strategy {
    /**
     * Decides the outcome for one candidate.
     *
     * @param booking_waitlist_candidate $candidate
     * @return booking_decision
     */
    public function decide(booking_waitlist_candidate $candidate): booking_decision;
}
