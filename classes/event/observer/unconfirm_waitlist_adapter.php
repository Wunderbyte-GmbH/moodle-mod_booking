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
 * Trigger adapter (Phase 3, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.3, T4): a manual
 * unconfirm on the waiting list permanently locks that user out of further offers for this
 * option (K7), exactly like an active decline. Must run BEFORE the option's generic
 * check_if_free_to_book_again()-driven reconcile() (freetobookagain_waitlist_adapter) is
 * triggered for the same event, so the reconciler never re-considers this user.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event\observer;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\declined;

/**
 * Declines a user's open waitlist offer on manual unconfirm.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unconfirm_waitlist_adapter {
    /**
     * Declines the user's open offer for this option, if one exists. A no-op if the user has no
     * open offer under the new mechanism (e.g. they were only ever offered via the old chain).
     *
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    public static function decline(int $optionid, int $userid): void {
        $repository = new db_waitlist_offer_repository();
        foreach ($repository->get_open_offers($optionid) as $offer) {
            if ($offer->userid === $userid) {
                $repository->transition($offer, new declined());
            }
        }
    }
}
