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
 * Dynamic form bootstrap for slot teacher assignments.
 *
 * @module     mod_booking/slotteacherassignments_form
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Notification from 'core/notification';

const CONTAINER_ID = '#mod-booking-slotteacherassignments-form-container';

/**
 * Initialize dynamic form.
 *
 * @param {number} id cmid
 * @param {number} optionid booking option id
 */
export const init = (id, optionid) => {
    const element = document.querySelector(CONTAINER_ID);
    if (!element) {
        return;
    }

    const dynamicForm = new DynamicForm(element, 'mod_booking\\form\\slotteacherassignments_form');

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();

        const response = e.detail || {};
        if (response.message) {
            Notification.addNotification({
                message: response.message,
                type: 'success',
            });
        }

        dynamicForm.load({id, optionid});
    });

    dynamicForm.load({id, optionid});
};
