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
 * @module     mod_booking/prepageFooter
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


import {continueToNextPage, backToPreviousPage} from 'mod_booking/bookit';
import {reloadAllTables} from 'local_wunderbyte_table/reload';

var SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INMODALDIV: ' div.modalMainContent',
    INMODALFOOTER: ' div.prepage-booking-footer',
    INMODALBUTTON: 'div.in-modal-button',
    BOOKITBUTTON: 'div.booking-button-area',
    STATICBACKDROP: 'div.modal-backdrop',
};

const WAITTIME = 1500;

/**
 * Add the click listener to a prepage modal button.
 * @param {integer} optionid
 * @param {integer} userid
 */
export function initFooterButtons(optionid, userid) {

    initBookingButton(optionid);

    // First, get all link elements in the footer.
    const elements = document.querySelectorAll("[id^=" + SELECTORS.MODALID + optionid + "] " + SELECTORS.INMODALFOOTER + " a");

    // eslint-disable-next-line no-console
    console.log('elements', elements);

    elements.forEach(element => {
        if (element && !element.dataset.initialized) {

            // Make sure we dont initialize this twice.
            element.dataset.initialized = true;

            element.addEventListener('click', () => {

                if (element.classList.contains('hidden')) {
                    return;
                }

                // The logic might be blocked because eg a form is there to prevent it.
                if (element.dataset.blocked == 'true') {
                    return;
                }

                const action = element.dataset.action;

                switch (action) {
                    case 'back':
                        backToPreviousPage(optionid, userid);
                    break;
                    case 'continue':
                        continueToNextPage(optionid, userid);
                    break;
                    case 'checkout':
                        closeModal(optionid);
                        window.location.href = element.dataset.href;
                    break;
                    case 'closemodal':
                        closeModal(optionid);
                    break;
                }
            });
        }
    });
}

/**
 *
 * @param {int} optionid
 */
async function initBookingButton(optionid) {

    // eslint-disable-next-line no-console
    console.log('initBookingButton');

    // First, we get the right modal.
    let modal = document.querySelector("div.modal.show[id^=" + SELECTORS.MODALID + optionid + "]");

    if (!modal) {
        return;
    }

    modal.addEventListener('click', (e) => {

        // eslint-disable-next-line no-console
        console.log('click on modal', e.target);

        let button = e.target;

        if (button) {

            const parentElement = e.target.closest(SELECTORS.BOOKITBUTTON);
            button = e.target.closest('.btn');

            // eslint-disable-next-line no-console
            console.log(button, parentElement);

            if (parentElement && button) {
                // eslint-disable-next-line no-console
                console.log('initBookingButton click');

                if ((parentElement.dataset.action == 'noforward')) {
                    return;
                }

                // We don't continue right away but wait for a second.
                setTimeout(() => {
                    continueToNextPage(optionid);
                }, WAITTIME);
            }
        }
    });
}

/**
 *
 * @param {int} optionid
 */
function closeModal(optionid) {
    const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);
    const modal = document.querySelector("div.modal.show[id^=" + SELECTORS.MODALID + optionid + "]");
    if (modal) {
        modal.classList.remove('show');
    }
    if (backdrop) {
        backdrop.remove();
        reloadAllTables();
    }
}