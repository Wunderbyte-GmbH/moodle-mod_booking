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
 * Pending waitlist-offer state (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2): the initial
 * state of every waitlist candidate before the reconciler has decided anything for the current
 * round.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\offer_statuses;

use mod_booking\local\waitlist\offer_status;

/**
 * Pending: the only non-terminal starting state. Can move to offered (K4), autobooked (K3), or
 * skipped (K8) - and nowhere else.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending implements offer_status {
    /**
     * Pending can move to offered (K4), autobooked (K3), or skipped (K8).
     *
     * @param offer_status $next
     * @return bool
     */
    public function can_transition_to(offer_status $next): bool {
        return $next instanceof offered
            || $next instanceof autobooked
            || $next instanceof skipped;
    }

    /**
     * Pending is not terminal.
     *
     * @return bool
     */
    public function is_terminal(): bool {
        return false;
    }

    /**
     * The status code persisted for pending.
     *
     * @return int
     */
    public function get_code(): int {
        return 0;
    }
}
