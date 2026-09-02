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
 * Trigger adapter (Phase 3, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.3, T7): a waiting-list
 * user who completes their booking (payment/confirmation) transitions their open offer to
 * accepted. A no-op if the user has no open offer under the new mechanism (e.g. autobook -
 * progression::autobook() calls user_submit_response() BEFORE creating its own offer row, so
 * there is nothing to find here).
 *
 * Deliberately does NOT call reconcile() itself (unlike the other three adapters) - accepting an
 * offer never frees new capacity, so it would never find anything new to do anyway, and doing so
 * is actively dangerous: this adapter fires from INSIDE user_submit_response(), which is exactly
 * what progression::autobook() calls - a reconcile() here would re-enter the very reconcile()
 * call that is already in progress, autobooking further candidates that re-trigger this same
 * event, and so on (found via a real memory-exhaustion crash during testing, not a theoretical
 * concern).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event\observer;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\accepted;

/**
 * Accepts a user's open waitlist offer once their booking completes.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_accepted_waitlist_adapter {
    /**
     * Accepts the user's open offer for this option, if one exists.
     *
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    public static function accept(int $optionid, int $userid): void {
        $repository = new db_waitlist_offer_repository();
        foreach ($repository->get_open_offers($optionid) as $offer) {
            if ($offer->userid === $userid) {
                $repository->transition($offer, new accepted());
            }
        }
    }
}
