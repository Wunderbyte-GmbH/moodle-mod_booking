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
 * @author     Georg Maißer
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dynamic edit option form.
 *
 * @module     mod_booking/dynamiceditoptionform
 * @copyright  2023 Wunderbyte GmbH
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import DynamicForm from 'core_form/dynamicform';

const SELECTORS = {
    OPTIONDATELEMENT: '[name="optiondate-element"]',
    DELETEOPTIONDATE: 'deleteoptiondate',
    DELETEOPTIONDATEBUTTON: '[name^="deletedate_"]',
    PAGE: '[id="page"]'
};

/**
 * DynamicForm subclass that preserves the user's view state across the full
 * form re-render every no-submit action (add date, delete date, apply date,
 * change template, ...) triggers: core disables the pressed button (focus falls
 * back to body) and replaces the whole form DOM without restoring scroll or
 * focus, so the viewport ends up at a wrong place and manually opened
 * optiondate cards (plain Bootstrap collapses without server-side state)
 * snap shut.
 */
class EditOptionDynamicForm extends DynamicForm {

    /** @type {?object} View state captured right before a no-submit reload. */
    viewsnapshot = null;

    /**
     * Capture the view state, then let core process the no-submit button.
     *
     * @param {Element} button the no-submit button that was pressed
     */
    processNoSubmitButton(button) {
        this.viewsnapshot = {
            scrolly: window.scrollY,
            buttonname: button.getAttribute('name'),
            opencollapseids: [...this.container.querySelectorAll('.collapse.show[id]')].map(el => el.id),
        };
        super.processNoSubmitButton(button);
    }

    /**
     * Drop a pending snapshot when the reload failed, so a later unrelated
     * reload does not restore stale state.
     *
     * @param {Object} exception
     */
    onSubmitError(exception) {
        this.viewsnapshot = null;
        super.onSubmitError(exception);
    }

    /**
     * Update the form and restore the captured view state afterwards.
     * Reloads without a snapshot (initial load, cancel, validation errors)
     * behave exactly as in core.
     *
     * Note: core's Templates.replaceNodeContents works synchronously and
     * returns the new DOM nodes, NOT a promise. Promise.resolve() keeps this
     * working either way should core ever become asynchronous here.
     *
     * @param {Object} response server response containing html and js
     * @returns {*} whatever core's updateForm returns
     */
    updateForm(response) {
        const snapshot = this.viewsnapshot;
        this.viewsnapshot = null;
        const result = super.updateForm(response);
        if (snapshot) {
            Promise.resolve(result).then(() => {
                this.restoreViewState(snapshot);
                return null;
            }).catch(() => null);
        }
        return result;
    }

    /**
     * Re-open collapsibles that were open before the reload and bring the
     * pressed button back into view. Only elements with stable ids (like the
     * optiondate cards, booking_optiondate_collapse<idx>) can match again;
     * mform section headers get random ids per render but their state is
     * already restored server-side, so they simply never match here.
     *
     * @param {Object} snapshot view state captured in processNoSubmitButton
     */
    restoreViewState(snapshot) {
        snapshot.opencollapseids.forEach(id => {
            const collapse = document.getElementById(id);
            if (collapse && !collapse.classList.contains('show')) {
                collapse.classList.add('show');
                const escaped = CSS.escape(id);
                const toggles = document.querySelectorAll(
                    `[data-target="#${escaped}"], [data-bs-target="#${escaped}"], [href="#${escaped}"]`
                );
                toggles.forEach(toggle => {
                    toggle.classList.remove('collapsed');
                    toggle.setAttribute('aria-expanded', 'true');
                });
            }
        });

        // Prefer anchoring on the pressed button (names are stable across
        // renders, element ids are not because of data-random-ids). Fall back
        // to the raw scroll position if the button is gone or has no box
        // (e.g. the d-none trigger buttons behind select changes).
        const button = snapshot.buttonname
            ? this.container.querySelector(`[name="${CSS.escape(snapshot.buttonname)}"]`)
            : null;
        if (button && button.getClientRects().length > 0) {
            button.scrollIntoView({block: 'center'});
            button.focus({preventScroll: true});
            this.keepAnchored(button);
        } else {
            window.scrollTo(0, snapshot.scrolly);
        }
    }

