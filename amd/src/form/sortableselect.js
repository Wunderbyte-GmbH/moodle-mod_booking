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
 * Form element "sortable select": choose several options and put them in an order.
 *
 * The element is rendered by PHP (mod_booking\form\element\sortableselect). Every chosen entry is a list
 * item with a hidden input, and the browser submits the inputs in the order of the list. So this module
 * never builds a value: it only adds, moves and removes list items, the order of the DOM is the value.
 *
 * @module     mod_booking/form/sortableselect
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Notification from 'core/notification';
import Pending from 'core/pending';
import SortableList from 'core/sortable_list';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {markFormAsDirty} from 'core_form/changechecker';

const SELECTORS = {
    LIST: '[data-region="sortableselect-list"]',
    ITEM: '[data-region="sortableselect-item"]',
    POSITION: '[data-region="sortableselect-position"]',
    EMPTY: '[data-region="sortableselect-empty"]',
    SELECT: '[data-region="sortableselect-select"]',
    STATUS: '[data-region="sortableselect-status"]',
    ADD: '[data-action="sortableselect-add"]',
    ACTION: 'button[data-action]',
    HANDLE: '[data-drag-type="move"]',
};

const ITEMTEMPLATE = 'mod_booking/form/sortableselect_item';

/**
 * The chosen entries of an element in their order.
 *
 * @param {HTMLElement} root
 * @returns {HTMLElement[]}
 */
const getItems = (root) => [...root.querySelectorAll(SELECTORS.ITEM)];

/**
 * The hidden input that is always submitted. The form dependencies (disabledIf) disable the inputs with the
 * name of the element, so this input tells whether the element is disabled.
 *
 * @param {HTMLElement} root
 * @returns {HTMLInputElement}
 */
const getStateInput = (root) => root.querySelector(':scope > input[type="hidden"]');

/**
 * Whether the element is disabled.
 *
 * @param {HTMLElement} root
 * @returns {boolean}
 */
const isDisabled = (root) => getStateInput(root).disabled;

/**
 * Brings everything that depends on the order of the list up to date: the position numbers, the buttons
 * that make no sense for the first and the last entry
 * or while the element is disabled, the text for an empty list and the options that
 * are already chosen.
 *
 * @param {HTMLElement} root
 */
const refresh = (root) => {
    const items = getItems(root);
    const disabled = isDisabled(root);
    items.forEach((item, index) => {
        item.querySelector(SELECTORS.POSITION).textContent = String(index + 1);
        item.querySelector('[data-action="up"]').disabled = disabled || index === 0;
        item.querySelector('[data-action="down"]').disabled = disabled || index === items.length - 1;
        // In the mode "sortonly" an entry has no button to remove it.
        const remove = item.querySelector('[data-action="remove"]');
        if (remove) {
            remove.disabled = disabled;
        }
        item.querySelector(SELECTORS.HANDLE).disabled = disabled;
        // An entry that was added later is not known to the form dependencies: it follows the element.
        item.querySelector('input[type="hidden"]').disabled = disabled;
    });
    // In the mode "sortonly" there is no select and no button to add an entry.
    const select = root.querySelector(SELECTORS.SELECT);
    const add = root.querySelector(SELECTORS.ADD);
    if (select && add) {
        select.disabled = disabled;
        add.disabled = disabled;
    }

    root.querySelector(SELECTORS.LIST).classList.toggle('d-none', items.length === 0);
    root.querySelector(SELECTORS.EMPTY).classList.toggle('d-none', items.length > 0);

    // An entry can only be chosen once.
    const chosen = items.map(item => item.dataset.value);
    root.querySelectorAll(`${SELECTORS.SELECT} option`).forEach(option => {
        if (option.value !== '') {
            option.disabled = chosen.includes(option.value);
            option.hidden = chosen.includes(option.value);
        }
    });
};

/**
 * Tells screen readers what happened: moving a list item is silent otherwise.
 *
 * @param {HTMLElement} root
 * @param {string} key string of mod_booking
 * @param {string|object} param parameter of the string
 */
const announce = (root, key, param) => {
    getString(key, 'mod_booking', param).then(text => {
        root.querySelector(SELECTORS.STATUS).textContent = text;
        return text;
    }).catch(Notification.exception);
};

/**
 * Call after the list has changed.
 *
 * Hidden inputs do not fire events, so the form would not ask before the user leaves the page with unsaved
 * changes. Other code can listen to the change event of the element.
 *
 * @param {HTMLElement} root
 */
