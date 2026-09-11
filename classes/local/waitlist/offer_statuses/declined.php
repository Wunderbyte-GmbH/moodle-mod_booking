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
 * Declined waitlist-offer state (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2): the
 * candidate manually declined the offer (unconfirm).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\offer_statuses;

use mod_booking\local\waitlist\offer_status;

/**
 * Declined: terminal, no outgoing transitions - in particular declined -> offered (the K7 bug,
 * see rules_waitinglist_notification_test.php's A1 characterization and the B1 target-behaviour
 * test) is structurally unreachable. The permanent, round-independent lockout itself lives in
 * the booking_waitlist_declines table (db/install.xml), populated by the repository - this
 * class only guarantees that within a single round, nothing can follow a decline.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class declined implements offer_status {
    /**
     * Declined is terminal - no outgoing transitions. In particular declined -> offered (the
     * K7 bug) must never be reachable.
     *
     * @param offer_status $next
     * @return bool
     */
    public function can_transition_to(offer_status $next): bool {
        return false;
    }

    /**
     * Declined is terminal.
     *
     * @return bool
     */
    public function is_terminal(): bool {
        return true;
    }

    /**
     * The status code persisted for declined.
     *
     * @return int
     */
    public function get_code(): int {
        return 3;
    }
}
