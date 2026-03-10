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
 * @module     mod_booking/teacherUnavailability
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/**
 * Init dynamic teacher unavailability form.
 *
 * @param {string} containerId
 */
export const init = async(containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const args = {
        id: Number(container.dataset.id || 0),
        optionid: Number(container.dataset.optionid || 0),
        teacherid: Number(container.dataset.teacherid || 0),
        date: Number(container.dataset.date || 0),
    };
    const reportUrl = container.dataset.reporturl || '';

    const form = new DynamicForm(container, 'mod_booking\\form\\teacherunavailability_form');

    form.addEventListener(form.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        const response = e.detail || {};
        await form.load(args);

        if (response.saved === true) {
            getString('allchangessaved', 'mod_booking').then((message) => {
                Notification.addNotification({
                    message,
                    type: 'success'
                });
                return;
            }).catch(() => {
                Notification.addNotification({
                    message: 'Saved',
                    type: 'success'
                });
            });
        }
    });

    container.addEventListener('click', (event) => {
        const trashIcon = event.target.closest('.deleteoptiondate');
        if (trashIcon) {
            event.preventDefault();
            event.stopPropagation();

            const itemContainer = trashIcon.closest('[name="optiondate-element"]');
            const deleteButton = itemContainer
                ? itemContainer.querySelector('input[type="submit"][data-action^="deleteunavailability_"]')
                : null;
            if (deleteButton) {
                window.skipClientValidation = true;
                form.processNoSubmitButton(deleteButton);
                window.setTimeout(() => {
                    window.skipClientValidation = false;
                }, 0);
            }
            return;
        }

        const button = event.target.closest('input[type="submit"][data-action^="deleteunavailability_"]');
        if (button) {
            event.preventDefault();
            window.skipClientValidation = true;
            form.processNoSubmitButton(button);
            window.setTimeout(() => {
                window.skipClientValidation = false;
            }, 0);
        }
    });

    form.addEventListener(form.events.FORM_CANCELLED, (event) => {
        event.preventDefault();
        if (reportUrl) {
            window.location.href = reportUrl;
            return;
        }
        form.load(args);
    });

    form.addEventListener(form.events.SERVER_VALIDATION_ERROR, () => {
        showInvalidFeedback(container);
    });

    form.addEventListener(form.events.CLIENT_VALIDATION_ERROR, () => {
        showInvalidFeedback(container);
    });


/**
 * Open collapsible sections that contain invalid feedback.
 *
 * @param {HTMLElement} container
 */
function showInvalidFeedback(container) {
    const elements = document.querySelectorAll('.invalid-feedback');
    const nonEmptyElements = Array.from(elements).filter((element) => element.textContent.trim() !== '');

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
        nonEmptyElements[0].scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }
}
};
