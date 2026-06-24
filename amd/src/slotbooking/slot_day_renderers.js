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
 * Shared per-day slot renderers (proportional timeline + flat list) and time helpers.
 *
 * Extracted from condition/slotBooking.js so both the booking and the move flows can render slots
 * the same way (calendar unification — see docs/blueprints/SLOTBOOKING_CALENDAR_UNIFICATION_
 * BLUEPRINT.md).
 *
 * Stage 2: the renderers no longer own the selection. They take a small **selection interface**
 * — { max, isSelected(key), isLocked(key), isCurrent(key), toggle(key), deselect(key) } — so the
 * same renderer works with the booking flow's hidden-input selection (createHiddenInputSelection)
 * and, later, the picker's this.selected/currentKeys/lockedKeys model. The booking flow keeps its
 * exact behaviour via the hidden-input adapter (isLocked/isCurrent always false).
 *
 * @module     mod_booking/slotbooking/slot_day_renderers
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

/**
 * Build a locale-aware time formatter for the given timezone.
 *
 * @param {string} timezone IANA timezone id
 * @return {Intl.DateTimeFormat}
 */
export const createTimeFormatter = (timezone) => {
    // 24h timeline labels (matches the booking behat expectations). NOTE: the move picker / list /
    // table use PHP userdate() (locale 12h/24h), so timeline vs. those is not yet fully aligned —
    // that alignment is deferred to the calendar-unification work (shared renderers + one time
    // source), see SLOTBOOKING_CALENDAR_UNIFICATION_BLUEPRINT.md.
    try {
        return new Intl.DateTimeFormat([], {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: timezone || undefined,
        });
    } catch {
        return new Intl.DateTimeFormat([], {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    }
};

/**
 * Format a unix timestamp as "HH:MM" using the given formatter.
 *
 * @param {number} timestamp unix seconds
 * @param {Intl.DateTimeFormat} formatter
 * @return {string}
 */
export const toTimeValue = (timestamp, formatter) => {
    const parts = formatter.formatToParts(new Date(Number(timestamp) * 1000));
    const hours = parts.find(part => part.type === 'hour')?.value || '00';
    const minutes = parts.find(part => part.type === 'minute')?.value || '00';
    return `${hours}:${minutes}`;
};

/**
 * Selection adapter backed by a hidden input carrying the selected slot keys (csv).
 *
 * This is the booking flow's selection model, expressed through the shared selection interface.
 * Behaviour is identical to the previous inline handling: single-select clears on pick, multi-
 * select honours the max, and every change is written back to the input + dispatched as 'change'.
 * isLocked/isCurrent are always false here (those states only exist in the move flow).
 *
 * @param {HTMLInputElement} selectionInput
 * @param {number} max maximum selectable slots
 * @return {object} selection interface
 */
export const createHiddenInputSelection = (selectionInput, max) => {
    const selected = new Set(
        String(selectionInput.value || '').split(',').map(v => v.trim()).filter(Boolean)
    );
    const maxSlots = Math.max(1, Number(max || 1));
    const persist = () => {
        selectionInput.value = Array.from(selected).join(',');
        selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
    };
    return {
        max: maxSlots,
        isSelected: (key) => selected.has(key),
        isLocked: () => false,
        isCurrent: () => false,
        // Drop a key from the working set without persisting (used to discard booked keys on
        // render — matches the old behaviour where booked keys were removed from currentKeys but
        // the input was only rewritten on the next user toggle).
        deselect: (key) => {
            selected.delete(key);
        },
        toggle(key) {
            if (selected.has(key)) {
                selected.delete(key);
            } else {
                if (maxSlots <= 1) {
                    selected.clear();
                } else if (selected.size >= maxSlots) {
                    return;
                }
                selected.add(key);
            }
            persist();
        },
    };
};

/**
 * Apply the selected/available/current/locked modifier classes to a timeline block from the
 * current selection state. Booked blocks keep their booked class untouched.
 *
 * @param {HTMLElement} block
 * @param {object} selection selection interface
 */
const refreshTimelineBlock = (block, selection) => {
    if (block.classList.contains('booking-slot--booked')) {
        return;
    }
    const key = block.dataset.slotKey;
    block.classList.remove('booking-slot--available', 'booking-slot--selected');
    block.classList.add(selection.isSelected(key) ? 'booking-slot--selected' : 'booking-slot--available');
    block.classList.toggle('booking-slot--current', selection.isCurrent(key));
    block.classList.toggle('booking-slot--locked', selection.isLocked(key));
};

/**
 * Render a day's slots as a proportional timeline (blocks positioned/sized by start/duration).
 *
 * @param {HTMLElement} container
 * @param {Array<object>} daySlots slot DTOs for the active day
 * @param {object} selection selection interface (see createHiddenInputSelection)
 * @param {Intl.DateTimeFormat} timeFormatter
 * @return {Promise<void>}
 */
export const renderFixedSlotsEditor = async(container, daySlots, selection, timeFormatter) => {
    container.innerHTML = '';
    if (!Array.isArray(daySlots) || daySlots.length === 0 || !selection) {
        return;
    }

    const openFrom = Math.min(...daySlots.map(s => Number(s.start || 0)));
    const openUntil = Math.max(...daySlots.map(s => Number(s.end || 0)));
    const span = openUntil - openFrom;
    if (span <= 0) {
        return;
    }
    const slotDurations = daySlots
        .map(s => Number(s.end || 0) - Number(s.start || 0))
        .filter(duration => duration > 0);
    const shortestSlotDuration = Math.min(...slotDurations);
    const minReadableSlotPx = 32;
    const heightForShortestSlot = Math.round((span / shortestSlotDuration) * minReadableSlotPx);
    const heightForSlotCount = 110 + (daySlots.length * 6);
    const timelineHeight = Math.max(140, Math.min(420, Math.max(heightForShortestSlot, heightForSlotCount)));
    // Smallest gap between consecutive slot starts: the readability floor must never exceed it,
    // otherwise back-to-back slots would render taller than their grid spacing and overlap.
    const sortedStarts = daySlots.map(s => Number(s.start || 0)).sort((a, b) => a - b);
    let minStartGap = span;
    for (let i = 1; i < sortedStarts.length; i++) {
        const gap = sortedStarts[i] - sortedStarts[i - 1];
        if (gap > 0 && gap < minStartGap) {
            minStartGap = gap;
        }
    }
    const maxNonOverlapPercent = (minStartGap / span) * 100;
    const minSlotHeightPercent = Math.min((minReadableSlotPx / timelineHeight) * 100, maxNonOverlapPercent);

    const labels = [];
    const ticks = [];
    const tickCandidates = [5 * 60, 10 * 60, 15 * 60, 20 * 60, 30 * 60, 3600, 2 * 3600, 3 * 3600];
    const tickInterval = tickCandidates.find(c => span / c <= 8) || 3600;
    const firstTick = Math.ceil(openFrom / tickInterval) * tickInterval;
    for (let tick = firstTick; tick <= openUntil; tick += tickInterval) {
        const top = ((tick - openFrom) / span) * 100;
        labels.push({top, text: toTimeValue(tick, timeFormatter)});
        ticks.push({top});
    }

    const blocks = [];
    daySlots.forEach(slot => {
        const slotStart = Number(slot.start || 0);
        const slotEnd = Number(slot.end || 0);
        if (slotEnd <= slotStart) {
            return;
        }

        const isBooked = String(slot.status || '') === 'booked' || slot.selectable === false;
        const key = String(slot.key || `${slotStart}:${slotEnd}`);
        if (isBooked) {
            selection.deselect(key);
        }

        const slotPrice = Number(slot.price || 0);
        const teacherList = Array.isArray(slot.teachers) ? slot.teachers : [];
        let teachers = '';
        if (teacherList.length > 0) {
            teachers = teacherList.length <= 2
                ? teacherList.map(t => String(t.fullname || '')).filter(Boolean).join(', ')
                : '\u{1F464} \xd7' + teacherList.length;
        }

        blocks.push({
            key,
            top: ((slotStart - openFrom) / span) * 100,
            height: Math.max(minSlotHeightPercent, ((slotEnd - slotStart) / span) * 100),
            statusclass: isBooked
                ? 'booking-slot--booked'
                : (selection.isSelected(key) ? 'booking-slot--selected' : 'booking-slot--available'),
            time: `${toTimeValue(slotStart, timeFormatter)} - ${toTimeValue(slotEnd, timeFormatter)}`,
            booked: isBooked,
            bookedlabel: 'Booked',
            priceformatted: (slotPrice > 0 && slot.priceformatted) ? String(slot.priceformatted) : '',
            teachers,
        });
    });

    const {html, js} = await Templates.renderForPromise('mod_booking/slotbooking/slot_grid_day', {
        timelineheight: timelineHeight,
        labels,
        ticks,
        blocks,
    });
    Templates.replaceNodeContents(container, html, js);

    container.querySelectorAll('.booking-slot').forEach(block => {
        // Reflect current/locked state for the move flow (no-op for the hidden-input adapter).
        refreshTimelineBlock(block, selection);
        if (block.classList.contains('booking-slot--booked')) {
            return;
        }
        const key = block.dataset.slotKey;
        block.addEventListener('click', () => {
            if (selection.isLocked(key)) {
                return;
            }
            selection.toggle(key);
            // A toggle may clear others (single-select) — refresh every block from state.
            container.querySelectorAll('.booking-slot').forEach(b => refreshTimelineBlock(b, selection));
        });
    });
};

/**
 * Render a day's slots as a flat clickable list.
 *
 * @param {HTMLElement} container
 * @param {Array<object>} slots slot DTOs
 * @param {object} selection selection interface (see createHiddenInputSelection)
 * @return {Promise<void>}
 */
export const renderSlotList = async(container, slots, selection) => {
    if (!Array.isArray(slots) || slots.length === 0 || !selection) {
        container.innerHTML = '';
        return;
    }

    const items = [];
    slots.forEach(slot => {
        const slotStart = Number(slot.start || 0);
        const slotEnd = Number(slot.end || 0);
        if (slotEnd <= slotStart) {
            return;
        }

        const key = String(slot.key || `${slotStart}:${slotEnd}`);
        const isBooked = String(slot.status || '') === 'booked' || slot.selectable === false;
        if (isBooked) {
            selection.deselect(key);
        }

        const slotPrice = Number(slot.price || 0);
        items.push({
            key,
            label: `${slot.daylabel || ''} - ${slot.timelabel || key}`,
            priceformatted: (slotPrice > 0 && slot.priceformatted) ? String(slot.priceformatted) : '',
            selected: !isBooked && selection.isSelected(key),
            booked: isBooked,
        });
    });

    const {html, js} = await Templates.renderForPromise('mod_booking/slotbooking/slot_day_list', {items});
    Templates.replaceNodeContents(container, html, js);

    container.querySelectorAll('.booking-slot-list-item').forEach(item => {
        const key = item.dataset.slotKey;
        item.classList.toggle('booking-slot-list-item--current', !item.classList.contains(
            'booking-slot-list-item--booked') && selection.isCurrent(key));
        item.classList.toggle('booking-slot-list-item--locked', selection.isLocked(key));
        if (item.classList.contains('booking-slot-list-item--booked')) {
            return;
        }
        item.addEventListener('click', () => {
            if (selection.isLocked(key)) {
                return;
            }
            selection.toggle(key);
            container.querySelectorAll('.booking-slot-list-item').forEach(el => {
                if (el.classList.contains('booking-slot-list-item--booked')) {
                    return;
                }
                el.classList.toggle('booking-slot-list-item--selected', selection.isSelected(el.dataset.slotKey));
            });
        });
    });
};
