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
 * Per-slot cancel: releases one booked slot from the "Booked slots" list.
 *
 * Each cancelable row of the booked-slots list (option detail page and options table, see
 * bookingoption_description_bookedslots.mustache and col_showdates) carries a delete button
 * addressing exactly one slot in one booking answer. On confirm the slot is released through the
 * mod_booking_release_slots webservice (which routes through slot_update_service: the released
 * slots are refunded as cart credit, and releasing the last slot of an unpurchased answer cancels
 * the whole answer through the standard deletion path), then the page reloads -
 *
 * Whether the buttons exist at all is decided server-side
 * (slot_mover::per_slot_release_available()); this module only wires the ones that were rendered.
 *
 * @module     mod_booking/slotbooking/slot_release
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    TRIGGER: '[data-action="booking-slot-release"]',
};

let delegated = false;

/**
 * Release one slot and reload on success.
 *
 * @param {HTMLButtonElement} trigger the clicked release button
 * @return {Promise<void>}
 */
const releaseSlot = async(trigger) => {
    trigger.disabled = true;
    try {
        await Ajax.call([{
            methodname: 'mod_booking_release_slots',
            args: {
                optionid: Number(trigger.dataset.optionid),
                baid: Number(trigger.dataset.baid),
                releaseslots: JSON.stringify([String(trigger.dataset.slotkey)]),
                reason: '',
            },
        }])[0];
        window.location.reload();
    } catch (error) {
        trigger.disabled = false;
        // Every failure this webservice raises is a user-facing policy sentence: the change
        // deadline has passed, cancelling is switched off, or this is the last slot of a purchased
        // booking. Notification.exception presents those as a developer dialog - error code as the
        // title, stack trace below. An alert shows just the sentence the user has to act on.
        // The buttons for the known cases are already withheld server-side (slot_dto), so this
        // path only remains for a stale page or a direct call.
        const errortitle = await getString('slot_release_error_title', 'mod_booking');
        Notification.alert(errortitle, error.message || String(error));
    }
};

/**
 * Bind one document-level delegated click handler for every release button on the page.
 *
 * Delegated so it survives the options table re-rendering rows, and guarded so the repeated
 * init calls (one per table row / description) bind it only once.
 */
export const init = () => {
    if (delegated) {
        return;
    }
    delegated = true;

    document.addEventListener('click', async(e) => {
        const trigger = e.target.closest(SELECTORS.TRIGGER);
        if (!trigger) {
            return;
        }
        e.preventDefault();

        const label = String(trigger.dataset.slotlabel || '');
        const [title, body, confirmlabel] = await Promise.all([
            getString('slot_release_confirm_title', 'mod_booking'),
            getString('slot_release_confirm_body', 'mod_booking', label),
            getString('slot_release_action', 'mod_booking'),
        ]);

        await Notification.saveCancelPromise(title, body, confirmlabel)
            .then(() => releaseSlot(trigger))
            // Dismissing the confirm dialog rejects the promise; that is a normal outcome.
            .catch(() => null);
    });
};
