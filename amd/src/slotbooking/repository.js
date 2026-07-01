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
 * Single entry point for all slot booking webservice calls.
 *
 * Every slot frontend module talks to the server exclusively through this module,
 * so JSON (un)marshalling and the methodnames live in one place.
 *
 * @module     mod_booking/slotbooking/repository
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Call a single webservice method.
 *
 * @param {string} methodname
 * @param {object} args
 * @return {Promise<object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Load the selectable picker slots and meta for an option.
 *
 * @param {number} optionid
 * @param {number} userid
 * @return {Promise<{slots: Array, meta: object}>}
 */
export const getSlots = (optionid, userid = 0) =>
    call('mod_booking_get_slots', {optionid, userid}).then((response) => ({
        slots: JSON.parse(response.slots),
        meta: JSON.parse(response.meta),
    }));

/**
 * Load the booked-slot report data for an option.
 *
 * @param {number} cmid
 * @param {number} optionid
 * @return {Promise<{slots: Array, details: object}>}
 */
export const getBookedSlots = (cmid, optionid) =>
    call('mod_booking_get_booked_slots', {cmid, optionid}).then((response) => ({
        slots: JSON.parse(response.slots),
        details: JSON.parse(response.details),
    }));

/**
 * Validate and persist a slot selection before booking.
 *
 * @param {number} optionid
 * @param {number} userid
 * @param {Array<string>} selection slot keys ("start:end")
 * @param {object} teacherselection map of slot key to teacher id list
 * @return {Promise<{valid: boolean, errors: object, price: number}>}
 */
export const saveSelection = (optionid, userid, selection, teacherselection = {}) =>
    call('mod_booking_save_slot_selection', {
        optionid,
        userid,
        selection: JSON.stringify(selection),
        teacherselection: JSON.stringify(teacherselection),
    }).then((response) => ({
        valid: response.valid,
        errors: JSON.parse(response.errors),
        price: response.price,
    }));

/**
 * Release (cancel) individual booked slots for the participant themselves.
 *
 * @param {number} optionid
 * @param {number} baid booking answer id
 * @param {Array<string>} releaseslots slot keys to release
 * @param {string} reason
 * @return {Promise<object>}
 */
export const releaseSlots = (optionid, baid, releaseslots, reason = '') =>
    call('mod_booking_release_slots', {
        optionid,
        baid,
        releaseslots: JSON.stringify(releaseslots),
        reason,
    });
