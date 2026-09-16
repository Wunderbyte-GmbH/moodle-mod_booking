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
 * Autocomplete transport for picking entry staff (ticket scanners) of a booking option.
 *
 * The search runs in the context of the booking instance (hidden "cmid" field of the option form),
 * so the server can restrict the candidates to users the picker may see.
 *
 * @module      mod_booking/form_ticketscanners_selector
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from "core/ajax";
import {render as renderTemplate} from "core/templates";

/**
 * Load the list of pickable users matching the query and render the selector labels for them.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {String} query The query string.
 * @param {Function} callback A callback function receiving an array of results.
 * @param {Function} failure A function to call in case of failure, receiving the error message.
 */
export async function transport(selector, query, callback, failure) {
    const element = document.querySelector(selector);
    const cmidfield = element && element.form ? element.form.querySelector('[name="cmid"]') : null;
    const cmid = cmidfield ? parseInt(cmidfield.value, 10) : 0;

    const request = {
        methodname: "mod_booking_search_ticketscanners",
        args: {query, cmid},
    };

    try {
        const response = await Ajax.call([request])[0];
        if (response.warnings.length > 0) {
            callback(response.warnings);
            return;
        }
        const labels = await Promise.all(
            response.list.map((user) => renderTemplate("mod_booking/form-user-selector-suggestion", user))
        );
        response.list.forEach((entity, index) => {
            entity.label = labels[index];
        });
        callback(response.list);
    } catch (e) {
        failure(e);
    }
}

/**
 * Process the results for auto complete elements.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {Array} results An array or results returned by {@see transport()}.
 * @return {Array} New array of the selector options.
 */
export function processResults(selector, results) {
    if (!Array.isArray(results)) {
        return results;
    }
    return results.map((result) => ({value: result.id, label: result.label}));
}
