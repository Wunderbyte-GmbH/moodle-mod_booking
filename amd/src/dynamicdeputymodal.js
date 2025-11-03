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
 * @module     mod_booking
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    TRIGGERMODALBUTTON: 'button[data-id="booking-booked-users-open-deputy-modal"]'
};

export const init = () => {

    const container = document.querySelector('body');
    if (!container) {
        return;
    }

    // Add one event listener only once
    if (!container.dataset.deputyButtonDelegated) {
        container.dataset.deputyButtonDelegated = 'true';

        container.addEventListener('click', (e) => {
            const button = e.target.closest(
                SELECTORS.TRIGGERMODALBUTTON
            );
            if (!button) {
                return;
            }
            deputySelectModal(button);
        });
    }
};

/**
 * Show modal to select deputies.
 * @param {htmlElement} button
 */
export function deputySelectModal(button) {

    const modalForm = new ModalForm({

        // Name of the class where form is defined (must extend \core_form\dynamic_form):
        formClass: "mod_booking\\form\\dynamicdeputyselect",
        // Add as many arguments as you need, they will be passed to the form:
        args: {},
        // Pass any configuration settings to the modal dialogue, for example, the title:
        modalConfig: {title: getString('adddeputies', 'mod_booking')},
        returnFocus: button
    });
    // Listen to events if you want to execute something on form submit.
    // Event detail will contain everything the process() function returned:
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        window.location.reload();
    });
    // Show the form.
    modalForm.show();
}