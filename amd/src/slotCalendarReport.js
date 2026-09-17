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
import {getBookedSlots} from 'mod_booking/slotbooking/repository';
import Templates from 'core/templates';
import Notification from 'core/notification';

const DETAIL_TEMPLATE = 'mod_booking/slotbooking/slot_details';

/**
 * Build the slot_details template context from a selected slot and its detail data.
 *
 * @param {object|null} slot
 * @param {object|null} detail
 * @param {object} labels
 * @return {object}
 */
const buildDetailContext = (slot, detail, labels) => {
    if (!slot || !detail) {
        return {
            slot: false,
            selectslotlabel: labels.selectSlot,
        };
    }

    const bookinganswers = Array.isArray(detail.bookinganswers) ? detail.bookinganswers.map((answer) => ({
        name: String((answer && answer.name) || answer || ''),
        status: String((answer && answer.status) || ''),
    })) : [];
    const teachers = Array.isArray(detail.teachers) ? detail.teachers.map((name) => String(name)) : [];

    return {
        slot: true,
        daylabel: slot.daylabel || '',
        timelabel: slot.timelabel || '',
        occupancylabel: labels.occupancy,
        bookedcount: Number(detail.bookedcount || 0),
        capacity: Number(detail.capacity || 0),
        priceformatted: detail.priceformatted || '',
        pricelabel: labels.price,
        studentslabel: labels.students,
        hasbookings: bookinganswers.length > 0,
        bookinganswers,
        teacherslabel: labels.teachers,
        hasteachers: teachers.length > 0,
        teachers,
        moveurl: detail.moveurl || '',
        moveslotlabel: labels.moveSlot,
        nonelabel: labels.none,
        selectslotlabel: labels.selectSlot,
    };
};

/**
 * Render the detail panel for the given slot via the mustache template.
 *
 * @param {HTMLElement} target
 * @param {object|null} slot
 * @param {object|null} detail
 * @param {object} labels
 */
const renderDetails = (target, slot, detail, labels) => {
    Templates.renderForPromise(DETAIL_TEMPLATE, buildDetailContext(slot, detail, labels))
        .then(({html, js}) => {
            Templates.replaceNodeContents(target, html, js);
            return true;
        })
        .catch(Notification.exception);
};

/**
 * Init report slot calendar.
 *
 * @param {string} containerId
 */
export const init = async(containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const root = container.querySelector('[data-region="slot-calendar-picker"]');
    const detailsTarget = container.querySelector('[data-region="slot-calendar-students"]');
    if (!root || !detailsTarget) {
        return;
    }

    const cmid = Number(container.dataset.cmid || 0);
    const optionid = Number(container.dataset.optionid || 0);

    const labels = {
        students: container.dataset.studentsLabel || 'Booked students',
        teachers: container.dataset.teachersLabel || 'Booked teachers',
        occupancy: container.dataset.occupancyLabel || 'Occupancy',
        price: container.dataset.priceLabel || 'Price',
        moveSlot: container.dataset.moveslotLabel || 'Move slot',
        selectSlot: container.dataset.selectslotLabel || 'Select a slot to view booked students.',
        none: container.dataset.noneLabel || 'None',
        noBookedSlots: container.dataset.nobookedslotsLabel || 'No booked slots on this day.',
    };

    renderDetails(detailsTarget, null, null, labels);

    let data;
    try {
        data = await getBookedSlots(cmid, optionid);
    } catch (error) {
        Notification.exception(error);
        return;
    }

    const slots = Array.isArray(data.slots) ? data.slots : [];
    const details = data.details || {};

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
