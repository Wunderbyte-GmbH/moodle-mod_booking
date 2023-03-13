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

import {reloadAllTables} from 'local_wunderbyte_table/reload';

var currentbookitpage = {};
var totalbookitpages = {};

var SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INMODALDIV: ' div.modalMainContent',
    MODALHEADER: 'div.modalHeader',
    MODALBUTTONAREA: 'div.modalButtonArea',
    MODALFOOTER: 'div.modalFooter',
    CONTINUEBUTTON: 'a.continue-button', // Don't want to find button right now.
    BACKBUTTON: 'a.back-button', // Don't want to find button right now.
    BOOKITBUTTON: 'div.booking-button-area.noprice',
    INMODALBUTTON: 'div.in-modal-button',
    STATICBACKDROP: 'div.modal-backdrop',
};

/**
 * Initializes the bookit button for the normal bookit function.
 * @param {integer} itemid
 * @param {string} area
 */
export const initbookitbutton = (itemid, area) => {

    const initselector = SELECTORS.BOOKITBUTTON +
    '[data-itemid]' +
    '[data-area]';

    if (!itemid || !area) {
        const initbuttons = document.querySelectorAll(initselector);
        initbuttons.forEach(button => {
            const inititemid = button.dataset.itemid;
            const initarea = button.dataset.area;
            initbookitbutton(inititemid, initarea);
        });
        return;
    }

    const selector = SELECTORS.BOOKITBUTTON +
    '[data-itemid="' + itemid + '"]' +
    '[data-area="' + area + '"]';

    const buttons = document.querySelectorAll(selector);

    if (!buttons) {
        return;
    }

    // We support more than one booking button on the same page.
    buttons.forEach(button => {

        if (button.dataset.nojs) {
            return;
        }

        if (!button.dataset.initialized) {
            button.dataset.initialized = 'true';

            const userid = button.dataset.userid;

            button.addEventListener('click', (e) => {

                // E.stopPropagation();

                if (e.target.classList.contains('btn')) {
                    bookit(itemid, area, userid);
                }
            });
        }
    });
};

/**
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {integer} totalnumberofpages
 * @param {string} uniquid
 */
export const initprepagemodal = (optionid, userid, totalnumberofpages, uniquid) => {

    // eslint-disable-next-line no-console
    console.log('initprepagemodal', optionid, userid, totalnumberofpages, uniquid);

    if (!optionid || !uniquid || !totalnumberofpages) {

        const elements = document.querySelectorAll("[id^=" + SELECTORS.MODALID);

        elements.forEach(element => {
            optionid = element.dataset.optionid;
            uniquid = element.dataset.uniquid;
            userid = element.dataset.userid;
            totalnumberofpages = element.dataset.pages;
            if (optionid && uniquid) {
                initprepagemodal(optionid, userid, totalnumberofpages, uniquid);
            }
        });
        return;
    }

    currentbookitpage[optionid] = 0;
    totalbookitpages[optionid] = totalnumberofpages;

    // We need to get all prepage modals on this site. Make sure they are initialized.

    respondToVisibility(optionid, userid, uniquid, totalnumberofpages, loadPreBookingPage);
};

/**
 * React on visibility change.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {string} uniquid
 * @param {integer} totalnumberofpages
 * @param {function} callback
 */