    /**
     * Keep the given element vertically centered while the freshly replaced
     * form settles: late module inits (autocomplete enhancement swaps huge
     * multi-selects for compact tag inputs, editors initialise, ...) shift
     * content above the anchor by over a thousand pixels for seconds after the
     * DOM replace. Total page height can stay roughly constant meanwhile
     * (content below grows while content above shrinks), so we poll the
     * element's own viewport position instead of observing page height.
     * Stops as soon as the user interacts or after a few seconds.
     *
     * @param {Element} element the element to keep in view
     */
    keepAnchored(element) {
        const interactionevents = ['wheel', 'touchstart', 'keydown', 'mousedown'];
        const timer = setInterval(() => {
            if (!element.isConnected || element.getClientRects().length === 0) {
                return;
            }
            const rect = element.getBoundingClientRect();
            const offset = (rect.top + rect.height / 2) - (window.innerHeight / 2);
            if (Math.abs(offset) > 40) {
                element.scrollIntoView({block: 'center'});
            }
        }, 150);
        const stop = () => {
            clearInterval(timer);
            interactionevents.forEach(type => window.removeEventListener(type, stop));
        };
        interactionevents.forEach(type => window.addEventListener(type, stop, {passive: true}));
        setTimeout(stop, 5000);
    }
}

export const init = (cmid, id, optionid, bookingid, copyoptionid, returnurl) => {
    // Initialize the form - pass the container element and the form class name.

    // eslint-disable-next-line no-console
    console.log('params: ', cmid, id, optionid, bookingid, copyoptionid, returnurl);

    const element = document.querySelector('#editoptionsformcontainer');

    // eslint-disable-next-line no-console
    console.log(element);
    const dynamicForm = new EditOptionDynamicForm(element, 'mod_booking\\form\\option_form');

    // eslint-disable-next-line no-console
    console.log(dynamicForm);
    // By default the form is removed from the DOM after it is submitted, you may want to change this behavior:
    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;

        if (response.returnurl && response.returnurl.length > 0) {
            window.location.href = response.returnurl;
        }

        // eslint-disable-next-line no-console
        console.log(response);
        // It is recommended to reload the form after submission because the elements may change.
        // This will also remove previous submission errors. You will need to pass the same arguments to the form
        // that you passed when you rendered the form on the page.
        dynamicForm.load(e.detail);
    });

    dynamicForm.addEventListener(dynamicForm.events.FORM_CANCELLED, (e) => {
        e.preventDefault();

        if (returnurl && returnurl.length > 0) {
            window.location.href = returnurl;
        } else {
            // Just in case we have no returnurl.
            dynamicForm.load(
                {
                    cmid: cmid,
                    id: id,
                    optionid: optionid,
                    bookingid: bookingid,
                    copyoptionid: copyoptionid,
                    returnurl: returnurl
                }
            );
        }
    });
    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, () => {
        showInvalidFeedback();
        // eslint-disable-next-line no-console
        console.log('validation error');
    });

    dynamicForm.addEventListener(dynamicForm.events.CLIENT_VALIDATION_ERROR, () => {
        showInvalidFeedback();
        // eslint-disable-next-line no-console
        console.log('validation error');
    });

    const checkbox1 = document.querySelector('[type="checkbox"][name="restrictanswerperiodopening"]');
    const checkbox2 = document.querySelector('[type="checkbox"][name="restrictanswerperiodclosing"]');
    const conditionalCheckbox = document.querySelector('[type="checkbox"][name="bo_cond_booking_time_sqlfiltercheck"]');
    let closest = null;
    if (conditionalCheckbox) {
        // Support both Moodle 4.5 (Bootstrap 4) and 5.1 (Bootstrap 5)
        closest = conditionalCheckbox.closest(
            '[class^="form-group row"],' + // Moodle 4.5.
            '[class*="fitem"],' + // Moodle legacy.
            '[data-fieldtype]' // Moodle 5.1 form field wrapper.
        );
    }

    dynamicForm.addEventListener('change', e => {
        // eslint-disable-next-line no-console
        console.log(e);

        if (e.target.name == 'optiontemplateid') {
            window.skipClientValidation = true;
            let button = document.querySelector('[name="btn_changetemplate"]');
            dynamicForm.processNoSubmitButton(button);
        }

        if (e.target.name == 'optiontype') {
            window.skipClientValidation = true;
            // Synchronize selflearningcourse hidden field with optiontype selection.
            // MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE = 1

            let selflearningField = document.querySelector('[name="selflearningcourse"]');
            if (selflearningField) {
                selflearningField.value = (e.target.value == 1) ? 1 : 0;
            }
            let button = document.querySelector('[name="btn_optiontype"]');
            dynamicForm.processNoSubmitButton(button);
        }

        if (e.target.name == 'slot_type') {
            window.skipClientValidation = true;
            let button = document.querySelector('[name="btn_slot_type"]');
            dynamicForm.processNoSubmitButton(button);
        }

        if (e.target.name == 'restrictanswerperiodopening' || e.target.name == 'restrictanswerperiodclosing') {
            hidecheckbox(checkbox1, checkbox2, closest, conditionalCheckbox, true);

        }
    });
    hidecheckbox(checkbox1, checkbox2, closest, conditionalCheckbox, false);


    const page = document.querySelector(SELECTORS.PAGE);

    if (page) {

        page.addEventListener('click', e => {

            const element = e.target;

            // eslint-disable-next-line no-console
            console.log('target', element);

            if (element.classList.contains(SELECTORS.DELETEOPTIONDATE)) {

                const container = element.closest(SELECTORS.OPTIONDATELEMENT);

                // eslint-disable-next-line no-console
                console.log('container', container, container.querySelector('.bg-white'));

                if (container) {

                    const card = container.querySelector('.bg-white');
                    if (card) {

                        // eslint-disable-next-line no-console
                        console.log('card', card);

                        card.classList.remove('bg-white');
                        card.classList.add('bg-danger');
                    }

                    const deletebutton = container.querySelector(SELECTORS.DELETEOPTIONDATEBUTTON);
                    if (deletebutton) {

                        // eslint-disable-next-line no-console
                        console.log('deletebutton', deletebutton);

                        deletebutton.click();
                    }
                }
            }
        });
    }

    const optiondateelements = document.querySelectorAll(SELECTORS.OPTIONDATELEMENT);

    // eslint-disable-next-line no-console
    console.log(optiondateelements);
};

