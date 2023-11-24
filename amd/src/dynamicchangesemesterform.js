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
 * Dynamic dynamicchangesemesterform.
 *
 * @module     mod_booking/dynamicchangesemesterform
 * @copyright  2022 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

export const init = (selector, formClass, existingsemester) => {

    // eslint-disable-next-line no-console
    console.log(selector, formClass);

    const form = new DynamicForm(document.querySelector(selector), formClass);

    form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();

        getString('changesemesteradhoctaskstarted', 'mod_booking').then(message => {
            Notification.addNotification({
                message: message,
                type: "success"
            });
            return;
        }).catch(e => {
            // eslint-disable-next-line no-console
            console.log(e);
        });
    });

    // Cancel button does not make much sense in such forms but since it's there we'll just reload.
    form.addEventListener(form.events.FORM_CANCELLED, (e) => {
        e.preventDefault();
        // eslint-disable-next-line promise/catch-or-return
        form.notifyResetFormChanges()
            .then(() => form.load(existingsemester));
    });
};
