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
// eslint-disable-next-line capitalized-comments
// eslint-disable-next-line spaced-comment
/*import Notification from 'core/notification';
import {add as addToast} from 'core/toast';*/

// eslint-disable-next-line capitalized-comments
// eslint-disable-next-line spaced-comment
/*const addNotification = msg => {
    addToast(msg);
};*/

export const init = (selector, formClass, existingsemesters) => {

    // eslint-disable-next-line capitalized-comments
    // addNotification('existingsemesters:' + JSON.stringify(existingsemesters));

    const form = new DynamicForm(document.querySelector(selector), formClass);

    form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();

        // eslint-disable-next-line capitalized-comments
        // addNotification('existingsemesters:' + JSON.stringify(existingsemesters));

        const response = e.detail;
        form.load({...existingsemesters, response});
        // eslint-disable-next-line capitalized-comments
        // addNotification('Form submitted');
        // eslint-disable-next-line capitalized-comments
        // Notification.addNotification({message: 'Form submitted: ' + JSON.stringify(response), type: 'success'});
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
};
