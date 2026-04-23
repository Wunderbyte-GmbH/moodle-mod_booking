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
import Notification from 'core/notification';

const SLOTBOOKING_REFRESH_EVENT = 'mod_booking:slotbooking-refresh';

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

const createTimeFormatter = (timezone) => {
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

const toTimeValue = (timestamp, formatter) => {
    const parts = formatter.formatToParts(new Date(Number(timestamp) * 1000));
    const hours = parts.find(part => part.type === 'hour')?.value || '00';
    const minutes = parts.find(part => part.type === 'minute')?.value || '00';
    return `${hours}:${minutes}`;
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

const renderCustomDayEditor = (container, daySlot, hiddenStartInput, durationSelect, timeFormatter) => {
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
    timeInput.className = 'form-control form-control-sm';
    timeInput.style.maxWidth = '10rem';
    timeInput.step = String(startIntervalSeconds);
    timeInput.min = toTimeValue(openFrom, timeFormatter);
    timeInput.max = toTimeValue(openUntil, timeFormatter);
    timeInput.value = toTimeValue(defaultStart, timeFormatter);
    controls.appendChild(timeInput);

    container.appendChild(controls);

    const timelineWrapper = document.createElement('div');
    timelineWrapper.className = 'd-flex align-items-stretch gap-1';
    timelineWrapper.style.width = '100%';
    timelineWrapper.style.minWidth = '0';
    container.appendChild(timelineWrapper);

    const labelsCol = document.createElement('div');
    labelsCol.className = 'position-relative flex-shrink-0';
    labelsCol.style.width = '2.8rem';
    labelsCol.style.height = '140px';
    timelineWrapper.appendChild(labelsCol);

    const timeline = document.createElement('div');
    timeline.className = 'border rounded position-relative flex-grow-1';
    timeline.style.height = '140px';
    timeline.style.background = 'linear-gradient(to bottom, #f8f9fa, #ffffff)';
    timeline.style.cursor = 'crosshair';
    timeline.style.overflow = 'hidden';
    timelineWrapper.appendChild(timeline);

    const timelineSpan = openUntil - openFrom;
    if (timelineSpan > 0) {
        const tickCandidates = [5 * 60, 10 * 60, 15 * 60, 20 * 60, 30 * 60, 3600, 2 * 3600, 3 * 3600];
        const tickInterval = tickCandidates.find(c => timelineSpan / c <= 8) || 3600;
        const firstTick = Math.ceil(openFrom / tickInterval) * tickInterval;
        for (let tick = firstTick; tick <= openUntil; tick += tickInterval) {
            const ratio = (tick - openFrom) / timelineSpan;

            const lbl = document.createElement('div');
            lbl.className = 'position-absolute text-muted';
            lbl.style.top = `${ratio * 100}%`;
            lbl.style.transform = 'translateY(-50%)';
            lbl.style.left = '0';
            lbl.style.right = '0';
            lbl.style.fontSize = '0.65rem';
            lbl.style.lineHeight = '1';
            lbl.style.textAlign = 'right';
            lbl.style.whiteSpace = 'nowrap';
            lbl.textContent = toTimeValue(tick, timeFormatter);
            labelsCol.appendChild(lbl);

            const tickLine = document.createElement('div');
            tickLine.className = 'position-absolute';
            tickLine.style.left = '0';
            tickLine.style.right = '0';
            tickLine.style.top = `${ratio * 100}%`;
            tickLine.style.height = '1px';
            tickLine.style.background = 'rgba(0,0,0,0.10)';
            tickLine.style.pointerEvents = 'none';
            timeline.appendChild(tickLine);
        }
    }

    const addBookedBlock = (start, end) => {
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
        block.className = 'position-absolute';
        block.style.left = '0';
        block.style.right = '0';
        block.style.top = `${top}%`;
        block.style.height = `${Math.max(2, height)}%`;
        block.style.background = 'rgba(220,53,69,0.18)';
        block.style.borderTop = '1px solid rgba(220,53,69,0.35)';
        block.style.borderBottom = '1px solid rgba(220,53,69,0.35)';
        timeline.appendChild(block);
    };

    (Array.isArray(daySlot.bookedranges) ? daySlot.bookedranges : []).forEach(range => {
        addBookedBlock(range.start, range.end);
    });

    const selectionBlock = document.createElement('div');
    selectionBlock.className = 'position-absolute';
    selectionBlock.style.left = '0';
    selectionBlock.style.right = '0';
    selectionBlock.style.top = '0';
    selectionBlock.style.height = '2px';
    selectionBlock.style.background = 'rgba(13,110,253,0.20)';
    selectionBlock.style.borderTop = '1px solid rgba(13,110,253,0.75)';
    selectionBlock.style.borderBottom = '1px solid rgba(13,110,253,0.75)';
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


const renderFixedSlotsEditor = (container, daySlots, selectionInput, maxSlots, timeFormatter) => {
    container.innerHTML = '';
    if (!Array.isArray(daySlots) || daySlots.length === 0 || !selectionInput) {
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

    const currentKeys = new Set(
        String(selectionInput.value || '').split(',').map(v => v.trim()).filter(Boolean)
    );

    const applyBlockStyle = (block, isSelected) => {
        if (isSelected) {
            block.style.background = 'rgba(13,110,253,0.22)';
            block.style.borderTop = '1px solid rgba(13,110,253,0.75)';
            block.style.borderBottom = 'none';
            block.style.color = 'rgb(13,70,170)';
        } else {
            block.style.background = 'rgba(25,135,84,0.12)';
            block.style.borderTop = '1px solid rgba(25,135,84,0.45)';
            block.style.borderBottom = 'none';
            block.style.color = 'rgb(20,100,60)';
        }
    };

    const applyBookedBlockStyle = (block) => {
        block.style.background = 'rgba(108,117,125,0.22)';
        block.style.borderTop = '1px solid rgba(108,117,125,0.8)';
        block.style.borderBottom = 'none';
        block.style.color = 'rgb(73,80,87)';
    };

    const updateSelectionInput = () => {
        selectionInput.value = Array.from(currentKeys).join(',');
        selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
    };

    const timelineWrapper = document.createElement('div');
    timelineWrapper.className = 'd-flex align-items-stretch gap-1';
    container.appendChild(timelineWrapper);

    const labelsCol = document.createElement('div');
    labelsCol.className = 'position-relative flex-shrink-0';
    labelsCol.style.width = '2.8rem';
    labelsCol.style.height = `${timelineHeight}px`;
    timelineWrapper.appendChild(labelsCol);

    const timeline = document.createElement('div');
    timeline.className = 'border rounded position-relative flex-grow-1';
    timeline.style.flex = '1 1 auto';
    timeline.style.minWidth = '12rem';
    timeline.style.height = `${timelineHeight}px`;
    timeline.style.background = 'linear-gradient(to bottom, #f8f9fa, #ffffff)';
    timeline.style.overflow = 'hidden';
    timelineWrapper.appendChild(timeline);

    const tickCandidates = [5 * 60, 10 * 60, 15 * 60, 20 * 60, 30 * 60, 3600, 2 * 3600, 3 * 3600];
    const tickInterval = tickCandidates.find(c => span / c <= 8) || 3600;
    const firstTick = Math.ceil(openFrom / tickInterval) * tickInterval;
    for (let tick = firstTick; tick <= openUntil; tick += tickInterval) {
        const ratio = (tick - openFrom) / span;

        const lbl = document.createElement('div');
        lbl.className = 'position-absolute text-muted';
        lbl.style.top = `${ratio * 100}%`;
        lbl.style.transform = 'translateY(-50%)';
        lbl.style.left = '0';
        lbl.style.right = '0';
        lbl.style.fontSize = '0.65rem';
        lbl.style.lineHeight = '1';
        lbl.style.textAlign = 'right';
        lbl.style.whiteSpace = 'nowrap';
        lbl.textContent = toTimeValue(tick, timeFormatter);
        labelsCol.appendChild(lbl);

        const tickLine = document.createElement('div');
        tickLine.className = 'position-absolute';
        tickLine.style.left = '0';
        tickLine.style.right = '0';
        tickLine.style.top = `${ratio * 100}%`;
        tickLine.style.height = '1px';
        tickLine.style.background = 'rgba(0,0,0,0.10)';
        tickLine.style.pointerEvents = 'none';
        timeline.appendChild(tickLine);
    }

    daySlots.forEach(slot => {
        const slotStart = Number(slot.start || 0);
        const slotEnd = Number(slot.end || 0);
        if (slotEnd <= slotStart) {
            return;
        }

        const isBooked = String(slot.status || '') === 'booked' || slot.selectable === false;

        const key = String(slot.key || `${slotStart}:${slotEnd}`);
        const top = ((slotStart - openFrom) / span) * 100;
        const minSlotHeightPercent = (minReadableSlotPx / timelineHeight) * 100;
        const height = Math.max(minSlotHeightPercent, ((slotEnd - slotStart) / span) * 100);

        const block = document.createElement('div');
        block.className = 'position-absolute';
        block.style.left = '1px';
        block.style.right = '1px';
        block.style.top = `${top}%`;
        block.style.height = `${height}%`;
        block.style.cursor = isBooked ? 'default' : 'pointer';
        block.style.overflow = 'hidden';
        block.style.borderRadius = '2px';
        block.style.padding = '2px 4px';
        if (isBooked) {
            currentKeys.delete(key);
            applyBookedBlockStyle(block);
        } else {
            applyBlockStyle(block, currentKeys.has(key));
        }

        const headerRow = document.createElement('div');
        headerRow.style.display = 'flex';
        headerRow.style.alignItems = 'center';
        headerRow.style.justifyContent = 'space-between';
        headerRow.style.gap = '0.35rem';

        const timeText = document.createElement('div');
        timeText.style.fontSize = '0.65rem';
        timeText.style.lineHeight = '1.2';
        timeText.style.fontWeight = '600';
        timeText.style.whiteSpace = 'nowrap';
        timeText.style.overflow = 'hidden';
        timeText.style.textOverflow = 'ellipsis';
        timeText.textContent = `${toTimeValue(slotStart, timeFormatter)} \u2013 ${toTimeValue(slotEnd, timeFormatter)}`;
        headerRow.appendChild(timeText);

        if (isBooked) {
            const bookedBadge = document.createElement('div');
            bookedBadge.style.fontSize = '0.6rem';
            bookedBadge.style.lineHeight = '1.2';
            bookedBadge.style.fontWeight = '700';
            bookedBadge.style.whiteSpace = 'nowrap';
            bookedBadge.style.marginLeft = 'auto';
            bookedBadge.textContent = 'Booked';
            headerRow.appendChild(bookedBadge);
        }

        const slotPrice = Number(slot.price || 0);
        if (slotPrice > 0 && slot.priceformatted) {
            const priceEl = document.createElement('div');
            priceEl.style.fontSize = '0.6rem';
            priceEl.style.lineHeight = '1.2';
            priceEl.style.fontWeight = '600';
            priceEl.style.whiteSpace = 'nowrap';
            priceEl.style.marginLeft = 'auto';
            priceEl.textContent = String(slot.priceformatted);
            headerRow.appendChild(priceEl);
        }

        block.appendChild(headerRow);

        const teachers = Array.isArray(slot.teachers) ? slot.teachers : [];
        if (teachers.length > 0) {
            const teacherEl = document.createElement('div');
            teacherEl.style.fontSize = '0.6rem';
            teacherEl.style.lineHeight = '1.2';
            teacherEl.style.whiteSpace = 'nowrap';
            teacherEl.style.overflow = 'hidden';
            teacherEl.style.textOverflow = 'ellipsis';
            if (teachers.length <= 2) {
                teacherEl.textContent = teachers
                    .map(t => String(t.fullname || ''))
                    .filter(Boolean)
                    .join(', ');
            } else {
                teacherEl.textContent = '\u{1F464} \xd7' + teachers.length;
            }
            block.appendChild(teacherEl);
        }

        block.addEventListener('click', () => {
            if (isBooked) {
                return;
            }
            if (currentKeys.has(key)) {
                currentKeys.delete(key);
            } else {
                if (maxSlots <= 1) {
                    currentKeys.clear();
                    timeline.querySelectorAll('.position-absolute').forEach(b => {
                        if (b.style.cursor === 'pointer') {
                            applyBlockStyle(b, false);
                        }
                    });
                } else if (currentKeys.size >= maxSlots) {
                    return;
                }
                currentKeys.add(key);
            }
            applyBlockStyle(block, currentKeys.has(key));
            updateSelectionInput();
        });

        timeline.appendChild(block);
    });
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

const renderTeacherSelection = (
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

    teacherContainer.innerHTML = '';

    if (requiredCount <= 0 || selectedSlotKeys.length === 0) {
        serializeTeacherSelection(hiddenInput, {});
        return;
    }

    const heading = document.createElement('div');
    heading.className = 'small fw-bold mb-2';
    heading.textContent = `${examinersLabel}: ${requiredCount}`;
    teacherContainer.appendChild(heading);

    selectedSlotKeys.forEach(slotKey => {
        const slot = slotsMap.get(slotKey);
        if (!slot) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'mb-2 p-2 border rounded';

        const slotLabel = document.createElement('div');
        slotLabel.className = 'small fw-bold mb-1';
        slotLabel.textContent = `${slot.daylabel || ''} · ${slot.timelabel || slotKey}`;
        row.appendChild(slotLabel);

        const teachers = Array.isArray(slot.teachers) ? slot.teachers : [];
        const availableIds = teachers
            .map(teacher => Number(teacher.id || 0))
            .filter(id => id > 0);

        const existing = Array.isArray(currentSelection[slotKey]) ? currentSelection[slotKey] : [];
        const preselected = existing
            .map(id => Number(id || 0))
            .filter(id => id > 0 && availableIds.includes(id));

        const select = document.createElement('select');
        select.className = 'form-control form-control-sm';
        select.dataset.slotKey = slotKey;

        if (requiredCount > 1) {
            select.multiple = true;
            select.size = Math.min(8, Math.max(requiredCount + 1, teachers.length));
        } else {
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '-';
            select.appendChild(emptyOption);
        }

        teachers.forEach(teacher => {
            const id = Number(teacher.id || 0);
            if (id <= 0) {
                return;
            }

            const option = document.createElement('option');
            option.value = String(id);
            option.textContent = String(teacher.fullname || id);
            option.selected = preselected.includes(id);
            select.appendChild(option);
        });

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
        row.appendChild(select);

        teacherContainer.appendChild(row);
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

    const dynamicForm = new DynamicForm(container.querySelector('[data-region="slotbooking-form"]'),
        'mod_booking\\form\\condition\\slotbooking_form');
    let currentLoadArgs = {
        id: optionid,
        userid,
    };
    const setupInteractiveUi = () => {
        const calendarRoot = container.querySelector('[data-region="slot-calendar-picker"]');
        const selectionInput = getSelectionInput(container);
        const jsonInput = container.querySelector('input[name="slot_calendar_data"]');
        const customEditorRoot = container.querySelector('[data-region="slot-custom-editor"]');
        const customStartInput = container.querySelector('input[name="slot_custom_start"]');
        const fixedEditorRoot = container.querySelector('[data-region="slot-fixed-editor"]');
        const customDurationSelect = container.querySelector('select[name="slot_custom_duration"]');
        const teacherSelectionInput = container.querySelector('input[name="slot_teacher_selection"]');
        const examinersLabelInput = container.querySelector('input[name="slot_examiners_per_slot_label"]');
        const usePricesInput = container.querySelector('input[name="slot_use_prices"]');
        const teachersRequiredInput = container.querySelector('input[name="slot_teachers_required_count"]');
        const timezone = getFormTimeZone(container);
        const timeFormatter = createTimeFormatter(timezone);
        const examinersLabel = (examinersLabelInput?.value || 'Examiners per slot').trim();
        const usePrices = Number(usePricesInput?.value || 0) === 1;

        if (!selectionInput) {
            return;
        }

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
                renderCustomDayEditor(customEditorRoot, daySlot, customStartInput, customDurationSelect, timeFormatter);
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
                        timeFormatter
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

        const teacherAnchor = fixedEditorRoot || calendarRoot || selectionInput;
        const teacherContainer = ensureTeacherContainer(container, teacherAnchor);
        const teachersRequired = Math.max(0, Number(teachersRequiredInput?.value || 0));

        const refreshTeacherSelection = () => {
            const selectedSlotKeys = getSelectedSlotKeys(selectionInput);
            renderTeacherSelection(
                teacherContainer,
                selectedSlotKeys,
                slotsMap,
                teachersRequired,
                teacherSelectionInput,
                examinersLabel
            );
        };

        if (calendarRoot && !calendarRoot.dataset.slotCalendarInitialized) {
            const maxInput = container.querySelector('input[name="slot_max_selection"]');
            const maxSlots = Number(maxInput?.value || 1);

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
                        selectionInput.value = selection.join(',');
                        selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
                    },
            };

            if (fixedEditorRoot) {
                calendarOptions.showSlotList = false;
                calendarOptions.showPriceLegend = usePrices;
                const renderFixedEditorForDay = (daySlots) => {
                    const normalizedDaySlots = Array.isArray(daySlots) ? daySlots : [];
                    if (normalizedDaySlots.length === 0) {
                        fixedEditorRoot.innerHTML = '';
                        fixedEditorRoot.style.display = 'none';
                        return;
                    }

                    fixedEditorRoot.style.display = '';
                    renderFixedSlotsEditor(
                        fixedEditorRoot,
                        normalizedDaySlots,
                        selectionInput,
                        maxSlots,
                        timeFormatter
                    );

                    if (!fixedEditorRoot.childElementCount) {
                        fixedEditorRoot.style.display = 'none';
                    }
                };
                calendarOptions.onDayChange = (_dayKey, daySlots) => {
                    renderFixedEditorForDay(daySlots);
                };
            }

            initSlotCalendarPicker(calendarRoot, calendarOptions);
            calendarRoot.dataset.slotCalendarInitialized = '1';
        }

        if (!selectionInput.dataset.slotSelectionBound) {
            selectionInput.addEventListener('change', refreshTeacherSelection);
            selectionInput.dataset.slotSelectionBound = '1';
        }

        refreshTeacherSelection();
    };

    const reloadForm = async(reloadArgs = null) => {
        if (reloadArgs) {
            currentLoadArgs = reloadArgs;
        }

        const validationButton = getValidationTriggerButton(container);
        if (validationButton) {
            validationButton.dataset.blocked = 'true';
        }

        await dynamicForm.load(currentLoadArgs);
        setupInteractiveUi();
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

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {
        e.preventDefault();
        const response = e.detail;

        if (response) {
            if (!continuebutton) {
                continuebutton = getValidationTriggerButton(container);
                bindValidationToContinueButton(continuebutton);
            }
            if (continuebutton) {
                continuebutton.dataset.blocked = 'false';
                continuebutton.click();
            }
        }
    });

    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, () => {
        setupInteractiveUi();
        showValidationFeedback(container);
    });

    dynamicForm.addEventListener(dynamicForm.events.CLIENT_VALIDATION_ERROR, () => {
        setupInteractiveUi();
        showValidationFeedback(container);
    });

    if (!container.dataset.slotbookingRefreshBound) {
        document.addEventListener(SLOTBOOKING_REFRESH_EVENT, async(event) => {
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
        });

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
