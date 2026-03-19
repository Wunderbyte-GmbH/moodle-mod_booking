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
 * @module     mod_booking/bookingpage/prepageFooter
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {continueToNextPage, backToPreviousPage, setBackModalVariables} from 'mod_booking/bookit';
import {reloadAllTables} from 'local_wunderbyte_table/reload';

const SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INLINEID: 'sbPrePageInline_',
    INMODALDIV: ' div.modalMainContent',
    INMODALFOOTER: ' div.prepage-booking-footer',
    FOOTERACTIONLINK: '.prepage-booking-footer a',
    INMODALBUTTON: 'div.in-modal-button',
    BOOKITBUTTON: 'div.booking-button-area',
    STATICBACKDROP: 'div.modal-backdrop',
};

var footerbuttonconfig = {};

/**
 * Trigger a Bootstrap-like custom event.
 *
 * @param {HTMLElement} element
 * @param {string} eventName
 */
function dispatchBootstrapEvent(element, eventName) {
    try {
        const event = new CustomEvent(eventName, {bubbles: true, cancelable: true});
        element.dispatchEvent(event);
    } catch (e) {
        // If CustomEvent is not supported (ancient browsers), ignore.
    }
}

/**
 * Hide a modal using the Bootstrap 5 API when available.
 *
 * @param {HTMLElement} modalEl
 * @returns {boolean}
 */
function hideBootstrap5Modal(modalEl) {
    const ModalCtor = window.bootstrap?.Modal;
    if (!ModalCtor) {
        return false;
    }

    let modalInstance = null;
    if (typeof ModalCtor.getOrCreateInstance === 'function') {
        modalInstance = ModalCtor.getOrCreateInstance(modalEl);
    } else if (typeof ModalCtor.getInstance === 'function') {
        modalInstance = ModalCtor.getInstance(modalEl);
    }

    if (!modalInstance || typeof modalInstance.hide !== 'function') {
        return false;
    }

    modalInstance.hide();
    return true;
}

/**
 * Hide a collapse using the Bootstrap 5 API when available.
 *
 * @param {HTMLElement} inlineEl
 * @returns {boolean}
 */
function hideBootstrap5Collapse(inlineEl) {
    const CollapseCtor = window.bootstrap?.Collapse;
    if (!CollapseCtor) {
        return false;
    }

    let collapseInstance = null;
    if (typeof CollapseCtor.getOrCreateInstance === 'function') {
        collapseInstance = CollapseCtor.getOrCreateInstance(inlineEl, {toggle: false});
    } else if (typeof CollapseCtor.getInstance === 'function') {
        collapseInstance = CollapseCtor.getInstance(inlineEl);
        if (!collapseInstance) {
            collapseInstance = new CollapseCtor(inlineEl, {toggle: false});
        }
    }

    if (!collapseInstance || typeof collapseInstance.hide !== 'function') {
        return false;
    }

    collapseInstance.hide();
    return true;
}

/**
 * Sync collapse trigger state after an inline section is hidden.
 *
 * @param {HTMLElement} inlineEl
 */
function updateCollapseControls(inlineEl) {
    if (!inlineEl?.id) {
        return;
    }

    const selector = [
        '[data-bs-target="#' + inlineEl.id + '"]',
        '[data-target="#' + inlineEl.id + '"]',
        'a[href="#' + inlineEl.id + '"]',
    ].join(', ');

    document.querySelectorAll(selector).forEach(control => {
        control.classList.add('collapsed');
        control.setAttribute('aria-expanded', 'false');
    });
}

/**
 * Extract option id from nearest modal/inline prepage container.
 * @param {HTMLElement} element
 * @returns {integer|null}
 */
