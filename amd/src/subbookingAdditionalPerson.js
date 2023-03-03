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

const SELECTOR = {
    MODALID: 'sbPrePageModal_',
    FORMCONTAINER: '.subbooking-additionalperson-form',
    MODALBODY: '.modal-body',
    CONTINUECONTAINER: ' div.prepage-booking-footer .continue-container',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    BOOKINGBUTTON: '[data-area="subbooking"][data-itemid="',
};

/**
 * Init function.
 */
export async function init() {

    const container = document.querySelector(SELECTOR.FORMCONTAINER);

    const id = container.dataset.id;

    const continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);

    const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\subbooking\\additionalperson_form');

    // We need to render the dynamic form right away, so we can acutally have all the necessary elements present.
    await dynamicForm.load({id: id});

    const bookitbutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.BOOKINGBUTTON + id);

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {
        const response = e.detail;

        if (response) {

            if (bookitbutton) {
                bookitbutton.dataset.blocked = 'false';
                bookitbutton.click();
            } else {
                continuebutton.dataset.blocked = 'false';
                continuebutton.click();
            }
            dynamicForm.load({id: id});
        }
    });

    dynamicForm.addEventListener('change', e => {

        if (e.target.classList.contains('custom-select')) {
            const button = document.querySelector('.subbooking-additionalperson-form [data-no-submit="1"]');
            dynamicForm.processNoSubmitButton(button);
        }
    });

    // This goes on continue button.
    // It will prevent the action to be triggered.
    // Unless the form is validated (see above).
    if (continuebutton) {
        continuebutton.dataset.blocked = true;
        continuebutton.addEventListener('click', () => {
            dynamicForm.submitFormAjax();
        });
    }

    // This goes on the bookit button as well as on the shopping cart.
    // It will prevent the action to be triggered.
    // Unless the form is validated (see above).
    if (bookitbutton) {
        bookitbutton.dataset.blocked = true;
        bookitbutton.addEventListener('click', () => {
            dynamicForm.submitFormAjax();
        });
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