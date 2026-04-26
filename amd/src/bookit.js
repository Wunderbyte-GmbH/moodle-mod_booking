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
import { reloadAllTables } from 'local_wunderbyte_table/reload';

import { closeModal, closeInline } from 'mod_booking/bookingpage/prepageFooter';

var currentbookitpage = {};
var totalbookitpages = {};
var inlineprepageconfig = {};
/** @type {Object.<number, string>} Maps optionid → condition shortname to skip in load_pre_booking_page calls. */
var skipconditions = {};
const SLOTBOOKING_REFRESH_EVENT = 'mod_booking:slotbooking-refresh';

const dispatchSlotbookingRefresh = (optionid, userid = 0, area = 'option') => {
    document.dispatchEvent(new CustomEvent(SLOTBOOKING_REFRESH_EVENT, {
        detail: {
            optionid: Number(optionid || 0),
            userid: Number(userid || 0),
            area,
        },
    }));
};

/**
 * Registers one delegated listener for bootstrap modal show events.
 */
const registerPrepageModalDelegatedListener = () => {
    const container = document.querySelector('body');
    if (!container || container.dataset.prepageModalDelegated) {
        return;
    }

    container.dataset.prepageModalDelegated = 'true';

    container.addEventListener('shown.bs.modal', event => {

        const modal = event.target.closest('[id^="' + SELECTORS.MODALID + '"]');
        if (!modal) {
            return;
        }

        if (modal.querySelector('[data-action="bookondetail"]')) {
            return;
        }

        const optionid = modal.dataset.optionid;
        const userid = modal.dataset.userid;
        const uniquid = modal.dataset.uniquid;
        const totalnumberofpages = modal.dataset.pages;

        if (!optionid || !uniquid || !totalnumberofpages) {
            return;
        }

        currentbookitpage[optionid] = 0;
        totalbookitpages[optionid] = totalnumberofpages;

        // Read skipcondition from modal data attribute or from module-level state.
        const skipcondition = modal.dataset.skipcondition || skipconditions[optionid] || '';
        loadPreBookingPage(optionid, userid, uniquid, skipcondition);
    });
};

/**
 * Gets inline prepage config for an option from memory or DOM.
 * @param {integer} optionid
 * @param {integer} userid
 * @returns {object|null}
 */
const getInlinePrepageConfig = (optionid, userid = 0) => {
    if (inlineprepageconfig[optionid]) {
        return inlineprepageconfig[optionid];
    }

    const inlinecontainer = document.querySelector('[id^="' + SELECTORS.INLINEID + optionid + '_"]');
    if (!inlinecontainer) {
        return null;
    }

    const uniquid = inlinecontainer.dataset.uniquid;
    const pages = inlinecontainer.dataset.pages;
    const inlineuserid = inlinecontainer.dataset.userid || userid;

    if (!uniquid) {
        return null;
    }

    currentbookitpage[optionid] = 0;
    if (pages) {
        totalbookitpages[optionid] = pages;
    }

    inlineprepageconfig[optionid] = {
        userid: inlineuserid,
        uniquid,
    };

    return inlineprepageconfig[optionid];
};

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
 * React on visibility change. Bootstrap 4 compatibility.
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

        var observer = new MutationObserver(function () {

            if (!isHidden(element)) {

                // Because of the modal animation, "isHIdden" is also true on hiding modal.
                if (element.classList.contains('show')) {

                    // Todo: Make sure it's not triggered on close.
                    callback(optionid, userid, uniquid);
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

                observer.observe(element, { attributes: true });
                element.dataset.observed = true;
                return;
            }
        }
        callback(optionid, userid, uniquid);
    });
}

export var SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INLINEID: 'sbPrePageInline_',
    INMODALDIV: ' div.modalMainContent',
    MODALHEADER: 'div.modalHeader',
    MODALBUTTONAREA: 'div.modalButtonArea',
    MODALFOOTER: 'div.modalFooter',
    CONTINUEBUTTON: 'a.continue-button',
    BACKBUTTON: 'a.back-button',
    BOOKITBUTTON_NOPRICE: 'div.booking-button-area.noprice',
    BOOKITBUTTON_SHOPPINGCART: 'div.booking-button-area.wb_shopping_cart',
    BOOKITBUTTON: 'div.booking-button-area.noprice, div.booking-button-area.wb_shopping_cart',
    BOOKITBUTTON_WITH_DATA:
        'div.booking-button-area.noprice[data-itemid][data-area], ' +
        'div.booking-button-area.wb_shopping_cart[data-itemid][data-area]',
    INMODALBUTTON: 'div.in-modal-button',
    STATICBACKDROP: 'div.modal-backdrop',
};