function getOptionidFromContainer(element) {
    const container = element.closest('[id^="' + SELECTORS.MODALID + '"] , [id^="' + SELECTORS.INLINEID + '"]');
    if (!container || !container.id) {
        return null;
    }

    const matcher = new RegExp('^' + SELECTORS.MODALID + '(\\d+)_|^' + SELECTORS.INLINEID + '(\\d+)_');
    const match = container.id.match(matcher);

    if (!match) {
        return null;
    }

    const optionid = match[1] || match[2];

    if (!optionid) {
        return null;
    }

    return parseInt(optionid, 10);
}

/**
 * Optional shopping cart re-init for actions that may redirect or close views.
 * @param {boolean} shoppingcartisinstalled
 */
function runShoppingCartPreActions(shoppingcartisinstalled) {
    if (!shoppingcartisinstalled) {
        return;
    }

    import('local_shopping_cart/cart')
        .then(module => {
            const cart = module.default ?? module;
            const oncashier = window.location.href.indexOf('cashier.php');

            if (typeof cart.reinit === 'function') {
                if (oncashier > 0) {
                    const params = new URLSearchParams(window.location.search);
                    const userid = params.get('userid') || -1;
                    cart.reinit(userid);
                } else {
                    cart.reinit();
                }
            }

            return null;
        })
        .catch(() => {
            // eslint-disable-next-line no-console
            console.log('local_shopping_cart/cart could not be loaded');
        });
}

/**
 * Register delegated footer listeners once on body.
 */
function registerDelegatedFooterListeners() {
    const container = document.querySelector('body');
    if (!container || container.dataset.prepageFooterDelegated) {
        return;
    }

    container.dataset.prepageFooterDelegated = 'true';

    container.addEventListener('hide.bs.modal', event => {
        const modal = event.target.closest('[id^="' + SELECTORS.MODALID + '"]');
        if (!modal) {
            return;
        }

        const optionid = getOptionidFromContainer(modal);
        if (optionid !== null) {
            setBackModalVariables(optionid);
        }
    });

    container.addEventListener('click', event => {
        const element = event.target.closest(SELECTORS.FOOTERACTIONLINK);
        if (!element) {
            return;
        }

        if (element.classList.contains('hidden') || element.dataset.blocked === 'true') {
            event.preventDefault();
            return;
        }

        const optionid = getOptionidFromContainer(element);
        if (optionid === null) {
            return;
        }

        const action = element.dataset.action;
        const config = footerbuttonconfig[optionid] ?? {};
        const userid = config.userid;
        const shoppingcartisinstalled = !!config.shoppingcartisinstalled;

        event.preventDefault();
        event.stopImmediatePropagation();

        switch (action) {
            case 'closeinline':
            case 'continuepost':
            case 'checkout':
            case 'closemodal':
                runShoppingCartPreActions(shoppingcartisinstalled);
                break;
            default:
                break;
        }

        switch (action) {
            case 'back':
                backToPreviousPage(optionid, userid);
                break;
            case 'continue':
            case 'continuepost':
                continueToNextPage(optionid, userid);
                break;
            case 'checkout':
                closeModal(optionid);
                if (element.dataset.href) {
                    window.location.href = element.dataset.href;
                }
                break;
            case 'closemodal':
                reloadOnBookingView();
                closeModal(optionid);
                break;
            case 'closeinline':
                reloadOnBookingView();
                closeInline(optionid);
                break;
            default:
                break;
        }
    }, true);
}

/**
 * Add the click listener to a prepage modal button.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {boolean} shoppingcartisinstalled
 */
export function initFooterButtons(optionid, userid, shoppingcartisinstalled) {
    footerbuttonconfig[optionid] = {
        userid,
        shoppingcartisinstalled: !!shoppingcartisinstalled,
    };

    registerDelegatedFooterListeners();
}

/**
 * Close bootstrap modal(s) whose id starts with SELECTORS.MODALID + optionid + '_'
 *
 * @param {int} optionid
 * @param {bool} reloadTables
 */
