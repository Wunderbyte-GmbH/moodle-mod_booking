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
 * Delete/cancel confirmation modal to delete a booking option.
 *
 * Replaces the old action=deletebookingoption URL flow on report.php: the
 * confirmation runs in a modal and the deletion itself through the webservice
 * mod_booking_delete_booking_option.
 *
 * @module     mod_booking/deletebookingoptionmodal
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Config from 'core/config';
import {deleteCancelPromise, exception as displayException} from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    TRIGGER: '[data-action="booking-deletebookingoption-modal"]',
    NAVITEM: 'a[data-key="nav_deletebookingoption"], [data-key="nav_deletebookingoption"] a',
};

export const init = () => {

    const container = document.querySelector('body');
    if (!container) {
        return;
    }

    // Add one event listener only once.
    if (!container.dataset.deletebookingoptionDelegated) {
        container.dataset.deletebookingoptionDelegated = 'true';

        container.addEventListener('click', (e) => {
            const trigger = e.target.closest(SELECTORS.TRIGGER);
            if (!trigger) {
                return;
            }
            e.preventDefault();
            deleteBookingOption(
                parseInt(trigger.dataset.cmid, 10),
                parseInt(trigger.dataset.optionid, 10),
                trigger.dataset.titlewithbooked || '',
                trigger.dataset.returnurl || '',
                trigger
            );
        });
    }
};

/**
 * Turn the "Delete this booking option" node of the settings navigation into a
 * trigger of the delete confirmation modal. The href of the node stays as a
 * fallback in case JS is not available.
 *
 * @param {number} cmid course module id of the booking instance
 * @param {number} optionid id of the booking option to delete
 * @param {string} titlewithbooked option title, incl. the number of booked users
 * @param {string} returnurl URL to redirect to after successful deletion
 */
export const initNavItem = (cmid, optionid, titlewithbooked, returnurl) => {
    init();
    document.querySelectorAll(SELECTORS.NAVITEM).forEach((navitem) => {
        navitem.dataset.action = 'booking-deletebookingoption-modal';
        navitem.dataset.cmid = String(cmid);
        navitem.dataset.optionid = String(optionid);
        navitem.dataset.titlewithbooked = titlewithbooked;
        navitem.dataset.returnurl = returnurl;
    });
};

/**
 * Show the delete confirmation modal and delete the booking option on confirm.
 *
 * @param {number} cmid course module id of the booking instance
 * @param {number} optionid id of the booking option to delete
 * @param {string} titlewithbooked option title, incl. the number of booked users
 * @param {string} returnurl URL to redirect to after successful deletion; empty
 *                           to reload the current page (shortcode pages etc.)
 * @param {HTMLElement|null} triggerElement element receiving the focus after the modal closes
 */
export const deleteBookingOption = async(cmid, optionid, titlewithbooked, returnurl, triggerElement = null) => {
    try {
        await deleteCancelPromise(
            getString('deletethisbookingoption', 'mod_booking'),
            getString('confirmdeletebookingoption', 'mod_booking', titlewithbooked),
            getString('delete', 'core'),
            {triggerElement}
        );
    } catch (e) {
        // The modal was cancelled.
        return;
    }
    try {
        const result = await Ajax.call([{
            methodname: 'mod_booking_delete_booking_option',
            args: {cmid, optionid},
        }])[0];
        if (result.success) {
            if (returnurl) {
                const target = new URL(returnurl, window.location.origin);
                if (target.origin === window.location.origin
                    && target.pathname === window.location.pathname
                    && target.search === window.location.search) {
                    // Setting location.href to the current URL would not trigger a
                    // fresh page load when the current URL carries a fragment (e.g.
                    // the "#" of the clicked dropdown link), so reload explicitly.
                    window.location.reload();
                } else {
                    window.location.href = returnurl;
                }
                return;
            }
            // No returnurl: stay on the current page and reload it, so deleting e.g.
            // from a shortcode page listing options of several instances returns to
            // that page. If the current URL is scoped to the deleted option, a reload
            // would request the deleted option again - escape to the instance view.
            if (new URL(window.location.href).searchParams.get('optionid') === String(optionid)) {
                window.location.href = Config.wwwroot + '/mod/booking/view.php?id=' + cmid;
            } else {
                window.location.reload();
            }
        }
    } catch (err) {
        displayException(err);
    }
};
