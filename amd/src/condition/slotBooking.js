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

const SELECTOR = {
    FORMCONTAINER: '.booking-slotbooking-prepage',
    PREPAGEBODY: '.prepage-body',
    CONTINUEBUTTON: ' div.prepage-booking-footer .continue-button',
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
    let container = document.querySelector('div.modal.show ' + SELECTOR.FORMCONTAINER);

    if (!container) {
        const containers = document.querySelectorAll('div.prepage-body ' + SELECTOR.FORMCONTAINER);
        containers.forEach(el => {
            if (!isHidden(el)) {
                container = el;
            }
        });

        if (!container) {
            return;
        }
    }

    const optionid = container.dataset.optionid;
    const userid = container.dataset.userid;

    const dynamicForm = new DynamicForm(container.querySelector('[data-region="slotbooking-form"]'),
        'mod_booking\\form\\condition\\slotbooking_form');

    await dynamicForm.load({
        id: optionid,
        userid,
    });
    const setupInteractiveUi = () => {
        const calendarRoot = container.querySelector('[data-region="slot-calendar-picker"]');
        const selectionInput = getSelectionInput(container);
        const jsonInput = container.querySelector('input[name="slot_calendar_data"]');
        const teacherSelectionInput = container.querySelector('input[name="slot_teacher_selection"]');
        const examinersLabelInput = container.querySelector('input[name="slot_examiners_per_slot_label"]');
        const teachersRequiredInput = container.querySelector('input[name="slot_teachers_required_count"]');
        const examinersLabel = (examinersLabelInput?.value || 'Examiners per slot').trim();

        if (!selectionInput) {
            return;
        }

        const slots = parseSlots(jsonInput);
        const slotsMap = new Map();
        slots.forEach(slot => {
            const key = String(slot.key || `${slot.start}:${slot.end}`);
            slotsMap.set(key, slot);
        });

        const teacherContainer = ensureTeacherContainer(container, calendarRoot || selectionInput);
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

            const initialSelection = selectionInput.value
                ? selectionInput.value.split(',').map(value => value.trim()).filter(Boolean)
                : [];

            initSlotCalendarPicker(calendarRoot, {
                slots,
                maxSelection: Number(maxInput?.value || 1),
                initialSelection,
                onChange: (selection) => {
                    selectionInput.value = selection.join(',');
                    selectionInput.dispatchEvent(new Event('change', {bubbles: true}));
                },
            });

            calendarRoot.dataset.slotCalendarInitialized = '1';
        }

        if (!selectionInput.dataset.slotSelectionBound) {
            selectionInput.addEventListener('change', refreshTeacherSelection);
            selectionInput.dataset.slotSelectionBound = '1';
        }

        refreshTeacherSelection();
    };

    setupInteractiveUi();

    let continuebutton = container.closest(SELECTOR.PREPAGEBODY).querySelector(SELECTOR.CONTINUEBUTTON);

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, e => {
        const response = e.detail;

        if (response) {
            if (!continuebutton) {
                continuebutton = container.closest(SELECTOR.PREPAGEBODY).querySelector(SELECTOR.CONTINUEBUTTON);
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

    if (continuebutton) {
        continuebutton.dataset.blocked = 'true';

        continuebutton.addEventListener('click', (event) => {
            if (continuebutton.dataset.blocked === 'true') {
                event.preventDefault();
                event.stopPropagation();
                dynamicForm.submitFormAjax();
            }
        });
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

/**
 * Function to check visibility of element.
 * @param {*} el
 * @returns {boolean}
 */
function isHidden(el) {
    var style = window.getComputedStyle(el);
    return ((style.display === 'none') || (style.visibility === 'hidden'));
}
