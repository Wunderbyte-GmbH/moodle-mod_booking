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
 * @module     mod_booking/condition/slotBooking
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {init as initSlotCalendarPicker} from 'mod_booking/slotCalendarPicker';
import {
    saveSelection,
    allowAddItemToCart,
    loadPreBookingPage as loadSwitchedOptionPage,
} from 'mod_booking/slotbooking/repository';
import {
    createTimeFormatter,
    createHiddenInputSelection,
    toTimeValue,
    renderFixedSlotsEditor,
    renderSlotList,
} from 'mod_booking/slotbooking/slot_day_renderers';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Config from 'core/config';
import {get_string as getString} from 'core/str';

const SLOTBOOKING_REFRESH_EVENT = 'mod_booking:slotbooking-refresh';

// A small, distinct palette so each merged option gets a stable, recognizable color across the
// sidebar and the timesheet - cycles if there are more options than colors.
const OPTION_COLOR_PALETTE = ['#0d6efd', '#d63384', '#fd7e14', '#20c997', '#6f42c1', '#dc3545', '#0dcaf0', '#adb5bd'];
const buildOptionColorMap = (optionIds) => {
    const map = new Map();
    optionIds.forEach((id, index) => {
        map.set(Number(id), OPTION_COLOR_PALETTE[index % OPTION_COLOR_PALETTE.length]);
    });
    return map;
};

const SELECTOR = {
    FORMCONTAINER: '.booking-slotbooking-prepage',
    PREPAGEBODY: '.prepage-body',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
    INLINESTARTCONTAINER: '.mod-booking-inlinestart',
    INLINESTARTBUTTON: '.inlinestart-continue-btn',
};

const isActuallyVisible = (el) => {
    if (!el) {
        return false;
    }

    if (el.closest('[aria-hidden="true"]')) {
        return false;
    }

    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
        return false;
    }

    const rect = el.getBoundingClientRect();
    return rect.width > 0 || rect.height > 0 || el.getClientRects().length > 0;
};

const getActiveFormContainer = () => {
    const containers = Array.from(document.querySelectorAll(SELECTOR.FORMCONTAINER))
        .filter(el => isActuallyVisible(el) && el.querySelector('[data-region="slotbooking-form"]'));

    if (containers.length === 0) {
        return null;
    }

    const modalContainers = containers.filter(el => el.closest('div.modal.show'));
    const preferred = modalContainers.length > 0 ? modalContainers : containers;
    return preferred[preferred.length - 1] || null;
};

const getActiveContinueButton = (container) => {
    if (!container) {
        return null;
    }

    const prepageBody = container.closest(SELECTOR.PREPAGEBODY);
    if (!prepageBody || !isActuallyVisible(prepageBody)) {
        return null;
    }

    return prepageBody.querySelector(SELECTOR.CONTINUEBUTTON);
};

const getInlineStartContinueButton = (container) => {
    if (!container) {
        return null;
    }

    const inlinestart = container.closest(SELECTOR.INLINESTARTCONTAINER);
    if (!inlinestart || !isActuallyVisible(inlinestart)) {
        return null;
    }

    return inlinestart.querySelector(SELECTOR.INLINESTARTBUTTON);
};

const getValidationTriggerButton = (container) => {
    return getActiveContinueButton(container) || getInlineStartContinueButton(container);
};

