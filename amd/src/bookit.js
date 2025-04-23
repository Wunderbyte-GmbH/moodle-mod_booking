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

import {closeModal, closeInline} from 'mod_booking/bookingpage/prepageFooter';

var currentbookitpage = {};
var totalbookitpages = {};

export var SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INLINEID: 'sbPrePageInline_',
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

        // We don't run code on disabled buttons.
        if (button.classList.contains('disabled')) {
            return;
        }

        if (!button.dataset.initialized) {
            button.dataset.initialized = 'true';

            const userid = button.dataset.userid;

            button.addEventListener('click', (e) => {

                // E.stopPropagation();

                const data = button.dataset;

                if (e.target.classList.contains('shopping-cart-cancel-button')) {

                    import('local_shopping_cart/shistory')
                    // eslint-disable-next-line promise/always-return
                    .then(shoppingcart => {
                        const confirmCancelModal = shoppingcart.confirmCancelModal;
                        // Now you can use the specific function
                        confirmCancelModal(button, 0);
                    })
                    .catch(err => {
                        // Handle any errors, including if the module doesn't exist
                        // eslint-disable-next-line no-console
                        console.log(err);
                    });


                } else if (
                    e.target.classList.contains('btn')
                ) {
                    if (!e.target.href || e.target.href.length < 2) {
                        bookit(itemid, area, userid, data);
                    }
                }
            });
        }
    });
};

/**
 *
 * @param {int} itemid
 * @param {string} area
 * @param {int} userid
 * @param {object} data
 */