const changed = (root) => {
    refresh(root);
    markFormAsDirty(root);
    root.dispatchEvent(new CustomEvent('change', {bubbles: true}));
};

/**
 * Tells screen readers the new position of an entry.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} item
 */
const announcePosition = (root, item) => {
    const items = getItems(root);
    announce(root, 'sortableselect:moved', {
        label: item.dataset.label,
        position: items.indexOf(item) + 1,
        total: items.length,
    });
};

/**
 * Adds the entry that is chosen in the select to the end of the list.
 *
 * @param {HTMLElement} root
 */
const addItem = (root) => {
    const select = root.querySelector(SELECTORS.SELECT);
    const option = select.selectedOptions[0];
    if (!option || option.value === '' || getItems(root).some(item => item.dataset.value === option.value)) {
        return;
    }
    const pending = new Pending('mod_booking/form/sortableselect:add');
    const label = option.textContent;
    // The same template as in PHP, so there is only one place that knows the HTML of an entry.
    Templates.renderForPromise(ITEMTEMPLATE, {
        name: root.dataset.name,
        value: option.value,
        label: label,
        position: getItems(root).length + 1,
        first: false,
        last: true,
        legacy: false,
    }).then(({html, js}) => {
        Templates.appendNodeContents(root.querySelector(SELECTORS.LIST), html, js);
        select.value = '';
        changed(root);
        announce(root, 'sortableselect:added', label);
        select.focus();
        return;
    }).catch(Notification.exception).finally(() => pending.resolve());
};

/**
 * Moves an entry one position up or down, or removes it.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} item
 * @param {string} action up, down or remove
 */
const handleAction = (root, item, action) => {
    if (action === 'remove') {
        // The focus must not get lost with the removed button.
        const neighbour = item.nextElementSibling ?? item.previousElementSibling;
        const label = item.dataset.label;
        item.remove();
        changed(root);
        announce(root, 'sortableselect:removed', label);
        (neighbour?.querySelector('[data-action="remove"]') ?? root.querySelector(SELECTORS.SELECT)).focus();
        return;
    }

    const target = action === 'up' ? item.previousElementSibling : item.nextElementSibling;
    if (!target) {
        return;
    }
    if (action === 'up') {
        target.before(item);
    } else {
        target.after(item);
    }
    changed(root);
    announcePosition(root, item);
    // At the top or at the bottom the pressed button is disabled now, the other direction takes the focus.
    const button = item.querySelector(`[data-action="${action}"]`);
    (button.disabled ? item.querySelector('[data-action="up"]:not(:disabled), [data-action="down"]:not(:disabled)') : button)
        ?.focus();
};

/**
 * Makes a sortable select element interactive.
 *
 * @param {string} elementId id of the container of the element
 */
export const init = (elementId) => {
    const root = document.getElementById(elementId);
    if (!root || root.dataset.initialized) {
        return;
    }
    root.dataset.initialized = '1';
    const list = root.querySelector(SELECTORS.LIST);

    // A button adds the chosen entry. Adding on change of the select would already add an entry
    // while a keyboard user moves through the options with the arrow keys.
    // In the mode "sortonly" the button does not exist: the entries can only be ordered.
    root.querySelector(SELECTORS.ADD)?.addEventListener('click', () => addItem(root));

    // One listener on the list instead of one per button: it also works for entries that are added later.
    list.addEventListener('click', (e) => {
        const button = e.target.closest(SELECTORS.ACTION);
        const item = button?.closest(SELECTORS.ITEM);
        if (item) {
            handleAction(root, item, button.dataset.action);
        }
    });

    // Drag and drop, and a dialogue to move an entry when the handle is clicked or activated by keyboard.
    // The sortable list moves the list item itself, only the state of the element has to follow.
    const sortableList = new SortableList(list);
    sortableList.getElementName = (element) => $.Deferred().resolve(element.attr('data-label'));
    $(list).on(SortableList.EVENTS.DROP, SELECTORS.ITEM, (e, info) => {
        if (info.positionChanged) {
            changed(root);
            announcePosition(root, info.element[0]);
        }
    });

    // Disabling an input fires no event, so the buttons and the select follow by an observer.
    new MutationObserver(() => refresh(root)).observe(getStateInput(root), {attributeFilter: ['disabled']});

    refresh(root);
};
