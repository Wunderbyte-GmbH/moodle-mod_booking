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
 * Ordered list for the instance setting "Priority of the search results".
 *
 * The value of the setting is a comma separated list of column names in a text field, because the
 * order of the columns is their priority. This module hides the text field and shows a list instead,
 * with buttons to move and remove a column, and a select with a button to add one. Every change is written back to
 * the text field, so the form submits and validates the same value with and without JavaScript.
 *
 * @module     mod_booking/searchpriority
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    INPUT: '[data-region="searchpriority-input"]',
    WIDGET: '[data-region="searchpriority"]',
    LIST: '[data-region="searchpriority-list"]',
    EMPTY: '[data-region="searchpriority-empty"]',
    SELECT: '[data-region="searchpriority-select"]',
    ADD: '[data-action="searchpriority-add"]',
};

/**
 * Column names of the text field in their order, without empty entries and duplicates.
 *
 * @param {HTMLInputElement} input
 * @returns {string[]}
 */
const readColumns = (input) => {
    const columns = input.value.split(',').map(column => column.trim()).filter(column => column !== '');
    return [...new Set(columns)];
};

/**
 * Creates a button of a list entry.
 *
 * @param {string} action up, down or remove
 * @param {string} icon Font Awesome icon class
 * @param {string} title text for title and screen readers
 * @param {boolean} disabled
 * @returns {HTMLButtonElement}
 */
const createButton = (action, icon, title, disabled) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-secondary ms-1 ml-1';
    button.dataset.action = action;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.disabled = disabled;
    const i = document.createElement('i');
    i.className = 'fa ' + icon;
    i.setAttribute('aria-hidden', 'true');
    button.append(i);
    return button;
};

/**
 * Shows the columns in the list and writes them to the text field.
 *
 * @param {HTMLElement} widget
 * @param {HTMLInputElement} input
 * @param {string[]} columns
 */
const render = (widget, input, columns) => {
    const list = widget.querySelector(SELECTORS.LIST);
    const select = widget.querySelector(SELECTORS.SELECT);
    const labels = {};
    select.querySelectorAll('option').forEach(option => {
        if (option.value !== '') {
            labels[option.value] = option.textContent;
            // A column can only be added once.
            option.hidden = columns.includes(option.value);
            option.disabled = columns.includes(option.value);
        }
    });

    list.replaceChildren();
    columns.forEach((column, index) => {
        // Columns that are not offered in the select (other fields of booking options) show their name.
        const label = labels[column] ?? column;
        const item = document.createElement('li');
        item.className = 'list-group-item d-flex align-items-center py-1';
        item.dataset.column = column;

        const position = document.createElement('span');
        position.className = 'badge bg-secondary badge-secondary me-2 mr-2';
        position.textContent = String(index + 1);
        const name = document.createElement('span');
        name.className = 'flex-grow-1';
        name.textContent = label;
        item.append(position, name);

        item.append(
            createButton('up', 'fa-arrow-up', widget.dataset.strMoveup.replace('LABEL', label), index === 0),
            createButton(
                'down',
                'fa-arrow-down',
                widget.dataset.strMovedown.replace('LABEL', label),
                index === columns.length - 1
            ),
            createButton('remove', 'fa-trash', widget.dataset.strRemove.replace('LABEL', label), false)
        );
        list.append(item);
    });

    widget.querySelector(SELECTORS.EMPTY).classList.toggle('d-none', columns.length > 0);
    list.classList.toggle('d-none', columns.length === 0);

    const value = columns.join(',');
    if (input.value !== value) {
        input.value = value;
        // The form change checker and other listeners rely on the event.
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }
};

/**
 * Replaces the text field of the setting by the list.
 */
export const init = () => {
    const input = document.querySelector(SELECTORS.INPUT);
    const widget = document.querySelector(SELECTORS.WIDGET);
    if (!input || !widget || widget.dataset.initialized) {
        return;
    }
    widget.dataset.initialized = '1';

    // The list takes the place of the text field, which keeps the value and the validation message.
    input.after(widget);
    input.classList.add('d-none');
    widget.classList.remove('d-none');

    let columns = readColumns(input);
    render(widget, input, columns);

    // A button adds the chosen column. Adding on change of the select would already add a column
    // while a keyboard user moves through the options with the arrow keys.
    const select = widget.querySelector(SELECTORS.SELECT);
    widget.querySelector(SELECTORS.ADD).addEventListener('click', () => {
        if (select.value !== '') {
            columns = [...columns.filter(column => column !== select.value), select.value];
            select.value = '';
            render(widget, input, columns);
            select.focus();
        }
    });

    widget.querySelector(SELECTORS.LIST).addEventListener('click', (e) => {
        const button = e.target.closest('button[data-action]');
        if (!button) {
            return;
        }
        const column = button.closest('[data-column]').dataset.column;
        const index = columns.indexOf(column);
        const action = button.dataset.action;
        if (action === 'remove') {
            columns.splice(index, 1);
        } else {
            const target = action === 'up' ? index - 1 : index + 1;
            if (target < 0 || target >= columns.length) {
                return;
            }
            [columns[index], columns[target]] = [columns[target], columns[index]];
        }
        render(widget, input, columns);

        // The list was rebuilt: keep the keyboard focus on the moved entry.
        const item = [...widget.querySelectorAll('[data-column]')].find(entry => entry.dataset.column === column);
        const focus = item?.querySelector(`button[data-action="${action}"]:not(:disabled)`)
            ?? item?.querySelector('button:not(:disabled)')
            ?? select;
        focus.focus();
    });
};
