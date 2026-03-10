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
 * @module     mod_booking/slotCalendarReport
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {init as initSlotCalendarPicker} from 'mod_booking/slotCalendarPicker';

const safeJsonParse = (value, fallback) => {
    try {
        return JSON.parse(value || '');
    } catch {
        return fallback;
    }
};

const createNameList = (names, emptyText) => {
    if (!Array.isArray(names) || names.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'small text-muted';
        empty.textContent = emptyText;
        return empty;
    }

    const list = document.createElement('ul');
    list.className = 'mb-0';

    names.forEach((name) => {
        const item = document.createElement('li');
        item.textContent = String(name);
        list.appendChild(item);
    });

    return list;
};

const createBookingAnswerList = (answers, emptyText) => {
    if (!Array.isArray(answers) || answers.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'small text-muted';
        empty.textContent = emptyText;
        return empty;
    }

    const list = document.createElement('ul');
    list.className = 'mb-0';

    answers.forEach((answer) => {
        const item = document.createElement('li');

        if (answer && typeof answer === 'object') {
            const name = String(answer.name || '').trim();
            const status = String(answer.status || '').trim();
            item.textContent = status ? `${name} (${status})` : name;
        } else {
            item.textContent = String(answer);
        }

        list.appendChild(item);
    });

    return list;
};

const renderDetails = (target, slot, detail, labels) => {
    target.innerHTML = '';

    if (!slot || !detail) {
        const hint = document.createElement('div');
        hint.className = 'small text-muted';
        hint.textContent = labels.selectSlot;
        target.appendChild(hint);
        return;
    }

    const card = document.createElement('div');
    card.className = 'border rounded p-3 bg-white';

    const title = document.createElement('div');
    title.className = 'fw-bold';
    title.textContent = slot.daylabel || '';
    card.appendChild(title);

    const subtitle = document.createElement('div');
    subtitle.className = 'small mb-2';
    subtitle.textContent = slot.timelabel || '';
    card.appendChild(subtitle);

    const occupancy = document.createElement('div');
    occupancy.className = 'small mb-2';
    occupancy.textContent = `${labels.occupancy}: ${Number(detail.bookedcount || 0)}/${Number(detail.capacity || 0)}`;
    card.appendChild(occupancy);

    const studentsLabel = document.createElement('div');
    studentsLabel.className = 'small fw-bold';
    studentsLabel.textContent = labels.students;
    card.appendChild(studentsLabel);

    card.appendChild(createBookingAnswerList(detail.bookinganswers, labels.none));

    const teachersLabel = document.createElement('div');
    teachersLabel.className = 'small fw-bold mt-2';
    teachersLabel.textContent = labels.teachers;
    card.appendChild(teachersLabel);

    card.appendChild(createNameList(detail.teachers, labels.none));

    if (detail.moveurl) {
        const moveWrap = document.createElement('div');
        moveWrap.className = 'mt-3';

        const moveLink = document.createElement('a');
        moveLink.href = String(detail.moveurl);
        moveLink.className = 'btn btn-sm btn-outline-primary';
        moveLink.textContent = labels.moveSlot;
        moveWrap.appendChild(moveLink);

        card.appendChild(moveWrap);
    }

    target.appendChild(card);
};

/**
 * Init report slot calendar picker.
 *
 * @param {string} containerId
 */
export const init = (containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const root = container.querySelector('[data-region="slot-calendar-picker"]');
    const detailsTarget = container.querySelector('[data-region="slot-calendar-students"]');
    const slotsInput = container.querySelector('input[name="calendar_slots_json"]');
    const detailsInput = container.querySelector('input[name="slot_details_json"]');

    if (!root || !detailsTarget || !slotsInput || !detailsInput) {
        return;
    }

    const slots = safeJsonParse(slotsInput.value, []);
    const details = safeJsonParse(detailsInput.value, {});

    const labels = {
        students: container.dataset.studentsLabel || 'Booked students',
        teachers: container.dataset.teachersLabel || 'Booked teachers',
        occupancy: container.dataset.occupancyLabel || 'Occupancy',
        moveSlot: container.dataset.moveslotLabel || 'Move slot',
        selectSlot: container.dataset.selectslotLabel || 'Select a slot to view booked students.',
        none: container.dataset.noneLabel || 'None',
        noBookedSlots: container.dataset.nobookedslotsLabel || 'No booked slots on this day.',
    };

    renderDetails(detailsTarget, null, null, labels);

    initSlotCalendarPicker(root, {
        slots,
        maxSelection: 1,
        replaceWhenFull: true,
        resetSelectionOnDayChange: true,
        emptySlotListText: labels.noBookedSlots,
        slotFilter: (slot) => Number(slot.bookings || 0) > 0,
        dayCountFormatter: (daySlots) => {
            const booked = daySlots.reduce((sum, slot) => sum + Number(slot.bookings || 0), 0);
            const capacity = daySlots.reduce((sum, slot) => sum + Number(slot.capacity || 0), 0);
            return `${booked}/${capacity}`;
        },
        dayStateResolver: (daySlots) => {
            const booked = daySlots.reduce((sum, slot) => sum + Number(slot.bookings || 0), 0);
            const capacity = daySlots.reduce((sum, slot) => sum + Number(slot.capacity || 0), 0);
            if (capacity > 0 && booked >= capacity) {
                return 'full';
            }
            return '';
        },
        onChange: (selection) => {
            if (!Array.isArray(selection) || selection.length === 0) {
                renderDetails(detailsTarget, null, null, labels);
                return;
            }

            const selectedKey = selection[selection.length - 1];
            const slot = slots.find((entry) => String(entry.key) === String(selectedKey)) || null;
            const detail = details[selectedKey] || null;
            renderDetails(detailsTarget, slot, detail, labels);
        },
        onDayChange: () => {
            renderDetails(detailsTarget, null, null, labels);
        },
    });
};