/**
 * Hide the given checkbox.
 * @param {mixed} checkbox1
 * @param {mixed} checkbox2
 * @param {mixed} closest
 * @param {mixed} conditionalCheckbox
 * @param {boolean} withelse
 */
function hidecheckbox(checkbox1, checkbox2, closest, conditionalCheckbox, withelse) {
    if (closest === null) {
        return;
    }
    if (!checkbox1.checked && !checkbox2.checked) {
        conditionalCheckbox.value = "";
        conditionalCheckbox.checked = false;
        closest.style.display = "none";
    } else if (withelse) {
        closest.style.display = "";
    }
}

/**
 * Show invalide feedback. Go through closest elements and open them.
 *
 *
 */
function showInvalidFeedback() {

    // Select all div elements with both 'form-control-feedback' and 'invalid-feedback' classes.
    const elements = document.querySelectorAll('.invalid-feedback');
    // Filter to keep only those that have non-empty content.
    const nonEmptyElements = Array.from(elements).filter(element => element.textContent.trim() !== '');

    // eslint-disable-next-line no-console
    console.log(nonEmptyElements);

    const container = document.querySelector('#editoptionsformcontainer');

    nonEmptyElements.forEach((element) => {
        let currentElement = element;

        while (currentElement && currentElement !== container) {
            currentElement = currentElement.parentElement;

            if (currentElement && currentElement.classList.contains('collapse')) {
                currentElement.classList.add('show');
            }
        }
    });
    if (nonEmptyElements.length > 0) {
        let firstelement = nonEmptyElements[0];
        firstelement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }
}
