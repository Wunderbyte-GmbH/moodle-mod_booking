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
 * @module     mod_booking/moveSlot
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {init as initSlotCalendarPicker} from 'mod_booking/slotCalendarPicker';

/**
 * Init move slot calendar picker.
 *
 * @param {string} containerId
 */
export const init = (containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const root = container.querySelector('[data-region="slot-calendar-picker"]');
    const selectionInput = container.querySelector('input[name="selectedslots"]');
    const slotsInput = container.querySelector('input[name="calendar_slots_json"]');
    const maxInput = container.querySelector('input[name="required_slot_count"]');

    if (!root || !selectionInput || !slotsInput || !maxInput) {
        return;
    }

    const initialSelection = selectionInput.value
        ? selectionInput.value.split(',').map(value => value.trim()).filter(Boolean)
        : [];

    let slots = [];
    try {
        slots = JSON.parse(slotsInput.value || '[]');
    } catch {
        slots = [];
    }

    initSlotCalendarPicker(root, {
        slots,
        maxSelection: Number(maxInput.value || 1),
        initialSelection,
        onChange: (selection) => {
            selectionInput.value = selection.join(',');
        },
    });
};
