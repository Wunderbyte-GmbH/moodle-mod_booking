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
 * Javascript controller for booking module
 *
 * @module mod_booking/view_actions
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since 3.1
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {init as initStarRating} from 'mod_booking/starrating';

const REPORTPAGE = '#page-mod-booking-report';

let reportListenersRegistered = false;

/**
 * Turn every rating select of the options overview into a star widget.
 *
 * A pick is sent to the server; the widget then shows the stored rating and is locked, because a
 * rating cannot be changed afterwards. Selects that already carry a widget are skipped, so this is
 * safe to run again after the table was reloaded.
 */
const setupStarRatings = () => {
    const cmid = new URLSearchParams(window.location.search).get('id');

    document.querySelectorAll('.starrating').forEach((select) => {
        const widget = initStarRating(select, {
            initialRating: select.dataset.currentRating,
            onSelect: (value) => {
                // Note: core/ajax returns a jQuery promise; Promise.resolve() makes it a native one.
                Promise.resolve(Ajax.call([{
                    methodname: 'mod_booking_rate_option',
                    args: {cmid: cmid, optionid: select.dataset.itemid, rate: value},
                }])[0])
                    .then((data) => {
                        widget.setReadonly(true);
                        widget.set(data.rate);
                        return null;
                    })
                    .catch(Notification.exception);
            },
        });
    });
};

/**
 * Remove aria-controls references that point to ids missing from the page.
 */
const removeInvalidAriaControls = () => {
    document.querySelectorAll('[aria-controls]').forEach((element) => {
        const controls = (element.getAttribute('aria-controls') || '').trim();
        if (controls && document.getElementById(controls) === null) {
            element.removeAttribute('aria-controls');
        }
    });
};

/**
 * Tick every participant checkbox of the manage responses form except the given one.
 *
 * @param {HTMLElement|null} except
 * @param {boolean} checked
 */
const setParticipantCheckboxes = (except, checked) => {
    document.querySelectorAll('#studentsform input[type="checkbox"]').forEach((checkbox) => {
        if (checkbox !== except) {
            checkbox.checked = checked;
        }
    });
};

/**
 * Helpers of the manage responses page (report.php), bound once through delegation.
 */
const registerReportListeners = () => {
    if (reportListenersRegistered || !document.querySelector(REPORTPAGE)) {
        return;
    }
    reportListenersRegistered = true;

    document.addEventListener('click', (e) => {
        if (!e.target.closest) {
            return;
        }
        // "Clear" resets the search fields and runs the search again.
        if (e.target.closest(REPORTPAGE + ' #buttonclear')) {
            ['menusearchwaitinglist', 'menusearchfinished', 'searchdate'].forEach((id) => {
                const field = document.getElementById(id);
                if (field) {
                    field.value = '';
                }
            });
            const searchbutton = document.getElementById('searchButton');
            if (searchbutton) {
                searchbutton.click();
            }
            return;
        }
        // "Select all" mirrors its state onto every participant checkbox.
        const checkall = e.target.closest(REPORTPAGE + ' #usercheckboxall');
        if (checkall) {
            setParticipantCheckboxes(checkall, checkall.checked);
        }
    });

    document.addEventListener('change', (e) => {
        if (!e.target.closest) {
            return;
        }
        // "Rate all": tick everybody and preselect the rating in every row.
        const rateall = e.target.closest(REPORTPAGE + ' #menuratingall');
        if (rateall) {
            setParticipantCheckboxes(rateall, true);
            document.querySelectorAll('.booking-option-rating .postratingmenu.ratinginput').forEach((select) => {
                if (Array.from(select.options).some((option) => option.value === rateall.value)) {
                    select.value = rateall.value;
                }
            });
            return;
        }
        // A rating changed in one row: tick that participant.
        const rating = e.target.closest(REPORTPAGE + ' .booking-option-rating .ratinginput');
        if (rating && rating.id) {
            const checkbox = document.querySelector('#studentsform [id="check' + rating.id.replace(/\D/g, '') + '"]');
            if (checkbox) {
                checkbox.checked = true;
            }
        }
    });
};

/**
 * Set up the options overview and the manage responses page. Safe to call repeatedly.
 */
export const setup = () => {
    setupStarRatings();
    removeInvalidAriaControls();
    registerReportListeners();
};
