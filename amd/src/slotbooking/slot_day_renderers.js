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
 * @param {number|function(?number): number} max maximum selectable slots, or a function resolving
 *   it from the currently-owning option id (merged multi-option calendar)
 * @param {object} options optional { resolveOptionId(key), onOptionSwitch() } - see below
 * @return {object} selection interface
 */
export const createHiddenInputSelection = (selectionInput, max, options = {}) => {
    const selected = new Set(
        String(selectionInput.value || '').split(',').map(v => v.trim()).filter(Boolean)
    );
    // max may be a plain number, or a function(optionId) -> number for a merged multi-option
    // calendar where different options allow different numbers of simultaneous slots (see
    // condition/slotBooking.js's optionMaxMap) - resolved fresh on every toggle rather than once
    // at construction, since which option "owns" the selection can change mid-day.
    const resolveMaxFor = typeof max === 'function' ? max : () => Math.max(1, Number(max || 1));
    const resolveOptionId = typeof options.resolveOptionId === 'function' ? options.resolveOptionId : null;
    const onOptionSwitch = typeof options.onOptionSwitch === 'function' ? options.onOptionSwitch : null;
    // Which option the current selection belongs to. Slot keys are only "start:end" (the server
    // parses them as exactly that pair, so they can't carry the option id) and can collide between
    // two merged options that happen to share a time - so this is tracked explicitly from whatever
    // toggle() was told at click time, not re-derived from the key alone.
    let selectedOptionId = (selected.size > 0 && resolveOptionId)
        ? resolveOptionId(Array.from(selected)[0])
        : null;
    const persist = () => {
        selectionInput.value = Array.from(selected).join(',');
        selectionInput.dataset.activeOptionId = selectedOptionId !== null ? String(selectedOptionId) : '';
        selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
    };
    return {
        get max() {
            return Math.max(1, Number(resolveMaxFor(selectedOptionId) || 1));
        },
        isSelected: (key) => selected.has(key),
        isLocked: () => false,
        isCurrent: () => false,
        // Drop a key from the working set without persisting (used to discard booked keys on
        // render — matches the old behaviour where booked keys were removed from currentKeys but
        // the input was only rewritten on the next user toggle).
        deselect: (key) => {
            selected.delete(key);
            if (selected.size === 0) {
                selectedOptionId = null;
            }
        },
        // optionId: the booking option the clicked slot belongs to, when the caller already knows
        // it unambiguously (e.g. which lane/column was clicked - see renderFixedSlotsEditor and
        // renderSlotList below). Falls back to resolveOptionId(key) when omitted.
        toggle(key, optionId) {
            const resolvedOptionId = (optionId !== undefined && optionId !== null)
                ? optionId
                : (resolveOptionId ? resolveOptionId(key) : null);
            if (selected.has(key)) {
                selected.delete(key);
                if (selected.size === 0) {
                    selectedOptionId = null;
                }
            } else {
                // Only one option's slots can be selected at once (multi-option merged calendar):
                // adding a slot from a different option than what's already selected drops the old
                // selection first, rather than mixing slots from two booking options.
                if (selected.size > 0 && selectedOptionId !== null && resolvedOptionId !== null
                        && selectedOptionId !== resolvedOptionId) {
                    selected.clear();
                    if (onOptionSwitch) {
                        onOptionSwitch();
                    }
                }
                // Cap is resolved for the option actually being added to - if an option switch just
                // cleared the selection above, resolvedOptionId is who we're capping for from here.
                const maxSlots = Math.max(1, Number(resolveMaxFor(resolvedOptionId) || 1));
                if (maxSlots <= 1) {
                    selected.clear();
                } else if (selected.size >= maxSlots) {
                    return;
                }
                selected.add(key);
                selectedOptionId = resolvedOptionId;
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
    if (block.classList.contains('booking-slot--booked') || block.classList.contains('booking-slot--buffer')) {
        return;
    }
    const key = block.dataset.slotKey;
    block.classList.remove('booking-slot--available', 'booking-slot--selected');
    block.classList.add(selection.isSelected(key) ? 'booking-slot--selected' : 'booking-slot--available');
    block.classList.toggle('booking-slot--current', selection.isCurrent(key));
    block.classList.toggle('booking-slot--locked', selection.isLocked(key));
};

/**
 * Render a day's slots as a proportional shared timeline, split into side-by-side "lanes": one
 * per booking option (see slot_dto::build_picker_slots()'s "optionid" field). Every lane shares
 * the same absolute time axis, computed from the day's actual slot range (plus buffer margins),
 * so a slot at a given real time lines up at the same vertical position in every lane instead of
 * just being stacked in whatever order it happens to sort into. Buffer separators are drawn to
 * scale too, since with a real time axis their true duration is what makes the gap between two
 * bookable slots make visual sense.
 *
 * @param {HTMLElement} container
 * @param {Array<object>} daySlots slot DTOs for the active day
 * @param {object} selection selection interface (see createHiddenInputSelection)
 * @param {Intl.DateTimeFormat} timeFormatter
 * @param {Map<number, string>|null} optionColors optional optionid -> CSS color, for the
 *   multi-option merged calendar (see condition/slotBooking.js's buildOptionColorMap())
 * @return {Promise<void>}
 */
export const renderFixedSlotsEditor = async(container, daySlots, selection, timeFormatter, optionColors = null) => {
    container.innerHTML = '';
    if (!Array.isArray(daySlots) || daySlots.length === 0 || !selection) {
        return;
    }

    const sortedSlots = daySlots
        .filter(slot => Number(slot.end || 0) > Number(slot.start || 0))
        .sort((a, b) => Number(a.start || 0) - Number(b.start || 0));
    if (sortedSlots.length === 0) {
        return;
    }

    // One lane per booking option, not a generic interval-partitioning of overlapping times:
    // the whole point of the per-option color is to keep each option visually distinct - a
    // greedy minimal-lane-count algorithm would happily interleave two options into the same
    // column whenever one's slot ends right before the other's starts, defeating that. Lane
    // order follows optionColors' insertion order (== allOptionIds, primary first) when
    // available, so the same option always lands in the same column across every day.
    const laneOrder = optionColors ? Array.from(optionColors.keys()) : [];
    const laneSlotsByOption = new Map();
    sortedSlots.forEach(slot => {
        const optionId = Number(slot.optionid || 0);
        if (!laneSlotsByOption.has(optionId)) {
            laneSlotsByOption.set(optionId, []);
            if (!laneOrder.includes(optionId)) {
                laneOrder.push(optionId);
            }
        }
        laneSlotsByOption.get(optionId).push(slot);
    });
    const lanes = laneOrder
        .filter(optionId => laneSlotsByOption.has(optionId))
        .map(optionId => laneSlotsByOption.get(optionId));

    // Shared time axis across every lane, spanning every slot's own buffer margins too, so a
    // slot right at the start/end of the visible window still gets its warmup/cooldown drawn in
    // full instead of clipping.
    let rangeStart = Infinity;
    let rangeEnd = -Infinity;
    sortedSlots.forEach(slot => {
        const start = Number(slot.start || 0);
        const end = Number(slot.end || 0);
        const warmup = Number(slot.bufferwarmupminutes || 0) * 60;
        const cooldown = Number(slot.buffercooldownminutes || 0) * 60;
        rangeStart = Math.min(rangeStart, start - warmup);
        rangeEnd = Math.max(rangeEnd, end + cooldown);
    });
    const span = Math.max(1, rangeEnd - rangeStart);

    // Minimum vertical space per hour, so the timeline stays readable even when the day's slots
    // span many hours - same idea as the userdefined custom-day timeline (condition/slotBooking.js).
    // Base density is a bit taller than before; the wrapper scrolls (see styles.css) instead of
    // squeezing everything to fit, so a busy day doesn't come out looking cramped.
    const PX_PER_HOUR = 40;
    const MIN_SLOT_PX = 34;
    let timelineHeight = Math.max(200, Math.round((span / 3600) * PX_PER_HOUR));

    // If the base scale would draw the shortest real slot smaller than MIN_SLOT_PX (e.g. a
    // 10-minute slot on a day that also spans several hours), scale the whole timeline up
    // uniformly until it doesn't. This is a uniform scale of the same time axis, so it only ever
    // grows the gaps between slots - it can't introduce overlap between slots that didn't already
    // overlap in real time.
    const shortestDuration = sortedSlots.reduce(
        (min, slot) => Math.min(min, Number(slot.end || 0) - Number(slot.start || 0)),
        Infinity
    );
    if (Number.isFinite(shortestDuration) && shortestDuration > 0) {
        const shortestPx = (shortestDuration / span) * timelineHeight;
        if (shortestPx < MIN_SLOT_PX) {
            timelineHeight = Math.round(timelineHeight * (MIN_SLOT_PX / shortestPx));
        }
    }

    const toPercent = (seconds) => Math.max(0, Math.min(100, ((seconds - rangeStart) / span) * 100));

    // Tick marks: pick the coarsest interval that still keeps ticks readably spaced for the
    // computed height.
    const tickCandidates = [5 * 60, 10 * 60, 15 * 60, 20 * 60, 30 * 60, 3600, 2 * 3600, 3 * 3600];
    const maxTicks = Math.max(4, Math.floor(timelineHeight / 50));
    const tickInterval = tickCandidates.find(c => span / c <= maxTicks) || 3600;
    const firstTick = Math.ceil(rangeStart / tickInterval) * tickInterval;
    const ticks = [];
    for (let tick = firstTick; tick <= rangeEnd; tick += tickInterval) {
        ticks.push({top: toPercent(tick), label: toTimeValue(tick, timeFormatter)});
    }

    const buildSlotBlock = (slot) => {
        const slotStart = Number(slot.start || 0);
        const slotEnd = Number(slot.end || 0);
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

        return {
            key,
            optionid: Number(slot.optionid || 0),
            statusclass: isBooked
                ? 'booking-slot--booked'
                : (selection.isSelected(key) ? 'booking-slot--selected' : 'booking-slot--available'),
            top: toPercent(slotStart),
            height: Math.max(1, toPercent(slotEnd) - toPercent(slotStart)),
            time: `${toTimeValue(slotStart, timeFormatter)} - ${toTimeValue(slotEnd, timeFormatter)}`,
            booked: isBooked,
            bookedlabel: 'Booked',
            manageurl: (isBooked && slot.manageurl) ? String(slot.manageurl) : '',
            priceformatted: (slotPrice > 0 && slot.priceformatted) ? String(slot.priceformatted) : '',
            teachers,
            color: optionColors ? (optionColors.get(Number(slot.optionid)) || '') : '',
        };
    };

    const buildLaneBlocks = (laneSlots) => {
        const laneBlocks = [];
        laneSlots.forEach(slot => {
            const warmup = Number(slot.bufferwarmupminutes || 0) * 60;
            const cooldown = Number(slot.buffercooldownminutes || 0) * 60;
            const slotStart = Number(slot.start || 0);
            const slotEnd = Number(slot.end || 0);

            if (warmup > 0) {
                laneBlocks.push({
                    key: `buffer:leading:${slot.key}`,
                    statusclass: 'booking-slot--buffer',
                    buffer: true,
                    top: toPercent(slotStart - warmup),
                    height: Math.max(0, toPercent(slotStart) - toPercent(slotStart - warmup)),
                });
            }

            laneBlocks.push(buildSlotBlock(slot));

            if (cooldown > 0) {
                laneBlocks.push({
                    key: `buffer:${slot.key}:trailing`,
                    statusclass: 'booking-slot--buffer',
                    buffer: true,
                    top: toPercent(slotEnd),
                    height: Math.max(0, toPercent(slotEnd + cooldown) - toPercent(slotEnd)),
                });
            }
        });
        return laneBlocks;
    };

    const lanesContext = lanes.map(laneSlots => ({blocks: buildLaneBlocks(laneSlots)}));

    const {html, js} = await Templates.renderForPromise('mod_booking/slotbooking/slot_grid_day', {
        heightpx: timelineHeight,
        ticks,
        lanes: lanesContext,
    });
    Templates.replaceNodeContents(container, html, js);

    container.querySelectorAll('.booking-slot').forEach(block => {
        // Reflect current/locked state for the move flow (no-op for the hidden-input adapter).
        refreshTimelineBlock(block, selection);
        if (block.classList.contains('booking-slot--booked') || block.classList.contains('booking-slot--buffer')) {
            return;
        }
        const key = block.dataset.slotKey;
        // The block's own lane already tells us unambiguously which booking option it belongs to -
        // pass it straight through instead of re-deriving it from the key.
        const optionId = block.dataset.optionId ? Number(block.dataset.optionId) : null;
        block.addEventListener('click', () => {
            if (selection.isLocked(key)) {
                return;
            }
            selection.toggle(key, optionId);
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
            optionid: Number(slot.optionid || 0),
            label: `${slot.daylabel || ''} - ${slot.timelabel || key}`,
            priceformatted: (slotPrice > 0 && slot.priceformatted) ? String(slot.priceformatted) : '',
            selected: !isBooked && selection.isSelected(key),
            booked: isBooked,
            manageurl: (isBooked && slot.manageurl) ? String(slot.manageurl) : '',
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
            const optionId = item.dataset.optionId ? Number(item.dataset.optionId) : null;
            selection.toggle(key, optionId);
            container.querySelectorAll('.booking-slot-list-item').forEach(el => {
                if (el.classList.contains('booking-slot-list-item--booked')) {
                    return;
                }
                el.classList.toggle('booking-slot-list-item--selected', selection.isSelected(el.dataset.slotKey));
            });
        });
    });
};
