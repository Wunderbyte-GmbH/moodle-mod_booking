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
 * Notification boundary (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.4): wraps the existing
 * message_controller so the reconciler itself stays messaging-free (Dependency Inversion).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Sends the two waitlist-progression notifications.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface messaging_gateway {
    /**
     * Notifies a candidate that they have received a paid offer (K4).
     *
     * @param waitlist_offer $offer
     * @param int $ruleid the applicable_rules() rule whose template/subject to use
     * @return void
     */
    public function notify_offer(waitlist_offer $offer, int $ruleid): void;

    /**
     * Notifies a candidate that they have been autobooked (K3).
     *
     * @param booking_waitlist_candidate $candidate
     * @param int $ruleid the applicable_rules() rule that triggered this round
     * @return void
     */
    public function notify_autobooked(booking_waitlist_candidate $candidate, int $ruleid): void;
}