/**
 * Build selector for matching a bookit button by item and area.
 *
 * @param {int|string} itemid
 * @param {string} area
 * @returns {string}
 */
const getBookitButtonByItemAreaSelector = (itemid, area) => {
    return `${SELECTORS.BOOKITBUTTON_NOPRICE}[data-itemid='${itemid}'][data-area='${area}'], ` +
        `${SELECTORS.BOOKITBUTTON_SHOPPINGCART}[data-itemid='${itemid}'][data-area='${area}']`;
};

/**
 * Build selector for matching visible modal bookit button by item and area.
 *
 * @param {int|string} itemid
 * @param {string} area
 * @returns {string}
 */
const getVisibleModalBookitButtonSelector = (itemid, area) => {
    return `[id^='${SELECTORS.MODALID}'].show ${SELECTORS.BOOKITBUTTON_NOPRICE}[data-itemid='${itemid}'][data-area='${area}'], ` +
        `[id^='${SELECTORS.MODALID}'].show ${SELECTORS.BOOKITBUTTON_SHOPPINGCART}[data-itemid='${itemid}'][data-area='${area}']`;
};

/**
 * Resolve a stricter replace target for rendered button markup.
 *
 * Some responses render the full outer booking-button-area wrapper. In that case,
 * replacing only the inner shopping-cart button would create nested wrappers.
 * We only climb when the DOM matches the exact wrapper chain we expect.
 *
 * @param {?HTMLElement} targetbutton
 * @returns {?HTMLElement}
 */
const getReplaceTargetButton = targetbutton => {
    if (!targetbutton) {
        return targetbutton;
    }

    const addtocartarea = targetbutton.parentElement;
    if (!addtocartarea || !addtocartarea.matches('div.bookit-addtocartbtn-area')) {
        return targetbutton;
    }

    const pricecontainer = addtocartarea.parentElement;
    if (!pricecontainer || !pricecontainer.matches('div.pricecontainer.mb-2.w-100')) {
        return targetbutton;
    }

    const outerbuttonarea = pricecontainer.parentElement;
    if (!outerbuttonarea || !outerbuttonarea.matches(
        'div.booking-button-area.w-100.d-flex.justify-content-center[data-itemid][data-area][data-componentname="mod_booking"]'
    )) {
        return targetbutton;
    }

    if (
        outerbuttonarea.dataset.itemid !== targetbutton.dataset.itemid
        || outerbuttonarea.dataset.area !== targetbutton.dataset.area
        || outerbuttonarea.dataset.userid !== targetbutton.dataset.userid
    ) {
        return targetbutton;
    }

    return outerbuttonarea;
};

/**
 * Initializes delegated bookit button handling.
 */
