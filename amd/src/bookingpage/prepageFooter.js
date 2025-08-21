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
import { continueToNextPage, backToPreviousPage, setBackModalVariables } from 'mod_booking/bookit';
import { reloadAllTables } from 'local_wunderbyte_table/reload';

const SELECTORS = {
    MODALID: 'sbPrePageModal_',
    INLINEID: 'sbPrePageInline_',
    INMODALDIV: ' div.modalMainContent',
    INMODALFOOTER: ' div.prepage-booking-footer',
    INMODALBUTTON: 'div.in-modal-button',
    BOOKITBUTTON: 'div.booking-button-area',
    STATICBACKDROP: 'div.modal-backdrop',
};

/**
 * Add the click listener to a prepage modal button.
 * @param {integer} optionid
 * @param {integer} userid
 * @param {boolean} shoppingcartisinstalled
 */
export function initFooterButtons(optionid, userid, shoppingcartisinstalled) {

    // eslint-disable-next-line no-console
    console.log('initFooterButtons', optionid);

    // Find inline footer anchors first (inline mode).
    let selectorInline = '[id^="' + SELECTORS.INLINEID + optionid + '_"]' + SELECTORS.INMODALFOOTER + ' a';
    let elements = Array.from(document.querySelectorAll(selectorInline));

    if (elements.length === 0) {
        // fallback: try modal-based
        let selectorModal = '[id^="' + SELECTORS.MODALID + optionid + '_"]' + SELECTORS.INMODALFOOTER + ' a';
        elements = Array.from(document.querySelectorAll(selectorModal));

        // Every time we close the modal, reset to the first prepage.
        const modalSelectorAll = '[id^="' + SELECTORS.MODALID + optionid + '_"]';
        const modalEls = Array.from(document.querySelectorAll(modalSelectorAll));
        modalEls.forEach(modalEl => {
            // listen to hide.bs.modal
            modalEl.addEventListener('hide.bs.modal', () => {
                setBackModalVariables(optionid);
            });
        });
    }

    // eslint-disable-next-line no-console
    console.log('buttons found', elements);

    elements.forEach(element => {
        if (!element || element.dataset.initialized) {
            return;
        }
        // Mark initialized
        element.dataset.initialized = '1';

        const action = element.dataset.action;

        // eslint-disable-next-line no-console
        console.log(element, action);

        // Pre-actions executed immediately for some actions (shopping cart reinit).
        switch (action) {
            case 'closeinline':
            case 'continuepost':
            case 'checkout':
                // eslint-disable-next-line no-console
                console.log('closeinline/checkout/continuepost', action);
                if (shoppingcartisinstalled) {
                    // dynamic import of cart module — adapt to module export shape.
                    import('local_shopping_cart/cart')
                        .then(module => {
                            // module may export default or named exports; support both.
                            const cart = module.default ?? module;
                            // eslint-disable-next-line no-console
                            console.log('cart module loaded', cart);
                            const oncashier = window.location.href.indexOf('cashier.php');
                            if (typeof cart.reinit === 'function') {
                                if (oncashier > 0) {
                                    cart.reinit(-1);
                                } else {
                                    cart.reinit();
                                }
                            }
                        })
                        .catch(() => {
                            // eslint-disable-next-line no-console
                            console.log('local_shopping_cart/cart could not be loaded');
                        });
                }
                // ensure collapse hide handler will reload tables
                listenToCloseInline(optionid);
                break;
            default:
                // nothing immediate
                break;
        }

        // attach click listener
        element.addEventListener('click', (evt) => {
            // If hidden by class 'hidden' ignore
            if (element.classList.contains('hidden')) {
                return;
            }

            // The logic might be blocked because eg a form is there to prevent it.
            if (element.dataset.blocked === 'true') {
                return;
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
                    // noop
                    break;
            }
        });
    });
}

/**
 * Close bootstrap modal(s) whose id starts with SELECTORS.MODALID + optionid + '_'
 *
 * @param {int} optionid
 * @param {bool} reloadTables
 */
