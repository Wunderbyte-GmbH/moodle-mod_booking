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

/**
 * Find the form container this init() call is responsible for.
 *
 * With a single [bookingoptionview] shortcode on a page there is only ever one candidate, so
 * picking "the last visible one" happened to work. With two or more independent shortcode
 * instances on the same page (see templates/condition/slotbooking.mustache, which calls
 * init.init({{optionid}}) once per rendered instance), that heuristic made every instance's
 * init() call resolve to the SAME (last) container, leaving every other instance's own markup
 * never bound. When optionid is given, scope the candidate list to that instance's own
 * container(s) first (data-optionid matches the shortcode's primary option) - the modal-vs-
 * inline "prefer the open modal" disambiguation below still applies within that scope, for the
 * case where this same option is also open in a popped-out modal.
 *
 * @param {?number} optionid primary option id of the instance calling init(), or null/undefined
 *                            to fall back to the old page-wide "last visible" heuristic
 * @returns {?HTMLElement}
 */
const getActiveFormContainer = (optionid) => {
    let containers = Array.from(document.querySelectorAll(SELECTOR.FORMCONTAINER))
        .filter(el => isActuallyVisible(el) && el.querySelector('[data-region="slotbooking-form"]'));

    if (optionid !== undefined && optionid !== null) {
        const scoped = containers.filter(el => Number(el.dataset.optionid) === Number(optionid));
        if (scoped.length > 0) {
            containers = scoped;
        }
    }

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

const renderCustomDayEditor = (
    container,
    daySlot,
    hiddenStartInput,
    durationSelect,
    timeFormatter,
    legendLabels = {}
) => {
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
    controls.className = 'd-flex align-items-center flex-wrap gap-2 mb-2';

    const durationLabel = document.createElement('label');
    durationLabel.className = 'small mb-0';
    durationLabel.textContent = 'Duration';
    controls.appendChild(durationLabel);

    durationSelect.classList.add('booking-slot-duration-select');
    controls.appendChild(durationSelect);

    const label = document.createElement('label');
    label.className = 'small mb-0 ms-2';
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

    const addBookedBlock = (start, end, mine, manageUrl) => {
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

        // Use the SAME .booking-slot/--booked look the fixed-type grid draws its own booked slots
        // with (see slot_grid_day.mustache), instead of a bare colored bar, so a userdefined
        // booking reads the same way as every other calendar type.
        const block = document.createElement('div');
        block.className = 'booking-slot booking-slot--booked position-absolute';
        block.style.top = `${top}%`;
        block.style.height = `${Math.max(2, height)}%`;
        block.style.cursor = 'default';

        if (!mine) {
            // Someone else's booking - nothing to click through to, just mark the time as occupied.
            block.title = legendLabels.blocked || 'Not bookable';
            timeline.appendChild(block);
            return;
        }

        // Time + badge share ONE line (unlike the fixed-type grid's separate header/badge-row -
        // see slot_grid_day.mustache): this timeline is much shorter, so there is rarely room for
        // two stacked lines without the badge getting clipped by .booking-slot's overflow:hidden.
        const header = document.createElement('div');
        header.className = 'booking-slot-header';

        const timeLabel = document.createElement('div');
        timeLabel.className = 'booking-slot-time';
        timeLabel.textContent = `${toTimeValue(clippedStart, timeFormatter)} - ${toTimeValue(clippedEnd, timeFormatter)}`;
        header.appendChild(timeLabel);

        if (manageUrl) {
            const link = document.createElement('a');
            link.href = manageUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'booking-slot-badge booking-slot-badge--link';
            link.textContent = legendLabels.mine || 'Booked';
            // Without this, the click also bubbles up to timeline's own click handler (which
            // moves the new-selection band to wherever was clicked) - confusing together with
            // actually navigating away via the link.
            link.addEventListener('click', (event) => event.stopPropagation());
            header.appendChild(link);
        } else {
            const badge = document.createElement('div');
            badge.className = 'booking-slot-badge';
            badge.textContent = legendLabels.mine || 'Booked';
            header.appendChild(badge);
        }
        block.appendChild(header);

        timeline.appendChild(block);
    };

    (Array.isArray(daySlot.bookedranges) ? daySlot.bookedranges : []).forEach(range => {
        addBookedBlock(range.start, range.end, Boolean(range.mine), range.manageurl);
    });

    const selectionBlock = document.createElement('div');
    selectionBlock.className = 'booking-slot-selection position-absolute';
    selectionBlock.style.top = '0';
    selectionBlock.style.height = '2px';
    // Purely a visual indicator of the currently chosen new start/duration - it can end up
    // positioned right on top of an existing booked block (e.g. the default start happens to
    // match a previous pick), and being appended last it would otherwise sit above that block in
    // paint order, intercepting clicks meant for its "Booked" link.
    selectionBlock.style.pointerEvents = 'none';
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

    // Bind 'input' in addition to 'change': 'change' only fires when the time field loses focus,
    // so a submit racing right after typing (a fast user, and reliably behat's "set field" followed
    // immediately by "I follow Continue") would serialize the form BEFORE the hidden start value
    // was ever synced - the previous/default start (the day's opening time) got submitted instead
    // of what is visibly in the field. 'input' fires on every keystroke/programmatic set, so the
    // hidden field is always current by the time anything reads it.
    const synctimefield = () => {
        syncStart(toTimestampForDay(daySlot.start, timeInput.value));
    };
    timeInput.addEventListener('change', synctimefield);
    timeInput.addEventListener('input', synctimefield);

    // durationSelect (unlike timeInput/timeline, which are recreated fresh inside `container` on
    // every render) is a PERSISTENT element passed in from outside - renderCustomDayEditor runs
    // again on every day switch and (since the multi-option sidebar) every option switch too.
    // addEventListener would stack one more "change" handler on it each time, each still holding
    // that OLD render's own openFrom/openUntil in its closure; several of them then firing for a
    // single real change event lets a stale one write a wrong, out-of-range timestamp into
    // hiddenStartInput first, which the current (correct) handler's own clamping then pulls all
    // the way back to openFrom - i.e. the start time visibly resets to the day's opening time.
    // Assigning .onchange instead of addEventListener replaces the previous handler outright.
    durationSelect.onchange = () => {
        syncStart(Number(hiddenStartInput.value || openFrom));
    };

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

/**
 * Container for the selected-slots summary, inserted right below the calendar+timeline row.
 *
 * Same lazy pattern as ensureTeacherContainer/ensureFeedbackRegion above: the region is created
 * client-side rather than by the mform, and lives INSIDE the reloadable form region, so a
 * dynamicForm.load() disposes of it and the next setupInteractiveUi() run recreates it.
 *
 * @param {HTMLElement} container
 * @param {HTMLElement} anchor element to insert after
 * @returns {HTMLElement}
 */
const ensureSelectionSummary = (container, anchor) => {
    let summaryRegion = container.querySelector('[data-region="slot-selection-summary"]');
    if (summaryRegion) {
        return summaryRegion;
    }

    summaryRegion = document.createElement('div');
    summaryRegion.dataset.region = 'slot-selection-summary';

    if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(summaryRegion, anchor.nextSibling);
    } else {
        container.appendChild(summaryRegion);
    }

    return summaryRegion;
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
 *
 * @param {?number} callsiteoptionid primary option id of the shortcode instance calling init()
 *                            (see templates/condition/slotbooking.mustache) - scopes container
 *                            lookup to this instance so multiple independent shortcodes on one
 *                            page don't clobber each other. Optional for backwards compatibility.
 */
export async function init(callsiteoptionid) {
    const container = getActiveFormContainer(callsiteoptionid);
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
    const slotbookingNoCustomDayAvailableMessage = await getString('slotbooking_no_custom_day_available', 'mod_booking');

    // The calendar day (as a "YYYY-MM-DD" key) the user was last looking at - declared here (outer
    // scope, not inside setupInteractiveUi) and kept up to date on every day change so it survives a
    // setupInteractiveUi() re-run. Without this, a server-side validation failure (dynamicForm's
    // SERVER_VALIDATION_ERROR/CLIENT_VALIDATION_ERROR, see below - both re-run setupInteractiveUi()
    // against a freshly server-rendered form) recreates the calendar picker from scratch, which
    // always defaults back to today/the first bookable day, silently discarding whatever day the
    // user had navigated to right when they most need to see the validation error in context.
    let persistedActiveDayKey = null;

    // The userdefined custom-day sidebar's "which option is active" click handler - see
    // setupCustomOptionSidebar in setupInteractiveUi. Declared here (not inside
    // setupInteractiveUi) and REASSIGNED every time that function runs, because the sidebar DOM
    // itself lives OUTSIDE the reloadable [data-region="slotbooking-form"] region: a
    // reloadForm() (e.g. after SLOTBOOKING_REFRESH_EVENT, dispatched when the booking-complete
    // modal is closed) replaces the calendar/editor markup with fresh elements and a fresh
    // slotCalendarPicker instance, but the sidebar's own click listeners are only ever bound
    // ONCE (guarded by a dataset flag) and would otherwise keep calling into the FIRST run's now
    // detached picker forever - silently doing nothing visible and never actually switching
    // activeOptionId. Routing every sidebar click through this indirection instead keeps it
    // working after every reload.
    let selectActiveCustomOption = null;

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

    // Whether each merged option has a price at all - see addSwitchedOptionToCart below. Reading
    // it from the OPTION's own setting (rather than trying to infer it from the selected slot's
    // own data) works uniformly across slot types - userdefined slots carry no per-slot price at
    // all, only fixed-type ones do.
    let optionUseprice = new Map();
    if (container.dataset.optionprices) {
        try {
            const parsedprices = JSON.parse(container.dataset.optionprices);
            optionUseprice = new Map(Object.entries(parsedprices).map(([id, useprice]) => [Number(id), Boolean(useprice)]));
        } catch (e) {
            optionUseprice = new Map();
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
            if (!templates.includes('mod_booking/condition/confirmation')) {
                fallbackToOptionPage();
                return;
            }

            // Free/priceless options never actually reach the cart - allow_add_item_to_cart.php
            // returns success without touching it at all (see its own useprice check), and the
            // booking is completed directly by loadSwitchedOptionPage above. Redirecting to
            // checkout.php in that case lands on a confusingly empty cart instead of confirming
            // anything; only a genuinely paid selection (added to a payable cart) belongs there.
            // optionUseprice comes from the option's OWN setting (see the comment where it's built
            // above) - NOT reconstructed from the selected slot's own data, which does not exist at
            // all for userdefined slots (only fixed-type ones carry a per-slot price), and would
            // otherwise make an empty `keys` array vacuously look "free" via .every(). If the
            // price info is missing/unparseable for some reason, default to treating it as PAID -
            // worst case that shows an empty cart page, whereas defaulting the other way could
            // falsely claim a still-unpaid booking was "successfully booked".
            const isFreeSelection = optionUseprice.has(Number(targetOptionId))
                && !optionUseprice.get(Number(targetOptionId));
            if (isFreeSelection) {
                const targetUrl = optionUrls.get(targetOptionId);
                window.location.href = targetUrl + (targetUrl.includes('?') ? '&' : '?') + 'justbooked=1';
                return;
            }

            window.location.href = Config.wwwroot + '/local/shopping_cart/checkout.php';
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

        // Moved above the userdefined custom-day branch below (it used to live further down,
        // defined only for the fixed-type calendar/list pickers) so BOTH branches can keep the
        // hidden "id" field (and activeOptionId) in sync with whichever merged option the user is
        // actually working with right now.
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

        // Every slot mode (calendar grid, multi-select list, single-select selectgroups and the
        // userdefined custom-day calendar) now sources its selectable slots from the embedded
        // slot_calendar_data hidden field, filled by the form's definition(). The fixed modes carry a
        // byte-identical copy of the former get_slots webservice payload, so no round-trip is needed and
        // the snapshot survives mform reloads alongside the slot_selection state.
        const slots = parseSlots(jsonInput);

        if (calendarRoot && customStartInput && customDurationSelect && customEditorRoot && slots.length > 0) {
            const originalDurationRow = customDurationSelect.closest('.form-group, .fitem');
            if (originalDurationRow) {
                originalDurationRow.style.display = 'none';
            }

            let lastCustomDaySlot = slots[0] || null;
            // Which merged option is currently "active" for the custom-day picker. Unlike the
            // fixed-type merged calendar (a shared "start:end" slot grid, where picking any slot
            // implicitly picks its option), userdefined mode has no shared grid - each option has
            // its own free-form start/duration picker, so exactly one option is active at a time,
            // switched via the sidebar (see setupCustomOptionSidebar below). Starts on the primary
            // option (or, when this is a setupInteractiveUi() re-run after a validation error -
            // see persistedActiveDayKey above and the SERVER_VALIDATION_ERROR/CLIENT_VALIDATION_ERROR
            // handlers below - on whichever option activeOptionId already carried over from before
            // the reload, so a switched-to option isn't silently discarded back to the primary one
            // right when the user needs to see why it failed); with no sidebar (single option, no
            // merge) it just never changes.
            let lastChosenCustomOptionId = activeOptionId || Number(optionid) || 0;
            let customCalendarPicker = null;

            // A day can carry one entry PER merged option (see optionid/optionname on each day -
            // slotbooking_form.php get_custom_open_days()) - always scope down to the currently
            // active option (see lastChosenCustomOptionId above) so only ITS entry for this day is
            // ever considered, never a different merged option's.
            const getCustomDayCandidates = (dayKey, daySlots) => {
                const raw = Array.isArray(daySlots) && daySlots.length > 0
                    ? daySlots
                    : (dayKey ? slots.filter(slot => toDayKey(slot.start || 0, timezone) === dayKey) : []);
                const scoped = lastChosenCustomOptionId
                    ? raw.filter(slot => Number(slot.optionid || optionid) === lastChosenCustomOptionId)
                    : raw;

                const byOption = new Map();
                scoped.forEach(slot => {
                    const oid = Number(slot.optionid || optionid);
                    if (!byOption.has(oid)) {
                        byOption.set(oid, slot);
                    }
                });
                return Array.from(byOption.values());
            };

            // Shown when the currently active option has no bookable day entry for whichever day
            // is selected (e.g. its own opening days/valid-from-until don't cover it), so a stale
            // previous day's editor doesn't linger on screen looking like it still applies.
            const renderNoCustomDayAvailable = () => {
                customEditorRoot.innerHTML = '';
                const info = document.createElement('div');
                info.className = 'small text-muted';
                info.textContent = slotbookingNoCustomDayAvailableMessage;
                customEditorRoot.appendChild(info);
                customEditorRoot.style.display = '';
            };

            // Each merged option can allow different durations (own min/max/step - see
            // durationoptions/defaultduration on each day, slotbooking_form.php
            // get_custom_open_days()). The <select> is otherwise a static copy of only the PRIMARY
            // option's own duration options, so submitting whatever it already shows after
            // switching to a different option can silently pick a duration that option's own
            // config validation() rejects. Rebuild it for whichever option was actually chosen.
            const applyCustomDurationOptions = (daySlot) => {
                const durationOptions = daySlot && daySlot.durationoptions && typeof daySlot.durationoptions === 'object'
                    ? daySlot.durationoptions
                    : null;
                if (!durationOptions) {
                    return;
                }

                const previousValue = customDurationSelect.value;
                customDurationSelect.innerHTML = '';
                Object.keys(durationOptions).forEach(seconds => {
                    const option = document.createElement('option');
                    option.value = seconds;
                    option.textContent = durationOptions[seconds];
                    customDurationSelect.appendChild(option);
                });

                const defaultDuration = String(daySlot.defaultduration || '');
                if (defaultDuration && Object.prototype.hasOwnProperty.call(durationOptions, defaultDuration)) {
                    customDurationSelect.value = defaultDuration;
                } else if (Object.prototype.hasOwnProperty.call(durationOptions, previousValue)) {
                    // Same duration is valid for the newly chosen option too - keep it instead of
                    // silently resetting what the user already picked.
                    customDurationSelect.value = previousValue;
                }
            };

            const renderChosenCustomDay = (daySlot) => {
                const optionChanged = lastCustomDaySlot
                    && Number(lastCustomDaySlot.optionid || optionid) !== Number(daySlot.optionid || optionid);
                lastCustomDaySlot = daySlot;
                lastChosenCustomOptionId = Number(daySlot.optionid || optionid);
                setActiveOptionId(lastChosenCustomOptionId);
                applyCustomDurationOptions(daySlot);
                if (optionChanged) {
                    // A start time chosen under a different option's opening hours/duration is not
                    // meaningful here - let renderCustomDayEditor derive a fresh default from this
                    // option's own openfrom instead of reusing a stale timestamp.
                    customStartInput.value = '0';
                }
                renderCustomDayEditor(
                    customEditorRoot,
                    daySlot,
                    customStartInput,
                    customDurationSelect,
                    timeFormatter,
                    legendLabels
                );
                customEditorRoot.style.display = '';
            };

            const renderResolvedCustomDay = (dayKey = '', daySlots = []) => {
                const candidates = getCustomDayCandidates(dayKey, daySlots);
                if (candidates.length === 0) {
                    renderNoCustomDayAvailable();
                    return false;
                }

                renderChosenCustomDay(candidates[0]);
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

                return renderResolvedCustomDay(activeDay, activeDaySlots);
            };

            // Lets the user pick WHICH merged booking option is active from the existing
            // multi-option sidebar, instead of a separate chooser embedded in the editor (which
            // had no way back to reconsider once picked). Single-select (unlike the fixed-type
            // merged calendar's multi-toggle filter sidebar - see setupSidebar above): exactly one
            // option is ever active for the free-form start/duration picker, so there is no
            // "invert selection" concept here.
            const setupCustomOptionSidebar = () => {
                const sidebarRegion = container.querySelector('[data-region="slotbooking-sidebar"]');
                if (!sidebarRegion) {
                    return;
                }

                const items = Array.from(sidebarRegion.querySelectorAll('.booking-slotbooking-sidebar-item'));

                const applyActiveState = () => {
                    items.forEach(item => {
                        const isActive = Number(item.dataset.optionid || 0) === lastChosenCustomOptionId;
                        item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        item.style.backgroundColor = isActive ? 'rgba(13, 110, 253, 0.1)' : '';
                        item.style.fontWeight = isActive ? '600' : '';
                    });
                };

                // Reassigned on EVERY setupInteractiveUi() run (see the declaration of
                // selectActiveCustomOption above) so the sidebar's own, only-ever-bound-once click
                // listeners below always act on the CURRENT calendar picker/day-resolution
                // closures, not a stale set from a previous run whose DOM has since been replaced.
                selectActiveCustomOption = (itemOptionId) => {
                    if (!itemOptionId || itemOptionId === lastChosenCustomOptionId) {
                        return;
                    }
                    lastChosenCustomOptionId = itemOptionId;
                    applyActiveState();
                    // setSlotFilter re-renders the calendar grid AND (since showSlotList is false
                    // for this picker) re-invokes onDayChange for the currently active day with the
                    // newly filtered slots - which is exactly renderResolvedCustomDay again, so the
                    // editor below switches to the newly active option immediately.
                    customCalendarPicker.setSlotFilter(slot => Number(slot.optionid || optionid) === itemOptionId);
                };

                // lastChosenCustomOptionId is re-seeded from activeOptionId on every run (see its
                // declaration above), so a reload keeps whichever option was actually active - sync
                // the sidebar's own highlight with that even when the listeners below are already
                // bound from an earlier run.
                applyActiveState();

                if (sidebarRegion.dataset.slotSidebarBound === '1') {
                    return;
                }
                sidebarRegion.dataset.slotSidebarBound = '1';

                const invertButton = sidebarRegion.querySelector('.booking-slotbooking-sidebar-invert');
                if (invertButton) {
                    invertButton.style.display = 'none';
                }

                items.forEach(item => {
                    const itemColor = optionColors.get(Number(item.dataset.optionid || 0));
                    if (itemColor) {
                        item.style.borderLeftColor = itemColor;
                    }
                    item.addEventListener('click', () => selectActiveCustomOption?.(Number(item.dataset.optionid || 0)));
                    item.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            selectActiveCustomOption?.(Number(item.dataset.optionid || 0));
                        }
                    });
                });
            };

            if (!calendarRoot.dataset.slotCalendarInitialized) {
                customCalendarPicker = initSlotCalendarPicker(calendarRoot, {
                    slots,
                    timezone,
                    maxSelection: 1,
                    dayCountFormatter: (daySlots) => {
                        const candidates = getCustomDayCandidates('', daySlots);
                        return candidates.some(c => c.bookable) ? 'Buchbar' : 'Nicht buchbar';
                    },
                    dayStateResolver: (daySlots) => {
                        const candidates = getCustomDayCandidates('', daySlots);
                        return candidates.some(c => c.bookable) ? '' : 'full';
                    },
                    // This custom-day mode renders its OWN editor below (customEditorRoot) instead
                    // of the picker's built-in slot-list panel - suppress that panel the same way
                    // the fixed-type calendar does (showSlotList: false), not by filtering every
                    // slot out (the previous "slotFilter: () => false" here made daySlots empty
                    // BEFORE dayCountFormatter/dayStateResolver ever saw it, so every day rendered
                    // as "full"/pink regardless of real availability - see slotCalendarPicker.js
                    // renderCalendarGrid(), which filters before calling either callback).
                    showSlotList: false,
                    // Scope the very first render to the currently active option too
                    // (setupCustomOptionSidebar below only changes this again once the user actually
                    // clicks a different sidebar item), so a merged non-primary option's days never
                    // flash into view.
                    slotFilter: (slot) => Number(slot.optionid || optionid) === lastChosenCustomOptionId,
                    // Reopen on whichever day the user was last looking at (see persistedActiveDayKey
                    // above) rather than always defaulting back to today/the first bookable day - most
                    // relevant right after a validation error re-renders this picker from scratch, so
                    // the error stays visible in the context the user was already looking at.
                    initialActiveDay: persistedActiveDayKey,
                    onChange: () => {
                        // Custom mode persists start/duration via dedicated inputs.
                    },
                    onDayChange: (dayKey, daySlots) => {
                        persistedActiveDayKey = dayKey;
                        renderResolvedCustomDay(dayKey, daySlots);
                    },
                });

                calendarRoot.dataset.slotCalendarInitialized = '1';
                setupCustomOptionSidebar();
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

        // (setActiveOptionId now lives further up, before the userdefined custom-day branch, so
        // both branches share the same definition.)

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
        const summaryAnchor = calendarWrapper || fixedEditorRoot || calendarRoot || selectionInput;

        // Selected-slots summary: only for the fixed-grid CALENDAR interface, and only when more
        // than one slot may be selected at once. That interface draws exactly ONE day at a time, so
        // with max_slots_per_user > 1 a slot picked on another day is otherwise invisible - it can
        // be neither seen nor removed without hunting for the right day by hand. With a single
        // selectable slot the timeline's own highlight already says everything, and the list
        // interface shows every day at once, so neither of those needs it.
        const maxSelectionInput = container.querySelector('input[name="slot_max_selection"]');
        const summaryEnabled = Boolean(fixedEditorRoot && calendarRoot)
            && Number(maxSelectionInput?.value || 1) > 1;
        const summaryRegion = summaryEnabled ? ensureSelectionSummary(container, summaryAnchor) : null;

        // Falls back to exactly the previous anchor chain when there is no summary.
        const teacherAnchor = listPickerRoot || summaryRegion || summaryAnchor;
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

        // The selection adapter shared by the day timeline and the summary's remove buttons.
        // Assigned once in the calendar-init block below (it needs resolveMaxSlots), and used from
        // both, so removing a slot in the summary is indistinguishable from clicking it off in the
        // timeline. This used to be built FRESH inside renderFixedEditorForDay on every single day
        // switch, which is precisely why nothing outside the currently drawn day could read or
        // touch the selection.
        let fixedSelection = null;
        // Re-renders the day timeline currently on screen. The timeline only repaints on a day
        // change or from its own click handler, so an external change to the selection - the
        // summary's remove button - would otherwise leave a just-removed slot still painted as
        // selected until the user navigates to another day and back.
        let refreshFixedEditor = null;

        // Server-validated total for the current selection (see renderLiveFeedback below). Kept so
        // the summary can show the price that will ACTUALLY be charged, rather than a client-side
        // sum which knows nothing about rules or the user's own price category.
        let lastValidatedTotal = null;

        const summaryHeading = summaryRegion
            ? await getString('slot_selection_summary_heading', 'mod_booking')
            : '';

        const renderSelectionSummary = async() => {
            if (!summaryRegion) {
                return;
            }

            const keys = getSelectedSlotKeys(selectionInput);
            if (keys.length === 0) {
                summaryRegion.innerHTML = '';
                return;
            }

            let currency = '';
            const rows = [];
            keys.forEach(key => {
                const slot = slotsMap.get(key);
                if (!slot) {
                    return;
                }
                if (!currency) {
                    currency = String(slot.currency || '').trim();
                }
                rows.push({
                    key,
                    // The PICKER's own day key - deliberately not slot.daykey, which slot_dto
                    // builds with userdate('%Y-%m-%d') and therefore does NOT zero-pad
                    // ("2026-10-9"), so it would never match a picker day key on a single-digit
                    // date and every such row's "jump to day" would silently do nothing.
                    daykey: calendarPickerInstance
                        ? (calendarPickerInstance.findDayKeyForSlotKey(key) || '')
                        : '',
                    daylabel: String(slot.daylabel || ''),
                    timelabel: String(slot.timelabel || key),
                    priceformatted: (usePrices && Number(slot.price || 0) > 0 && slot.priceformatted)
                        ? String(slot.priceformatted)
                        : '',
                });
            });

            if (rows.length === 0) {
                summaryRegion.innerHTML = '';
                return;
            }

            const maxSlots = Math.max(1, Number(maxSelectionInput?.value || 1));
            const showtotal = usePrices && lastValidatedTotal !== null && lastValidatedTotal > 0;
            const {html, js} = await Templates.renderForPromise('mod_booking/slotbooking/slot_selection_summary', {
                heading: summaryHeading,
                countlabel: await getString('slot_selection_count', 'mod_booking', {
                    count: rows.length,
                    max: maxSlots,
                }),
                showtotal,
                totalformatted: showtotal
                    ? `${lastValidatedTotal.toFixed(2)}${currency ? ' ' + currency : ''}`
                    : '',
                rows,
            });
            Templates.replaceNodeContents(summaryRegion, html, js);

            summaryRegion.querySelectorAll('[data-action="goto-day"]').forEach(button => {
                button.addEventListener('click', () => {
                    const dayKey = button.closest('[data-day-key]')?.dataset.dayKey || '';
                    if (dayKey && calendarPickerInstance) {
                        calendarPickerInstance.goToDay(dayKey);
                    }
                });
            });

            summaryRegion.querySelectorAll('[data-action="remove-slot"]').forEach(button => {
                button.addEventListener('click', () => {
                    const key = button.closest('[data-slot-key]')?.dataset.slotKey || '';
                    if (!key || !fixedSelection) {
                        return;
                    }
                    // Routed through the same adapter the timeline uses, so this persists to the
                    // hidden input and dispatches 'change' - which re-renders the summary, the day
                    // timeline and the calendar day badges together, from one source of truth.
                    fixedSelection.toggle(key);
                    // See refreshFixedEditor above: the timeline does not listen for selection
                    // changes, so the removed slot would stay highlighted on the visible day.
                    if (refreshFixedEditor) {
                        refreshFixedEditor();
                    }
                });
            });
        };

        // Live server-side pre-validation: on every selection change we ask the save_slot_selection
        // webservice whether the current selection is bookable and what it costs, and surface the first
        // error (or the running total price) inline. The DynamicForm submit stays the final gate.
        const feedbackRegion = ensureFeedbackRegion(container, teacherContainer);
        const renderLiveFeedback = (result) => {
            feedbackRegion.classList.remove('text-danger', 'text-success');
            if (!result) {
                feedbackRegion.textContent = '';
                lastValidatedTotal = null;
                renderSelectionSummary();
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
                lastValidatedTotal = null;
                renderSelectionSummary();
                return;
            }

            const price = Number(result.price || 0);
            lastValidatedTotal = price;

            // With the summary present the total belongs in ITS total row, directly under the
            // per-slot prices it adds up - printing it here as well would show the same number
            // twice in two places. This region then carries validation errors only.
            if (summaryRegion) {
                feedbackRegion.textContent = '';
                renderSelectionSummary();
                return;
            }

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
                    // persistselection=false: feedback only - a late response of this debounced
                    // call must never (re)write the slot store behind the booking commit's back.
                    const result = await saveSelection(Number(activeOptionId) || 0, Number(userid) || 0, keys, teacherMap, false);
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

            // ONE adapter for the whole setupInteractiveUi() run (see the declaration above),
            // instead of a new one per day render. Must exist before initSlotCalendarPicker below:
            // the picker fires onDayChange from inside its own constructor, which lands in
            // renderFixedEditorForDay and needs it immediately.
            if (fixedEditorRoot) {
                fixedSelection = createHiddenInputSelection(selectionInput, resolveMaxSlots, {
                    resolveOptionId: resolveSlotOptionId,
                    onOptionSwitch: () => Notification.addNotification({
                        message: slotbookingSwitchedOptionMessage,
                        type: 'info',
                    }),
                });
            }

            const selectedDayLabel = await getString('slot_selection_selectedday', 'mod_booking');

            const calendarOptions = {
                slots,
                selectedLabel: selectedDayLabel,
                // The summary owns the count when it is present, as a real translated string -
                // the picker's built-in counter would otherwise print the same thing twice.
                showSelectionInfo: !summaryEnabled,
                timezone,
                maxSelection: maxSlots,
                // Reopen on whichever day the user was last looking at (see persistedActiveDayKey
                // above) rather than always defaulting back to today/the first bookable day - most
                // relevant right after a validation error re-renders this picker from scratch, so the
                // error stays visible in the context the user was already looking at.
                initialActiveDay: persistedActiveDayKey,
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
                let lastRenderedDaySlots = [];
                const renderFixedEditorForDay = async(daySlots) => {
                    const normalizedDaySlots = Array.isArray(daySlots) ? daySlots : [];
                    lastRenderedDaySlots = normalizedDaySlots;
                    if (normalizedDaySlots.length === 0) {
                        fixedEditorRoot.innerHTML = '';
                        fixedEditorRoot.style.display = 'none';
                        return;
                    }

                    fixedEditorRoot.style.display = '';
                    await renderFixedSlotsEditor(
                        fixedEditorRoot,
                        normalizedDaySlots,
                        fixedSelection,
                        timeFormatter,
                        optionColors
                    );

                    if (!fixedEditorRoot.childElementCount) {
                        fixedEditorRoot.style.display = 'none';
                    }
                };
                refreshFixedEditor = () => renderFixedEditorForDay(lastRenderedDaySlots);
                calendarOptions.onDayChange = (dayKey, daySlots) => {
                    persistedActiveDayKey = dayKey;
                    renderFixedEditorForDay(daySlots);
                };
            } else {
                calendarOptions.onDayChange = (dayKey) => {
                    persistedActiveDayKey = dayKey;
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
            // Mirror the hidden input's selection into the calendar picker. The picker does not own
            // the selection in this mode (initialSelection is [] and onChange a no-op - see the
            // fixedEditorRoot branch above), so without this its this.selected stayed permanently
            // empty: the day cells could not badge days holding a selection, and the built-in
            // counter always read "0/N selected" no matter what had actually been picked.
            selectionInput.addEventListener('change', () => {
                if (calendarPickerInstance) {
                    calendarPickerInstance.setSelectedKeys(getSelectedSlotKeys(selectionInput));
                }
            });
            selectionInput.addEventListener('change', renderSelectionSummary);
            selectionInput.dataset.slotSelectionBound = '1';
        }

        // Seed both from whatever the input already carries: after a server validation error the
        // mform brings the previous slot_selection back, and the badges/summary must come back with
        // it rather than looking like nothing was ever selected.
        if (calendarPickerInstance) {
            calendarPickerInstance.setSelectedKeys(getSelectedSlotKeys(selectionInput));
        }
        await renderSelectionSummary();
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
                // Flush the visible custom start time into its hidden field before submitting.
                // The sync normally rides on the time input's own input/change events, but not
                // every way of setting the field fires those reliably (WebDriver's setValue in
                // behat does not) - without this, the submit serializes whatever start the hidden
                // field last saw (the day's default), silently booking a different time than the
                // one visible in the field.
                const customtimeinput = container.querySelector(
                    '[data-region="slot-custom-editor"] input[type=time]'
                );
                if (customtimeinput) {
                    customtimeinput.dispatchEvent(new Event('change', {bubbles: true}));
                }
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

        // Which option the server actually just processed - read from process_dynamic_submission()'s
        // own returned "id" field (JSON-encoded into response.data by core_form_dynamic_form)
        // rather than trusting the client-side activeOptionId tracking alone. activeOptionId SHOULD
        // always match it (see setActiveOptionId), but a client-side desync there (e.g. the
        // multi-option sidebar resolving to a different option than what was visibly shown) would
        // otherwise submit against the wrong option unnoticed - this is the authoritative source.
        let submittedOptionId = activeOptionId;
        try {
            const parsedData = JSON.parse(response.data || 'null');
            if (parsedData && parsedData.id) {
                submittedOptionId = Number(parsedData.id);
            }
        } catch (e) {
            // Malformed/missing response.data - fall back to activeOptionId above.
        }

        // If that isn't this page's primary option, this page's wizard can't continue the booking
        // for it (different merged options can have entirely different prepage condition
        // sequences) - try booking it straight into the cart instead (see addSwitchedOptionToCart
        // above), falling back to a full redirect to that option's own booking page only if
        // something else still needs the user's attention.
        if (submittedOptionId !== Number(optionid) && optionUrls.has(submittedOptionId)) {
            const confirmTitle = await getString('confirmbookingtitle', 'mod_booking');
            const confirmQuestion = await getString('confirmbookinglong', 'mod_booking');
            const yesLabel = await getString('yes');
            const noLabel = await getString('no');
            Notification.confirm(confirmTitle, confirmQuestion, yesLabel, noLabel, () => {
                addSwitchedOptionToCart(submittedOptionId, Number(userid) || 0);
            });
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
