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
 * "Update booking" prepage controller: move / cancel / change a booked answer's slots in one editor.
 *
 * Drives the slotupdate_form DynamicForm. The slot universe comes from the embedded
 * slot_calendar_data snapshot (no webservice), the answer's current slots are pre-selected and its
 * locked slots are pinned. Deselecting cancels, switching moves/changes. A signed live price is
 * computed client-side from the embedded slot prices; the authoritative net delta + itemised
 * confirmation come from the form's two-pass submit (slot_update_service::plan/apply).
 *
 * @module     mod_booking/condition/slotUpdate
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import {init as initSlotCalendarPicker} from 'mod_booking/slotCalendarPicker';
import {createHiddenInputSelection, renderSlotList} from 'mod_booking/slotbooking/slot_day_renderers';
import {closeModal, closeInline} from 'mod_booking/bookingpage/prepageFooter';
import {getString} from 'core/str';
import Notification from 'core/notification';
import Config from 'core/config';

/**
 * Parse a JSON-encoded array from a hidden input value.
 *
 * @param {HTMLElement|null} input
 * @return {Array}
 */
const parseJsonArray = (input) => {
    if (!input) {
        return [];
    }
    try {
        const parsed = JSON.parse(input.value || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

/**
 * Current slot keys selected in the form (comma separated hidden input or a <select>).
 *
 * @param {HTMLElement|null} selectionInput
 * @return {Array<string>}
 */
const getSelectedKeys = (selectionInput) => {
    if (!selectionInput) {
        return [];
    }
    if (selectionInput.tagName === 'SELECT') {
        return Array.from(selectionInput.selectedOptions || [])
            .map(opt => String(opt.value || '').trim())
            .filter(Boolean);
    }
    return String(selectionInput.value || '')
        .split(',')
        .map(value => value.trim())
        .filter(Boolean);
};

/**
 * Resolve the slot-selection input (hidden field or rendered select).
 *
 * @param {HTMLElement} container
 * @return {HTMLElement|null}
 */
const getSelectionInput = (container) => {
    return container.querySelector('input[name="slot_selection"]')
        || container.querySelector('select[name="slot_selection"]')
        || container.querySelector('select[name="slot_selection[]"]');
};

/**
 * Ensure (and return) the inline signed-price region beneath the picker.
 *
 * @param {HTMLElement} container
 * @return {HTMLElement}
 */
const ensurePriceRegion = (container) => {
    let region = container.querySelector('[data-region="slotupdate-price"]');
    if (!region) {
        region = document.createElement('div');
        region.dataset.region = 'slotupdate-price';
        region.className = 'mt-2 font-weight-bold';
        const anchor = container.querySelector('[data-region="slot-calendar-picker"]')
            || container.querySelector('[data-region="slot-list-picker"]')
            || getSelectionInput(container);
        if (anchor && anchor.parentNode) {
            anchor.parentNode.appendChild(region);
        } else {
            container.appendChild(region);
        }
    }
    return region;
};

/**
 * Build a "start:end" -> price and -> "daylabel · timelabel" lookup from the embedded slots.
 *
 * @param {Array} slots
 * @return {{price: Map<string, number>, label: Map<string, string>, currency: string}}
 */
const indexSlots = (slots) => {
    const price = new Map();
    const label = new Map();
    let currency = '';
    slots.forEach(slot => {
        const key = String(slot.key || `${slot.start}:${slot.end}`);
        price.set(key, Number(slot.price || 0));
        label.set(key, `${slot.daylabel || ''} · ${slot.timelabel || key}`.trim());
        if (!currency && slot.currency) {
            currency = String(slot.currency);
        }
    });
    return {price, label, currency};
};

/**
 * Compose the itemised confirmation body from the server plan (removed/added keys + net delta).
 *
 * @param {object} plan the FORM_SUBMITTED 'needsconfirm' payload
 * @param {Map<string, string>} labels key -> readable label
 * @param {string} currency
 * @return {Promise<string>}
 */
const buildConfirmBody = async(plan, labels, currency) => {
    const lines = [];
    const labelFor = (key) => labels.get(key) || key;

    const removed = Array.isArray(plan.removed) ? plan.removed : [];
    const added = Array.isArray(plan.added) ? plan.added : [];

    if (plan.ismove && removed.length && added.length) {
        const moved = await getString('slot_update_confirm_moved', 'mod_booking');
        lines.push(`${moved}: ${added.map(labelFor).join(', ')}`);
    } else {
        if (removed.length) {
            const lbl = await getString('slot_update_confirm_removed', 'mod_booking');
            lines.push(`${lbl}: ${removed.map(labelFor).join(', ')}`);
        }
        if (added.length) {
            const lbl = await getString('slot_update_confirm_added', 'mod_booking');
            lines.push(`${lbl}: ${added.map(labelFor).join(', ')}`);
        }
    }

    const net = Number(plan.netdelta || 0);
    const amount = `${Math.abs(net).toFixed(2)}${currency ? ' ' + currency : ''}`;
    if (plan.route === 'cart') {
        lines.push(await getString('slot_update_confirm_net_charge', 'mod_booking', amount));
    } else if (plan.route === 'refund') {
        lines.push(await getString('slot_update_confirm_net_refund', 'mod_booking', amount));
    } else if (plan.route === 'cancel') {
        lines.push(await getString('slot_update_confirm_cancel_all', 'mod_booking'));
    }

    return lines.join('<br>');
};

/**
 * Render the signed live price (selected - current) from the embedded slot prices.
 *
 * @param {HTMLElement} region
 * @param {Map<string, number>} priceByKey
 * @param {Array<string>} currentKeys
 * @param {Array<string>} selectedKeys
 * @param {string} currency
 * @param {boolean} usePrices
 * @return {Promise<void>}
 */
const renderSignedPrice = async(region, priceByKey, currentKeys, selectedKeys, currency, usePrices) => {
    if (!usePrices) {
        region.textContent = '';
        return;
    }
    const sum = (keys) => keys.reduce((total, key) => total + (priceByKey.get(key) || 0), 0);
    const delta = sum(selectedKeys) - sum(currentKeys);
    if (Math.abs(delta) < 0.005) {
        region.textContent = '';
        return;
    }
    const sign = delta > 0 ? '+' : '−';
    const amount = `${sign}${Math.abs(delta).toFixed(2)}${currency ? ' ' + currency : ''}`;
    const label = await getString('slot_update_delta_label', 'mod_booking');
    region.textContent = `${label}: ${amount}`;
};

/**
 * Hydrate the picker after the form (re)loads: feed it the embedded slots, pre-select the current
 * slots, pin the locked ones and wire the signed live price.
 *
 * @param {HTMLElement} container
 * @return {Promise<void>}
 */
const setupPicker = async(container) => {
    const selectionInput = getSelectionInput(container);
    if (!selectionInput) {
        return;
    }

    const slots = parseJsonArray(container.querySelector('input[name="slot_calendar_data"]'));
    const currentKeys = parseJsonArray(container.querySelector('input[name="slot_update_current"]'));
    const lockedKeys = parseJsonArray(container.querySelector('input[name="slot_update_locked"]'));
    const usePrices = Number(container.querySelector('input[name="slot_use_prices"]')?.value || 0) === 1;
    // D4 (edit-only): the update may never grow the booking, so the cap is the current slot count.
    const maxSelection = Math.max(1, currentKeys.length);

    const {price: priceByKey, currency} = indexSlots(slots);
    const priceRegion = ensurePriceRegion(container);
    const updatePrice = () => renderSignedPrice(
        priceRegion,
        priceByKey,
        currentKeys,
        getSelectedKeys(selectionInput),
        currency,
        usePrices
    );

    const calendarRoot = container.querySelector('[data-region="slot-calendar-picker"]');
    const listRoot = container.querySelector('[data-region="slot-list-picker"]');

    let currentLabel = '';
    let lockedLabel = '';
    try {
        [currentLabel, lockedLabel] = await Promise.all([
            getString('slot_move_current_booking', 'mod_booking'),
            getString('slot_move_locked_label', 'mod_booking'),
        ]);
    } catch {
        currentLabel = '';
        lockedLabel = '';
    }

    if (calendarRoot && calendarRoot.dataset.slotUpdateInit !== '1') {
        calendarRoot.dataset.slotUpdateInit = '1';
        initSlotCalendarPicker(calendarRoot, {
            slots,
            maxSelection,
            initialSelection: currentKeys,
            currentKeys,
            lockedKeys,
            currentLabel,
            lockedLabel,
            // The update form renders the calendar region only for the 'calendar' view mode.
            slotView: 'timeline',
            showPriceLegend: usePrices,
            onChange: (selection) => {
                selectionInput.value = (Array.isArray(selection) ? selection : []).join(',');
                updatePrice();
            },
        });
    } else if (listRoot && listRoot.dataset.slotUpdateInit !== '1') {
        listRoot.dataset.slotUpdateInit = '1';
        await renderSlotList(listRoot, slots, createHiddenInputSelection(selectionInput, maxSelection));
    }

    if (!selectionInput.dataset.slotUpdatePriceBound) {
        selectionInput.addEventListener('change', updatePrice);
        selectionInput.dataset.slotUpdatePriceBound = '1';
    }

    updatePrice();
};

/**
 * Close the prepage (modal or inline) and let the booking tables reload.
 *
 * @param {number} optionid
 */
const closePrepage = (optionid) => {
    closeModal(optionid);
    closeInline(optionid);
};

/**
 * Initialise the update prepage for one option.
 *
 * @param {string} containerId DOM id of the update container (carries optionid/userid/baid/selfservice)
 * @return {Promise<void>}
 */
export const init = async(containerId) => {
    const container = document.getElementById(containerId);
    if (!container || container.dataset.slotupdateDelegated === '1') {
        return;
    }
    container.dataset.slotupdateDelegated = '1';

    const optionid = Number(container.dataset.optionid || 0);
    const userid = Number(container.dataset.userid || 0);
    const baid = Number(container.dataset.baid || 0);
    const selfservice = container.dataset.selfservice === '1';
    const formRegion = container.querySelector('[data-region="slotupdate-form"]');
    if (!formRegion || !optionid || !baid) {
        return;
    }

    const dynamicForm = new DynamicForm(formRegion, 'mod_booking\\form\\condition\\slotupdate_form');
    const loadArgs = {
        id: optionid,
        userid,
        baid,
        selfservice: selfservice ? 1 : 0,
    };

    const labelLookup = () => indexSlots(
        parseJsonArray(container.querySelector('input[name="slot_calendar_data"]'))
    );

    // Standalone pages (moveslot.php / rebookslot.php) carry a return URL and redirect after a
    // committed change; inside a prepage modal there is no return URL and we close the modal instead.
    const returnurl = container.dataset.returnurl || '';
    const finish = () => {
        if (returnurl) {
            window.location.href = returnurl;
            return;
        }
        closePrepage(optionid);
    };

    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        const response = e.detail;
        if (!response) {
            return;
        }

        if (response.status === 'nochange') {
            finish();
            return;
        }

        if (response.status === 'needsconfirm') {
            const {label, currency} = labelLookup();
            let body = '';
            try {
                body = await buildConfirmBody(response, label, currency);
            } catch (err) {
                body = '';
                window.console.log(err);
            }
            const [title, save] = await Promise.all([
                getString('slot_update_confirm_title', 'mod_booking'),
                getString('slot_update_confirm_save', 'mod_booking'),
            ]);
            try {
                await Notification.saveCancelPromise(title, body, save);
            } catch {
                // User cancelled the confirmation: keep the form open, nothing committed.
                return;
            }
            const confirmedInput = container.querySelector('input[name="slot_update_confirmed"]');
            if (confirmedInput) {
                confirmedInput.value = '1';
            }
            dynamicForm.submitFormAjax();
            return;
        }

        if (response.status === 'committed') {
            // An upgrade is held in the cart: send the user to checkout to pay the difference.
            if (response.mode === 'cart') {
                window.location.href = Config.wwwroot + '/local/shopping_cart/checkout.php';
                return;
            }
            // A downgrade refunds the difference as credit; surface a toast (only useful when we
            // stay on the page, i.e. inside a modal — a redirect would drop it), then finish.
            if (response.mode === 'refund' && !returnurl) {
                try {
                    const message = await getString(
                        'slotmove_refunded',
                        'mod_booking',
                        Math.abs(Number(response.pricedelta || 0)).toFixed(2)
                    );
                    Notification.addNotification({message, type: 'success'});
                } catch (err) {
                    window.console.log(err);
                }
            }
            finish();
        }
    });

    const rehydrate = async() => {
        await setupPicker(container);
    };

    dynamicForm.addEventListener(dynamicForm.events.SERVER_VALIDATION_ERROR, rehydrate);
    dynamicForm.addEventListener(dynamicForm.events.CLIENT_VALIDATION_ERROR, rehydrate);

    await dynamicForm.load(loadArgs);
    await setupPicker(container);

    const submit = container.querySelector('[data-action="slotupdate-submit"]');
    if (submit && submit.dataset.slotUpdateBound !== '1') {
        submit.dataset.slotUpdateBound = '1';
        submit.addEventListener('click', (event) => {
            event.preventDefault();
            // Each submit starts a fresh confirm round-trip (first pass = summary).
            const confirmedInput = container.querySelector('input[name="slot_update_confirmed"]');
            if (confirmedInput) {
                confirmedInput.value = '0';
            }
            dynamicForm.submitFormAjax();
        });
    }
};
