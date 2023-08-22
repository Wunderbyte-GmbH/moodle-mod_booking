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
 * @module     mod_booking/condition/bookingPolicy
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';

const SELECTOR = {
    FORMCONTAINER: '.condition-customform',
    MODALBODY: '.modal-body',
    CONTINUECONTAINER: ' div.prepage-booking-footer .continue-container',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    BOOKINGBUTTON: '[data-area="subbooking"][data-itemid="',
};

/**
 * Init function.
 */
export async function init() {

    const container = document.querySelector("div.modal.show " + SELECTOR.FORMCONTAINER);

    if (!container) {
        return;
    }

    const id = container.dataset.id;

    const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\condition\\customform_form');

    // We need to render the dynamic form right away, so we can acutally have all the necessary elements present.
    await dynamicForm.load({id: id});

    let continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {

        const response = e.detail;

        if (response) {

            if (!continuebutton) {
                continuebutton = container.closest(SELECTOR.MODALBODY).querySelector(SELECTOR.CONTINUEBUTTON);
            }
            if (continuebutton) {

                continuebutton.dataset.blocked = 'false';
                continuebutton.click();
            }
        }
    });

    // This goes on continue button.
    // It will prevent the action to be triggered.
    // Unless the form is validated (see above).
    if (continuebutton) {

        continuebutton.dataset.blocked = true;

        continuebutton.addEventListener('click', () => {

            if (continuebutton.dataset.blocked == 'true') {

                dynamicForm.submitFormAjax();
            }
        });
    }
}