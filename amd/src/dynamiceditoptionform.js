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
 * @author     Georg Maißer
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic edit option form.
 *
 * @module     mod_booking/dynamiceditoptionform
 * @copyright  2023 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';


export const init = (cmid, id, optionid, bookingid, copyoptionid, returnurl) => {
    // Initialize the form - pass the container element and the form class name.

    // eslint-disable-next-line no-console
    console.log('params: ', cmid, id, optionid, bookingid, copyoptionid, returnurl);

    const element = document.querySelector('#editoptionsformcontainer');

    // eslint-disable-next-line no-console
    console.log(element);
    const dynamicForm = new DynamicForm(element, 'mod_booking\\form\\option_form');

    // eslint-disable-next-line no-console
    console.log(dynamicForm);
    // By default the form is removed from the DOM after it is submitted, you may want to change this behavior:
    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;

        if (response.returnurl && response.returnurl.length > 0) {
            window.location.href = response.returnurl;
        }

        // eslint-disable-next-line no-console
        console.log(response);
        // It is recommended to reload the form after submission because the elements may change.
        // This will also remove previous submission errors. You will need to pass the same arguments to the form
        // that you passed when you rendered the form on the page.
        dynamicForm.load(e.detail);
    });

    dynamicForm.addEventListener(dynamicForm.events.FORM_CANCELLED, (e) => {
        e.preventDefault();

        if (returnurl && returnurl.length > 0) {
            window.location.href = returnurl;
        } else {
            // Just in case we have no returnurl.
            dynamicForm.load([cmid, id, optionid, bookingid, copyoptionid, returnurl]);
        }
    });

    dynamicForm.addEventListener('change', e => {
        // eslint-disable-next-line no-console
        console.log(e);

        if (e.target.name == 'optiontemplateid') {
            window.skipClientValidation = true;
            let button = document.querySelector('[name="btn_changetemplate"]');
            dynamicForm.processNoSubmitButton(button);
        }

    });
};