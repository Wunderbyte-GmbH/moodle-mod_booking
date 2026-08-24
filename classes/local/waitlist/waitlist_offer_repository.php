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
 * Repository interface for waitlist-offer data access (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §3.2). The reconciler (progression, coming later) contains no SQL at all - it only talks to
 * this interface, which makes it mockable for unit tests and keeps "how is sorted/filtered" in
 * exactly one place, unlike today's code where O1-O4 are spread across three different files.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Data-access contract for booking_waitlist_offers/booking_waitlist_declines.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface waitlist_offer_repository {
    /**
     * All currently open (non-terminal) offers for an option.
     *
     * @param int $optionid
     * @return waitlist_offer[]
     */
    public function get_open_offers(int $optionid): array;

    /**
     * The waiting-list candidates who have no offer/decision at all yet for the CURRENT round,
     * excluding the given user ids (K7: the permanently declined). Ordered sortorder ASC, id
     * ASC (O1/O2).
     *
     * @param int $optionid
     * @param int[] $excludeuserids
     * @return array
     */
    public function get_unbehandelte_waitinglist(int $optionid, array $excludeuserids): array;

    /**
     * Creates a new offer/decision row.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $roundid
     * @param int $sortorder
     * @param offer_status $status
     * @param int $expiresat 0 = no deadline (e.g. autobooked); real deadline for a genuine offer.
     * @param int $ruleid
     * @return waitlist_offer
     */
    public function create_offer(
        int $optionid,
        int $userid,
        int $roundid,
        int $sortorder,
        offer_status $status,
        int $expiresat = 0,
        int $ruleid = 0
    ): waitlist_offer;

    /**
     * Transitions an existing offer to a new status. Must throw if $newstatus is not reachable
     * from the offer's current status (offer_status::can_transition_to()).
     *
     * @param waitlist_offer $offer
     * @param offer_status $newstatus
     * @return void
     */
    public function transition(waitlist_offer $offer, offer_status $newstatus): void;

    /**
     * Whether a user is locked out of offers for this option - either K7 (an active decline,
     * always permanent) or K4 (an expired offer, permanent unless the option has waitlistrecycling
     * enabled, in which case waitlist_heartbeat_task periodically resets these via
     * reset_expired_locks()).
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_permanently_declined(int $optionid, int $userid): bool;


    /**
     * All user ids currently locked out of offers for this option - see is_permanently_declined().
     *
     * @param int $optionid
     * @return int[]
     */
    public function get_permanently_declined_userids(int $optionid): array;

    /**
     * Whether a user is still actually on the waiting list right now - a live re-check, since
     * get_unbehandelte_waitinglist() returns a snapshot that can go stale mid-reconcile() (K8).
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_still_on_waitinglist(int $optionid, int $userid): bool;

    /**
     * Loads a single offer by id.
     *
     * @param int $id
     * @return waitlist_offer|null null if no such offer exists (anymore).
     */
    public function get_offer_by_id(int $id): ?waitlist_offer;


    /**
     * Finds options that are genuinely "stalled" (T7, WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
     * §4.2): have at least one waiting-list answer, no open offer yet, AND real free capacity -
     * a narrowly-scoped query on purpose (a lesson from an earlier load-test experience), not
     * "every option with any waiting list at all".
     *
     * @return int[] option ids
     */
    public function find_stalled_options(): array;

    /**
     * Waitlist-recycling: removes the K4 (expired) lock for every user currently locked out on
     * this option, so they become offerable again on the next reconcile() - but only the
     * expiry-locks (reason=4). A K7 lock (reason=3, an active decline) is never touched by this -
     * that lock is permanent regardless of waitlistrecycling.
     *
     * @param int $optionid
     * @return void
     */
    public function reset_expired_locks(int $optionid): void;

    /**
     * Finds options where waitlistrecycling is enabled AND the waiting list is currently fully
     * flagged - at least one person is still waiting, nobody has an open (pending/offered) offer,
     * and everyone still waiting is locked out (declined or expired). waitlist_heartbeat_task
     * calls reset_expired_locks() on each of these, then reconcile()s them.
     *
     * @return int[] option ids
     */
    public function find_recyclable_options(): array;
}
