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
    INMODALBUTTON: 'div.in-modal-button',
    STATICBACKDROP: 'div.modal-backdrop',
};

/**
 * Initializes the bookit button for the normal bookit function.
 * @param {integer} itemid
 * @param {string} area
 */
export const initbookitbutton = (itemid, area) => {

    const selector = SELECTORS.BOOKITBUTTON +
    '[data-itemid="' + itemid + '"]' +
    '[data-area="' + area + '"]';

    const buttons = document.querySelectorAll(selector);

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

            button.addEventListener('click', e => {

                e.stopPropagation();

                // eslint-disable-next-line no-console
                console.log('clicked ', e.target);

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

            const jsonobject = JSON.parse(res.json);

            // We support more than one template, they will be seperated by comma.
            // We have a data key in the json
            const templates = res.template.split(',');
            let dataarray = jsonobject;
            const buttontype = res.buttontype;

            renderTemplatesOnPage(templates, dataarray, selector);

            showRightButton(optionid, buttontype);

            return true;
        },
        fail: function(err) {
            // eslint-disable-next-line no-console
            console.log(err);
        }
    }]);
};

/**
 *
 * @param {string} templates
 * @param {object} dataarray
 * @param {string} selector
 */
async function renderTemplatesOnPage(templates, dataarray, selector) {

    for (const template of templates) {
        // eslint-disable-next-line no-console
        console.log('i render first this template, ', template);

        const data = dataarray.shift();

        if (!data) {
            return true;
        }

        await Templates.renderForPromise(template, data.data).then(({html, js}) => {

            // eslint-disable-next-line no-console
            console.log('no i append this template, ', template);
            Templates.appendNodeContents(selector, html, js);

            return true;
        }).catch(ex => {
            Notification.addNotification({
                message: 'failed rendering ' + ex,
                type: "danger"
            });
        });
    }
    return true;
}

/**
 * Reveal the hidden continue button.
 * @param {interger} optionid
 * @param {interger} buttontype
 */
function showRightButton(optionid, buttontype) {

    // eslint-disable-next-line no-console
    console.log(SELECTORS.MODALID + optionid + ' ' + SELECTORS.INMODALBUTTON);

    // If we are not yet on the last booking page.
    if (currentbookitpage[optionid] + 1 < totalbookitpages[optionid]) {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON);
        if (element) {
            element.classList.remove('hidden');

            if (buttontype == 1) {
                element.classList.add('disabled');
            }
        }

        const inModalButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.INMODALBUTTON);
        if (inModalButton) {
            inModalButton.classList.add('hidden');
        }

    } else {
        // We are on the last booking page.
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON);
        if (element) {
            element.classList.add('hidden');
        }

        if (buttontype == 1) {
            const inModalButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.INMODALBUTTON);
            if (inModalButton) {
                inModalButton.classList.add('hidden');
            }
        } else {
            const inModalButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.INMODALBUTTON);
            if (inModalButton) {
                inModalButton.classList.remove('hidden');
            }
        }
    }
    if (currentbookitpage[optionid] > 0) {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.BACKBUTTON);
        if (element) {
            element.classList.remove('hidden');

            if (buttontype == 1) {
                element.classList.add('disabled');
            }
        }

    } else {
        const element = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.BACKBUTTON);
        if (element) {
            element.classList.add('hidden');
        }
    }

}

/**
 *
 * @param {integer} optionid
 * @param {boolean} show
 */
export function toggleContinueButton(optionid, show = null) {

    const continueButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.CONTINUEBUTTON);

    const bookingButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.BOOKITBUTTON);

    // eslint-disable-next-line no-console
    console.log(bookingButton, optionid, show);

    if (continueButton) {
        disableButton(continueButton, show);
    }
    if (bookingButton) {
        disableButton(bookingButton, show);
    }

    showBookItButton(optionid, show);
}

/**
 *
 * @param {integer} optionid
 * @param {boolean} show
 */
function showBookItButton(optionid, show) {

    // Hide Bookit button.
    const inModalButton = document.querySelector(SELECTORS.MODALID + optionid + ' ' + SELECTORS.INMODALBUTTON);
    if (currentbookitpage[optionid] + 1 == totalbookitpages[optionid]) {
        // Being on the last page.
        if (show) {
            inModalButton.classList.remove('hidden');
        } else {
            inModalButton.classList.add('hidden');
        }
    }
}

/**
 *
 * @param {HTMLElement} element
 * @param {boolean} show
 */
function disableButton(element, show) {

    // eslint-disable-next-line no-console
    console.log(element, show);

    // If show is not defined yet, we define it automatically.
    if (show === null) {
        if (element.classList.contains('disabled')) {
            show = true;
        } else {
            show = false;
        }
    }

    // Now we add or remove the disabled class.
    if (show) {
        element.classList.remove('disabled');
    } else {
        element.classList.add('disabled');
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

        element.addEventListener('click', (e) => {

            e.stopPropagation();

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

                // For every button, we need a new jsonarray.
                const arraytoreduce = [...jsonarray];

                // eslint-disable-next-line no-console
                console.log(templates, arraytoreduce);

                templates.forEach(template => {
                    const data = arraytoreduce.shift();

                    // We need to check if this will render the prepagemodal again.
                    // We never render the prepage modal in the in modal button.
                    if (!(template === 'mod_booking/prepagemodal'
                            && button.parentElement.classList.contains('in-modal-button'))) {

                        // eslint-disable-next-line no-console
                        console.log(template, data, button);

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
                    }
                });
            });
            /* const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);
            const modal = document.querySelector(SELECTORS.MODALID + itemid);
            if (modal) {
                modal.classList.remove('show');
            }
            if (backdrop) {
                backdrop.remove();
            } */
        }
    }]);
}