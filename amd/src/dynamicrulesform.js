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
 * @module     mod_booking/dynamicsemestersform
 * @copyright  2022 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import ModalForm from 'core_form/modalform';

export const init = (selector) => {

    const element = document.querySelector(selector);

    element.addEventListener('click', e => {

        editRulesModal(e.target);

    });
};

/**
 *  Function to show modal elemnt.
 * @param {HTMLElement} element
 */
function editRulesModal(element) {

    if (!element) {
        return;
    }

    const ruleid = element.dataset.id;
    const name = element.dataset.name;
    const action = element.dataset.action;
    const contextid = element.dataset.contextid;

    if (!ruleid) {
        return;
    }

    if (action == "delete") {
        // A rule is deleted.
        const deleteForm = new ModalForm({
            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\deleteruleform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {id: ruleid, name: name, contextid: contextid},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {
                title: getString('deletebookingrule', 'mod_booking')
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

    } else if (action == "edit-or-new") {
        // A rule is added (ruleid == 0) or edited (ruleid > 0).
        const modalForm = new ModalForm({
            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\rulesform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {
                id: ruleid,
                contextid: contextid
            },
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {title: getString('editrule', 'mod_booking')},
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

            if (e.target.name == 'bookingruletype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingruletype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bookingruletemplate') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingruletemplates"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'rule_react_on_event_event') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingruletype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bookingruleconditiontype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingruleconditiontype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bookingruleactiontype') {
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingruleactiontype"]');
                modalForm.processNoSubmitButton(button);
            }
        });

        // Show the form.
        modalForm.show();
    } else {
        // eslint-disable-next-line no-console
        console.log('Error in dynamicrulesform.js: action should be "delete" or "edit-or-new".');
        return;
    }
}