function respondToVisibility(optionid, userid, uniquid, totalnumberofpages, callback) {

    // eslint-disable-next-line no-console
    console.log('respondToVisibility', optionid, totalnumberofpages, uniquid);

    let elements = document.querySelectorAll("[id^=" + SELECTORS.MODALID + optionid + "_" + uniquid + "]");

    // eslint-disable-next-line no-console
    console.log('elements', "[id^=" + SELECTORS.MODALID + optionid + "_" + uniquid + "]", elements);

    elements.forEach(element => {
        if (!element || element.dataset.initialized == 'true') {
            return;
        }

        element.dataset.initialized = true;

        var observer = new MutationObserver(function() {
            if (!isHidden(element)) {
                // Todo: Make sure it's not triggered on close.
                callback(optionid, userid, uniquid, totalnumberofpages);
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
        callback(optionid, userid, uniquid, totalnumberofpages);
    });
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
 * @param {integer} userid
 * @param {string} uniquid
 */
export const loadPreBookingPage = (
    optionid, userid = 0, uniquid = '') => {

    // eslint-disable-next-line no-console
    console.log('loadPreBookingPage', optionid, uniquid, userid);

    const element = returnVisibleElement(optionid, uniquid, SELECTORS.INMODALDIV);
    if (element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    } else {
        // eslint-disable-next-line no-console
        console.error('didnt find element');
    }

    Ajax.call([{
        methodname: "mod_booking_load_pre_booking_page",
        args: {
            optionid,
            userid,
            'pagenumber': currentbookitpage[optionid],
        },
        done: function(res) {

            // eslint-disable-next-line no-console
            console.log(currentbookitpage[optionid], totalbookitpages[optionid]);

            // If we are on the last page, we reset it to 0.
            if (currentbookitpage[optionid] === totalbookitpages[optionid] - 1) {
                currentbookitpage[optionid] = 0;
            }

            const jsonobject = JSON.parse(res.json);

            // We support more than one template, they will be seperated by comma.
            // We have a data key in the json
            const templates = res.template.split(',');
            let dataarray = jsonobject;
            // Const buttontype = res.buttontype;

            renderTemplatesOnPage(templates, dataarray, element);

            // ShowRightButton(optionid, buttontype);

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
 * @param {HTMLElement} element
 */
async function renderTemplatesOnPage(templates, dataarray, element) {

    // eslint-disable-next-line no-console
    console.log('templates: ', templates);

    const modal = element.closest('.modal-body');

    modal.querySelector(SELECTORS.MODALHEADER).innerHTML = '';
    modal.querySelector(SELECTORS.INMODALDIV).innerHTML = '';
    modal.querySelector(SELECTORS.MODALBUTTONAREA).innerHTML = '';
    modal.querySelector(SELECTORS.MODALFOOTER).innerHTML = '';

    templates.forEach(async template => {

        const data = dataarray.shift();

        let targetelement = element;

        if (!data) {
            return true;
        }

        switch (template) {
            case 'mod_booking/bookingpage/header':
                targetelement = modal.querySelector(SELECTORS.MODALHEADER);
                break;
            case 'mod_booking/bookingoption_description_prepagemodal_bookit':
                targetelement = modal.querySelector(SELECTORS.INMODALDIV);
                break;
            case 'mod_booking/bookit_button':
            case 'mod_booking/bookit_price':
                targetelement = modal.querySelector(SELECTORS.MODALBUTTONAREA);
                break;
            case 'mod_booking/bookingpage/footer':
                targetelement = modal.querySelector(SELECTORS.MODALFOOTER);
                break;
        }

        await Templates.renderForPromise(template, data.data).then(({html, js}) => {

            // eslint-disable-next-line no-console
            console.log('targetelement: ', targetelement);

            Templates.replaceNodeContents(targetelement, html, js);

            return true;
        }).catch(ex => {
            Notification.addNotification({
                message: 'failed rendering ' + ex,
                type: "danger"
            });
        });
        return true;
    });
    return true;
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

            const jsonarray = JSON.parse(res.json);

            // We might have more than one template to render.
            const templates = res.template.split(',');

            // There might be more than one button area.
            const buttons = document.querySelectorAll(SELECTORS.BOOKITBUTTON +
                '[data-itemid=\'' + itemid + '\']' +
                '[data-area=\'' + area + '\']');

            const promises = [];

            // eslint-disable-next-line no-console
            console.log(buttons);

            // We run through every button. and render the data.
            buttons.forEach(button => {

                // For every button, we need a new jsonarray.
                const arraytoreduce = [...jsonarray];

                templates.forEach(template => {
                    const data = arraytoreduce.shift();

                    // We need to check if this will render the prepagemodal again.
                    // We never render the prepage modal in the in modal button.
                    if (!(template === 'mod_booking/bookingpage/prepagemodal'
                            && button.parentElement.classList.contains('in-modal-button'))) {

                        const datatorender = data.data ?? data;

                        const promise = Templates.renderForPromise(template, datatorender).then(({html, js}) => {

                            Templates.replaceNode(button, html, js);

                            return true;
                        }).catch(ex => {
                            Notification.addNotification({
                                message: 'failed rendering ' + ex,
                                type: "danger"
                            });
                        });

                        promises.push(promise);
                    }
                });
            });

            Promise.all(promises).then(() => {

                const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);

                if (!backdrop) {
                    reloadAllTables();
                }

                // The actions on successful booking are executed elsewhere.
                return true;
            }).catch(e => {
                // eslint-disable-next-line no-console
                console.log(e);
            });
        }
    }]);
}

/**
 * We want to find out the visible modal
 * @param {integer} optionid
 * @param {string} uniquid
 * @param {string} appendedSelector
 * @returns {HTMLElement}
 */
function returnVisibleElement(optionid, uniquid, appendedSelector) {

    // First, we get all the possbile Elements (we don't now the unique id appended to the element.)
    let selector = "[id^=" + SELECTORS.MODALID + optionid + "_" + uniquid + "] " + appendedSelector;
    if (!uniquid || uniquid.length === 0) {
        selector = "[id^=" + SELECTORS.MODALID + optionid + "].show " + appendedSelector;
    }

    const elements = document.querySelectorAll(selector);

    let visibleElement = null;

    elements.forEach(element => {

        // eslint-disable-next-line no-console
        console.log('visibleElement', selector, element);

        visibleElement = element;
    });

    return visibleElement;
}

/**
 * Load next prepage booking page.
 * @param {int} optionid
 * @param {int} userid
 */
export function continueToNextPage(optionid, userid) {

    currentbookitpage[optionid]++;

    loadPreBookingPage(optionid, userid);
}

/**
 *  Load previous prepage booking page.
 * @param {int} optionid
 * @param {int} userid
 */
export function backToPreviousPage(optionid, userid) {

    currentbookitpage[optionid]--;

    loadPreBookingPage(optionid, userid);
}