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
 * Client side editor for the customform availability condition rows in the
 * booking option form: move up/down, delete and insert form elements.
 *
 * The option form renders up to MAXROWS rows; visibility is controlled by a
 * hideIf chain that does not tolerate gaps. Therefore all operations here are
 * implemented as shift operations on the value tuples of the rows — the DOM
 * itself is never reordered. Saving happens via the normal form submit:
 * get_condition_object_for_json() collects the rows in field name order.
 *
 * @module     mod_booking/customformeditor
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Classic AMD format (instead of ESM) so the file also works when served
// directly from amd/src on dev systems with disabled JS caching.
define([], () => {

const MAXROWS = 50;

const SELECTOR = {
    BUTTON: '[data-cfe-action]',
};

/**
 * Return the mform field of a given row by its short name.
 *
 * @param {HTMLFormElement} form
 * @param {String} shortname one of select|label|value|notempty|waitinglist
 * @param {Number} row
 * @returns {HTMLElement|null}
 */
const getField = (form, shortname, row) => {
    let name;
    switch (shortname) {
        case 'select':
            name = `bo_cond_customform_select_1_${row}`;
            break;
        case 'label':
            name = `bo_cond_customform_label_1_${row}`;
            break;
        case 'value':
            name = `bo_cond_customform_value_1_${row}`;
            break;
        case 'elementid':
            name = `bo_cond_customform_elementid_1_${row}`;
            break;
        case 'notempty':
            return form.querySelector(`input[type="checkbox"][name="bo_cond_customform_notempty_1_${row}"]`);
        case 'waitinglist':
            return form.querySelector(`input[type="checkbox"][name="bo_cond_customform_enroluserstowaitinglist${row}"]`);
    }
    return form.querySelector(`[name="${name}"]`);
};

/**
 * Read the value tuple of a row.
 *
 * @param {HTMLFormElement} form
 * @param {Number} row
 * @returns {Object}
 */
const readRow = (form, row) => ({
    formtype: getField(form, 'select', row)?.value ?? '0',
    elementid: getField(form, 'elementid', row)?.value ?? '',
    label: getField(form, 'label', row)?.value ?? '',
    value: getField(form, 'value', row)?.value ?? '',
    notempty: getField(form, 'notempty', row)?.checked ?? false,
    waitinglist: getField(form, 'waitinglist', row)?.checked ?? false,
});

/**
 * Write a value tuple into a row and notify the hideIf chain.
 *
 * @param {HTMLFormElement} form
 * @param {Number} row
 * @param {Object} tuple
 */
const writeRow = (form, row, tuple) => {
    const select = getField(form, 'select', row);
    if (!select) {
        return;
    }
    select.value = tuple.formtype;
    const elementid = getField(form, 'elementid', row);
    if (elementid) {
        elementid.value = tuple.elementid;
    }
    const label = getField(form, 'label', row);
    if (label) {
        label.value = tuple.label;
    }
    const value = getField(form, 'value', row);
    if (value) {
        value.value = tuple.value;
    }
    const notempty = getField(form, 'notempty', row);
    if (notempty) {
        notempty.checked = tuple.notempty;
    }
    const waitinglist = getField(form, 'waitinglist', row);
    if (waitinglist) {
        waitinglist.checked = tuple.waitinglist;
    }
    // The hideIf chain listens on change events.
    ['select', 'notempty', 'waitinglist'].forEach((shortname) => {
        getField(form, shortname, row)?.dispatchEvent(new Event('change', {bubbles: true}));
    });
};

/**
 * An empty tuple, used to clear a row.
 *
 * @returns {Object}
 */
// An empty elementid makes the server assign a fresh id from the nextelementid counter.
const emptyTuple = () => ({formtype: '0', elementid: '', label: '', value: '', notempty: false, waitinglist: false});

/**
 * Number of used rows (rows with a chosen form type). The hideIf chain
 * guarantees the used rows are gapless 1…N.
 *
 * @param {HTMLFormElement} form
 * @returns {Number}
 */
const usedRows = (form) => {
    let used = 0;
    for (let i = 1; i <= MAXROWS; i++) {
        const select = getField(form, 'select', i);
        if (!select) {
            break;
        }
        if (select.value !== '0' && select.value !== '') {
            used = i;
        }
    }
    return used;
};

/**
 * Show only the used rows plus one trailing empty row.
 *
 * This replaces the former hideIf chain on the previous row's select, which
 * could not cope with temporarily empty rows created by the insert operation.
 * Rows the user emptied manually in the middle stay visible until saved.
 *
 * @param {HTMLFormElement} form
 */
const updateVisibility = (form) => {
    const used = usedRows(form);
    for (let i = 1; i <= MAXROWS; i++) {
        const group = form.querySelector(`[id^="fgroup_id_formgroupelement_1_${i}_"]`);
        if (!group) {
            continue;
        }
        // Never hide a non-empty row; hide trailing empty rows beyond the first one.
        const select = getField(form, 'select', i);
        const isempty = !select || select.value === '0' || select.value === '';
        group.classList.toggle('d-none', isempty && i > used + 1);
    }
};

/**
 * Handle a click on one of the editor buttons.
 *
 * @param {HTMLFormElement} form
 * @param {String} action up|down|delete|insert
 * @param {Number} row
 */
const apply = (form, action, row) => {
    const used = usedRows(form);
    switch (action) {
        case 'up': {
            if (row <= 1 || row > used) {
                return;
            }
            const tmp = readRow(form, row - 1);
            writeRow(form, row - 1, readRow(form, row));
            writeRow(form, row, tmp);
            break;
        }
        case 'down': {
            if (row >= used) {
                return;
            }
            const tmp = readRow(form, row + 1);
            writeRow(form, row + 1, readRow(form, row));
            writeRow(form, row, tmp);
            break;
        }
        case 'delete': {
            if (row > used) {
                return;
            }
            for (let i = row; i < used; i++) {
                writeRow(form, i, readRow(form, i + 1));
            }
            writeRow(form, used, emptyTuple());
            break;
        }
        case 'insert': {
            // Insert an empty row below the current one.
            if (used >= MAXROWS || row > used) {
                return;
            }
            for (let i = used; i > row; i--) {
                writeRow(form, i + 1, readRow(form, i));
            }
            writeRow(form, row + 1, emptyTuple());
            break;
        }
    }
};

/**
 * Init: delegate clicks for all editor buttons of the option form.
 */
const init = () => {
    const firstselect = document.querySelector('[name="bo_cond_customform_select_1_1"]');
    if (firstselect) {
        updateVisibility(firstselect.closest('form'));
    }
    if (document.body.dataset.cfeDelegated) {
        return;
    }
    document.body.dataset.cfeDelegated = 'true';
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest(SELECTOR.BUTTON);
        if (!btn) {
            return;
        }
        e.preventDefault();
        const form = btn.closest('form');
        if (!form) {
            return;
        }
        apply(form, btn.dataset.cfeAction, parseInt(btn.dataset.cfeRow, 10));
        updateVisibility(form);
    });
    // When the user picks a type on the trailing empty row, reveal the next one.
    document.body.addEventListener('change', (e) => {
        if (/^bo_cond_customform_select_1_\d+$/.test(e.target.name || '')) {
            updateVisibility(e.target.closest('form'));
        }
    });
    // Fields hidden via hideIf are disabled and would not be submitted. The server would
    // then silently fall back to the set_defaults() value of the OLD row position, undoing
    // the client side shifting. So right before submit we re-enable all row fields to make
    // sure the actual (shifted) DOM state is what reaches the server.
    document.body.addEventListener('submit', (e) => {
        e.target.querySelectorAll(
            '[name^="bo_cond_customform_select_1_"], [name^="bo_cond_customform_label_1_"], '
            + '[name^="bo_cond_customform_value_1_"], [name^="bo_cond_customform_notempty_1_"], '
            + '[name^="bo_cond_customform_enroluserstowaitinglist"]'
        ).forEach((field) => {
            field.removeAttribute('disabled');
        });
    }, true);
};

return {init};
});
