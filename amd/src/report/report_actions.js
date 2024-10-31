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
 * Javascript controller for actions on new report page.
 *
 * @module mod_booking/report_actions
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    const bookeduserscheckboxall = document.querySelector(
        '#page-mod-booking-report2 #accordion-item-bookedusers #usercheckboxall'
    );
    if (bookeduserscheckboxall) {
        bookeduserscheckboxall.addEventListener('click', function(event) {
            let checkboxes = document.querySelectorAll('#accordion-item-bookedusers input.usercheckbox');
            for (let i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = event.target.checked;
            }
        });
    }
    const waitinglistcheckboxall = document.querySelector(
        '#page-mod-booking-report2 #accordion-item-waitinglist #usercheckboxall'
    );
    if (waitinglistcheckboxall) {
        waitinglistcheckboxall.addEventListener('click', function(event) {
            let checkboxes = document.querySelectorAll('#accordion-item-waitinglist input.usercheckbox');
            for (let i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = event.target.checked;
            }
        });
    }
};