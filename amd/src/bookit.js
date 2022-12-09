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
 * @module     mod_booking/bookit
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

var currentbookitpage = {};
var totalbookitpages = {};

var SELECTORS = {
    MODALID: '#sbPrePageModal',
    INMODALDIV: ' div.pageContent',
    CONTINUEBUTTON: 'a.continue-button',
    BACKBUTTON: 'a.back-button',
};

/**
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} totalnumberofpages
 */
export const init = (optionid, totalnumberofpages) => {

    currentbookitpage[optionid] = 0;
    totalbookitpages[optionid] = totalnumberofpages;

    respondToVisibility(optionid, totalnumberofpages, loadPreBookingPage);

    // We can add the click listener to the continue button right away.

    initializeButton(optionid, true); // Back button.
    initializeButton(optionid, false); // Continue button.
};

/**
 * React on visibility change.
 * @param {integer} optionid
 * @param {integer} totalnumberofpages
 * @param {function} callback
 */
function respondToVisibility(optionid, totalnumberofpages, callback) {

    if (totalnumberofpages < 1) {
        return;
    }

    const selector = SELECTORS.MODALID + optionid + SELECTORS.INMODALDIV;
    let element = document.querySelector(selector);

    if (!element) {
        return;
    }

    // eslint-disable-next-line no-console
    console.log(selector, element);

    var observer = new MutationObserver(function() {
        if (!isHidden(element)) {
            // this.disconnect();
            callback(optionid, totalnumberofpages);
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
    callback(optionid, totalnumberofpages);
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
 * Loads the (next) pre booking page.
 * @param {integer} optionid
 * @param {integer} totalnumberofpages
 */
export const loadPreBookingPage = (
    optionid, totalnumberofpages) => {

    // eslint-disable-next-line no-console
    console.log('loadPreBookingPage ' + optionid, totalnumberofpages);

    // We need to clear the content of the div.
    const selector = SELECTORS.MODALID + optionid + SELECTORS.INMODALDIV;

    const element = document.querySelector(selector);

    while (element.firstChild) {
        element.removeChild(element.firstChild);
    }

    Ajax.call([{
        methodname: "mod_booking_load_pre_booking_page",
        args: {
            'optionid': optionid,
            'pagenumber': currentbookitpage[optionid],
        },
        done: function(res) {

            // eslint-disable-next-line no-console
            console.log(res.json, "template " + res.template);

            const jsonobject = JSON.parse(res.json);

            // We support more than one template, they will be seperated by comma.
            // We have a data key in the json
            const templates = res.template.split(',');
            let dataarray = jsonobject;

            templates.forEach(template => {

                const data = dataarray.shift();

                if (!data) {
                    return true;
                }

                // eslint-disable-next-line no-console
                console.log(data.data, "template " + template);

                Templates.renderForPromise(template, data.data).then(({html, js}) => {

                    // eslint-disable-next-line no-console
                    console.log(selector);

                    Templates.appendNodeContents(selector, html, js);

                    return true;
                }).catch(ex => {
                    Notification.addNotification({
                        message: 'failed rendering ' + ex,
                        type: "danger"
                    });
                });
                return true;
            });

            showRightButton(optionid);

            return true;
        },
        fail: function(err) {
            // eslint-disable-next-line no-console
            console.log(err);
        }
    }]);
};

/**
 * Reveal the hidden continue button.
 * @param {interger} optionid
 */
function showRightButton(optionid) {

    // eslint-disable-next-line no-console
    console.log(optionid, currentbookitpage[optionid], totalbookitpages[optionid]);

    if (currentbookitpage[optionid] + 1 < totalbookitpages[optionid]) {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON);
        element.classList.remove('hidden');
    } else {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON);
        element.classList.add('hidden');
    }
    if (currentbookitpage[optionid] > 0) {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.BACKBUTTON);
        element.classList.remove('hidden');
    } else {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.BACKBUTTON);
        element.classList.add('hidden');
    }

}

/**
 * Add the click listener to a button.
 * @param {integer} optionid
 * @param {bool} back // If it is the back button, it's true, else its continue.
 */
function initializeButton(optionid, back) {
    let selector = "";

    if (back) {
        selector = SELECTORS.MODALID + optionid + ' ' + SELECTORS.BACKBUTTON;
    } else {
        selector = SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON;
    }

    const element = document.querySelector(selector);

    // eslint-disable-next-line no-console
    console.log(element, selector);

    if (!element.dataset.initialized) {
        element.dataset.initialized = true;

        element.addEventListener('click', () => {

            if (element.classList.contains('hidden')) {
                return;
            }

            if (back) {
                currentbookitpage[optionid]--;
            } else {
                currentbookitpage[optionid]++;
            }

            loadPreBookingPage(optionid, totalbookitpages[optionid]);
        });
    }
}