export function closeModal(optionid, reloadTables = true) {
    if (typeof window.bootstrap === 'undefined') {
        // eslint-disable-next-line no-console
        console.warn('Bootstrap JS is not available. Cannot programmatically hide modals.');
    }

    const modalSelectorAll = '[id^="' + SELECTORS.MODALID + optionid + '_"]';
    const modalEls = Array.from(document.querySelectorAll(modalSelectorAll));

    modalEls.forEach(modalEl => {
        // Attach a one-time shown.bs.modal listener to hide after shown (like original).
        const onShown = (e) => {
            modalEl.removeEventListener('shown.bs.modal', onShown);
            // eslint-disable-next-line no-console
            console.log('modal hide after shown', e);

            try {
                let modalInstance = window.bootstrap?.Modal.getInstance(modalEl) ?? null;
                if (!modalInstance && typeof window.bootstrap !== 'undefined') {
                    // create without showing (do not toggle)
                    modalInstance = new window.bootstrap.Modal(modalEl);
                }
                if (modalInstance) {
                    modalInstance.hide();
                } else {
                    // fallback: remove 'show' class and backdrop if present
                    modalEl.classList.remove('show');
                }
            } catch (err) {
                // eslint-disable-next-line no-console
                console.warn('Error hiding bootstrap modal instance', err);
            }

            if (reloadTables) {
                reloadAllTables();
            }
        };

        modalEl.addEventListener('shown.bs.modal', onShown);

        // Now try to hide it immediately as well.
        try {
            let modalInstance = window.bootstrap?.Modal.getInstance(modalEl) ?? null;
            if (!modalInstance && typeof window.bootstrap !== 'undefined') {
                modalInstance = new window.bootstrap.Modal(modalEl);
            }
            if (modalInstance) {
                modalInstance.hide();
            } else {
                modalEl.classList.remove('show');
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('Error hiding bootstrap modal instance (immediate)', err);
        }

        if (reloadTables) {
            reloadAllTables();
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
        const onShown = (e) => {
            inlineEl.removeEventListener('shown.bs.collapse', onShown);
            // eslint-disable-next-line no-console
            console.log('collapse hide after shown', e);

            try {
                let collapseInstance = window.bootstrap?.Collapse.getInstance(inlineEl) ?? null;
                if (!collapseInstance && typeof window.bootstrap !== 'undefined') {
                    collapseInstance = new window.bootstrap.Collapse(inlineEl, { toggle: false });
                }
                if (collapseInstance) {
                    // toggle will hide it if shown
                    collapseInstance.toggle();
                } else {
                    // fallback toggle: toggle class 'show'
                    inlineEl.classList.toggle('show');
                }
            } catch (err) {
                // eslint-disable-next-line no-console
                console.warn('Error toggling bootstrap collapse instance', err);
            }

            if (reloadTables) {
                reloadAllTables();
            }
        };

        inlineEl.addEventListener('shown.bs.collapse', onShown);

        // Now trigger hide/toggle immediately as well.
        try {
            let collapseInstance = window.bootstrap?.Collapse.getInstance(inlineEl) ?? null;
            if (!collapseInstance && typeof window.bootstrap !== 'undefined') {
                collapseInstance = new window.bootstrap.Collapse(inlineEl, { toggle: false });
            }
            if (collapseInstance) {
                collapseInstance.toggle();
            } else {
                inlineEl.classList.toggle('show');
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('Error toggling bootstrap collapse instance (immediate)', err);
        }

        if (reloadTables) {
            reloadAllTables();
        }
    });
}

/**
 * Attach listeners so that hiding inline collapse triggers a table reload.
 *
 * @param {int} optionid
 */
function listenToCloseInline(optionid) {
    const inlineSelectorAll = '[id^="' + SELECTORS.INLINEID + optionid + '_"]';
    const inlineEls = Array.from(document.querySelectorAll(inlineSelectorAll));

    inlineEls.forEach(inlineEl => {
        inlineEl.addEventListener('hide.bs.collapse', () => {
            reloadAllTables();
        });
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
