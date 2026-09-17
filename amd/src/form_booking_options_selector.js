// This file is part of Moodle - https://moodle.org/
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
 * Provides the required functionality for an autocomplete element to select a booking option.
 *
 * @module      mod_booking/form_booking_options_selector
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from "core/ajax";
import {render as renderTemplate} from "core/templates";

/**
 * Read numeric data attribute from selector element.
 *
 * @param {String|HTMLElement} selector
 * @param {String} name
 * @return {Number}
 */
function readNumericDataAttribute(selector, name) {
  let element = null;

  if (selector && typeof selector === "string") {
    element = document.querySelector(selector);
  } else if (selector && selector.dataset) {
    element = selector;
  }

  if (!element || !element.dataset) {
    return 0;
  }

  const value = Number(element.dataset[name] || 0);
  return Number.isFinite(value) ? value : 0;
}

/**
 * Load the list of booking options matching the query and render the selector labels for them.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {String} query The query string.
 * @param {Function} callback A callback function receiving an array of results.
 * @param {Function} failure A function to call in case of failure, receiving the error message.
 */
export async function transport(selector, query, callback, failure) {
  const bookingid = readNumericDataAttribute(selector, "bookingid");
  const cmid = readNumericDataAttribute(selector, "cmid");

  const request = {
    methodname: "mod_booking_search_booking_options",
    args: {
      query: query,
      bookingid: bookingid,
      cmid: cmid,
    },
  };

  try {
    const response = await Ajax.call([request])[0];

    let labels = [];

    // eslint-disable-next-line no-console
    console.log(response);

    if (response.warnings.length > 0) {
        callback(response.warnings);
    } else {
        response.list.forEach((optiondata) => {
            labels.push(
                renderTemplate(
                  "mod_booking/form_booking_options_selector_suggestion",
                  optiondata
                )
            );
            });
            labels = await Promise.all(labels);

            response.list.forEach((optiondata, index) => {
              optiondata.label = labels[index];
            });
            callback(response.list);
    }
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
  } else {
    return results.map((result) => ({value: result.id, label: result.label}));
  }
}
