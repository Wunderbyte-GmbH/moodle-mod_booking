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
 * State Pattern interface for a waitlist offer's status
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.2). Each concrete implementation in
 * local\waitlist\offer_statuses\ knows its own valid outgoing transitions - this is what makes
 * an invalid transition like declined -> offered (the K7 bug) structurally unreachable, not
 * merely something tests happen to cover.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * A single state in the waitlist offer state machine.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface offer_status {
    /**
     * Whether a transition from this state to $next is allowed.
     *
     * @param offer_status $next
     * @return bool
     */
    public function can_transition_to(offer_status $next): bool;

    /**
     * Whether this state is terminal (no outgoing transitions at all).
     *
     * @return bool
     */
    public function is_terminal(): bool;

    /**
     * The numeric code persisted in booking_waitlist_offers.status (db/install.xml).
     *
     * Not part of the architecture doc's original 2-method sketch - added because the
     * repository layer needs a stable way to serialise/deserialise a state to/from the DB
     * column. Values match the mapping fixed in the DB-Schema step (0=pending, 1=offered,
     * 2=accepted, 3=declined, 4=expired, 5=skipped, 6=autobooked).
     *
     * @return int
     */
    public function get_code(): int;
}
