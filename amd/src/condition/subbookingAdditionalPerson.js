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
 * @module     mod_booking/condition/subbookingAdditionalPerson
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {initbookitbutton} from 'mod_booking/bookit';
import {eventTypes} from 'core_filters/events';

const SELECTOR = {
    FORMCONTAINER: '.subbooking-additionalperson-form',
    MODALBODY: '.prepage-body',
    CONTINUECONTAINER: ' div.prepage-booking-footer .continue-container',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    BOOKINGBUTTON: '[data-area="subbooking"][data-userid][data-itemid="',
};

/**
 * Init function.
 * @param {boolean} shoppingcartisinstalled
 */
export async function init(shoppingcartisinstalled) {

    // eslint-disable-next-line no-console
    console.log('init dynamic form');

    const containers = document.querySelectorAll(SELECTOR.FORMCONTAINER);

    containers.forEach(async container => {
        const id = container.dataset.id;

        const continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);
        const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\subbooking\\additionalperson_form');

        // We need to render the dynamic form right away, so we can acutally have all the necessary elements present.
        await dynamicForm.load({id: id});

        const bookitbutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.BOOKINGBUTTON + id);

        // eslint-disable-next-line no-console
        console.log(bookitbutton, continuebutton);

        dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, async e => {
            const response = e.detail;

            if (response) {

                await dynamicForm.load({id: id});

                unblockButtons(id, container);
            }
        });

        document.addEventListener(eventTypes.filterContentUpdated, e => {
            // eslint-disable-next-line no-console
            console.log(e.target);

            initButtons(id, container, dynamicForm, shoppingcartisinstalled);
        });

        dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, () => {

            // eslint-disable-next-line no-console
            console.log('error with form');

            initButtons(id, container, dynamicForm, shoppingcartisinstalled);
        });

        dynamicForm.addEventListener('change', e => {

            if (e.target.classList.contains('custom-select')) {
                const button = document.querySelector('.subbooking-additionalperson-form [data-no-submit="1"]');
                dynamicForm.processNoSubmitButton(button);
            }
        });

        initButtons(id, container, dynamicForm, shoppingcartisinstalled);
    });
}

/**
 * @param {integer} id
 * @param {HTMLElement} container
 * @param {*} dynamicForm
 * @param {boolean} shoppingcartisinstalled
 */
function initButtons(id, container, dynamicForm, shoppingcartisinstalled) {

    // We always need to get the buttons anew, as they might have been replaced.

    const prepagemodal = container.closest(SELECTOR.MODALBODY);

    if (!prepagemodal) {
        return;
    }

    const bookitbutton = prepagemodal.querySelector(SELECTOR.BOOKINGBUTTON + id);

    // This goes on the bookit button as well as on the shopping cart.
    // It will prevent the action to be triggered.
    // Unless the form is validated (see above).
    if (bookitbutton) {

        // eslint-disable-next-line no-console
        console.log('bookitbutton', bookitbutton);

        blockButton(bookitbutton, dynamicForm);
    }

    // Only after the Form is loaded, we reinitialze the buttons.
    try {
        // Avoid any errors/warnings if no shopping_cart installed.
        if (shoppingcartisinstalled) {
            import('local_shopping_cart/cart')
                    // eslint-disable-next-line promise/always-return
                    .then(shoppingcart => {
                        shoppingcart.buttoninit();
                    })
                    .catch(err => {
                        // Handle any errors, including if the module doesn't exist
                        // eslint-disable-next-line no-console
                        console.log(err);
                });
            }
        initbookitbutton();
    } catch (e) {
        // eslint-disable-next-line no-console
        console.log(e);
    }
}

/**
 *
 * @param {HTMLElement} button
 * @param {*} dynamicForm
 */
function blockButton(button, dynamicForm) {

    // eslint-disable-next-line no-console
    console.log('blockButton', button);

    if (!button.dataset.blocked) {
        button.dataset.blocked = true;

         // eslint-disable-next-line no-console
        console.log('blockButton add listener', button);

        button.addEventListener('click', () => {

            // eslint-disable-next-line no-console
            console.log('click', button.dataset.blocked, button.dataset.blocked == true);

            if (button.dataset.blocked === 'true') {
                dynamicForm.submitFormAjax();
            }
        });
    }
}

/**
 *
 * @param {integer} id
 * @param {HTMLElement} container
 */
function unblockButtons(id, container) {

    // We always need to get the buttons anew, as they might have been replaced.

    const bookitbutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.BOOKINGBUTTON + id);

    if (bookitbutton && bookitbutton.dataset.blocked === 'true') {
        bookitbutton.dataset.blocked = 'false';
        bookitbutton.click();
    }
}

