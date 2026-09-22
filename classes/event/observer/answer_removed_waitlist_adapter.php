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
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event\observer;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\skipped;

/**
 * Closes a user's open waitlist offer when they leave the waiting list.
 */
final class answer_removed_waitlist_adapter {
    /**
     * Skips the user's open offer for this option, if one exists. Unlike decline() this writes no
     * K7 lock: the person only left, they did not refuse, so a later re-join is a fresh start.
     * A no-op if the user has no open offer.
     *
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    public static function skip(int $optionid, int $userid): void {
        $repository = new db_waitlist_offer_repository();
        foreach ($repository->get_open_offers($optionid) as $offer) {
            if ($offer->userid === $userid) {
                $repository->transition($offer, new skipped());
            }
        }
    }
}
