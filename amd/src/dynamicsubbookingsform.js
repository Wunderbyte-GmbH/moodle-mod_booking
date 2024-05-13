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
 * @module     mod_booking/dynamicsubbookingsform
 * @copyright  2022 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import ModalForm from 'core_form/modalform';

export const init = (selector) => {

    // For this selector, we have the buttons add, edit & delete

    const elements = document.querySelectorAll(selector);

    elements.forEach(element => {

        element.addEventListener('click', e => {

            editSubbookingsModal(e.target);

        });
    });
};

/**
 *  Function to show modal elemnt.
 * @param {HTMLElement} element
 */
function editSubbookingsModal(element) {

    if (!element) {
        return;
    }

    const id = element.dataset.id ?? 0;
    const cmid = element.dataset.cmid ?? 0;
    const optionid = element.dataset.optionid ?? 0;
    const name = element.dataset.name ?? '';
    const action = element.dataset.action;

    if (action == "delete") {
        // A rule is deleted.
        const deleteForm = new ModalForm({

            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\subbookingsdeleteform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {cmid: cmid, optionid: optionid, id: id, name: name},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {
                title: getString('bookingsubbookingdelete', 'mod_booking')
            },
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // After submitting we want to reload the window to update the rule list.
        deleteForm.addEventListener(deleteForm.events.FORM_SUBMITTED, () => {
            window.location.reload();
        });

        // Show the form.
        deleteForm.show();

    } else if (action == "add" || action == 'edit') {

        // A rule is added (ruleid == 0) or edited (ruleid > 0).
        const modalForm = new ModalForm({
            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\subbookingsform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {cmid: cmid, optionid: optionid, id: id, name: name},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {title: getString('editsubbooking', 'mod_booking')},
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // Listen to events if you want to execute something on form submit.
        // Event detail will contain everything the process() function returned:
        modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {

            // After adding or editing, we want to reload the window to update the rule list.
            window.location.reload();
        });

        // We need to add an event listener for the change of the rule, action, and condition select.
        modalForm.addEventListener('change', (e) => {
            if (!e.target.name) {
                return;
            }

            if (e.target.name == 'subbooking_type') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_subbookingtype"]');
                modalForm.processNoSubmitButton(button);
            }
        });

        // Show the form.
        modalForm.show();
    } else {
        return;
    }
}