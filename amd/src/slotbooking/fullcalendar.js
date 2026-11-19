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
 * Loads FullCalendar shipped with mod_booking (thirdparty/fullcalendar).
 *
 * It gets its own requirejs name, because local_entities registers its copy as 'fullcalendar'
 * for the whole page.
 *
 * @module     mod_booking/slotbooking/fullcalendar
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';

const MODULE = 'mod_booking_fullcalendar';

let loading = null;
let library = null;

/**
 * Loads the library once.
 *
 * @returns {Promise<Object>} the global FullCalendar object
 */
export const load = () => {
    if (!loading) {
        window.requirejs.config({
            paths: {[MODULE]: Config.wwwroot + '/mod/booking/thirdparty/fullcalendar/main.min'},
            shim: {[MODULE]: {exports: 'FullCalendar'}},
        });
        loading = new Promise((resolve, reject) => {
            window.requirejs([MODULE], (FullCalendar) => {
                library = FullCalendar;
                resolve(FullCalendar);
            }, reject);
        });
    }
    return loading;
};

/**
 * The library, once load() is done.
 *
 * @returns {Object|null}
 */
export const getLibrary = () => library;
