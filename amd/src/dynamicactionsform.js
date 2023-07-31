/* eslint-disable promise/always-return */
/* eslint-disable promise/catch-or-return */
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

/*
 * @package    mod_booking
 * @author     Bernhard Fischer
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic semesters form.
 *
 * @module     mod_booking/dynamicactionsform
 * @copyright  2023 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import ModalForm from 'core_form/modalform';

export const init = (selector) => {

    const element = document.querySelector(selector);

    element.addEventListener('click', e => {

        editActionsModal(e.target);

    });
};

/**
 *  Function to show modal elemnt.
 * @param {HTMLElement} element
 */
function editActionsModal(element) {

    if (!element) {
        return;
    }

    const actionid = element.dataset.id;
    const name = element.dataset.name;
    const action = element.dataset.action;

    if (!actionid) {
        return;
    }

    if (action == "delete") {
        // A action is deleted.
        const deleteForm = new ModalForm({

            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\editactionsform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {id: actionid, name: name},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {
                title: getString('deletebookingaction', 'mod_booking')
            },
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // After submitting we want to reload the window to update the action list.
        deleteForm.addEventListener(deleteForm.events.FORM_SUBMITTED, () => {
            window.location.reload();
        });

        // Show the form.
        deleteForm.show();

    } else if (action == "edit-or-new") {
        // A action is added (actionid == 0) or edited (actionid > 0).
        const modalForm = new ModalForm({
            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\actions\\actionsform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {id: actionid},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {title: getString('editaction', 'mod_booking')},
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // Listen to events if you want to execute something on form submit.
        // Event detail will contain everything the process() function returned:
        modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {

            // After adding or editing, we want to reload the window to update the action list.
            window.location.reload();
        });

        // We need to add an event listener for the change of the action, action, and condition select.
        modalForm.addEventListener('change', (e) => {
            if (!e.target.name) {
                return;
            }

            if (e.target.name == 'bookingactiontype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingactiontype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'action_react_on_event_event') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingactiontype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bookingactionconditiontype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingactionconditiontype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bookingactionactiontype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingactionactiontype"]');
                modalForm.processNoSubmitButton(button);
            }
        });

        // Show the form.
        modalForm.show();
    } else {
        // eslint-disable-next-line no-console
        console.log('Error in dynamicactionsform.js: action should be "delete" or "edit-or-new".');
        return;
    }
}
