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
 * Opens the read-only slot availability overview in a modal from the "Booked" status bar.
 *
 * Once a user has used up max_slots_per_user the alreadybooked condition blocks the booking button
 * and with it the slot picker - which is the only place the option's remaining availability is ever
 * shown. This puts that list back where the user goes looking for it.
 *
 * The list is drawn by renderSlotList(), the SAME renderer the booking picker uses, from slot DTOs
 * embedded server-side (see bookingoption_description_slotoverview.mustache and col_showdates).
 * There is therefore exactly one day-grouping implementation and one row markup for both the
 * bookable and the read-only list. The only difference is the selection adapter: this one reports
 * every slot as locked and never toggles anything, so nothing is selectable and there is no submit
 * path. No booking condition is involved.
 *
 * @module     mod_booking/slotbooking/slot_overview_modal
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import {get_string as getString} from 'core/str';
import {renderSlotList} from 'mod_booking/slotbooking/slot_day_renderers';

const SELECTORS = {
    SOURCE: '[data-region="slot-overview-source"]',
    // The "Booked" status bar. It is a plain div rather than a button (see bookit_button.mustache,
    // the {{^isbutton}} branch), so the keyboard affordances are added here.
    TRIGGER: '.booking-button-mainarea',
    // Carries data-itemid (the option id for area="option"), which scopes a trigger to its own
    // option - the options table renders many of these on one page.
    BUTTONAREA: '.booking-button-area',
};

/**
 * A selection interface in which nothing can ever be picked.
 *
 * @param {Array<object>} slots slot DTOs, used to recognise the user's own booked slots
 * @return {object} selection interface (see createHiddenInputSelection)
 */
const createReadOnlySelection = (slots) => {
    const ownKeys = new Set(
        slots.filter(slot => String(slot.status || '') === 'booked')
            .map(slot => String(slot.key || `${slot.start}:${slot.end}`))
    );

    return {
        max: 0,
        isSelected: () => false,
        isLocked: () => true,
        isCurrent: (key) => ownKeys.has(String(key)),
        // Returning null rather than an empty body mirrors the noop() idiom in slotCalendarPicker.
        deselect: () => null,
        toggle: () => null,
    };
};

/**
 * Resolve the embedded slot source belonging to the option a trigger sits in.
 *
 * @param {HTMLElement} trigger the clicked "Booked" bar
 * @return {?HTMLElement}
 */
const findSourceFor = (trigger) => {
    const buttonArea = trigger.closest(SELECTORS.BUTTONAREA);
    const optionid = buttonArea ? String(buttonArea.dataset.itemid || '') : '';
    if (!optionid) {
        return null;
    }

    return document.querySelector(`${SELECTORS.SOURCE}[data-optionid="${CSS.escape(optionid)}"]`);
};

/**
 * Bind the "Booked" status bars to open their option's availability overview.
 *
 * @returns {Promise<void>}
 */
export const init = async() => {
    if (document.body.dataset.slotOverviewModalBound === '1') {
        return;
    }
    document.body.dataset.slotOverviewModalBound = '1';

    const title = await getString('slot_overview_heading', 'mod_booking');

    const openOverview = async(source) => {
        let slots = [];
        try {
            slots = JSON.parse(source.dataset.slots || '[]');
        } catch {
            return;
        }
        if (!Array.isArray(slots) || slots.length === 0) {
            return;
        }

        // Create the modal FIRST and render into its real body element. Rendering into a detached
        // node and handing Modal.create() the resulting outerHTML string would ship the markup but
        // silently drop every listener renderSlotList() binds - the day headers would look right
        // and simply not open.
        const modal = await Modal.create({
            title,
            large: true,
            show: true,
            removeOnClose: true,
        });

        await renderSlotList(modal.getBody()[0], slots, createReadOnlySelection(slots));
    };

    // Delegated: the options table renders its rows dynamically, so binding each bar directly
    // would miss every row that arrives after this runs.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest(SELECTORS.TRIGGER);
        if (!trigger) {
            return;
        }
        const source = findSourceFor(trigger);
        if (source) {
            openOverview(source);
        }
    });

    // Mark the bars that actually do something, so only those look and behave clickable.
    document.querySelectorAll(SELECTORS.TRIGGER).forEach(trigger => {
        if (!findSourceFor(trigger)) {
            return;
        }
        trigger.classList.add('booking-button-mainarea--clickable');
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('tabindex', '0');
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const source = findSourceFor(trigger);
                if (source) {
                    openOverview(source);
                }
            }
        });
    });
};
