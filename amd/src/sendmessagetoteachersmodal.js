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
 * Modal form to send a message to the teachers of a booking option (used on report2.php).
 *
 * @module     mod_booking/sendmessagetoteachersmodal
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    TRIGGERMODALBUTTON: '[data-action="booking-report2-sendmessagetoteachers-modal"]'
};

export const init = () => {

    const container = document.querySelector('body');
    if (!container) {
        return;
    }

    // Add one event listener only once.
    if (!container.dataset.sendmessagetoteachersButtonDelegated) {
        container.dataset.sendmessagetoteachersButtonDelegated = 'true';

        container.addEventListener('click', (e) => {
            const button = e.target.closest(
                SELECTORS.TRIGGERMODALBUTTON
            );
            if (!button) {
                return;
            }
            sendMessageToTeachersModal(button);
        });
    }
};

/**
 * Show modal to send a message to the teachers of a booking option.
 * @param {HTMLElement} button
 */
export function sendMessageToTeachersModal(button) {

    const modalForm = new ModalForm({
        // The same dynamic form as the "Send message to teacher(s)" action button
        // of the booked users table: teacher autocomplete (preselected, users can
        // be removed and re-added), subject, message and attachment.
        formClass: "mod_booking\\form\\modal_send_message_to_teachers",
        // Add as many arguments as you need, they will be passed to the form:
        args: {
            cmid: button.dataset.cmid,
            optionid: button.dataset.optionid,
        },
        // Pass any configuration settings to the modal dialogue, for example, the title:
        modalConfig: {title: getString('sendmessagetoteachers', 'mod_booking')},
        saveButtonText: getString('sendmessage', 'mod_booking'),
        returnFocus: button
    });
    // Show the success (or failure) feedback returned by the form, in the same way
    // the wunderbyte table action buttons do.
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, (e) => {
        const data = e.detail;
        if (data.message && data.message.length > 0) {
            Notification.addNotification({
                message: data.message,
                type: data.success == 1 ? 'success' : 'error',
            });
        }
    });
    // Show the form.
    modalForm.show();
}
