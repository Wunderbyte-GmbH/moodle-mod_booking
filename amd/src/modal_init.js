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
 * @module     mod_booking/modal_init
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

/**
 * Gets called from mustache template.
 * @param {int} optionid
 */
export const init = (optionid = null) => {

    let spinners = [];
    if (optionid === null) {
        spinners = document.querySelectorAll('[id^=bo_modal_spinner]');
    } else {
        spinners = [document.querySelector('[id^="bo_modal_spinner_' + optionid + '_"]')];
    }
    spinners.forEach((spinner) => {
        const optionid = spinner.dataset.optionid;
        const userid = spinner.dataset.userid ?? 0;

        respondToVisibility(optionid, userid, getBookingOptionDescription);
    });
};

/**
 * React on visibility change.
 * @param {int} optionid
 * @param {int} userid
 * @param {function} callback
 */
function respondToVisibility(optionid, userid, callback) {
    let element = document.getElementById('bo_modal_spinner_' + optionid);

    var observer = new MutationObserver(function() {
        if (!isHidden(element)) {
            this.disconnect();
            callback(optionid, userid);
        }
    });

    // We look if we find a hidden parent. If not, we load right away.
    while (element !== null) {
        if (!isHidden(element)) {
            element = element.parentElement;
        } else {
            if (element.dataset.observed) {
                return;
            }

            observer.observe(element, {attributes: true});
            element.dataset.observed = true;
            return;
        }
    }
    callback(optionid, userid);
}

/**
 * Function to check visibility of element.
 * @param {*} el
 * @returns {boolean}
 */
function isHidden(el) {
    var style = window.getComputedStyle(el);
    return ((style.display === 'none') || (style.visibility === 'hidden'));
}

/**
 * Reloads the rendered table and sets it to the div with the right identifier.
 * @param {int} optionid
 * @param {int} userid
 */
export const getBookingOptionDescription = (
    optionid,
    userid) => {

    let description = document.getElementById('bo_modal_description_' + optionid);
    let spinner = document.querySelector('#bo_modal_spinner_' + optionid + ' .spinner-border');

    if (spinner) {
        spinner.classList.remove('hidden');
    }
    if (description) {
        description.classList.add('hidden');
    }

    Ajax.call([{
        methodname: "mod_booking_get_booking_option_description",
        args: {
            'optionid': optionid,
            'userid': userid
        },
        done: function(res) {

            let spinner = document.querySelector('#bo_modal_spinner_' + optionid + ' .spinner-border');
            let description = document.getElementById('bo_modal_description_' + optionid);

            const jsonobject = JSON.parse(res.content);

            Templates.renderForPromise(res.template, jsonobject).then(({html, js}) => {

                Templates.appendNodeContents('#bo_modal_description_' + optionid, html, js);

                spinner.classList.add('hidden');
                description.classList.remove('hidden');

                return true;
            }).catch(ex => {
                Notification.addNotification({
                    message: 'failed rendering ' + ex,
                    type: "danger"
                });
            });

            spinner.classList.add('hidden');
            description.classList.remove('hidden');
            return true;
        },
        fail: function(err) {
            // eslint-disable-next-line no-console
            console.log(err);
            spinner.classList.add('hidden');
            description.classList.remove('hidden');
        }
    }]);
};
