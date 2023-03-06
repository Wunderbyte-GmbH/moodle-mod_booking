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
 * @module     mod_booking/subbookingAdditionalPerson
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {initbookitbutton} from 'mod_booking/bookit';
import {buttoninit} from 'local_shopping_cart/cart';
import {eventTypes} from 'core_filters/events';

const SELECTOR = {
    MODALID: 'sbPrePageModal_',
    FORMCONTAINER: '.subbooking-additionalperson-form',
    MODALBODY: '.modal-body',
    CONTINUECONTAINER: ' div.prepage-booking-footer .continue-container',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    BOOKINGBUTTON: '[data-area="subbooking"][data-userid][data-itemid="',
};

/**
 * Init function.
 */
export async function init() {

    // eslint-disable-next-line no-console
    console.log('init dynamic form');

    const container = document.querySelector(SELECTOR.FORMCONTAINER);

    const id = container.dataset.id;

    const continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);
    const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\subbooking\\additionalperson_form');

    // We need to render the dynamic form right away, so we can acutally have all the necessary elements present.
    await dynamicForm.load({id: id});

    const bookitbutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.BOOKINGBUTTON + id);

    // eslint-disable-next-line no-console
    console.log(bookitbutton, continuebutton);

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {
        const response = e.detail;

        if (response) {

            unblockButtons(id, container);

            dynamicForm.load({id: id});
        }
    });

    document.addEventListener(eventTypes.filterContentUpdated, e => {
        // eslint-disable-next-line no-console
        console.log(e.target);

        initButtons(id, container, dynamicForm);
    });

    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, () => {

        // eslint-disable-next-line no-console
        console.log('error with form');

        initButtons(id, container, dynamicForm);
    });

    dynamicForm.addEventListener('change', e => {

        if (e.target.classList.contains('custom-select')) {
            const button = document.querySelector('.subbooking-additionalperson-form [data-no-submit="1"]');
            dynamicForm.processNoSubmitButton(button);
        }
    });

    initButtons(id, container, dynamicForm);
}

/**
 * @param {integer} id
 * @param {HTMLElement} container
 * @param {*} dynamicForm
 */
function initButtons(id, container, dynamicForm) {

    // We always need to get the buttons anew, as they might have been replaced.

    const bookitbutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.BOOKINGBUTTON + id);
    const continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);

    // This goes on continue button.
    // It will prevent the action to be triggered.
    // Unless the form is validated (see above).
    if (continuebutton) {

        // eslint-disable-next-line no-console
        console.log('continuebutton', continuebutton);

        blockButton(continuebutton, dynamicForm);
    }

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
        buttoninit();
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
            console.log('click');

            dynamicForm.submitFormAjax();
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
    const continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);

    if (bookitbutton) {
        bookitbutton.dataset.blocked = 'false';
        bookitbutton.click();
    }

    if (continuebutton) {
        continuebutton.dataset.blocked = 'false';
        if (!bookitbutton) {
            continuebutton.click();
        }
    }
}

