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
 * WunderByte javascript library/framework
 *
 * @module mod_booking/wunderbyte
 * @copyright 2023 Georg Maißer <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {WunderByteJS} from "mod_booking/wunderbyte";

const SELECTOR = {
    CONFIRMBUTTON: '#confirmbutton',
    SORTCONTAINER: 'ul#wb-sortabe',
    SORTITEM: 'li.list-group-item',
};

/**
 * Elective sorting.
 */
export function electiveSorting() {
    let options = {
        items: SELECTOR.SORTITEM,
        container: SELECTOR.SORTCONTAINER
    };

    let wunderbyteJS = new WunderByteJS();
    wunderbyteJS.sortable(options);

    // Add the change listener to the sortable items to update list link.
    const confirmButton = document.querySelector(SELECTOR.CONFIRMBUTTON);
    const sortContainer = document.querySelector(SELECTOR.SORTCONTAINER);

    // Options for the observer (which mutations to observe)
    const config = {attributes: true, childList: true, subtree: true};

    // Callback function to execute when mutations are observed
    const callback = (mutationList) => {
    for (const mutation of mutationList) {
        if (mutation.type === 'childList') {

            let list = [];
            document.querySelectorAll(SELECTOR.SORTITEM).forEach(element => {
                list.push(parseInt(element.dataset.id));
            });

            confirmButton.dataset.list = JSON.stringify(list);
        }
    }
    };

    // Create an observer instance linked to the callback function
    const observer = new MutationObserver(callback);

    // Start observing the target node for configured mutations
    if (sortContainer) {
        observer.observe(sortContainer, config);
    }
}