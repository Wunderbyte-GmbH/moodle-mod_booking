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
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Templates from 'core/templates';

const optiondateForm = new DynamicForm(document.querySelector('#optiondates-form'), 'mod_booking\\form\\optiondate_form');

export const init = (cmid, bookingid, optionid) => {

    loadform(cmid, bookingid, optionid);

    optiondateForm.addEventListener(optiondateForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;
        Templates.renderForPromise('mod_booking/bookingoption_dates', response)
        // It returns a promise that needs to be resolved.
        .then(({html}) => {
            document.querySelector('#optiondates-list').innerHTML = '';
            Templates.appendNodeContents('#optiondates-list', html);
            return;
        })
        // Deal with this exception (Using core/notify exception function is recommended).
        // eslint-disable-next-line no-undef
        .catch(ex => displayException(ex));

        // It is recommended to reload the form after submission because the elements may change.
        // This will also remove previous submission errors. You will need to pass the same arguments to the form
        // that you passed when you rendered the form on the page.
        var datelist = document.querySelector('#optiondates-list');
        datelist.parentNode.removeChild(datelist);
        loadform(cmid, bookingid, optionid);
    });
};

export const loadform = (cmid, bookingid, optionid) => {

    optiondateForm.load({
        'cmid': cmid,
        'bookingid': bookingid,
        'optionid': optionid
    })
    .then(() => {
        datelistinit();
        return;
    })
    // Deal with this exception (Using core/notify exception function is recommended).
    // eslint-disable-next-line no-undef
    .catch(ex => displayException(ex));
};

export const datelistinit = () => {

    var dateform = document.querySelector("#optiondates-form");
    var datelist = document.querySelector("#optiondates-list");

    datelist.parentNode.removeChild(datelist);

    // Important: Move datelist after dateform so $_POST will work in PHP.
    dateform.parentNode.insertBefore(datelist, dateform.nextSibling);

    datelist.addEventListener('click', function(e) {

        let action = e.target.dataset.action;
        let targetid = e.target.dataset.targetid;

        if (action === 'delete') {
            e.target.closest('li').remove();
            document.getElementById(targetid).remove();
        }

        if (action === 'add') {

            let targetElement = e.target.closest('li');
            let date = document.querySelector("#meeting-time");
            let element = '<li><span class="badge bg-primary">' + date.value +
                '</span> <i class="fa fa-trash ml-2 icon-red" data-action="delete"></i></li>';
            targetElement.insertAdjacentHTML('afterend', element);
        }
    });
};
