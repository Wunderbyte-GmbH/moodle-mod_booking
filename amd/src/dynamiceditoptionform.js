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


export const init = (cmid, id, optionid, bookingid, copyoptionid, returnurl) => {
    // Initialize the form - pass the container element and the form class name.

    // eslint-disable-next-line no-console
    console.log('params: ', cmid, id, optionid, bookingid, copyoptionid, returnurl);

    const element = document.querySelector('#editoptionsformcontainer');

    // eslint-disable-next-line no-console
    console.log(element);
    const dynamicForm = new DynamicForm(element, 'mod_booking\\form\\option_form');

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

    var checkbox1 = document.querySelector('[type="checkbox"][name="restrictanswerperiodopening"]');
    var checkbox2 = document.querySelector('[type="checkbox"][name="restrictanswerperiodclosing"]');
    var conditionalCheckbox = document.querySelector('[type="checkbox"][name="bo_cond_booking_time_sqlfiltercheck"]');
    let closest = conditionalCheckbox.closest('[class^="form-group row"],[class*=" fitem"]');

    dynamicForm.addEventListener('change', e => {
        // eslint-disable-next-line no-console
        console.log(e);

        if (e.target.name == 'optiontemplateid') {
            window.skipClientValidation = true;
            let button = document.querySelector('[name="btn_changetemplate"]');
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
