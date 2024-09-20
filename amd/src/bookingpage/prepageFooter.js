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
 * @module     mod_booking/bookingpage/prepageFooter
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import jQuery from 'jquery';
import {continueToNextPage, backToPreviousPage, setBackModalVariables} from 'mod_booking/bookit';
import {reloadAllTables} from 'local_wunderbyte_table/reload';

var SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INLINEID: 'sbPrePageInline_',
    INMODALDIV: ' div.modalMainContent',
    INMODALFOOTER: ' div.prepage-booking-footer',
    INMODALBUTTON: 'div.in-modal-button',
    BOOKITBUTTON: 'div.booking-button-area',
    STATICBACKDROP: 'div.modal-backdrop',
};

/* Const WAITTIME = 1500;*/

/**
 * Add the click listener to a prepage modal button.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {boolean} shoppingcartisinstalled
 */
export function initFooterButtons(optionid, userid, shoppingcartisinstalled) {

    // eslint-disable-next-line no-console
    console.log('initFooterButtons', optionid);

    // We need to find out if we are in modal or inline mode.
     // First, get all link elements in the footer.
     let elements = document.querySelectorAll('[id^="' + SELECTORS.INLINEID + optionid + '_"] ' + SELECTORS.INMODALFOOTER + " a");

     if (elements.length === 0) {
         elements = document.querySelectorAll('[id^="' + SELECTORS.MODALID + optionid + '_"] ' + SELECTORS.INMODALFOOTER + " a");

         // Everytime we close the modal, we want to reset to the first prepage.
        jQuery.each(jQuery('[id^="' + SELECTORS.MODALID + optionid + '_"]'), function() {
            jQuery(this).on("hide.bs.modal", function() {
                setBackModalVariables(optionid);
            });
        });
     }

    // This would be linked to automatic forwarding, which we don't use at the moment.
    // initBookingButton(optionid);

    // eslint-disable-next-line no-console
    console.log('buttons found', elements);

    elements.forEach(async element => {
        if (element && !element.dataset.initialized) {

            // Make sure we dont initialize this twice.
            element.dataset.initialized = true;

            const action = element.dataset.action;

            // eslint-disable-next-line no-console
            console.log(element, action);

            // We might to execute some actions right away.

            switch (action) {
                // If we find the checkout button, we reload shopping cart.
                case 'closeinline':
                case 'continuepost':
                case 'checkout':
                    // eslint-disable-next-line no-console
                    console.log('closeinline', action);
                    if (shoppingcartisinstalled) {
                        import('local_shopping_cart/cart')
                        .then(cart => {
                            // eslint-disable-next-line no-console
                            console.log(cart);

                            const oncashier = window.location.href.indexOf("cashier.php");
                            // If we are not on cashier, we can just redirect.
                            if (oncashier > 0) {
                                cart.reinit(-1);
                            } else {
                                cart.reinit();
                            }
                            return;
                        })
                        .catch(() => {
                            // eslint-disable-next-line no-console
                            console.log('local_shopping_cart/cart could not be loaded');
                        });
                    }
                    listenToCloseInline();
                break;
            }

            // Depending on the action button, we add the right listener.
            element.addEventListener('click', () => {

                if (element.classList.contains('hidden')) {
                    return;
                }

                // The logic might be blocked because eg a form is there to prevent it.
                if (element.dataset.blocked == 'true') {
                    return;
                }

                switch (action) {
                    case 'back':
                        backToPreviousPage(optionid, userid);
                    break;
                    case 'continue':
                    case 'continuepost':
                        continueToNextPage(optionid, userid);
                    break;
                    case 'checkout':
                        closeModal(optionid);
                        window.location.href = element.dataset.href;
                    break;
                    case 'closemodal':
                        reloadOnBookingView();
                        closeModal(optionid);
                    break;
                    case 'closeinline':
                        reloadOnBookingView();
                        closeInline(optionid);

                }
            });
        }
    });
}

