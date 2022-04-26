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

/**
 * Modal form to create specific option dates.
 *
 * @module     mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import ModalForm from 'core_form/modalform';
import {add as addToast} from 'core/toast';

const addNotification = msg => {
    addToast(msg);
    // eslint-disable-next-line no-console
    console.log(msg);
};


export const init = (modalTitle, formClass, resultSelector) => {
    waitForElm('button[name="customdatesbtn"]').then((customdatesbtn) => {
        customdatesbtn.addEventListener('click', (e) => {
            e.preventDefault();
            const form = new ModalForm({
                formClass,
                modalConfig: {title: modalTitle},
                returnFocus: e.currentTarget
            });
            // If necessary extend functionality by overriding class methods, for example:
            form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
                const response = e.detail;
                addNotification('Form submitted...');
                document.querySelector(resultSelector).innerHTML = '<pre>' + JSON.stringify(response) + '</pre>';
            });

            // Demo of different events.
            form.addEventListener(form.events.LOADED, () => addNotification('Form loaded'));
            form.addEventListener(form.events.NOSUBMIT_BUTTON_PRESSED,
                (e) => addNotification('No submit button pressed ' + e.detail.getAttribute('name')));
            form.addEventListener(form.events.CLIENT_VALIDATION_ERROR, () => addNotification('Client-side validation error'));
            form.addEventListener(form.events.SERVER_VALIDATION_ERROR, () => addNotification('Server-side validation error'));
            form.addEventListener(form.events.ERROR, (e) => addNotification('Oopsie - ' + e.detail.message));
            form.addEventListener(form.events.SUBMIT_BUTTON_PRESSED, () => addNotification('Submit button pressed'));
            form.addEventListener(form.events.CANCEL_BUTTON_PRESSED, () => addNotification('Cancel button pressed'));

            form.show();
        });
    });
};

/**
 * Wait until a certain element is loaded.
 * @param {string} selector - The element selector.
 * @returns {Promise}
 */
function waitForElm(selector) {
    // eslint-disable-next-line consistent-return
    return new Promise(resolve => {
        if (document.querySelector(selector)) {
            return resolve(document.querySelector(selector));
        }

        const observer = new MutationObserver(() => {
            if (document.querySelector(selector)) {
                resolve(document.querySelector(selector));
                observer.disconnect();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });
}
