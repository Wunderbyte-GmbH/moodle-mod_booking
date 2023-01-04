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
    BOOKITBUTTON: 'div.booking-button-area',
};

export const initbookitbutton = (itemid, area) => {

    const buttons = document.querySelectorAll(SELECTORS.BOOKITBUTTON +
        '[data-itemid="' + itemid + '"]' +
        '[data-area="' + area + '"]');

    const selector = SELECTORS.BOOKITBUTTON +
    '[data-itemid="' + itemid + '"]' +
    '[data-area="' + area + '"]';
    // eslint-disable-next-line no-console
    console.log(selector, buttons);

    if (!buttons) {
        return;
    }

    // We support more than one booking button on the same page.
    buttons.forEach(button => {

        if (button.dataset.nojs) {
            return;
        }

        // eslint-disable-next-line no-console
        console.log('add click listener ', button);
        if (!button.dataset.initialized) {
            button.dataset.initialized = 'true';

            const userid = button.dataset.userid;

            button.addEventListener('click', bookitbutton => {
                // eslint-disable-next-line no-console
                console.log('clicked ', bookitbutton.target);

                bookit(itemid, area, userid);
            });
        }
    });
};

/**
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} totalnumberofpages
 */
export const initprepagemodal = (optionid, totalnumberofpages) => {

    // eslint-disable-next-line no-console
    console.log('initprepagemodal', optionid);

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
    // console.log(selector, element);

    var observer = new MutationObserver(function() {
        if (!isHidden(element)) {
            // Todo: Make sure it's not triggered on close.
            callback(optionid);
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
 */
export const loadPreBookingPage = (
    optionid) => {

    // eslint-disable-next-line no-console
    // console.log('loadPreBookingPage ' + optionid, totalnumberofpages);

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
            // (res.json, "template " + res.template);

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
                // console.log(data.data, "template " + template);

                Templates.renderForPromise(template, data.data).then(({html, js}) => {

                    // eslint-disable-next-line no-console
                    // console.log(selector);

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
    // console.log(optionid, currentbookitpage[optionid], totalbookitpages[optionid]);

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
 * Add the click listener to a prepage modal button.
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
    // console.log(element, selector);

    if (element && !element.dataset.prepageinit) {
        element.dataset.prepageinit = true;

        element.addEventListener('click', () => {

            if (element.classList.contains('hidden')) {
                return;
            }

            if (back) {
                currentbookitpage[optionid]--;
            } else {
                currentbookitpage[optionid]++;
            }

            loadPreBookingPage(optionid);
        });
    }
}

/**
 *
 * @param {int} itemid
 * @param {string} area
 * @param {int} userid
 */
function bookit(itemid, area, userid) {

    Ajax.call([{
        methodname: "mod_booking_bookit",
        args: {
            'itemid': itemid,
            'area': area,
            'userid': userid,
        },
        done: function(res) {

            // eslint-disable-next-line no-console
            console.log(res);

            const jsonarray = JSON.parse(res.json);

            // We might have more than one template to render.
            const templates = res.template.split(',');

            // There might be more than one button area.
            const buttons = document.querySelectorAll(SELECTORS.BOOKITBUTTON +
                '[data-itemid=\'' + itemid + '\']' +
                '[data-area=\'' + area + '\']');

            // We run through every button. and render the data.
            buttons.forEach(button => {
                while (button.firstChild) {
                    const child = button.firstChild;
                    child.remove();
                }

                templates.forEach(template => {
                    const data = jsonarray.shift();

                    const datatorender = data.data ?? data;

                    // eslint-disable-next-line no-console
                    console.log(datatorender);

                    Templates.renderForPromise(template, datatorender).then(({html, js}) => {

                        Templates.appendNodeContents(button, html, js);

                        return true;
                        }).catch(ex => {
                            Notification.addNotification({
                                message: 'failed rendering ' + ex,
                                type: "danger"
                            });
                        });

                    return true;
                });
            });
        }
    }]);
}