export const initbookitbutton = () => {

    const container = document.querySelector('body'); // Or a closer wrapper if you know it.
    if (!container) {
        return;
    }

    const bootstrapVersion = detectBootstrapVersion();

    // Intercept all cancel clicks in capture phase before bootstrap modal handlers fire.
    // Two distinct cancel types are handled here:
    //   .shopping-cart-cancel-button → show shopping-cart confirmation dialog, do NOT call bookit
    //   .bo-cancel-button             → call bookit directly (normal booking cancel)
    if (!container.dataset.bookitCancelCaptureDelegated) {
        container.dataset.bookitCancelCaptureDelegated = 'true';

        window.addEventListener('click', (e) => {

            const shoppingCartCancelButton = e.target.closest('.shopping-cart-cancel-button');
            const cancelButton = e.target.closest('.bo-cancel-button');

            // Not a cancel click at all — let other handlers deal with it.
            if (!shoppingCartCancelButton && !cancelButton) {
                return;
            }

            // Stop propagation immediately — we own all cancel clicks regardless of DOM structure.
            // This must happen before the container lookup so that Bootstrap's modal data-api
            // listeners cannot fire even when the cancel button lives outside BOOKITBUTTON_WITH_DATA.
            e.preventDefault();
            e.stopImmediatePropagation();
            e.stopPropagation();

            if (shoppingCartCancelButton) {
                // Shopping-cart cancel: the cancel button's direct parent booking-button-area
                // carries componentname and other data attributes needed by confirmCancelModal.
                const button = shoppingCartCancelButton.closest(
                    'div.booking-button-area[data-componentname][data-itemid][data-area]'
                );
                if (!button || button.classList.contains('disabled')) {
                    return;
                }
                import('local_shopping_cart/shistory')
                    .then(shoppingcart => {
                        shoppingcart.confirmCancelModal(button, 0);
                        return true;
                    })
                    .catch(err => {
                        // eslint-disable-next-line no-console
                        console.error(err);
                    });
                return;
            }

            // Normal booking cancel: strip overrideids and call bookit.
            const button = cancelButton.closest('div.booking-button-area[data-itemid][data-area]');
            if (!button || button.classList.contains('disabled')) {
                return;
            }
            const { itemid, area, userid } = button.dataset;
            const cancelData = { ...button.dataset };
            delete cancelData.overrideids;
            const inModal = !!button.closest('[id^="' + SELECTORS.MODALID + '"], [id^="' + SELECTORS.INLINEID + '"]');
            bookit(itemid, area, userid, cancelData, inModal);
        }, true);
    }

    // Add one event listener only once
    if (!container.dataset.bookitDelegated) {
        container.dataset.bookitDelegated = 'true';

        // Bootstrap 5: use bubble phase (false) to respect stopImmediatePropagation from capture phase
        // Bootstrap 4: use capture phase (true) for proper event handling
        const useCapture = bootstrapVersion === 5 ? false : true;

        container.addEventListener('click', (e) => {

            // All cancel clicks are fully handled in the capture phase above.
            if (e.target.closest('.bo-cancel-button') || e.target.closest('.shopping-cart-cancel-button')) {
                return;
            }

            const button = e.target.closest(SELECTORS.BOOKITBUTTON_WITH_DATA);
            if (!button) {
                return;
            }

            const bookTarget = e.target.closest('.btn');

            // Ignore disabled buttons
            if (button.classList.contains('disabled')) {
                return;
            }

            if (button.dataset.nojs == 1) {
                return;
            }

            const { itemid, area, userid } = button.dataset;

            if (bookTarget) {
                if (!bookTarget.href || bookTarget.href.length < 2) {
                    const inModal = !!button.closest('[id^="' + SELECTORS.MODALID + '"], [id^="' + SELECTORS.INLINEID + '"]');
                    const buttonData = { ...button.dataset };
                    bookit(itemid, area, userid, buttonData, inModal);
                }
            }
        }, useCapture);
    }
};

/**
 *
 * @param {int} itemid
 * @param {string} area
 * @param {int} userid
 * @param {object} data
 * @param {?boolean} clickedFromModal
 */
