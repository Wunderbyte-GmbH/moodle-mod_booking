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
 * @module     mod_booking/teacherUnavailability
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';
import {init as initSlotCalendarPicker} from 'mod_booking/slotCalendarPicker';

const CHECKBOX_PREFIX = 'slot_selection_cb_';

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

const getSelectionInput = (container) => {
    return container.querySelector('input[name="slot_selection"]');
};

const getCheckboxes = (container) => {
    return Array.from(container.querySelectorAll(`input[name^="${CHECKBOX_PREFIX}"]`));
};

const getSelectedFromHidden = (selectionInput) => {
    if (!selectionInput) {
        return [];
    }

    return String(selectionInput.value || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
};

const setSelectionHiddenValue = (selectionInput, selectedKeys) => {
    if (!selectionInput) {
        return;
    }

    const normalized = Array.from(new Set((selectedKeys || []).map((value) => String(value).trim()).filter(Boolean)));
    selectionInput.value = normalized.join(',');
};

const syncHiddenFromCheckboxes = (container) => {
    const selectionInput = getSelectionInput(container);
    const checkboxes = getCheckboxes(container);
    if (!selectionInput || checkboxes.length === 0) {
        return;
    }

    const slots = parseSlots(container.querySelector('input[name="slot_calendar_data"]'));
    const selected = [];

    checkboxes.forEach((checkbox) => {
        const name = String(checkbox.name || '');
        const index = Number(name.replace(CHECKBOX_PREFIX, ''));
        const slot = Number.isInteger(index) ? slots[index] : null;
        if (!slot || !slot.key) {
            return;
        }

        if (checkbox.checked) {
            selected.push(String(slot.key));
        }
    });

    setSelectionHiddenValue(selectionInput, selected);
};

const readArgsFromContainer = (container) => {
    return {
        id: Number(container.dataset.id || 0),
        optionid: Number(container.dataset.optionid || 0),
        scopeoptionid: Number(container.dataset.scopeoptionid || 0),
        teacherid: Number(container.dataset.teacherid || 0),
        date: Number(container.dataset.date || 0),
        scope: String(container.dataset.scope || 'system'),
        markmode: String(container.dataset.markmode || 'unavailability'),
        viewmode: String(container.dataset.viewmode || 'calendar'),
    };
};

const readArgsFromForm = (container, fallbackArgs) => {
    const form = container.querySelector('form');
    if (!form) {
        return fallbackArgs;
    }

    const readValue = (name, fallback = '') => {
        const field = form.querySelector(`[name="${name}"]`);
        if (!field) {
            return fallback;
        }

        return String(field.value ?? fallback);
    };

    return {
        id: Number(readValue('id', fallbackArgs.id || 0)),
        optionid: Number(readValue('optionid', fallbackArgs.optionid || 0)),
        scopeoptionid: Number(readValue('scopeoptionid', fallbackArgs.scopeoptionid || 0)),
        teacherid: Number(readValue('teacherid', fallbackArgs.teacherid || 0)),
        date: Number(readValue('date', fallbackArgs.date || 0)),
        scope: readValue('scope', fallbackArgs.scope || 'system'),
        markmode: readValue('markmode', fallbackArgs.markmode || 'unavailability'),
        viewmode: readValue('viewmode', fallbackArgs.viewmode || 'calendar'),
    };
};

const showInvalidFeedback = (container) => {
    const elements = document.querySelectorAll('.invalid-feedback');
    const nonEmptyElements = Array.from(elements).filter((element) => element.textContent.trim() !== '');

    nonEmptyElements.forEach((element) => {
        let currentElement = element;

        while (currentElement && currentElement !== container) {
            currentElement = currentElement.parentElement;

            if (currentElement && currentElement.classList.contains('collapse')) {
                currentElement.classList.add('show');
            }
        }
    });

    if (nonEmptyElements.length > 0) {
        nonEmptyElements[0].scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }
};

const setupInteractiveUi = (container) => {
    const form = container.querySelector('form');
    if (!form) {
        return;
    }

    const selectionInput = getSelectionInput(container);
    if (!selectionInput) {
        return;
    }

    const jsonInput = container.querySelector('input[name="slot_calendar_data"]');
    const slots = parseSlots(jsonInput);
    const calendarRoot = container.querySelector('[data-region="slot-calendar-picker"]');

    if (calendarRoot && !calendarRoot.dataset.slotCalendarInitialized) {
        const initialSelection = getSelectedFromHidden(selectionInput);

        initSlotCalendarPicker(calendarRoot, {
            slots,
            maxSelection: Math.max(1, slots.length),
            initialSelection,
            onChange: (selection) => {
                setSelectionHiddenValue(selectionInput, selection);
            },
        });

        calendarRoot.dataset.slotCalendarInitialized = '1';
    }

    const checkboxes = getCheckboxes(container);
    if (checkboxes.length > 0) {
        checkboxes.forEach((checkbox) => {
            if (checkbox.dataset.slotSelectionBound === '1') {
                return;
            }

            checkbox.addEventListener('change', () => {
                syncHiddenFromCheckboxes(container);
            });
            checkbox.dataset.slotSelectionBound = '1';
        });

        syncHiddenFromCheckboxes(container);
    }
};

/**
 * Init dynamic teacher unavailability form.
 *
 * @param {string} containerId
 */
export const init = async(containerId) => {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    const reportUrl = container.dataset.reporturl || '';
    let args = readArgsFromContainer(container);
    const form = new DynamicForm(container, 'mod_booking\\form\\teacherunavailability_form');

    const reloadForm = async(reloadArgs = null) => {
        if (reloadArgs) {
            args = reloadArgs;
        } else {
            args = readArgsFromForm(container, args);
        }

        await form.load(args);
        setupInteractiveUi(container);

        const activeForm = container.querySelector('form');
        if (!activeForm || activeForm.dataset.scopeWatcherBound === '1') {
            return;
        }

        const watchedSelectors = [
            'select[name="scope"]',
            'select[name="scopeoptionid"]',
            'input[name="scopeoptionid"]',
            'select[name="markmode"]',
            'select[name="viewmode"]',
        ];

        watchedSelectors.forEach((selector) => {
            const field = activeForm.querySelector(selector);
            if (!field || field.dataset.slotReloadBound === '1') {
                return;
            }

            field.addEventListener('change', async() => {
                const freshArgs = readArgsFromForm(container, args);
                await reloadForm(freshArgs);
            });
            field.dataset.slotReloadBound = '1';
        });

        activeForm.dataset.scopeWatcherBound = '1';
    };

    await reloadForm(args);

    form.addEventListener(form.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        const response = e.detail || {};

        await reloadForm();

        if (response.saved === true) {
            getString('allchangessaved', 'mod_booking').then((message) => {
                Notification.addNotification({
                    message,
                    type: 'success'
                });
                return null;
            }).catch(() => {
                Notification.addNotification({
                    message: 'Saved',
                    type: 'success'
                });
            });
        }
    });

    form.addEventListener(form.events.FORM_CANCELLED, async(event) => {
        event.preventDefault();
        if (reportUrl) {
            window.location.href = reportUrl;
            return;
        }

        await reloadForm();
    });

    form.addEventListener(form.events.SERVER_VALIDATION_ERROR, () => {
        setupInteractiveUi(container);
        showInvalidFeedback(container);
    });

    form.addEventListener(form.events.CLIENT_VALIDATION_ERROR, () => {
        setupInteractiveUi(container);
        showInvalidFeedback(container);
    });
};