// /**
//  *
//  * @param {int} optionid
//  */
// async function initBookingButton(optionid) {

//     // First, we get the right modal.
//     let modal = document.querySelector('div.modal.show[id^="' + SELECTORS.MODALID + optionid + '_"]');

//     if (!modal) {

//         // First, we get the right modal.
//         const modals = document.querySelectorAll('div.inlineprepagearea [id^="' + SELECTORS.INLINEID + optionid + '_"]');

//         modals.forEach(el => {
//             if (!isHidden(el)) {
//                 modal = el;
//             }
//         });
//         if (!modal) {
//             return;
//         }
//     }

//     modal.addEventListener('click', (e) => {

//         let button = e.target;

//         if (button) {

//             const bookingButtonArea = e.target.closest(SELECTORS.BOOKITBUTTON);

//             button = e.target.closest('.btn');

//             if (bookingButtonArea && button) {

//                 if ((bookingButtonArea.dataset.action == 'noforward')) {
//                     return;
//                 }

//                 // There are several bugs caused by automatic forwarding, so we comment it out for now.
//                 // We don't continue right away but wait for a second.
//                 /* setTimeout(() => {
//                     continueToNextPage(optionid);
//                 }, WAITTIME);*/
//             }
//         }
//     });
// }

/**
 *
 * @param {int} optionid
 * @param {bool} reloadTables
 */
export function closeModal(optionid, reloadTables = true) {
    jQuery.each(jQuery('[id^="' + SELECTORS.MODALID + optionid + '_"]'), async function() {

        // We don't have a good way to check if the modal is ready to execute hide.
        // So first we attach a listener to this modal to close it after show.

        // It might be that the modal in question is not shown yet.
        jQuery(this).on('shown.bs.modal', e => {

            jQuery(e.currentTarget).off('shown.bs.modal');
            // eslint-disable-next-line no-console
            console.log('modal hide after shown', e);

            jQuery(this).modal('hide');

            if (reloadTables) {
                reloadAllTables();
            }
        });

        // Now we run hide anyways, just to be sure.
        jQuery(this).modal('hide');

        if (reloadTables) {
            reloadAllTables();
        }
    });
}

/**
 *
 * @param {int} optionid
 * @param {bool} reloadTables
 */
export function closeInline(optionid, reloadTables = true) {
    jQuery.each(jQuery('[id^="' + SELECTORS.INLINEID + optionid + '_"]'), function() {

        // We don't have a good way to check if the modal is ready to execute hide.
        // So first we attach a listener to this modal to close it after show.

        // It might be that the modal in question is not shown yet.
        jQuery(this).on('shown.bs.collapse', e => {

            jQuery(e.currentTarget).off('shown.bs.collapse');
            // eslint-disable-next-line no-console
            console.log('collapse hide after shown', e);

            jQuery(this).collapse('toggle');

            if (reloadTables) {
                reloadAllTables();
            }
        });

        // Now we run hide anyways, just to be sure.
        jQuery(this).collapse('toggle');

        if (reloadTables) {
            reloadAllTables();
        }
    });
}

/**
 *
 * @param {int} optionid
 */
function listenToCloseInline(optionid) {

    jQuery.each(jQuery('[id^="' + SELECTORS.INLINEID + optionid + '_"]'), function() {

        jQuery(this).on('hide.bs.collapse', function() {

            reloadAllTables();
        });
    });
}

/**
 * Reload on booking view
 *
 */
function reloadOnBookingView() {
    const onbookondetail = window.location.href.indexOf("optionview.php");

    if (onbookondetail >= 0) {
        window.location.reload();
    }
}

// /**
//  * Function to check visibility of element.
//  * @param {*} el
//  * @returns {boolean}
//  */
// function isHidden(el) {
//     var style = window.getComputedStyle(el);
//     return ((style.display === 'none') || (style.visibility === 'hidden'));
// }
