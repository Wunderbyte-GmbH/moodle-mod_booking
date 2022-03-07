
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
 * @package    local_wunderbyte_table
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Templates from 'core/templates';
// ...


// Initialize the form - pass the container element and the form class name.
const dynamicForm = new DynamicForm(document.querySelector('#formcontainer'), 'mod_booking\\form\\optiondate_form');
// By default the form is removed from the DOM after it is submitted, you may want to change this behavior:
export const init = () => {
dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
    e.preventDefault();
    const response = e.detail;
    console.log(response);
    Templates.renderForPromise('mod_booking/bookingoption_dates', response)

    // It returns a promise that needs to be resoved.
    .then(({html}) => {
        datelistinit();
        // Here eventually I have my compiled template, and any javascript that it generated.
        // The templates object has append, prepend and replace functions.
        Templates.appendNodeContents('.datelist', html);
        console.log("test");
    })

    // Deal with this exception (Using core/notify exception function is recommended).
    .catch(ex => displayException(ex));


    // It is recommended to reload the form after submission because the elements may change.
    // This will also remove previous submission errors. You will need to pass the same arguments to the form
    // that you passed when you rendered the form on the page.
})
};

export const datelistinit = () => {
    document.querySelector(".datelist").addEventListener('click', function(e) {
        let action = e.target.dataset.action;
        if (action === 'delete') {
        e.target.closest('li').remove();
        }
        if (action === 'add') {
        let targetElement = e.target.closest('li');
        let date = document.querySelector("#meeting-time");
        let element = '<li><span class="badge bg-primary">' + date.value + '</span> <i class="fa fa-window-close ml-2" data-action="delete"></i></li>';
        targetElement.insertAdjacentHTML('afterend', element);
        }
    })


}