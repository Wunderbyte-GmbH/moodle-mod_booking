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
 * @module     mod_booking/subbookingAdditionalPerson
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';

const SELECTOR = {
    FORMCONTAINER: '.subbooking-additionalperson-form',
};

/**
 * Init function.
 */
export function init() {

    const container = document.querySelector(SELECTOR.FORMCONTAINER);

    const id = container.dataset.id;

    // eslint-disable-next-line no-console
    console.log('subbookingAdditionalPerson', container, id);

    const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\subbooking_additionalperson_form');

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {
        const response = e.detail;
        // eslint-disable-next-line no-console
        console.log(response);
        dynamicForm.container.innerHTML = '';
    });

    dynamicForm.addEventListener('change', e => {

        // eslint-disable-next-line no-console
        console.log(e);

        const button = document.querySelector('.subbooking-additionalperson-form [data-no-submit="1"]');

        dynamicForm.processNoSubmitButton(button);
    });

    // eslint-disable-next-line no-console
    console.log('trigger form load');

    dynamicForm.load({id: id});

    document.addEventListener("DOMContentLoaded", () => {
        // eslint-disable-next-line no-console
        console.log("Hello World!");
      });
}