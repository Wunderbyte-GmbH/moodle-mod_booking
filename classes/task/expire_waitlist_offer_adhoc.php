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
 * K4 hard expiry (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.1): one task per offer,
 * scheduled with nextruntime = expiresat at offer-creation time (progression::offer()) - not a
 * chain repeat-task like today's send_mail_interval. Idempotent (K5): a no-op if the offer has
 * already left the "offered" state (accepted/declined/already expired) by the time this runs.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\progression_factory;

/**
 * Expires one waitlist offer and immediately re-reconciles its option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_waitlist_offer_adhoc extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return \lang_string|string
     */
    public function get_name() {
        return get_string('taskexpirewaitlistofferadhoc', 'mod_booking');
    }

    /**
     * Expires the offer (K4) and immediately re-reconciles the option, so the freed-up capacity
     * is offered to the next candidate right away (K1), not on the next unrelated trigger.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $offerid = (int) $data->offerid;

        $repository = new db_waitlist_offer_repository();
        $offer = $repository->get_offer_by_id($offerid);
        if ($offer === null) {
            return;
        }
        if (!($offer->status instanceof offered)) {
            // K5: already accepted/declined/expired by something else - idempotent no-op.
            return;
        }

        $repository->transition($offer, new expired());
        progression_factory::get()->reconcile($offer->optionid, 'offer:expired');
    }
}
