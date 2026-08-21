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
 * Test double for A4/K8 (a4_k8_skip_test.php): a repository decorator that pretends one specific
 * user has already left the waiting list, while delegating everything else (including the
 * round's initial snapshot) to the real repository - simulating a K8 mid-round departure. Exists
 * as its own file because there is no way to make a real user leave the list from a separate
 * thread mid-call in a single PHPUnit process, so this uses the repository interface exactly as
 * intended (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.2: "makes it mockable for unit
 * tests").
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Delegates every method to a real repository, except is_still_on_waitinglist() for one chosen
 * user id, which always answers "no" regardless of the real DB state.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class a4_leaves_mid_round_repository implements waitlist_offer_repository {
    /** @var waitlist_offer_repository */
    private $inner;

    /** @var int */
    private $leavinguserid;

    /**
     * Constructs the decorator.
     *
     * @param waitlist_offer_repository $inner
     * @param int $leavinguserid
     */
    public function __construct(waitlist_offer_repository $inner, int $leavinguserid) {
        $this->inner = $inner;
        $this->leavinguserid = $leavinguserid;
    }

    /**
     * Delegates unchanged.
     *
     * @param int $optionid
     * @return waitlist_offer[]
     */
    public function get_open_offers(int $optionid): array {
        return $this->inner->get_open_offers($optionid);
    }

    /**
     * Delegates unchanged - this is the round's initial snapshot, taken BEFORE the simulated
     * departure, exactly like the real stale-snapshot scenario K8 guards against.
     *
     * @param int $optionid
     * @param int[] $excludeuserids
     * @return array
     */
    public function get_unbehandelte_waitinglist(int $optionid, array $excludeuserids): array {
        return $this->inner->get_unbehandelte_waitinglist($optionid, $excludeuserids);
    }

    /**
     * Delegates unchanged.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $roundid
     * @param int $sortorder
     * @param offer_status $status
     * @param int $expiresat
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
    ): waitlist_offer {
        return $this->inner->create_offer($optionid, $userid, $roundid, $sortorder, $status, $expiresat, $ruleid);
    }

    /**
     * Delegates unchanged.
     *
     * @param waitlist_offer $offer
     * @param offer_status $newstatus
     * @return void
     */
    public function transition(waitlist_offer $offer, offer_status $newstatus): void {
        $this->inner->transition($offer, $newstatus);
    }

    /**
     * Delegates unchanged.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_permanently_declined(int $optionid, int $userid): bool {
        return $this->inner->is_permanently_declined($optionid, $userid);
    }

    /**
     * Delegates unchanged.
     *
     * @param int $optionid
     * @return int[]
     */
    public function get_permanently_declined_userids(int $optionid): array {
        return $this->inner->get_permanently_declined_userids($optionid);
    }

    /**
     * Always false for the chosen leaving user id - simulates their mid-round departure.
     * Delegates unchanged for everyone else.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    public function is_still_on_waitinglist(int $optionid, int $userid): bool {
        if ($userid === $this->leavinguserid) {
            return false;
        }
        return $this->inner->is_still_on_waitinglist($optionid, $userid);
    }

    /**
     * Delegates unchanged.
     *
     * @param int $id
     * @return waitlist_offer|null
     */
    public function get_offer_by_id(int $id): ?waitlist_offer {
        return $this->inner->get_offer_by_id($id);
    }

    /**
     * Delegates unchanged.
     *
     * @return int[]
     */
    public function find_stalled_options(): array {
        return $this->inner->find_stalled_options();
    }

    /**
     * Delegates unchanged.
     *
     * @param int $optionid
     * @return void
     */
    public function reset_expired_locks(int $optionid): void {
        $this->inner->reset_expired_locks($optionid);
    }

    /**
     * Delegates unchanged.
     *
     * @return int[]
     */
    public function find_recyclable_options(): array {
        return $this->inner->find_recyclable_options();
    }
}
