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
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Form to edit teachers for a specific optiondate.
 *
 * @module     mod_booking/editteachersforoptiondate_form
 * @copyright  2022 Wunderbyte GmbH
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';

export const initbuttons = () => {
    let buttons = document.querySelectorAll('.btn-modal-edit-teachers');

    buttons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();

            openEditTeachersModal(button);
        });
    });

};

/**
 *
 * @param {*} button
 */
 function openEditTeachersModal(button) {

    const cmid = button.dataset.cmid;
    const optiondateid = button.dataset.optiondateid;

    const modalForm = new ModalForm({

        // Name of the class where form is defined (must extend \core_form\dynamic_form):
        formClass: "mod_booking\\form\\editteachersforoptiondate_form",
        // Add as many arguments as you need, they will be passed to the form:
        args: {
            'cmid': cmid,
            'optiondateid': optiondateid,
        },
        // Pass any configuration settings to the modal dialogue, for example, the title:
        modalConfig: {title: getString('teachers', 'mod_booking')},
        // DOM element that should get the focus after the modal dialogue is closed:
        returnFocus: button
    });

    // Listen to events if you want to execute something on form submit.
    // Event detail will contain everything the process() function returned:
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;

        // eslint-disable-next-line no-console
        console.log('response: ', response);
        modalForm.load({response});
    });

    // Show the form.
    modalForm.show();
}
