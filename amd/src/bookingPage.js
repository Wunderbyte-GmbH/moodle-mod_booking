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
 * @module     mod_booking/bookingPage
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {toggleContinueButton} from 'mod_booking/bookit';

var SELECTORS = {
    ACCEPTBOOKINPOLICYCHECKBOX: 'input.booking-page-accept-checkbox',
};

export const init = (optionid) => {

    // eslint-disable-next-line no-console
    console.log(optionid, SELECTORS.ACCEPTBOOKINPOLICYCHECKBOX);

    const elements = document.querySelectorAll(SELECTORS.ACCEPTBOOKINPOLICYCHECKBOX);

    if (!elements) {
        // eslint-disable-next-line no-console
        console.log('didnt find elmeents ');
    }

    elements.forEach(element => {
        element.addEventListener('change', e => {
            // eslint-disable-next-line no-console
            console.log(e.target.checked);

            toggleContinueButton(optionid, e.target.checked);
        });
    });
};