export function bookit(itemid, area, userid, data) {

    // eslint-disable-next-line no-console
    console.log('run bookit');

    Ajax.call([{
        methodname: "mod_booking_bookit",
        args: {
            'itemid': itemid,
            'area': area,
            'userid': userid,
            'data': JSON.stringify(data),
        },
        done: function(res) {

            var skipreload = false;

            if (document.querySelector('.booking-elective-component')) {
                window.location.reload();
            }

            const jsonarray = JSON.parse(res.json);

            // We might have more than one template to render.
            const templates = res.template.split(',');

            // There might be more than one button area.
            const buttons = document.querySelectorAll(SELECTORS.BOOKITBUTTON +
                '[data-itemid=\'' + itemid + '\']' +
                '[data-area=\'' + area + '\']');

            const promises = [];

            // We run through every button. and render the data.
            buttons.forEach(button => {

                // eslint-disable-next-line no-console
                console.log('bookit values', button.dataset.nojs, res.status);
                skipreload = true;
                if (button.dataset.nojs == 1
                    && res.status == 0) {
                    // eslint-disable-next-line no-console
                    console.log('bookit skip', button.dataset.nojs, res.status);
                } else {
                    // For every button, we need a new jsonarray.
                    const arraytoreduce = [...jsonarray];
                    if (res.status == 1) {
                        skipreload = false;
                    }
                    templates.forEach(template => {

                        const data = arraytoreduce.shift();

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
                    });
                }
            });

            Promise.all(promises).then(() => {

                const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);

                if (area === 'subbooking') {
                    skipreload = true;
                } else {
                    if (currentbookitpage[itemid] < totalbookitpages[itemid]) {
                        skipreload = true;
                    }
                }

                // eslint-disable-next-line no-console
                console.log('skipreload', skipreload, currentbookitpage[itemid], totalbookitpages[itemid]);

                if (!backdrop && !skipreload) {
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

            if (element.querySelector('[data-action="bookondetail"]')) {
                // eslint-disable-next-line no-console
                console.log('bookondetail abort');
                return;
            }

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
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {integer} totalnumberofpages
 * @param {string} uniquid
 */
export const initprepageinline = (optionid, userid, totalnumberofpages, uniquid) => {

    // eslint-disable-next-line no-console
    console.log('initprepageinline', optionid, userid, totalnumberofpages, uniquid);

    if (!optionid || !uniquid || !totalnumberofpages) {

        const elements = document.querySelectorAll("[id^=" + SELECTORS.INLINEID);

        // eslint-disable-next-line no-console
        console.log(elements);

        elements.forEach(element => {
            optionid = element.dataset.optionid;
            uniquid = element.dataset.uniquid;
            userid = element.dataset.userid;
            totalnumberofpages = element.dataset.pages;
            if (optionid && uniquid) {
                initprepageinline(optionid, userid, totalnumberofpages, uniquid);
            }
        });
        return;
    }

    currentbookitpage[optionid] = 0;
    totalbookitpages[optionid] = totalnumberofpages;

    // Retrieve the right button.
    const buttons = document.querySelectorAll(
        '[data-itemid="' + optionid + '"]' +
        '[data-area="option"]'
    );

    // Add the click listener to the button.

    buttons.forEach(button => {

        if (button.dataset.initialized) {
            return;
        }

        button.dataset.initialized = true;

        // eslint-disable-next-line no-console
        console.log('add listener to button', button, button.dataset.action);

        if (button.querySelector('[data-action="bookondetail"]')) {
            // eslint-disable-next-line no-console
            console.log('bookondetail abort');
            return;
        }

        button.addEventListener('click', e => {

            // eslint-disable-next-line no-console
            console.log('e.target', e.target);

            // Get the row element.
            let rowcontainer = e.target.closest('.mod-booking-row');

            const transferarea = !rowcontainer.lastElementChild.classList.contains('inlineprepagearea');
            // We move the inlineprepagearea only if we need to.
            if (transferarea) {
                let inlinediv = returnVisibleElement(optionid, uniquid, SELECTORS.INMODALDIV);

                rowcontainer.append(inlinediv.closest('.inlineprepagearea'));
                // Inlinediv.remove();

                // We need to get all prepage modals on this site. Make sure they are initialized.
                loadPreBookingPage(optionid, userid, uniquid);
            }
        });
    });
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

    let elements = document.querySelectorAll("[id^=" + SELECTORS.MODALID + optionid + "_" + uniquid + "]");

    elements.forEach(element => {

        if (!element || element.dataset.initialized == 'true') {
            return;
        }

        element.dataset.initialized = true;

        var observer = new MutationObserver(function() {

            if (!isHidden(element)) {

                // Because of the modal animation, "isHIdden" is also true on hiding modal.
                if (element.classList.contains('show')) {

                    // Todo: Make sure it's not triggered on close.
                    callback(optionid, userid, uniquid, totalnumberofpages);
                }
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
        methodname: "mod_booking_allow_add_item_to_cart",
        args: {
            'itemid': optionid,
            'userid': userid,
        },
        done: function(response) {
            // Will always be 1, if shopping cart is not installed!
            if (response.success == 1
                || response.success == 5 // Already booked, we need this for subbokings.
                || response.success == 0 // Already in cart, we need this for subbokings.
            ) {
                Ajax.call([{
                    methodname: "mod_booking_load_pre_booking_page",
                    args: {
                        optionid,
                        userid,
                        'pagenumber': currentbookitpage[optionid],
                    },
                    done: function(res) {
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
                    },
                    fail: function(err) {
                        // eslint-disable-next-line no-console
                        console.log(err);
                    }
                }]);
            } else {

                // eslint-disable-next-line no-console
                console.log('closeModal');
                closeModal(optionid, false);
                closeInline(optionid, false);

                // Make sure that the prepage modal is actually closed.

                import('local_shopping_cart/cart')
                    // eslint-disable-next-line promise/always-return
                    .then(shoppingcart => {
                        const addItemShowNotification = shoppingcart.addItemShowNotification;
                        // Now you can use the specific function
                        response.userid = userid;
                        addItemShowNotification(response);
                    })
                    .catch(err => {
                        // Handle any errors, including if the module doesn't exist
                        // eslint-disable-next-line no-console
                        console.log(err);
                });
            }

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

    const modal = element.closest('.prepage-body');

    // We need to pass the id of our element to the templates to render.
    // If not, we might select the wrong modal or collapsible.
    let elementid = modal.id;

    if (!elementid) {
        const parent = modal.closest('[id]');
        elementid = parent.id;
    }

    // eslint-disable-next-line no-console
    console.log(modal, elementid);

    modal.querySelector(SELECTORS.MODALHEADER).innerHTML = '';
    modal.querySelector(SELECTORS.INMODALDIV).innerHTML = '';
    modal.querySelector(SELECTORS.MODALBUTTONAREA).innerHTML = '';
    modal.querySelector(SELECTORS.MODALFOOTER).innerHTML = '';

    var counter = 0;
    templates.forEach(async template => {

        const data = dataarray.shift();

        let targetelement = element;

        if (!data) {
            return true;
        }

        data.data.elementid = elementid;

        switch (template) {
            case 'mod_booking/bookingpage/header':
                targetelement = modal.querySelector(SELECTORS.MODALHEADER);
                break;
            case 'mod_booking/bookit_button':
            case 'mod_booking/bookit_price':
                targetelement = modal.querySelector(SELECTORS.MODALBUTTONAREA);
                break;
            case 'mod_booking/bookingpage/footer':
                targetelement = modal.querySelector(SELECTORS.MODALFOOTER);
                break;
            default:
                targetelement = modal.querySelector(SELECTORS.INMODALDIV);
                break;
        }

        // eslint-disable-next-line no-console
        console.log(data.data);

        await Templates.renderForPromise(template, data.data).then(({html, js}) => {

            if (counter < 1) {
                counter++;
                Templates.replaceNodeContents(targetelement, html, js);
            } else {
                Templates.appendNodeContents(targetelement, html, js);
            }
            return true;
        }).catch(ex => {
            Notification.addNotification({
                message: 'failed rendering ' + JSON.stringify(ex),
                type: "danger"
            });
        });
        return true;
    });
    return true;
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
    let selector = '[id^="' + SELECTORS.MODALID + optionid + '_' + uniquid + '"] ' + appendedSelector;
    if (!uniquid || uniquid.length === 0) {
        selector = '[id^="' + SELECTORS.MODALID + optionid + '_"].show ' + appendedSelector;
    }

    let elements = document.querySelectorAll(selector);

    // If the nodelist is empty, we we mgiht use inline.
    // If so, we need to have a different way of selecting elements.
    if (elements.length === 0) {

        selector = '[id^="' + SELECTORS.INLINEID + optionid + '_"] ' + appendedSelector;
        elements = document.querySelectorAll(selector);

    }

    let visibleElement = null;

    elements.forEach(element => {

        var elementtocheck = element.parentElement.parentElement;

        // We look if we find a hidden parent. If not, we load right away.
        while (elementtocheck !== null) {
            if (!isHidden(elementtocheck)) {
                elementtocheck = elementtocheck.parentElement;

            } else {

                break;
            }
        }
        // If after the while, we have still an element, it's hidden.
        // So we only apply visible if it's null.
        if (!elementtocheck) {
            visibleElement = element;
        }
    });

    return visibleElement;
}

/**
 * Load next prepage booking page.
 * @param {int} optionid
 * @param {int} userid
 */
export function continueToNextPage(optionid, userid) {

    // eslint-disable-next-line no-console
    console.log('continueToNextPage', optionid, userid, currentbookitpage[optionid], totalbookitpages[optionid]);
    if (currentbookitpage[optionid] < totalbookitpages[optionid]) {
        currentbookitpage[optionid]++;
        loadPreBookingPage(optionid, userid);
    }
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

/**
 *  Set back variables used in modal.
 *  @param {int} optionid
 */
export function setBackModalVariables(optionid) {

    currentbookitpage[optionid] = 0;
}
