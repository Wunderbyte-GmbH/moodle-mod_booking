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
 * Provides the required functionality for an autocomplete element to select a course.
 *
 * @module      mod_booking/form_templates_selector
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from "core/ajax";
import {render as renderTemplate} from "core/templates";

/**
 * Load the list of courses matching the query and render the selector labels for them.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {String} query The query string.
 * @param {Function} callback A callback function receiving an array of results.
 * @param {Function} failure A function to call in case of failure, receiving the error message.
 */
export async function transport(selector, query, callback, failure) {
  const request = {
    methodname: "mod_booking_search_templates",
    args: {
      query: query,
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
        response.list.forEach((course) => {
            labels.push(
                renderTemplate(
                "mod_booking/form-course-selector-suggestion",
                course
                )
            );
            });
            labels = await Promise.all(labels);

            response.list.forEach((entity, index) => {
            entity.label = labels[index];
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
