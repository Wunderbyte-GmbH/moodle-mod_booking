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

import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';

/**
 * Gets called from mustache template.
 */
export const init = () => {

    const buttons = document.querySelectorAll(".booking-button-notify-me");

    if (buttons) {
        buttons.forEach(button => {

            if (button.dataset.initialized) {
                return;
            }
            const userid = button.dataset.userid;
            const itemid = button.dataset.itemid;

            button.addEventListener('click', () => {
                toggleNotifiy(button, userid, itemid);
            });
            button.dataset.initialized = true;
        });
    }
};

/**
 * Toggle Notify
 * @param {any} button
 * @param {int} userid
 * @param {int} optionid
 */
 export const toggleNotifiy = (
    button,
    userid,
    optionid) => {

    Ajax.call([{
        methodname: "mod_booking_toggle_notify_user",
        args: {
            'userid': userid,
            'optionid': optionid
        },
        done: function(res) {

            toggleButton(button, res.status);

            return true;
        },
        fail: function(err) {

            // eslint-disable-next-line no-console
            console.log('something went wrong', err);
        }
    }]);
};

/**
 *
 * @param {*} button
 * @param {*} status
 */
function toggleButton(button, status) {
    const statusstring = status == 1 ? 'alreadyonlist' : 'notifyme';

    getString(statusstring, 'mod_booking').then(res => {
        if (status == 1) {
            button.innerHTML = '<i class="fa fa-bell" aria-hidden="true"></i>';
        } else {
            button.innerHTML = '<i class="fa fa-bell-o" aria-hidden="true"></i>';
        }
        button.setAttribute('title', res);
        button.removeAttribute('data-original-title');
        return;
    }).catch(e => {
        // eslint-disable-next-line no-console
        console.log(e);
    });
}