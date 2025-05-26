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
 * Library containing general JS functions for the booking module.
 *
 * @module     mod_booking/bookingcompetencies
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getSortSelection} from 'local_wunderbyte_table/sort';
import {callLoadData} from 'local_wunderbyte_table/init';

/**
 * Gets called from mustache template.
 *
 * @param {string} encodedtable
 */
export const init = (encodedtable) => {

    const buttons = document.querySelectorAll(".booking-competencies-trigger-filter-button");

    if (buttons) {
        buttons.forEach(button => {

            if (button.dataset.initialized) {
                return;
            }
            const competencies = button.dataset.competencyIds;
            const idstring = button.dataset.tableIdstring;

            button.addEventListener('click', () => {
                toggleCompetenciesFilter(competencies, idstring, encodedtable);
            });
            button.dataset.initialized = true;
        });
    }
};

/**
 * Toggle Competencies Filter
 * @param {string} competencies
 * @param {string} idstring
 * @param {string} encodedtable
 */
 export const toggleCompetenciesFilter = (
    competencies,
    idstring,
    encodedtable) => {

    const sort = getSortSelection(idstring);

    const idsArray = competencies.split(',').map(id => id.trim());
    const filterobjects = JSON.stringify({competencies: idsArray});

    callLoadData(idstring,
      encodedtable,
      0, // Pagenumber is always set to 0.
      null,
      sort,
      null,
      null,
      null,
      filterobjects,
      '',
      false,
      true);
};


