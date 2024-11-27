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
 * Dynamic campaigns form.
 * @module     mod_booking/dynamiccampaignsform
 * @copyright  2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import ModalForm from 'core_form/modalform';

export const init = (selector) => {

    const element = document.querySelector(selector);

    element.addEventListener('click', e => {

        editCampaignsModal(e.target);

    });
};

/**
 *  Function to show modal elemnt.
 * @param {HTMLElement} element
 */
function editCampaignsModal(element) {

    if (!element) {
        return;
    }

    const campaignid = element.dataset.id;
    const bookingcampaigntype = element.dataset.bookingcampaigntype;
    const action = element.dataset.action;
    const name = element.dataset.name;

    if (!campaignid) {
        return;
    }

    if (action == "delete") {
                // eslint-disable-next-line no-console
                console.log("delete");
        // A campaign is deleted.
        const deleteForm = new ModalForm({

            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\deletecampaignform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {id: campaignid, bookingcampaigntype: bookingcampaigntype, name: name},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {
                title: getString('deletebookingcampaign', 'mod_booking')
            },
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // After submitting we want to reload the window to update the campaign list.
        deleteForm.addEventListener(deleteForm.events.FORM_SUBMITTED, () => {
            window.location.reload();
        });

        // Show the form.
        deleteForm.show();

    } else if (action == "edit-or-new") {

        // eslint-disable-next-line no-console
        console.log("editornew");
        // A campaign is added (campaignid == 0) or edited (campaignid > 0).
        const modalForm = new ModalForm({
            // Name of the class where form is defined (must extend \core_form\dynamic_form):
            formClass: "mod_booking\\form\\campaignsform",
            // Add as many arguments as you need, they will be passed to the form:
            args: {id: campaignid, bookingcampaigntype: bookingcampaigntype},
            // Pass any configuration settings to the modal dialogue, for example, the title:
            modalConfig: {title: getString('editcampaign', 'mod_booking')},
            // DOM element that should get the focus after the modal dialogue is closed:
            returnFocus: element
        });

        // Listen to events if you want to execute something on form submit.
        // Event detail will contain everything the process() function returned:
        modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {

            // After adding or editing, we want to reload the window to update the campaign list.
            window.location.reload();
        });

        // We need to add an event listener for the change of the campaign type select.
        modalForm.addEventListener('change', (e) => {

            // eslint-disable-next-line no-console
            console.log('change detected: ', e);

            if (!e.target.name) {
                return;
            }
            if (e.target.name == 'bookingcampaigntype') {
                // eslint-disable-next-line no-console
                console.log('change in bookingcampaigntype');
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bookingcampaigntype"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'bofieldname') {
                // eslint-disable-next-line no-console
                console.log('change in booking option fieldname');
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_bofieldname"]');
                modalForm.processNoSubmitButton(button);
            }

            if (e.target.name == 'cpfield') {
                // eslint-disable-next-line no-console
                console.log('change in user profile field');
                window.skipClientValidation = true;
                let button = document.querySelector('[name="btn_cpfield"]');
                modalForm.processNoSubmitButton(button);
            }
        });

        // Show the form.
        modalForm.show();
    } else {
        // eslint-disable-next-line no-console
        console.log('Error in dynamiccampaignsform.js: action should be "delete" or "edit-or-new".');
        return;
    }
}
