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
 * @module     mod_booking/condition/bookingPolicy
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Notification from 'core/notification';

const SELECTOR = {
    FORMCONTAINER: '.condition-customform',
    PREPAGEBODY: '.prepage-body',
    CONTINUECONTAINER: ' div.prepage-booking-footer .continue-container',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    BOOKINGBUTTON: '[data-area="subbooking"][data-itemid="',
};

/**
 * Init function.
 */
export async function init() {

    let container = document.querySelector("div.modal.show " + SELECTOR.FORMCONTAINER);

    // If we don't find the container like this, we use the inline form.
    if (!container) {
        const containers = document.querySelectorAll("div.prepage-body " + SELECTOR.FORMCONTAINER);
        containers.forEach(el => {
            if (!isHidden(el)) {
                container = el;
            }
        });

        if (!container) {
            return;
        }
    }

    // eslint-disable-next-line no-console
    console.log("container", container);

    const id = container.dataset.id;
    const userid = container.dataset.userid;

    const dynamicForm = new DynamicForm(container, 'mod_booking\\form\\condition\\customform_form');

    const prepagebody = container.closest(SELECTOR.PREPAGEBODY);

    // Fail closed: continuing is only possible after the form was submitted successfully.
    // The gate state lives on the prepage body, so a re-init of this page (e.g. after
    // back navigation re-rendered the form) replaces the state instead of stacking
    // another listener with a stale form reference.
    prepagebody.customformgate = {
        dynamicForm,
        formsubmitted: false,
        loadfailed: false,
    };

    // The continue button lives in the prepage footer, which may render before OR after
    // this init runs. A delegated listener on the prepage body catches the click in both
    // cases: in the bubbling phase it runs before the footer's own body-level listener
    // (button -> prepage body -> body), so it can stop an unsubmitted continue reliably.
    if (!prepagebody.dataset.customformgateattached) {
        prepagebody.dataset.customformgateattached = 'true';
        prepagebody.addEventListener('click', e => {
            const gate = prepagebody.customformgate;
            if (!gate || gate.formsubmitted) {
                return;
            }
            const button = e.target.closest(SELECTOR.CONTINUEBUTTON);
            if (!button) {
                return;
            }
            // The form was not submitted yet, so the click must not reach the footer handler.
            e.preventDefault();
            e.stopImmediatePropagation();
            // eslint-disable-next-line no-console
            console.log('Continue button click blocked because form is not validated yet.');
            if (!gate.loadfailed && gate.dynamicForm.getFormNode()) {
                gate.dynamicForm.submitFormAjax();
            }
        });
    }

    // Additionally stamp the button as blocked for the footer's own dataset check.
    const blockContinueButton = () => {
        const continuebutton = prepagebody.querySelector(SELECTOR.CONTINUEBUTTON);
        if (continuebutton && !continuebutton.dataset.blocked) {
            continuebutton.dataset.blocked = true;
        }
    };

    blockContinueButton();

    try {
        // We need to render the dynamic form right away, so we can acutally have all the necessary elements present.
        await dynamicForm.load({
            id,
            userid,
        });
    } catch (err) {
        // Without the form, continuing must stay impossible (fail closed) - but the
        // user has to see why nothing happens.
        prepagebody.customformgate.loadfailed = true;
        blockContinueButton();
        Notification.exception(err);
        return;
    }
    // The footer (and with it the continue button) might only have been rendered
    // while the form was loading.
    blockContinueButton();

    // Delegated listener: clear field on click if it still holds its initial value.
    container.addEventListener('click', e => {
        const input = e.target.closest('input[data-initial-value]');
        if (!input) {
            return;
        }

        // Only shorttext fields should be auto-cleared on first click.
        if (
            !input.name
            || (
                !input.name.startsWith('customform_shorttext_')
                && !input.name.startsWith('customform_url_')
            )
        ) {
            return;
        }

        if (input.value !== '' && input.value === input.dataset.initialValue) {
            input.value = '';
        }
    });

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {

        const response = e.detail;

        // eslint-disable-next-line no-console
        console.log("response", response);

        if (response) {

            prepagebody.customformgate.formsubmitted = true;

            const continuebutton = prepagebody.querySelector(SELECTOR.CONTINUEBUTTON);
            if (continuebutton) {

                continuebutton.dataset.blocked = 'false';
                continuebutton.click();
            }
        }
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