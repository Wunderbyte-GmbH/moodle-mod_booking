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
 * Modal form to configure and download the sign-in sheet (used on report2.php).
 *
 * @module     mod_booking/signinsheetmodal
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    TRIGGERMODALBUTTON: '[data-action="booking-report2-signinsheet-modal"]',
    QUICKDOWNLOADBUTTON: '[data-id="booking-report2-signinsheet-quickdownload"]'
};

export const init = () => {

    const container = document.querySelector('body');
    if (!container) {
        return;
    }

    // Add one event listener only once.
    if (!container.dataset.signinsheetButtonDelegated) {
        container.dataset.signinsheetButtonDelegated = 'true';

        container.addEventListener('click', (e) => {
            const button = e.target.closest(
                SELECTORS.TRIGGERMODALBUTTON
            );
            if (!button) {
                return;
            }
            signinsheetModal(button);
        });
    }
};

/**
 * Show modal to configure and download the sign-in sheet.
 * @param {HTMLElement} button
 */
export function signinsheetModal(button) {

    const modalForm = new ModalForm({
        // Name of the class where form is defined (must extend \core_form\dynamic_form):
        formClass: "mod_booking\\form\\modal_signinsheet_download",
        // Add as many arguments as you need, they will be passed to the form:
        args: {
            cmid: button.dataset.cmid,
            optionid: button.dataset.optionid,
        },
        // Pass any configuration settings to the modal dialogue, for example, the title:
        modalConfig: {title: getString('signinsheetconfigure', 'mod_booking')},
        // Label of the submit button, depends on the sign-in sheet mode (PDF or HTML template).
        saveButtonText: getString(button.dataset.savebuttonstr || 'signinsheetdownload', 'mod_booking'),
        returnFocus: button
    });
    // The form does not execute the download itself, it just returns the URL of
    // the existing download endpoint on report.php. Navigating there triggers the
    // file download (attachment), so the user stays on report2.php.
    // No e.preventDefault() here: ModalForm only closes the modal after submission
    // when the default is not prevented, and the download does not reload the page.
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, (e) => {
        if (e.detail.downloadurl) {
            // The settings were persisted for this option, so the quick download
            // button has to pick them up without a page reload.
            const quickbutton = document.querySelector(SELECTORS.QUICKDOWNLOADBUTTON);
            if (quickbutton) {
                quickbutton.setAttribute('href', e.detail.downloadurl);
            }
            window.location.href = e.detail.downloadurl;
        }
    });
    // Show the form.
    modalForm.show();
}