export function closeModal(optionid, reloadTables = true) {
    const modalSelectorAll = '[id^="' + SELECTORS.MODALID + optionid + '_"]';
    const modalEls = Array.from(document.querySelectorAll(modalSelectorAll));

    modalEls.forEach(modalEl => {
        try {
            const onHidden = () => {
                if (reloadTables) {
                    reloadAllTables();
                }
            };

            modalEl.addEventListener('hidden.bs.modal', onHidden, {once: true});

            if (modalEl.classList.contains('show')) {
                if (!hideBootstrap5Modal(modalEl)) {
                    hideModalFallback(modalEl);
                }
            } else {
                onHidden();
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('Error hiding bootstrap modal instance', err);
            hideModalFallback(modalEl);
            if (reloadTables) {
                reloadAllTables();
            }
        }

        // Defensive cleanup in case no bootstrap lifecycle event fires.
        window.setTimeout(cleanupModalArtifacts, 50);
    });
}

/**
 * DOM fallback to hide a modal element and remove backdrop/body state.
 * Also dispatches bootstrap-like events so other listeners get notified.
 *
 *  @param {HTMLElement} modalEl
 */
export function hideModalFallback(modalEl) {
    if (!modalEl) {
        return;
    }

    dispatchBootstrapEvent(modalEl, 'hide.bs.modal');

    // Remove modal "visible" styling.
    modalEl.classList.remove('show');
    modalEl.style.display = 'none';
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.removeAttribute('aria-modal');
    modalEl.removeAttribute('role');

    // Remove modal-open class from body (undo scroll lock).
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');

    // Remove any modal-backdrop elements left behind.
    const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
    backdrops.forEach(backdrop => {
        if (backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
    });

    dispatchBootstrapEvent(modalEl, 'hidden.bs.modal');
}

/**
 * DOM fallback to hide an inline collapse element.
 *
 * @param {HTMLElement} inlineEl
 */
function hideCollapseFallback(inlineEl) {
    if (!inlineEl) {
        return;
    }

    dispatchBootstrapEvent(inlineEl, 'hide.bs.collapse');

    inlineEl.classList.remove('show');
    inlineEl.classList.remove('collapsing');
    inlineEl.classList.add('collapse');
    inlineEl.style.removeProperty('height');
    inlineEl.setAttribute('aria-expanded', 'false');
    updateCollapseControls(inlineEl);

    dispatchBootstrapEvent(inlineEl, 'hidden.bs.collapse');
}

/**
 * Remove stale Bootstrap modal artifacts that can block page scrolling.
 */
function cleanupModalArtifacts() {
    // Keep lock only if another modal is still visible.
    const hasShownModal = !!document.querySelector('.modal.show');
    if (!hasShownModal) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
    backdrops.forEach(backdrop => {
        if (backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
    });
}

/**
 * Close inline collapse area(s) whose id starts with SELECTORS.INLINEID + optionid + '_'
 *
 * @param {int} optionid
 * @param {bool} reloadTables
 */
export function closeInline(optionid, reloadTables = true) {
    const inlineSelectorAll = '[id^="' + SELECTORS.INLINEID + optionid + '_"]';
    const inlineEls = Array.from(document.querySelectorAll(inlineSelectorAll));

    inlineEls.forEach(inlineEl => {
        try {
            const onHidden = () => {
                if (reloadTables) {
                    reloadAllTables();
                }
            };

            inlineEl.addEventListener('hidden.bs.collapse', onHidden, {once: true});

            if (inlineEl.classList.contains('show')) {
                if (!hideBootstrap5Collapse(inlineEl)) {
                    hideCollapseFallback(inlineEl);
                }
            } else {
                onHidden();
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('Error hiding bootstrap collapse instance', err);
            hideCollapseFallback(inlineEl);
            if (reloadTables) {
                reloadAllTables();
            }
        }
    });
}

/**
 * Reload on booking view
 *
 */
function reloadOnBookingView() {
    const onbookondetail = window.location.href.indexOf('optionview.php');

    if (onbookondetail >= 0) {
        window.location.reload();
    }
}
