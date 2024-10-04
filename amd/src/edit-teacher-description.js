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

import ModalForm from 'core_form/modalform';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    EDITTEACHERDESCRIPTIONBUTTON: '.edit-teacher-description',
};

export const init = () => {
    // eslint-disable-next-line no-console
    console.log('run init');

    // Cashout functionality.
    const editteacherbuttons = document.querySelectorAll(SELECTORS.EDITTEACHERDESCRIPTIONBUTTON);

    editteacherbuttons.forEach(editteacherbutton => {
        if (editteacherbutton) {
            editteacherbutton.addEventListener('click', e => {

                // eslint-disable-next-line no-console
                console.log(e.target);

                editTeacherModal(editteacherbutton);
            });
        }
    });
};

/**
 * Show cashout modal.
 * @param {htmlElement} button
 */
export function editTeacherModal(button) {

    // eslint-disable-next-line no-console
    console.log('cashoutModal');

    const teacherid = button.dataset.teacherid;

    const modalForm = new ModalForm({

        // Name of the class where form is defined (must extend \core_form\dynamic_form):
        formClass: "mod_booking\\form\\modal_editteacherdescription",
        // Add as many arguments as you need, they will be passed to the form:
        args: {teacherid},
        // Pass any configuration settings to the modal dialogue, for example, the title:
        modalConfig: {title: getString('editteacherslink', 'booking')},
        // DOM element that should get the focus after the modal dialogue is closed:
        returnFocus: button
    });
    // Listen to events if you want to execute something on form submit.
    // Event detail will contain everything the process() function returned:
    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => {
        setTimeout(() => {
            window.location.reload();
        }, 500);
    });

    // Show the form.
    modalForm.show();

}