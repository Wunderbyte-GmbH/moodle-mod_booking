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
 * @module     mod_booking/dynamicsemestersform
 * @copyright  2022 Wunderbyte GmbH
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';

export const init = (selector, formClass, existingsemesters) => {

    waitForElm(selector).then((elm) => {
        // eslint-disable-next-line no-console
        console.log('selector loaded');
        const form = new DynamicForm(document.querySelector(elm), formClass);

        form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
            e.preventDefault();

            const response = e.detail;
            form.load({...existingsemesters, response});
        });

        // Cancel button does not make much sense in such forms but since it's there we'll just reload.
        form.addEventListener(form.events.FORM_CANCELLED, (e) => {
            e.preventDefault();

            // eslint-disable-next-line capitalized-comments
            // addNotification('existingsemesters:' + JSON.stringify(existingsemesters));

            // eslint-disable-next-line promise/catch-or-return
            form.notifyResetFormChanges()
                .then(() => form.load(existingsemesters));
            // AddNotification('Form cancelled');
        });

        // Demo of different events.
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.NOSUBMIT_BUTTON_PRESSED, () => addNotification('No submit button pressed.'));
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.CLIENT_VALIDATION_ERROR, () => addNotification('Client-side validation error'));
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.SERVER_VALIDATION_ERROR, () => addNotification('Server-side validation error'));
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.ERROR, (e) => addNotification('There was a form error: ' + e.detail.message));
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.SUBMIT_BUTTON_PRESSED, () => addNotification('Submit button pressed'));
        // eslint-disable-next-line capitalized-comments
        // form.addEventListener(form.events.CANCEL_BUTTON_PRESSED, () => addNotification('Cancel button pressed'));
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