const parseSlots = (jsonInput) => {
    if (!jsonInput) {
        return [];
    }

    try {
        const parsed = JSON.parse(jsonInput.value || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

const parseTeacherSelection = (input) => {
    if (!input || !input.value) {
        return {};
    }

    try {
        const parsed = JSON.parse(input.value || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
};

const serializeTeacherSelection = (input, selection) => {
    if (!input) {
        return;
    }
    input.value = JSON.stringify(selection || {});
};

const getSelectionInput = (container) => {
    return container.querySelector('input[name="slot_selection"]')
        || container.querySelector('select[name="slot_selection"]')
        || container.querySelector('select[name="slot_selection[]"]');
};

const getFormTimeZone = (container) => {
    if (!container) {
        return 'UTC';
    }

    const timezoneInput = container.querySelector('input[name="slot_timezone"]');
    const timezone = String(timezoneInput?.value || '').trim();
    if (!timezone || timezone === '99') {
        return 'UTC';
    }

    try {
        Intl.DateTimeFormat(undefined, {timeZone: timezone});
        return timezone;
    } catch {
        return 'UTC';
    }
};

const toTimestampForDay = (dayTimestamp, timeValue) => {
    if (!timeValue || !/^\d{2}:\d{2}$/.test(timeValue)) {
        return 0;
    }

    const [hours, minutes] = timeValue.split(':').map(Number);
    return Number(dayTimestamp) + (hours * 3600) + (minutes * 60);
};

const toDayKey = (timestamp, timezone) => {
    try {
        const formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone || undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
        return formatter.format(new Date(Number(timestamp) * 1000));
    } catch {
        return '';
    }
};

const snapStartTimestamp = (timestamp, openFrom, openUntil, duration, intervalSeconds) => {
    const minStart = Number(openFrom || 0);
    const maxStart = Math.max(minStart, Number(openUntil || 0) - Math.max(1, Number(duration || 0)));
    const interval = Math.max(1, Number(intervalSeconds || 0));
    const raw = Math.max(minStart, Math.min(Number(timestamp || 0), maxStart));
    const stepsFromOpen = Math.ceil((raw - minStart) / interval);
    const snapped = minStart + (Math.max(0, stepsFromOpen) * interval);
    return Math.max(minStart, Math.min(snapped, maxStart));
};


// Minimum vertical space given to each hour of the opening window, so the timeline stays
// readable even when opening/closing time span most of the day. Taller-than-viewport
// timelines scroll inside their wrapper (see .booking-slot-timeline-wrapper--scroll) instead
// of stretching the page.
const TIMELINE_MIN_PX_PER_HOUR = 30;
const TIMELINE_MIN_HEIGHT_PX = 160;
const TIMELINE_TARGET_PX_PER_TICK = 50;

const renderCustomDayEditor = (container, daySlot, hiddenStartInput, durationSelect, timeFormatter, legendLabels = {}) => {
    if (!daySlot || !hiddenStartInput || !durationSelect) {
        return;
    }

    const openFrom = Number(daySlot.openfrom || 0);
    const openUntil = Number(daySlot.openuntil || 0);
    const startIntervalMinutes = Math.max(1, Number(daySlot.startintervalminutes || 30));
    const startIntervalSeconds = startIntervalMinutes * 60;
    if (openFrom <= 0 || openUntil <= openFrom) {
        return;
    }

    container.innerHTML = '';

    const existingStart = Number(hiddenStartInput.value || 0);
    const selectedDuration = Number(durationSelect.value || 0);
    const defaultStart = Math.max(openFrom, Math.min(existingStart || openFrom, openUntil - Math.max(1, selectedDuration)));

    const info = document.createElement('div');
    info.className = 'small text-muted mb-2';
    info.textContent = `${daySlot.daylabel}: ${toTimeValue(openFrom, timeFormatter)} - ${toTimeValue(openUntil, timeFormatter)}`;
    container.appendChild(info);

    const controls = document.createElement('div');
    controls.className = 'd-flex align-items-center gap-2 mb-2';

    const label = document.createElement('label');
    label.className = 'small mb-0';
    label.textContent = 'Start';
    controls.appendChild(label);

    const timeInput = document.createElement('input');
    timeInput.type = 'time';
    timeInput.className = 'form-control form-control-sm booking-slot-time-input';
    timeInput.step = String(startIntervalSeconds);
    timeInput.min = toTimeValue(openFrom, timeFormatter);
    timeInput.max = toTimeValue(openUntil, timeFormatter);
    timeInput.value = toTimeValue(defaultStart, timeFormatter);
    controls.appendChild(timeInput);

    container.appendChild(controls);

    const legend = document.createElement('div');
    legend.className = 'd-flex align-items-center gap-3 mb-1';
    [
        {className: 'booking-slot-legend-swatch--mine', text: legendLabels.mine || 'Your booking'},
        {className: 'booking-slot-legend-swatch--blocked', text: legendLabels.blocked || 'Not bookable'},
    ].forEach(item => {
        const entry = document.createElement('div');
        entry.className = 'd-flex align-items-center gap-1 small text-muted';

        const swatch = document.createElement('span');
        swatch.className = `booking-slot-legend-swatch ${item.className}`;
        entry.appendChild(swatch);

        const text = document.createElement('span');
        text.textContent = item.text;
        entry.appendChild(text);

        legend.appendChild(entry);
    });
    container.appendChild(legend);

    const timelineScroll = document.createElement('div');
    timelineScroll.className = 'booking-slot-timeline-wrapper--scroll';
    container.appendChild(timelineScroll);

    const timelineWrapper = document.createElement('div');
    timelineWrapper.className = 'booking-slot-timeline-wrapper d-flex align-items-stretch gap-1';
    timelineScroll.appendChild(timelineWrapper);

    const timelineSpan = openUntil - openFrom;
    const timelineHeight = Math.max(
        TIMELINE_MIN_HEIGHT_PX,
        Math.round((timelineSpan / 3600) * TIMELINE_MIN_PX_PER_HOUR)
    );

    const labelsCol = document.createElement('div');
    labelsCol.className = 'booking-slot-timeline-labels position-relative flex-shrink-0';
    labelsCol.style.height = `${timelineHeight}px`;
    timelineWrapper.appendChild(labelsCol);

    const timeline = document.createElement('div');
    timeline.className = 'booking-slot-timeline booking-slot-timeline--clickable border rounded position-relative flex-grow-1';
    timeline.style.height = `${timelineHeight}px`;
    timelineWrapper.appendChild(timeline);

    if (timelineSpan > 0) {
        const tickCandidates = [5 * 60, 10 * 60, 15 * 60, 20 * 60, 30 * 60, 3600, 2 * 3600, 3 * 3600];
        const maxTicks = Math.max(4, Math.floor(timelineHeight / TIMELINE_TARGET_PX_PER_TICK));
        const tickInterval = tickCandidates.find(c => timelineSpan / c <= maxTicks) || 3600;
        const firstTick = Math.ceil(openFrom / tickInterval) * tickInterval;
        for (let tick = firstTick; tick <= openUntil; tick += tickInterval) {
            const ratio = (tick - openFrom) / timelineSpan;

            const lbl = document.createElement('div');
            lbl.className = 'booking-slot-timeline-label position-absolute text-muted';
            lbl.style.top = `${ratio * 100}%`;
            lbl.textContent = toTimeValue(tick, timeFormatter);
            labelsCol.appendChild(lbl);

            const tickLine = document.createElement('div');
            tickLine.className = 'booking-slot-timeline-tick position-absolute';
            tickLine.style.top = `${ratio * 100}%`;
            timeline.appendChild(tickLine);
        }
    }

    const addBookedBlock = (start, end, mine) => {
        const span = openUntil - openFrom;
        if (span <= 0) {
            return;
        }

        const clippedStart = Math.max(openFrom, Number(start || 0));
        const clippedEnd = Math.min(openUntil, Number(end || 0));
        if (clippedEnd <= clippedStart) {
            return;
        }

        const top = ((clippedStart - openFrom) / span) * 100;
        const height = ((clippedEnd - clippedStart) / span) * 100;

        const block = document.createElement('div');
        block.className = 'booking-slot-booked-range position-absolute '
            + (mine ? 'booking-slot-booked-range--mine' : 'booking-slot-booked-range--blocked');
        block.title = mine ? (legendLabels.mine || 'Your booking') : (legendLabels.blocked || 'Not bookable');
        block.style.top = `${top}%`;
        block.style.height = `${Math.max(2, height)}%`;
        timeline.appendChild(block);
    };

    (Array.isArray(daySlot.bookedranges) ? daySlot.bookedranges : []).forEach(range => {
        addBookedBlock(range.start, range.end, Boolean(range.mine));
    });

    const selectionBlock = document.createElement('div');
    selectionBlock.className = 'booking-slot-selection position-absolute';
    selectionBlock.style.top = '0';
    selectionBlock.style.height = '2px';
    timeline.appendChild(selectionBlock);

    const syncStart = (timestamp) => {
        const duration = Math.max(1, Number(durationSelect.value || 0));
        const clamped = snapStartTimestamp(
            timestamp,
            openFrom,
            openUntil,
            duration,
            startIntervalSeconds
        );
        hiddenStartInput.value = String(clamped);
        timeInput.value = toTimeValue(clamped, timeFormatter);

        const span = openUntil - openFrom;
        const top = span > 0 ? ((clamped - openFrom) / span) * 100 : 0;
        const height = span > 0 ? (duration / span) * 100 : 0;
        selectionBlock.style.top = `${Math.max(0, Math.min(100, top))}%`;
        selectionBlock.style.height = `${Math.max(2, Math.min(100, height))}%`;

        // Programmatic value assignment above does not fire a native 'change' event; dispatch one
        // so live-validation wiring (see setupInteractiveUi) reacts to every start/duration pick,
        // whether it came from the time input, the duration select, or a timeline click.
        hiddenStartInput.dispatchEvent(new Event('change', {bubbles: true}));
    };

    timeInput.addEventListener('change', () => {
        syncStart(toTimestampForDay(daySlot.start, timeInput.value));
    });

    durationSelect.addEventListener('change', () => {
        syncStart(Number(hiddenStartInput.value || openFrom));
    });

    timeline.addEventListener('click', (event) => {
        const rect = timeline.getBoundingClientRect();
        const ratio = rect.height > 0 ? (event.clientY - rect.top) / rect.height : 0;
        const timestamp = openFrom + Math.round((openUntil - openFrom) * Math.max(0, Math.min(1, ratio)));
        syncStart(timestamp);
    });

    syncStart(defaultStart);
};


const getSelectedSlotKeys = (selectionInput) => {
    if (!selectionInput) {
        return [];
    }

    if (selectionInput.tagName === 'SELECT') {
        if (selectionInput.multiple) {
            return Array.from(selectionInput.selectedOptions || [])
                .map(option => String(option.value || '').trim())
                .filter(Boolean);
        }

        const singleValue = String(selectionInput.value || '').trim();
        return singleValue ? [singleValue] : [];
    }

    return String(selectionInput.value || '')
        .split(',')
        .map(value => value.trim())
        .filter(Boolean);
};

const ensureTeacherContainer = (container, anchor) => {
    let teacherContainer = container.querySelector('[data-region="slot-teacher-selection"]');
    if (teacherContainer) {
        return teacherContainer;
    }

    teacherContainer = document.createElement('div');
    teacherContainer.dataset.region = 'slot-teacher-selection';
    teacherContainer.className = 'mt-3';

    if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(teacherContainer, anchor.nextSibling);
    } else {
        container.appendChild(teacherContainer);
    }

    return teacherContainer;
};

const ensureFeedbackRegion = (container, anchor) => {
    let feedbackRegion = container.querySelector('[data-region="slot-live-feedback"]');
    if (feedbackRegion) {
        return feedbackRegion;
    }

    feedbackRegion = document.createElement('div');
    feedbackRegion.dataset.region = 'slot-live-feedback';
    feedbackRegion.className = 'small mt-2';
    feedbackRegion.setAttribute('aria-live', 'polite');

    if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(feedbackRegion, anchor.nextSibling);
    } else {
        container.appendChild(feedbackRegion);
    }

    return feedbackRegion;
};

const renderTeacherSelection = async(
    teacherContainer,
    selectedSlotKeys,
    slotsMap,
    requiredCount,
    hiddenInput,
    examinersLabel
) => {
    const currentSelection = parseTeacherSelection(hiddenInput);

    const selectedSet = new Set(selectedSlotKeys);
    Object.keys(currentSelection).forEach(slotKey => {
        if (!selectedSet.has(slotKey)) {
            delete currentSelection[slotKey];
        }
    });

    if (requiredCount <= 0 || selectedSlotKeys.length === 0) {
        teacherContainer.innerHTML = '';
        serializeTeacherSelection(hiddenInput, {});
        return;
    }

    const rows = [];
    selectedSlotKeys.forEach(slotKey => {
        const slot = slotsMap.get(slotKey);
        if (!slot) {
            return;
        }

        const teachers = Array.isArray(slot.teachers) ? slot.teachers : [];
        const availableIds = teachers
            .map(teacher => Number(teacher.id || 0))
            .filter(id => id > 0);

        const existing = Array.isArray(currentSelection[slotKey]) ? currentSelection[slotKey] : [];
        const preselected = existing
            .map(id => Number(id || 0))
            .filter(id => id > 0 && availableIds.includes(id));

        const options = [];
        teachers.forEach(teacher => {
            const id = Number(teacher.id || 0);
            if (id <= 0) {
                return;
            }
            options.push({
                value: String(id),
                label: String(teacher.fullname || id),
                selected: preselected.includes(id),
            });
        });

        rows.push({
            slotkey: slotKey,
            slotlabel: `${slot.daylabel || ''} · ${slot.timelabel || slotKey}`,
            multiple: requiredCount > 1,
            size: Math.min(8, Math.max(requiredCount + 1, teachers.length)),
            showempty: requiredCount <= 1,
            options,
        });
    });

    const {html, js} = await Templates.renderForPromise('mod_booking/slotbooking/slot_teacher_select', {
        heading: `${examinersLabel}: ${requiredCount}`,
        rows,
    });
    Templates.replaceNodeContents(teacherContainer, html, js);

    teacherContainer.querySelectorAll('select[data-slot-key]').forEach(select => {
        const slotKey = select.dataset.slotKey;
        const persistSelection = () => {
            const selectedIds = Array.from(select.selectedOptions || [])
                .map(option => Number(option.value || 0))
                .filter(id => id > 0);

            const normalized = Array.from(new Set(selectedIds));
            if (requiredCount > 0 && normalized.length > requiredCount) {
                normalized.splice(requiredCount);
            }

            if (normalized.length === 0) {
                delete currentSelection[slotKey];
            } else {
                currentSelection[slotKey] = normalized;
            }

            serializeTeacherSelection(hiddenInput, currentSelection);
        };

        select.addEventListener('change', persistSelection);
    });

    serializeTeacherSelection(hiddenInput, currentSelection);
};

/**
 * Init function.
 */
export async function init() {
    const container = getActiveFormContainer();
    if (!container) {
        return;
    }

    const optionid = container.dataset.optionid;
    const userid = container.dataset.userid;
    const formRegion = container.querySelector('[data-region="slotbooking-form"]');
    if (!formRegion) {
        return;
    }

    // Multi-option sidebar: container.dataset.optionids (JSON array, primary id first) is only
    // present when the calendar merges more than one option's slots - see
    // templates/condition/slotbooking.mustache and bo_availability/conditions/slotbooking.php.
    let allOptionIds = [Number(optionid) || 0];
    try {
        const parsedIds = JSON.parse(container.dataset.optionids || '[]');
        if (Array.isArray(parsedIds) && parsedIds.length > 0) {
            allOptionIds = parsedIds.map(id => Number(id)).filter(id => id > 0);
        }
    } catch {
        allOptionIds = [Number(optionid) || 0];
    }
    const additionalOptionIds = allOptionIds.filter(id => id !== Number(optionid));
    const optionColors = buildOptionColorMap(allOptionIds);

    // The optionid that currently "owns" the user's selection - starts as the primary option and
    // switches whenever the user picks a slot belonging to a different merged-in option (see
    // enforceSingleOptionSelection() below). This is what actually gets booked on Continue.
    let activeOptionId = Number(optionid) || 0;
    let calendarPickerInstance = null;
    const slotbookingSwitchedOptionMessage = await getString('slotbooking_switched_option', 'mod_booking');

    // Merged options can have entirely different prepage condition sequences (different step
    // counts, different conditions) - there is no reliable way to continue THIS page's wizard for
    // a different option. Once a slot from a non-primary merged option is actually booked (see the
    // FORM_SUBMITTED handler below), send the browser to that option's own booking page instead,
    // where a correctly-sequenced flow starts fresh. Only present for multi-option calendars (see
    // slotbooking.php render_page()).
    let optionUrls = new Map();
    if (container.dataset.optionurls) {
        try {
            const parsedurls = JSON.parse(container.dataset.optionurls);
            optionUrls = new Map(Object.entries(parsedurls).map(([id, url]) => [Number(id), String(url)]));
        } catch (e) {
            optionUrls = new Map();
        }
    }

    // Try to book a merged-in, non-primary option's slot selection straight into the shopping
    // cart, skipping this page's wizard entirely - requesting page 0 of THAT option's own prepage
    // sequence (with slotbooking explicitly skipped - see below) is enough: if nothing else is
    // blocking, bo_info::load_pre_booking_page() commits the add-to-cart itself and returns the
    // confirmation page. Only when something else genuinely needs the user's attention (another
    // prepage condition for that option) do we fall back to a full-page redirect to that option's
    // own booking page, where it can be shown correctly.
    const addSwitchedOptionToCart = async(targetOptionId, targetUserId) => {
        const fallbackToOptionPage = () => {
            window.location.href = optionUrls.get(targetOptionId);
        };

        try {
            // Skipping slotbooking's own page below only makes sense once we KNOW its selection is
            // actually bookable right now - re-picking a slot the user already holds (e.g. a repeat
            // purchase past max_slots_per_user) is still rejected by hard_block() same as always,
            // but forcibly skipping slotbooking would bypass that check and silently proceed to an
            // empty-handed "success". save_slot_selection runs the exact same bookability check
            // hard_block() does, so validate with it first and surface the real reason if it fails,
            // instead of redirecting anywhere.
            const keys = getSelectedSlotKeys(getSelectionInput(container));
            const teacherSelectionInputEl = container.querySelector('input[name="slot_teacher_selection"]');
            const teacherMap = teacherSelectionInputEl ? parseTeacherSelection(teacherSelectionInputEl) : {};
            const validation = await saveSelection(targetOptionId, targetUserId, keys, teacherMap);
            if (!validation.valid) {
                const messages = (validation.errors && typeof validation.errors === 'object')
                    ? Object.values(validation.errors).filter(Boolean)
                    : [];
                Notification.addNotification({
                    message: messages.length > 0 ? String(messages[0]) : slotbookingSwitchedOptionMessage,
                    type: 'danger',
                });
                return;
            }

            const allowResult = await allowAddItemToCart(targetOptionId, targetUserId);
            if (![0, 1, 5].includes(Number(allowResult?.success))) {
                fallbackToOptionPage();
                return;
            }

            // Slotbooking's own is_available() is intentionally hard-coded to "not available"
            // whenever slot booking is enabled at all (its page stays visible/re-editable
            // throughout the flow by design - see slotbooking.php condition::is_available()) - the
            // actual slot-selection gate is hard_block(), checked separately (just confirmed above
            // via save_slot_selection). Without skipping it here, page 0 would just re-render its
            // own calendar again instead of progressing past it.
            const pageResult = await loadSwitchedOptionPage(targetOptionId, targetUserId, 0, 'slotbooking');
            const templates = String(pageResult?.template || '').split(',');
            if (templates.includes('mod_booking/condition/confirmation')) {
                window.location.href = Config.wwwroot + '/local/shopping_cart/checkout.php';
            } else {
                fallbackToOptionPage();
            }
        } catch (e) {
            fallbackToOptionPage();
        }
    };

    // Wires the sidebar's per-option toggle rows and its "invert selection" button to the
    // calendar picker's slotFilter, purely client-side (all merged options' slots are already
    // loaded - see slot_calendar_data in slotbooking_form.php). No-op if there is no sidebar
    // (single option) or no calendar picker yet (e.g. list/selectgroups view modes aren't merged).
    const setupSidebar = (picker) => {
        if (!picker) {
            return;
        }
        const sidebarRegion = container.querySelector('[data-region="slotbooking-sidebar"]');
        if (!sidebarRegion || sidebarRegion.dataset.slotSidebarBound === '1') {
            return;
        }
        sidebarRegion.dataset.slotSidebarBound = '1';

        const items = Array.from(sidebarRegion.querySelectorAll('.booking-slotbooking-sidebar-item'));
        const excludedOptionIds = new Set();

        const applyFilter = () => {
            picker.setSlotFilter(excludedOptionIds.size > 0
                ? (slot) => !excludedOptionIds.has(Number(slot.optionid))
                : null);
        };

        const toggleOne = (item) => {
            const itemOptionId = Number(item.dataset.optionid || 0);
            if (!itemOptionId) {
                return;
            }
            if (excludedOptionIds.has(itemOptionId)) {
                excludedOptionIds.delete(itemOptionId);
                item.classList.remove('booking-slotbooking-sidebar-item-filtered');
            } else {
                excludedOptionIds.add(itemOptionId);
                item.classList.add('booking-slotbooking-sidebar-item-filtered');
            }
        };

        items.forEach(item => {
            const itemColor = optionColors.get(Number(item.dataset.optionid || 0));
            if (itemColor) {
                item.style.borderLeftColor = itemColor;
            }
            item.addEventListener('click', () => {
                toggleOne(item);
                applyFilter();
            });
            item.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggleOne(item);
                    applyFilter();
                }
            });
        });

        const invertButton = sidebarRegion.querySelector('.booking-slotbooking-sidebar-invert');
        if (invertButton) {
            invertButton.addEventListener('click', () => {
                items.forEach(toggleOne);
                applyFilter();
            });
        }
    };

    const dynamicForm = new DynamicForm(formRegion, 'mod_booking\\form\\condition\\slotbooking_form');
    let currentLoadArgs = {
        id: optionid,
        userid,
        additionalids: additionalOptionIds.length > 0 ? JSON.stringify(additionalOptionIds) : '',
    };
    const setupInteractiveUi = async() => {
        // Scope to the booking form: in Fall 2 the move tab carries its own slot-calendar-picker
        // region, which must not be picked up by the book-again slot selection.
        const calendarRoot = formRegion.querySelector('[data-region="slot-calendar-picker"]');
        const selectionInput = getSelectionInput(container);
        const jsonInput = container.querySelector('input[name="slot_calendar_data"]');
        const customEditorRoot = container.querySelector('[data-region="slot-custom-editor"]');
        const customStartInput = container.querySelector('input[name="slot_custom_start"]');
        const fixedEditorRoot = container.querySelector('[data-region="slot-fixed-editor"]');
        const listPickerRoot = container.querySelector('[data-region="slot-list-picker"]');
        const customDurationSelect = container.querySelector('select[name="slot_custom_duration"]');
        const teacherSelectionInput = container.querySelector('input[name="slot_teacher_selection"]');
        const examinersLabelInput = container.querySelector('input[name="slot_examiners_per_slot_label"]');
        const usePricesInput = container.querySelector('input[name="slot_use_prices"]');
        const teachersRequiredInput = container.querySelector('input[name="slot_teachers_required_count"]');
        const legendMineInput = container.querySelector('input[name="slot_legend_mine_label"]');
        const legendBlockedInput = container.querySelector('input[name="slot_legend_blocked_label"]');
        const timezone = getFormTimeZone(container);
        const timeFormatter = createTimeFormatter(timezone);
        const examinersLabel = (examinersLabelInput?.value || 'Examiners per slot').trim();
        const usePrices = Number(usePricesInput?.value || 0) === 1;
        const legendLabels = {
            mine: (legendMineInput?.value || 'Your booking').trim(),
            blocked: (legendBlockedInput?.value || 'Not bookable').trim(),
        };

        if (!selectionInput) {
            return;
        }

        // Every slot mode (calendar grid, multi-select list, single-select selectgroups and the
        // userdefined custom-day calendar) now sources its selectable slots from the embedded
        // slot_calendar_data hidden field, filled by the form's definition(). The fixed modes carry a
        // byte-identical copy of the former get_slots webservice payload, so no round-trip is needed and
        // the snapshot survives mform reloads alongside the slot_selection state.
        const slots = parseSlots(jsonInput);

        if (calendarRoot && customStartInput && customDurationSelect && customEditorRoot && slots.length > 0) {
            let lastCustomDaySlot = slots[0] || null;
            let customCalendarPicker = null;

            const findCustomDaySlot = (dayKey, daySlots) => {
                if (Array.isArray(daySlots) && daySlots.length > 0) {
                    return daySlots[0];
                }

                if (dayKey) {
                    const fromAllSlots = slots.find(slot => {
                        return toDayKey(slot.start || 0, timezone) === dayKey;
                    });
                    if (fromAllSlots) {
                        return fromAllSlots;
                    }
                }

                return null;
            };

            const renderResolvedCustomDay = (dayKey = '', daySlots = []) => {
                const daySlot = findCustomDaySlot(dayKey, daySlots);
                if (!daySlot) {
                    return false;
                }

                lastCustomDaySlot = daySlot;
                renderCustomDayEditor(
                    customEditorRoot,
                    daySlot,
                    customStartInput,
                    customDurationSelect,
                    timeFormatter,
                    legendLabels
                );
                customEditorRoot.style.display = '';
                return true;
            };

            const renderFromPickerState = () => {
                if (!customCalendarPicker) {
                    return false;
                }

                const activeDay = String(customCalendarPicker.activeDay || '');
                const activeDaySlots = activeDay && customCalendarPicker.slotsByDay instanceof Map
                    ? (customCalendarPicker.slotsByDay.get(activeDay) || [])
                    : [];

                if (renderResolvedCustomDay(activeDay, activeDaySlots)) {
                    return true;
                }

                if (lastCustomDaySlot) {
                    renderCustomDayEditor(
                        customEditorRoot,
                        lastCustomDaySlot,
                        customStartInput,
                        customDurationSelect,
                        timeFormatter,
                        legendLabels
                    );
                    customEditorRoot.style.display = '';
                    return true;
                }

                return false;
            };

            if (!calendarRoot.dataset.slotCalendarInitialized) {
                customCalendarPicker = initSlotCalendarPicker(calendarRoot, {
                    slots,
                    timezone,
                    maxSelection: 1,
                    dayCountFormatter: (daySlots) => {
                        const daySlot = Array.isArray(daySlots) ? daySlots[0] : null;
                        return daySlot && daySlot.bookable ? 'Buchbar' : 'Nicht buchbar';
                    },
                    dayStateResolver: (daySlots) => {
                        const daySlot = Array.isArray(daySlots) ? daySlots[0] : null;
                        return daySlot && daySlot.bookable ? '' : 'full';
                    },
                    slotFilter: () => false,
                    emptySlotListText: '',
                    onChange: () => {
                        // Custom mode persists start/duration via dedicated inputs.
                    },
                    onDayChange: (dayKey, daySlots) => {
                        renderResolvedCustomDay(dayKey, daySlots);
                    },
                });

                calendarRoot.dataset.slotCalendarInitialized = '1';
                renderFromPickerState();
                window.requestAnimationFrame(() => {
                    if (!customEditorRoot.childElementCount) {
                        renderFromPickerState();
                    }
                });
            }

            return;
        }

        const slotsMap = new Map();
        slots.forEach(slot => {
            const key = String(slot.key || `${slot.start}:${slot.end}`);
            slotsMap.set(key, slot);
        });

        // Each merged option can allow a different number of simultaneous slots
        // (max_slots_per_user) - derived directly from the already-loaded slots (every slot carries
        // its own option's limit as `maxslots`; see slot_dto.php), rather than the single
        // slot_max_selection hidden field, which only ever reflects the PRIMARY option's own limit.
        const optionMaxMap = new Map();
        slots.forEach(slot => {
            const oid = Number(slot.optionid || optionid);
            if (!optionMaxMap.has(oid)) {
                optionMaxMap.set(oid, Math.max(1, Number(slot.maxslots || 1)));
            }
        });

        let lastKnownSelectionKeys = [];
        const resolveSlotOptionId = (key) => {
            const slot = slotsMap.get(key);
            return slot ? Number(slot.optionid || optionid) : Number(optionid);
        };

        const setActiveOptionId = (newOptionId) => {
            if (newOptionId === activeOptionId) {
                return;
            }
            activeOptionId = newOptionId;
            const idInput = formRegion.querySelector('input[name="id"]');
            if (idInput) {
                idInput.value = String(activeOptionId);
            }
        };

        // Only one option's slots can be booked in a single pass: if the newly picked slot belongs
        // to a different option than the current selection, drop the old selection rather than
        // submitting a mix of slots from two different booking options.
        const enforceSingleOptionSelection = (selection) => {
            if (selection.length === 0) {
                lastKnownSelectionKeys = [];
                return selection;
            }

            const addedKey = selection.find(key => !lastKnownSelectionKeys.includes(key));
            const targetOptionId = addedKey ? resolveSlotOptionId(addedKey) : resolveSlotOptionId(selection[0]);

            const resolved = selection.filter(key => resolveSlotOptionId(key) === targetOptionId);
            if (resolved.length !== selection.length) {
                Notification.addNotification({
                    message: slotbookingSwitchedOptionMessage,
                    type: 'info',
                });
                // The picker's own selected keys still hold the dropped, wrong-option entries at
                // this point (its onChange fired with them already included) - correct its internal
                // state too, so the calendar doesn't keep highlighting a slot that no longer counts
                // as selected once selectionInput.value is overwritten below.
                if (calendarPickerInstance) {
                    calendarPickerInstance.selected = new Set(resolved);
                    calendarPickerInstance.render();
                }
            }

            setActiveOptionId(targetOptionId);
            lastKnownSelectionKeys = resolved;
            return resolved;
        };

        // Anchor the teacher-selection/live-feedback regions after the WHOLE calendar+fixed-editor
        // row (not just fixedEditorRoot, which is only one flex item inside that row) so they land
        // on their own line below both columns instead of squeezing in as a third flex item.
        const calendarWrapper = container.querySelector('[data-region="slot-calendar-wrapper"]');
        const teacherAnchor = listPickerRoot || calendarWrapper || fixedEditorRoot || calendarRoot || selectionInput;
        const teacherContainer = ensureTeacherContainer(container, teacherAnchor);
        const teachersRequired = Math.max(0, Number(teachersRequiredInput?.value || 0));

        const refreshTeacherSelection = () => {
            const selectedSlotKeys = getSelectedSlotKeys(selectionInput);
            return renderTeacherSelection(
                teacherContainer,
                selectedSlotKeys,
                slotsMap,
                teachersRequired,
                teacherSelectionInput,
                examinersLabel
            );
        };

        // Live server-side pre-validation: on every selection change we ask the save_slot_selection
        // webservice whether the current selection is bookable and what it costs, and surface the first
        // error (or the running total price) inline. The DynamicForm submit stays the final gate.
        const feedbackRegion = ensureFeedbackRegion(container, teacherContainer);
        const renderLiveFeedback = (result) => {
            feedbackRegion.classList.remove('text-danger', 'text-success');
            if (!result) {
                feedbackRegion.textContent = '';
                return;
            }

            if (!result.valid) {
                const messages = (result.errors && typeof result.errors === 'object')
                    ? Object.values(result.errors).filter(Boolean)
                    : [];
                if (messages.length > 0) {
                    feedbackRegion.classList.add('text-danger');
                    feedbackRegion.textContent = String(messages[0]);
                } else {
                    feedbackRegion.textContent = '';
                }
                return;
            }

            const price = Number(result.price || 0);
            if (usePrices && price > 0) {
                const selectedKeys = getSelectedSlotKeys(selectionInput);
                const currency = String(slotsMap.get(selectedKeys[0])?.currency || '').trim();
                feedbackRegion.classList.add('text-success');
                feedbackRegion.textContent = `${price.toFixed(2)}${currency ? ' ' + currency : ''}`;
            } else {
                feedbackRegion.textContent = '';
            }
        };

        let liveValidateTimer = null;
        const liveValidate = () => {
            if (liveValidateTimer) {
                window.clearTimeout(liveValidateTimer);
            }
            liveValidateTimer = window.setTimeout(async() => {
                const keys = getSelectedSlotKeys(selectionInput);
                if (keys.length === 0) {
                    renderLiveFeedback(null);
                    return;
                }
                const teacherMap = parseTeacherSelection(teacherSelectionInput);
                try {
                    const result = await saveSelection(Number(activeOptionId) || 0, Number(userid) || 0, keys, teacherMap);
                    renderLiveFeedback(result);
                } catch (e) {
                    renderLiveFeedback(null);
                }
            }, 300);
        };

        if (calendarRoot && !calendarRoot.dataset.slotCalendarInitialized) {
            const maxInput = container.querySelector('input[name="slot_max_selection"]');
            const maxSlots = Number(maxInput?.value || 1);
            // Resolves to whichever option currently owns the selection - falls back to the primary
            // option's maxSlots when nothing is selected yet.
            const resolveMaxSlots = (optId) => optionMaxMap.get(Number(optId)) || maxSlots;

            const calendarOptions = {
                slots,
                timezone,
                maxSelection: maxSlots,
                initialSelection: fixedEditorRoot
                    ? []
                    : (selectionInput.value
                        ? selectionInput.value.split(',').map(v => v.trim()).filter(Boolean)
                        : []),
                onChange: fixedEditorRoot
                    ? () => {}
                    : (selection) => {
                        const resolved = enforceSingleOptionSelection(selection);
                        selectionInput.value = resolved.join(',');
                        selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
                    },
            };

            if (fixedEditorRoot) {
                calendarOptions.showSlotList = false;
                calendarOptions.showPriceLegend = usePrices;
                const renderFixedEditorForDay = async(daySlots) => {
                    const normalizedDaySlots = Array.isArray(daySlots) ? daySlots : [];
                    if (normalizedDaySlots.length === 0) {
                        fixedEditorRoot.innerHTML = '';
                        fixedEditorRoot.style.display = 'none';
                        return;
                    }

                    fixedEditorRoot.style.display = '';
                    await renderFixedSlotsEditor(
                        fixedEditorRoot,
                        normalizedDaySlots,
                        createHiddenInputSelection(selectionInput, resolveMaxSlots, {
                            resolveOptionId: resolveSlotOptionId,
                            onOptionSwitch: () => Notification.addNotification({
                                message: slotbookingSwitchedOptionMessage,
                                type: 'info',
                            }),
                        }),
                        timeFormatter,
                        optionColors
                    );

                    if (!fixedEditorRoot.childElementCount) {
                        fixedEditorRoot.style.display = 'none';
                    }
                };
                calendarOptions.onDayChange = (_dayKey, daySlots) => {
                    renderFixedEditorForDay(daySlots);
                };
            }

            calendarPickerInstance = initSlotCalendarPicker(calendarRoot, calendarOptions);
            calendarRoot.dataset.slotCalendarInitialized = '1';
            setupSidebar(calendarPickerInstance);
        }

        if (listPickerRoot) {
            const listMaxInput = container.querySelector('input[name="slot_max_selection"]');
            const listMaxSlots = Number(listMaxInput?.value || 1);
            const resolveListMaxSlots = (optId) => optionMaxMap.get(Number(optId)) || listMaxSlots;
            await renderSlotList(listPickerRoot, slots, createHiddenInputSelection(selectionInput, resolveListMaxSlots, {
                resolveOptionId: resolveSlotOptionId,
                onOptionSwitch: () => Notification.addNotification({
                    message: slotbookingSwitchedOptionMessage,
                    type: 'info',
                }),
            }));
        }

        if (!selectionInput.dataset.slotSelectionBound) {
            selectionInput.addEventListener('change', refreshTeacherSelection);
            selectionInput.addEventListener('change', liveValidate);
            // Keep activeOptionId (and the hidden "id" field) in sync no matter which selection
            // mechanism fired the change - the calendar picker's onChange already calls
            // setActiveOptionId itself, but the fixedEditorRoot/listPickerRoot pickers only go
            // through createHiddenInputSelection, which doesn't know about booking options at all.
            selectionInput.addEventListener('change', () => {
                const keys = getSelectedSlotKeys(selectionInput);
                if (keys.length > 0) {
                    // Prefer the option id createHiddenInputSelection stamped onto the input itself
                    // (unambiguous - it came from the clicked slot's own lane) over
                    // resolveSlotOptionId(key), which can't tell two merged options apart when they
                    // happen to share a "start:end" key.
                    const activeFromDataset = Number(selectionInput.dataset.activeOptionId || 0);
                    setActiveOptionId(activeFromDataset || resolveSlotOptionId(keys[0]));
                }
            });
            selectionInput.dataset.slotSelectionBound = '1';
        }

        await refreshTeacherSelection();
        liveValidate();
    };

    const reloadForm = async(reloadArgs = null) => {
        if (!container.isConnected || !formRegion.isConnected) {
            return;
        }

        if (reloadArgs) {
            currentLoadArgs = reloadArgs;
        }

        const validationButton = getValidationTriggerButton(container);
        if (validationButton) {
            validationButton.dataset.blocked = 'true';
        }

        await dynamicForm.load(currentLoadArgs);
        await setupInteractiveUi();
    };

    await reloadForm(currentLoadArgs);

    let continuebutton = getValidationTriggerButton(container);

    const bindValidationToContinueButton = (button) => {
        if (!button || button.dataset.slotValidationBound === '1') {
            return;
        }

        button.dataset.blocked = 'true';
        button.dataset.slotValidationBound = '1';

        button.addEventListener('click', (event) => {
            if (button.dataset.blocked === 'true') {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                dynamicForm.submitFormAjax();
            }
        });
    };

    bindValidationToContinueButton(continuebutton);

    // Fall 2: when a self-service move tab is present, wire the Book/Move switcher. The move tab
    // lazy-loads the slotUpdate DynamicForm controller; switching to it hides
    // the footer continue button (the book action) so only the move's own submit commits the move.
    const setupMoveTab = () => {
        const tabs = container.querySelector('.booking-slotbooking-tabs');
        const bookPane = container.querySelector('[data-slotpane="book"]');
        const movePane = container.querySelector('[data-slotpane="move"]');
        if (!tabs || !bookPane || !movePane || tabs.dataset.slotMoveTabBound === '1') {
            return;
        }
        tabs.dataset.slotMoveTabBound = '1';

        const links = Array.from(tabs.querySelectorAll('[data-slottab]'));

        // Build the "Update booking" editor right away (not lazily on first tab open) so it is ready
        // the moment the user switches tabs. A window resize on activation lets the picker recompute
        // its layout, which it could not while the move pane was still display:none.
        import('mod_booking/condition/slotUpdate')
            .then(module => module.init('booking-slotupdate-' + (Number(optionid) || 0)))
            .catch(Notification.exception);

        const activate = (target) => {
            links.forEach(link => link.classList.toggle('active', link.dataset.slottab === target));
            bookPane.classList.toggle('d-none', target !== 'book');
            movePane.classList.toggle('d-none', target !== 'move');

            // The footer continue button is the book action; hide it on the move tab so it is
            // neither visible nor clickable (the footer handler also honours the 'hidden' class).
            const footerContinue = getValidationTriggerButton(container);
            if (footerContinue) {
                footerContinue.classList.toggle('hidden', target === 'move');
            }

            if (target === 'move') {
                window.dispatchEvent(new Event('resize'));
            }
        };

        links.forEach(link => {
            link.addEventListener('click', event => {
                event.preventDefault();
                activate(link.dataset.slottab);
            });
        });
    };

    setupMoveTab();

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        const response = e.detail;

        if (!response) {
            return;
        }

        // The slot selection just got saved for activeOptionId (the "id" field submitted was
        // already switched to it - see setActiveOptionId). If that isn't this page's primary
        // option, this page's wizard can't continue the booking for it (different merged options
        // can have entirely different prepage condition sequences) - try booking it straight into
        // the cart instead (see addSwitchedOptionToCart above), falling back to a full redirect to
        // that option's own booking page only if something else still needs the user's attention.
        if (activeOptionId !== Number(optionid) && optionUrls.has(activeOptionId)) {
            await addSwitchedOptionToCart(activeOptionId, Number(userid) || 0);
            return;
        }

        if (!continuebutton) {
            continuebutton = getValidationTriggerButton(container);
            bindValidationToContinueButton(continuebutton);
        }
        if (continuebutton) {
            continuebutton.dataset.blocked = 'false';
            continuebutton.click();
        }
    });

    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, async() => {
        await setupInteractiveUi();
        showValidationFeedback(container);
    });

    dynamicForm.addEventListener(dynamicForm.events.CLIENT_VALIDATION_ERROR, async() => {
        await setupInteractiveUi();
        showValidationFeedback(container);
    });

    if (!container.dataset.slotbookingRefreshBound) {
        const slotbookingRefreshHandler = async(event) => {
            if (!container.isConnected || !formRegion.isConnected) {
                document.removeEventListener(SLOTBOOKING_REFRESH_EVENT, slotbookingRefreshHandler);
                return;
            }

            const detail = event.detail || {};
            const refreshedOptionid = Number(detail.optionid || 0);
            const refreshedUserid = Number(detail.userid || 0);

            if (refreshedOptionid !== Number(optionid || 0)) {
                return;
            }

            if (refreshedUserid > 0 && refreshedUserid !== Number(userid || 0)) {
                return;
            }

            await reloadForm();
        };

        document.addEventListener(SLOTBOOKING_REFRESH_EVENT, slotbookingRefreshHandler);

        container.dataset.slotbookingRefreshBound = '1';
    }
}

/**
 * Show first validation error from the current prepage form.
 *
 * @param {HTMLElement} container
 */
function showValidationFeedback(container) {
    const validationMessages = Array.from(container.querySelectorAll('.invalid-feedback'))
        .map(element => (element.textContent || '').trim())
        .filter(Boolean);

    if (validationMessages.length > 0) {
        Notification.addNotification({
            message: validationMessages[0],
            type: 'warning',
        });
    }
}
