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
 * Skipped waitlist-offer state (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2, §3.3): the
 * candidate was no longer on the waiting list by the time the reconciler reached them (K8).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\offer_statuses;

use mod_booking\local\waitlist\offer_status;

/**
 * Skipped: terminal, no outgoing transitions. The reconciler (progression::reconcile()) is
 * responsible for not decrementing free capacity for a skip - that is its own responsibility,
 * not this class's.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skipped implements offer_status {
    /**
     * Skipped is terminal - no outgoing transitions.
     *
     * @param offer_status $next
     * @return bool
     */
    public function can_transition_to(offer_status $next): bool {
        return false;
    }

    /**
     * Skipped is terminal.
     *
     * @return bool
     */
    public function is_terminal(): bool {
        return true;
    }

    /**
     * The status code persisted for skipped.
     *
     * @return int
     */
    public function get_code(): int {
        return 5;
    }
}