export function bookit(itemid, area, userid, data, clickedFromModal = null) {

    const modalSelector = '[id^="' + SELECTORS.MODALID + '"], [id^="' + SELECTORS.INLINEID + '"]';
    let resolvedClickedFromModal = clickedFromModal;

    if (typeof resolvedClickedFromModal !== 'boolean') {
        const activeElement = document.activeElement;
        const activeButton = activeElement?.closest(
            getBookitButtonByItemAreaSelector(itemid, area)
        );

        if (activeButton) {
            resolvedClickedFromModal = !!activeButton.closest(modalSelector);
        } else {
            const visibleModalButton = document.querySelector(
                getVisibleModalBookitButtonSelector(itemid, area)
            );
            resolvedClickedFromModal = !!visibleModalButton;
        }
    }

    Ajax.call([{
        methodname: "mod_booking_bookit",
        args: {
            'itemid': itemid,
            'area': area,
            'userid': userid,
            'data': JSON.stringify(data),
        },
        done: function (res) {

            var skipreload = false;

            if (document.querySelector('.booking-elective-component')) {
                window.location.reload();
            }

            const jsonarray = JSON.parse(res.json);

            // We might have more than one template to render.
            const templates = res.template.split(',');

            // There might be more than one button area.
            const buttons = document.querySelectorAll(getBookitButtonByItemAreaSelector(itemid, area));

            const promises = [];

            // We run through every button. and render the data.
            buttons.forEach(button => {
                // Filter buttons based on whether they're in a modal context
                const buttonInModal = !!button.closest('[id^="' + SELECTORS.MODALID + '"], [id^="' + SELECTORS.INLINEID + '"]');
                if (resolvedClickedFromModal && !buttonInModal) {
                    // Skip buttons outside modal when click came from modal
                    return;
                }
                if (!resolvedClickedFromModal && buttonInModal) {
                    // Skip buttons inside modal when click came from outside
                    return;
                }

                skipreload = true;
                if (button.dataset.nojs == 1
                    && res.status == 0
                    && 1 == 2) {
                    // eslint-disable-next-line no-console
                    console.log('bookit skip', button.dataset.nojs, res.status);
                } else {
                    // For every button, we need a new jsonarray.
                    const arraytoreduce = [...jsonarray];
                    if (res.status == 1) {
                        skipreload = false;
                    }

                    const originalbutton = button;

                    const replaceButtonNode = (targetbutton, html, js = '') => {
                        const replacetarget = getReplaceTargetButton(targetbutton);
                        if (!replacetarget) {
                            return;
                        }
                        Templates.replaceNode(replacetarget, html, js);
                        return;
                    };

                    templates.forEach(template => {

                        const data = arraytoreduce.shift();
                        const shortHash = Math.random().toString(36).slice(2, 7);
                        const datatorender = data.data ?? data;

                        if (
                            template === "mod_booking/bookingpage/prepagemodal"
                            || template === "mod_booking/bookingpage/prepageinline"
                        ) {
                            if (resolvedClickedFromModal) {
                                // For clicks inside modal content, update that modal button directly.
                                button = originalbutton;
                            } else {
                                button = button.closest('div[data-bs-toggle="modal"]')
                                    ?? button.closest('div[data-bs-toggle="collapse"]');
                            }
                            datatorender.uniquid = shortHash;

                            if (button && !resolvedClickedFromModal) {
                                const targetmodalid = button.dataset.bsTarget?.replace('#', '');
                                if (targetmodalid) {
                                    const targetmodal = document.getElementById(targetmodalid);
                                    if (targetmodal) {
                                        targetmodal.remove();
                                    }
                                }
                            }
                        } else {
                            button = originalbutton;
                        }

                        // For modal clicks, use buttonhtml if available; otherwise use template rendering
                        if (resolvedClickedFromModal && datatorender.buttonhtml) {
                            const promise = Promise.resolve().then(() => {
                                let html = datatorender.buttonhtml;
                                html = html.replaceAll('nojs="1"', 'nojs="0"');
                                replaceButtonNode(button, html);
                                return true;
                            }).catch(ex => {
                                Notification.addNotification({
                                    message: 'failed rendering ' + ex,
                                    type: "danger"
                                });
                            });
                            promises.push(promise);
                        } else {
                            const promise = Templates.renderForPromise(template, datatorender).then(({ html, js }) => {

                                // Here, we might need to replace the parent node instead of button.

                                replaceButtonNode(button, html, js);

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
                }
            });

            Promise.all(promises).then(() => {
                if (resolvedClickedFromModal) {
                    buttons.forEach(button => {
                        const buttonInModal = !!button.closest(
                            '[id^="' + SELECTORS.MODALID + '"],[id^="' + SELECTORS.INLINEID + '"]'
                        );
                        buttonInModal.dataset.nojs = 0;
                    });
                }
                const backdrop = document.querySelector(SELECTORS.STATICBACKDROP);

                if (area === 'subbooking') {
                    skipreload = true;
                } else {
                    if (currentbookitpage[itemid] < totalbookitpages[itemid]) {
                        skipreload = true;
                    }
                }

                if (!skipreload && (!backdrop || resolvedClickedFromModal)) {
                    reloadAllTables();
                }

                if (Number(res.status || 0) === 1) {
                    dispatchSlotbookingRefresh(itemid, userid, area);
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
 * Detects Bootstrap version being used.
 * @returns {number} 4 for Bootstrap 4, 5 for Bootstrap 5
 */
const detectBootstrapVersion = () => {
    // Bootstrap 5 uses window.bootstrap namespace
    if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal) {
        return 5;
    }
    // Default to Bootstrap 4 if we can't confirm Bootstrap 5
    return 4;
};

/**
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {integer} totalnumberofpages
 * @param {string} uniquid
 */
export const initprepagemodal = (optionid, userid, totalnumberofpages, uniquid) => {

    if (!optionid || !uniquid || !totalnumberofpages) {

        const elements = document.querySelectorAll("[id^=" + SELECTORS.MODALID);

        elements.forEach(element => {

            if (element.querySelector('[data-action="bookondetail"]')) {
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

    const bootstrapVersion = detectBootstrapVersion();

    // Bootstrap 5: Use event listener approach
    if (bootstrapVersion === 5) {
        registerPrepageModalDelegatedListener();
    } else {
        // Bootstrap 4: Use MutationObserver approach
        respondToVisibility(optionid, userid, uniquid, totalnumberofpages, loadPreBookingPage);
    }
};

/**
 * Gets called from mustache template.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {integer} totalnumberofpages
 * @param {string} uniquid
 */
export const initprepageinline = (optionid, userid, totalnumberofpages, uniquid) => {

    const isinlineprepage = document.querySelector('.inlineprepagearea');
    if (!isinlineprepage) {
        return;
    }

    if (optionid && totalnumberofpages) {
        currentbookitpage[optionid] = 0;
        totalbookitpages[optionid] = totalnumberofpages;
    }

    if (optionid && uniquid) {
        inlineprepageconfig[optionid] = {
            userid,
            uniquid,
        };
    }

    const container = document.querySelector('body');
    if (!container) {
        return;
    }

    if (!container.dataset.prepageInlineDelegated) {
        container.dataset.prepageInlineDelegated = 'true';

        container.addEventListener('click', e => {
            const button = e.target.closest(
                SELECTORS.BOOKITBUTTON +
                '[data-itemid]' +
                '[data-area="option"]'
            );

            if (!button) {
                return;
            }

            if (button.querySelector('[data-action="bookondetail"]')) {
                return;
            }

            const optionid = button.dataset.itemid;
            const config = getInlinePrepageConfig(optionid, button.dataset.userid);

            if (!config || !config.uniquid) {
                return;
            }

            // Get the row element.
            const rowcontainer = button.closest('.mod-booking-row');
            if (!rowcontainer || !rowcontainer.lastElementChild) {
                return;
            }

            const transferarea = !rowcontainer.lastElementChild.classList.contains('inlineprepagearea');
            // We move the inlineprepagearea only if we need to.
            if (transferarea) {
                const inlinediv = returnVisibleElement(optionid, config.uniquid, SELECTORS.INMODALDIV);
                if (!inlinediv) {
                    return;
                }

                rowcontainer.append(inlinediv.closest('.inlineprepagearea'));
                // Inlinediv.remove();

                // We need to get all prepage modals on this site. Make sure they are initialized.
                loadPreBookingPage(optionid, config.userid, config.uniquid);
            }
        });
    }
};

/**
 * Loads the (next) pre booking page.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {string} uniquid
 * @param {string} skipcondition optional condition shortname to exclude from the sorted pages
 */
export const loadPreBookingPage = (
    optionid, userid = 0, uniquid = '', skipcondition = null) => {

    // If skipcondition not explicitly provided, fall back to module-level state.
    const actualSkipcondition = skipcondition !== null ? skipcondition : (skipconditions[optionid] || '');

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
        done: function (response) {
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
                        'skipcondition': actualSkipcondition,
                    },
                    done: function (res) {
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
                    fail: function (err) {
                        // eslint-disable-next-line no-console
                        console.log(err);
                    }
                }]);
            } else {

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
        fail: function (err) {
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
    const refreshContainer = element.closest('[id^="' + SELECTORS.MODALID + '"], [id^="' + SELECTORS.INLINEID + '"]');
    const refreshOptionid = Number(refreshContainer?.dataset.optionid || 0);
    const refreshUserid = Number(refreshContainer?.dataset.userid || 0);

    // We need to pass the id of our element to the templates to render.
    // If not, we might select the wrong modal or collapsible.
    let elementid = modal.id;

    if (!elementid) {
        const parent = modal.closest('[id]');
        elementid = parent.id;
    }

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

        await Templates.renderForPromise(template, data.data).then(({ html, js }) => {

            if (counter < 1) {
                counter++;
                Templates.replaceNodeContents(targetelement, html, js);
            } else {
                Templates.appendNodeContents(targetelement, html, js);
            }

            if (template === 'mod_booking/condition/confirmation' && refreshOptionid > 0) {
                window.setTimeout(() => {
                    dispatchSlotbookingRefresh(refreshOptionid, refreshUserid);
                }, 0);
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

/**
 * Initialises the inline-start prepage area (rendered server-side).
 *
 * The condition (e.g. slotbooking) is already visible on the page.  When the user
 * clicks "Continue", the remaining prepage pages are shown in the standard
 * Bootstrap modal or inline collapse (depending on site configuration).
 *
 * @param {number} optionid
 * @param {number} userid
 * @param {string} skipcondition condition shortname shown inline (e.g. 'slotbooking')
 * @param {number} remainingpages number of prepage pages still to be shown after the inline one
 * @param {string} remaininguniqid uniquid of the remaining pages modal/collapse element
 * @param {boolean} useinline true = remaining pages use inline collapse; false = modal
 */
export const initprepageinlinestart = (optionid, userid, skipcondition, remainingpages, remaininguniqid, useinline) => {

    // Persist skipcondition so subsequent loadPreBookingPage calls carry it automatically.
    if (skipcondition) {
        skipconditions[optionid] = skipcondition;
    }

    if (!remainingpages || remainingpages <= 0) {
        // Nothing more to show after the inline condition.
        return;
    }

    currentbookitpage[optionid] = 0;
    totalbookitpages[optionid] = remainingpages;

    if (useinline && remaininguniqid) {
        inlineprepageconfig[optionid] = {userid, uniquid: remaininguniqid};
    }

    // Register a single delegated click listener for all inline-start continue buttons.
    const body = document.querySelector('body');
    if (!body || body.dataset.inlinestartContinueDelegated) {
        return;
    }
    body.dataset.inlinestartContinueDelegated = 'true';

    body.addEventListener('click', e => {
        const btn = e.target.closest('.inlinestart-continue-btn');
        if (!btn) {
            return;
        }

        if (btn.dataset.blocked === 'true') {
            e.preventDefault();
            return;
        }

        const btnOptionid = parseInt(btn.dataset.optionid, 10);
        const btnUserid = parseInt(btn.dataset.userid, 10);
        const btnSkipcondition = btn.dataset.skipcondition || '';
        const btnRemainingpages = parseInt(btn.dataset.remainingpages, 10);
        const btnRemaininguniqid = btn.dataset.remaininguniqid || '';
        const btnUseinline = btn.dataset.useinline === '1';

        // Persist skipcondition for subsequent navigation inside the remaining pages.
        if (btnSkipcondition) {
            skipconditions[btnOptionid] = btnSkipcondition;
        }

        // Update page-count state for the remaining flow.
        currentbookitpage[btnOptionid] = 0;
        totalbookitpages[btnOptionid] = btnRemainingpages;

        if (btnUseinline) {
            if (btnRemaininguniqid) {
                inlineprepageconfig[btnOptionid] = {userid: btnUserid, uniquid: btnRemaininguniqid};
            }

            const inlineEl = document.getElementById(SELECTORS.INLINEID + btnOptionid + '_' + btnRemaininguniqid);
            if (!inlineEl) {
                return;
            }

            const onShown = () => {
                loadPreBookingPage(btnOptionid, btnUserid, btnRemaininguniqid, btnSkipcondition);
            };

            const CollapseCtor = window.bootstrap && window.bootstrap.Collapse;
            if (CollapseCtor) {
                inlineEl.addEventListener('shown.bs.collapse', onShown, {once: true});
                CollapseCtor.getOrCreateInstance(inlineEl).show();
            } else {
                // Bootstrap 4 fallback – open collapse manually then load.
                inlineEl.classList.add('show');
                onShown();
            }
        } else {
            // Modal mode – the shown.bs.modal handler (registerPrepageModalDelegatedListener)
            // will pick up skipconditions[optionid] when loading the first page.
            const modalEl = document.getElementById(SELECTORS.MODALID + btnOptionid + '_' + btnRemaininguniqid);
            if (!modalEl) {
                return;
            }

            if (btnSkipcondition) {
                modalEl.dataset.skipcondition = btnSkipcondition;
            }

            const bootstrapVersion = detectBootstrapVersion();
            if (bootstrapVersion === 5) {
                registerPrepageModalDelegatedListener();
                const ModalCtor = window.bootstrap.Modal;
                ModalCtor.getOrCreateInstance(modalEl).show();
            } else {
                // Bootstrap 4: trigger via MutationObserver already registered by initprepagemodal.
                respondToVisibility(
                    btnOptionid,
                    btnUserid,
                    btnRemaininguniqid,
                    btnRemainingpages,
                    (oid, uid, uid2) => {
                        loadPreBookingPage(oid, uid, uid2);
                    }
                );
                // Trigger modal open via attribute-based approach.
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
            }
        }
    });
};
