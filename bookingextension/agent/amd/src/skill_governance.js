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
 * Skill governance page: filters the skill table by the text in the search box.
 *
 * @module     bookingextension_agent/skill_governance
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    searchInput: '#skill-search-input',
    skillRows: '#skills-governance-table tbody .skill-row',
};

/** Class of the collapsible details row that follows each skill row. */
const DETAIL_ROW_CLASS = 'skill-detail-row';

/**
 * Show or hide one skill row together with its details row.
 *
 * @param {HTMLElement} row The skill row.
 * @param {boolean} visible Whether the row is shown.
 */
const setRowVisible = (row, visible) => {
    const display = visible ? '' : 'none';
    row.style.display = display;
    const nextRow = row.nextElementSibling;
    if (nextRow && nextRow.classList.contains(DETAIL_ROW_CLASS)) {
        nextRow.style.display = display;
    }
};

/**
 * Filter the skill rows by name, component or capability.
 *
 * @param {string} query The search text.
 */
const filterRows = (query) => {
    const needle = query.toLowerCase().trim();
    document.querySelectorAll(SELECTORS.skillRows).forEach((row) => {
        const haystacks = [
            row.dataset.skillname || '',
            row.dataset.component || '',
            row.dataset.capabilities || '',
        ];
        setRowVisible(row, haystacks.some((value) => value.toLowerCase().includes(needle)));
    });
};

/**
 * Attach the search filter to the skill governance page.
 */
export const init = () => {
    const searchInput = document.querySelector(SELECTORS.searchInput);
    if (!searchInput) {
        return;
    }
    searchInput.addEventListener('input', () => filterRows(searchInput.value));
};
