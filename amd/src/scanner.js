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
 * SofaTicket entry scanner.
 *
 * Reads a ticket QR from the device camera using the standard BarcodeDetector API and resolves it
 * through the mod_booking_verify_ticket webservice (single source of truth). Every scan opens a
 * full-screen result overlay: the check-in is only written when entry staff presses "Confirm";
 * "Reject" records a rejection (mod_booking_reject_ticket) and writes no presence.
 *
 * Options with several dates are checked in per date. The scanner keeps a sticky date selection
 * per option (defaulting to the nearest date as chosen by the server) so a queue for one session
 * can be scanned without touching the selection again.
 *
 * QR decoding is deliberately written against the standard `BarcodeDetector` interface. On platforms
 * without native support (Safari/WebKit — hence all iOS browsers — and Firefox) a polyfill implementing
 * the same interface must be assigned to `globalThis.BarcodeDetector` before scanning; bundling the
 * `barcode-detector` (zxing-wasm) polyfill is tracked as a follow-up. Where the API is missing, the
 * scanner degrades gracefully with a clear message instead of failing silently.
 *
 * @module     mod_booking/scanner
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString, getStrings} from 'core/str';
import Log from 'core/log';

const SELECTORS = {
    root: '[data-region="scanner"]',
    video: '[data-region="scanner-video"]',
    counter: '[data-region="scanner-counter"]',
    status: '[data-region="scanner-status"]',
    start: '[data-action="scanner-start"]',
    datebar: '[data-region="scanner-datebar"]',
    datebaroption: '[data-region="scanner-datebar-option"]',
    dateselect: '[data-region="scanner-dateselect"]',
    overlay: '[data-region="scanner-overlay"]',
    iconsuccess: '[data-region="scanner-icon-success"]',
    icondanger: '[data-region="scanner-icon-danger"]',
    iconwarning: '[data-region="scanner-icon-warning"]',
    headline: '[data-region="scanner-result-headline"]',
    event: '[data-region="scanner-result-event"]',
    overlaydate: '[data-region="scanner-overlay-date"]',
    overlaydateselect: '[data-region="scanner-overlay-dateselect"]',
    identity: '[data-region="scanner-result-identity"]',
    picture: '[data-region="scanner-result-picture"]',
    name: '[data-region="scanner-result-name"]',
    fields: '[data-region="scanner-result-fields"]',
    detail: '[data-region="scanner-result-detail"]',
    confirmbutton: '[data-action="scanner-confirm"]',
    nextbutton: '[data-action="scanner-next"]',
    rejectbutton: '[data-action="scanner-reject"]',
};

/** Milliseconds the "checked in" confirmation stays on screen before the camera returns. */
const CHECKEDIN_FLASH_MS = 700;

/** Transparent 1x1 GIF shown while no holder picture is loaded (img needs a non-empty src). */
const PLACEHOLDER_PICTURE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/**
 * Initialise the scanner on the page.
 *
 * @param {object} config
 * @param {number} config.cmid Booking course module id.
 * @param {boolean} config.serialscan Return to the camera automatically after each decision.
 * @param {number} config.duplicatewindow Seconds within which a repeat of a confirmed code is ignored.
 * @param {boolean} config.showpicture Whether the profile picture is part of the configured identity data.
 */
export const init = async(config) => {
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }

    const els = {};
    Object.keys(SELECTORS).forEach((key) => {
        els[key] = root.querySelector(SELECTORS[key]);
    });

    // Preload the static (non-parameterised) UI labels.
    const [
        sValid, sNotfound, sScanning, sStart, sStop, sStopped, sNocamera,
        sNodetector, sPermission, sInvalid, sDuplicate, sError, sConfirmidentity, sRejected,
        sConfirmprompt, sCheckedin,
    ] = await getStrings([
        'ticketvalid', 'ticketnotfound', 'ticketscannerscanning', 'ticketscannerstart',
        'ticketscannerstop', 'ticketscannerstopped', 'ticketscannernocamera',
        'ticketscannernodetector', 'ticketscannerpermissiondenied', 'ticketscannerinvalid',
        'ticketscannerduplicate', 'ticketscannererror', 'ticketverifyidentityprompt',
        'ticketentryrejected', 'ticketscannerconfirmprompt', 'ticketscannercheckedin',
    ].map((key) => ({key, component: 'mod_booking'})));

    const state = {
        running: false,
        stream: null,
        detector: null,
        // Detection is paused (frames are skipped, the loop keeps running) while the overlay is open.
        paused: false,
        // A webservice call is in flight.
        busy: false,
        // Last code that was confirmed, for the duplicate window.
        lastcode: null,
        lasttime: 0,
        // The ticket currently shown in the overlay: {code, response}.
        current: null,
        // Sticky date selection per option id, and the option currently shown in the date bar.
        datebyoption: {},
        baroptionid: 0,
    };

    const setStatus = (text) => {
        if (els.status) {
            els.status.textContent = text;
        }
    };

    const updateCounter = async(present, booked) => {
        if (els.counter) {
            els.counter.textContent = await getString('ticketscannercounter', 'mod_booking', {present, booked});
        }
    };

    const formatTime = (timestamp) => timestamp
        ? new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
        : '';

    const VIBRATION = {success: 40, danger: [120, 60, 120], warning: 80};
    const vibrate = (type) => {
        if (navigator.vibrate) {
            navigator.vibrate(VIBRATION[type] || 80);
        }
    };

    /**
     * Pull an alphanumeric ticket code out of the raw QR content.
     * Accepts either a bare code or a verification URL carrying ?code=...
     *
     * @param {string} raw
     * @return {string|null}
     */
    const extractCode = (raw) => {
        if (!raw) {
            return null;
        }
        let candidate = raw.trim();
        if (candidate.indexOf('code=') !== -1) {
            try {
                candidate = new URL(candidate).searchParams.get('code') || candidate;
            } catch (e) {
                const match = candidate.match(/[?&]code=([^&]+)/);
                candidate = match ? decodeURIComponent(match[1]) : candidate;
            }
        }
        return /^[A-Za-z0-9]+$/.test(candidate) ? candidate : null;
    };

    /**
     * Fill a date <select> with the dates of the scanned option.
     *
     * @param {HTMLSelectElement} select
     * @param {Array} dates
     * @param {number} selected
     */
    const fillDateSelect = async(select, dates, selected) => {
        if (!select) {
            return;
        }
        select.innerHTML = '';
        for (const date of dates) {
            const option = document.createElement('option');
            option.value = String(date.optiondateid);
            option.textContent = date.present
                ? await getString('ticketscannerdatepresent', 'mod_booking', date.label)
                : date.label;
            option.selected = date.optiondateid === selected;
            select.appendChild(option);
        }
    };

    /**
     * Keep the sticky date bar in sync with the option that was just scanned.
     *
     * @param {object} response
     */
    const updateDateBar = async(response) => {
        if (!els.datebar) {
            return;
        }
        if (!response.optionid || !response.dates || !response.dates.length) {
            els.datebar.classList.add('d-none');
            state.baroptionid = 0;
            return;
        }
        state.datebyoption[response.optionid] = response.optiondateid;
        state.baroptionid = response.optionid;
        els.datebaroption.textContent = response.eventname;
        await fillDateSelect(els.dateselect, response.dates, response.optiondateid);
        els.datebar.classList.remove('d-none');
    };

    /**
     * The date to request for a scan: the sticky selection of the option shown in the date bar.
     * The server ignores it when the ticket belongs to another option and picks that option's nearest date.
     *
     * @return {number}
     */
    const stickyDateId = () => {
        if (state.baroptionid && state.datebyoption[state.baroptionid]) {
            return state.datebyoption[state.baroptionid];
        }
        return 0;
    };

    const setIcon = (type) => {
        els.iconsuccess.classList.toggle('d-none', type !== 'success');
        els.icondanger.classList.toggle('d-none', type !== 'danger');
        els.iconwarning.classList.toggle('d-none', type !== 'warning');
    };

    const setButtons = (mode) => {
        els.confirmbutton.classList.toggle('d-none', mode !== 'decide');
        els.rejectbutton.classList.toggle('d-none', mode !== 'decide');
        els.nextbutton.classList.toggle('d-none', mode !== 'next');
        els.confirmbutton.disabled = false;
        els.rejectbutton.disabled = false;
    };

    /**
     * Render the identity card (picture, name, configured profile data) of the holder.
     *
     * @param {object} response
     * @param {boolean} show
     */
    const renderIdentity = (response, show) => {
        els.fields.innerHTML = '';
        if (!show) {
            els.identity.classList.add('d-none');
            els.picture.classList.add('d-none');
            els.picture.src = PLACEHOLDER_PICTURE;
            return;
        }
        if (config.showpicture && response.userpictureurl) {
            els.picture.src = response.userpictureurl;
            els.picture.classList.remove('d-none');
        } else {
            els.picture.classList.add('d-none');
            els.picture.src = PLACEHOLDER_PICTURE;
        }
        els.name.textContent = response.fullname || '';
        for (const field of (response.identityfields || [])) {
            if (field.shortname === 'fullname' || !field.value) {
                continue;
            }
            const dt = document.createElement('dt');
            dt.textContent = field.name;
            const dd = document.createElement('dd');
            dd.textContent = field.value;
            els.fields.appendChild(dt);
            els.fields.appendChild(dd);
        }
        els.identity.classList.remove('d-none');
    };

    /**
     * Show the full-screen overlay for a resolved ticket.
     *
     * @param {string} type success|warning|danger
     * @param {string} headline
     * @param {object} response
     * @param {string} detail
     * @param {string} buttons decide|next
     */
    const showOverlay = (type, headline, response, detail, buttons) => {
        els.overlay.dataset.state = type;
        setIcon(type);
        els.headline.textContent = headline;
        els.event.textContent = response.eventname || '';
        els.detail.textContent = detail || '';
        setButtons(buttons);
        els.overlay.classList.remove('d-none');
        state.paused = true;
        vibrate(type);
        // Restart the draw animation of the icon on every result.
        els.overlay.querySelectorAll('svg').forEach((svg) => {
            svg.classList.remove('booking-scanner-animate');
            // Force a reflow so the animation restarts.
            void svg.offsetWidth;
            svg.classList.add('booking-scanner-animate');
        });
        // Move focus to the primary action for keyboard and screen-reader users.
        (buttons === 'decide' ? els.confirmbutton : els.nextbutton).focus();
    };

    const closeOverlay = () => {
        els.overlay.classList.add('d-none');
        state.current = null;
        state.paused = false;
        state.busy = false;
        if (!config.serialscan) {
            stop();
            setStatus(sStopped);
        } else if (state.running) {
            setStatus(sScanning);
        }
    };

    /**
     * Render the webservice response of a lookup into the overlay.
     *
     * @param {object} response
     * @param {string} code
     */
    const renderLookup = async(response, code) => {
        await updateCounter(response.presentcount, response.bookedcount);
        await updateDateBar(response);
        state.current = {code, response};

        const hasdates = response.dates && response.dates.length > 0;
        els.overlaydate.classList.toggle('d-none', !hasdates);
        if (hasdates) {
            await fillDateSelect(els.overlaydateselect, response.dates, response.optiondateid);
        }
        const showidentity = response.status !== 'notfound'
            && (response.personalized || response.requiresconfirmation);
        renderIdentity(response, showidentity);

        if (response.status === 'valid' && response.alreadypresent) {
            const headline = await getString('ticketalreadypresent', 'mod_booking', formatTime(response.presenttime));
            showOverlay('warning', headline, response, response.eventdatelabel, 'next');
        } else if (response.status === 'valid') {
            const detail = [response.eventdatelabel, showidentity ? sConfirmidentity : sConfirmprompt]
                .filter((part) => part).join(' — ');
            showOverlay('success', sValid, response, detail, 'decide');
        } else if (response.status === 'revoked') {
            const headline = await getString('ticketrevoked', 'mod_booking', formatTime(response.revokedtime));
            showOverlay('danger', headline, response, response.eventdatelabel, 'next');
        } else {
            showOverlay('danger', sNotfound, {}, '', 'next');
        }
    };

    /**
     * Resolve a code without writing anything (the scan step).
     *
     * @param {string} code
     * @param {number} optiondateid
     * @return {Promise}
     */
    const lookup = (code, optiondateid) => {
        state.busy = true;
        const request = Ajax.call([{
            methodname: 'mod_booking_verify_ticket',
            args: {code, checkin: false, confirmed: false, optiondateid: optiondateid || 0},
        }]);
        return request[0]
            .then((response) => renderLookup(response, code))
            .catch((error) => {
                Log.debug(error);
                showOverlay('danger', sError, {}, '', 'next');
                return null;
            })
            .finally(() => {
                state.busy = false;
            });
    };

    /**
     * Write the check-in for the ticket shown in the overlay (the Confirm step).
     */
    const confirm = () => {
        if (!state.current || state.busy) {
            return;
        }
        const {code, response} = state.current;
        state.busy = true;
        els.confirmbutton.disabled = true;
        els.rejectbutton.disabled = true;
        const request = Ajax.call([{
            methodname: 'mod_booking_verify_ticket',
            args: {code, checkin: true, confirmed: true, optiondateid: response.optiondateid || 0},
        }]);
        request[0]
            .then(async(written) => {
                await updateCounter(written.presentcount, written.bookedcount);
                if (written.status !== 'valid' || written.pendingconfirmation) {
                    // The ticket changed under us (e.g. cancelled meanwhile): show what the server says.
                    await renderLookup(written, code);
                    return null;
                }
                state.lastcode = code;
                state.lasttime = Date.now();
                if (written.dates && written.dates.length) {
                    await fillDateSelect(els.overlaydateselect, written.dates, written.optiondateid);
                }
                const headline = written.alreadypresent
                    ? await getString('ticketalreadypresent', 'mod_booking', formatTime(written.presenttime))
                    : sCheckedin;
                els.headline.textContent = headline;
                els.detail.textContent = written.eventdatelabel || '';
                setButtons('none');
                vibrate('success');
                setTimeout(closeOverlay, CHECKEDIN_FLASH_MS);
                return null;
            })
            .catch((error) => {
                Log.debug(error);
                showOverlay('danger', sError, response, '', 'next');
            })
            .finally(() => {
                state.busy = false;
            });
    };

    /**
     * Record a rejection for the ticket shown in the overlay and return to the camera.
     */
    const reject = () => {
        if (!state.current || state.busy) {
            return;
        }
        const {code, response} = state.current;
        state.busy = true;
        els.confirmbutton.disabled = true;
        els.rejectbutton.disabled = true;
        const request = Ajax.call([{
            methodname: 'mod_booking_reject_ticket',
            args: {code, optiondateid: response.optiondateid || 0},
        }]);
        request[0]
            .catch((error) => {
                Log.debug(error);
                return null;
            })
            .finally(() => {
                // A rejected code may be scanned again right away.
                state.lastcode = null;
                closeOverlay();
                setStatus(sRejected);
            });
    };

    /**
     * Handle a decoded QR value: dedupe, validate, look up.
     *
     * @param {string} raw
     */
    const handleDetection = (raw) => {
        if (state.busy || state.paused) {
            return;
        }
        const code = extractCode(raw);
        if (!code) {
            showOverlay('danger', sInvalid, {}, '', 'next');
            return;
        }
        const now = Date.now();
        if (code === state.lastcode && (now - state.lasttime) < (config.duplicatewindow * 1000)) {
            setStatus(sDuplicate);
            return;
        }
        lookup(code, stickyDateId());
    };

    const scanFrame = async() => {
        if (!state.running || !state.detector) {
            return;
        }
        if (!state.paused && !state.busy) {
            try {
                const barcodes = await state.detector.detect(els.video);
                if (barcodes && barcodes.length) {
                    handleDetection(barcodes[0].rawValue);
                }
            } catch (e) {
                Log.debug(e);
            }
        }
        if (state.running) {
            requestAnimationFrame(scanFrame);
        }
    };

    const stop = () => {
        state.running = false;
        if (state.stream) {
            state.stream.getTracks().forEach((track) => track.stop());
            state.stream = null;
        }
        if (els.start) {
            els.start.textContent = sStart;
            els.start.disabled = false;
        }
    };

    const start = async() => {
        if (state.running) {
            return;
        }
        if (typeof globalThis.BarcodeDetector === 'undefined') {
            setStatus(sNodetector);
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus(sNocamera);
            return;
        }
        try {
            const formats = await globalThis.BarcodeDetector.getSupportedFormats();
            if (formats.indexOf('qr_code') === -1) {
                setStatus(sNodetector);
                return;
            }
            state.detector = new globalThis.BarcodeDetector({formats: ['qr_code']});
            state.stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}, audio: false});
            els.video.srcObject = state.stream;
            await els.video.play();
            state.running = true;
            els.start.textContent = sStop;
            setStatus(sScanning);
            requestAnimationFrame(scanFrame);
        } catch (e) {
            Log.debug(e);
            setStatus(sPermission);
            stop();
        }
    };

    els.confirmbutton.addEventListener('click', confirm);
    els.rejectbutton.addEventListener('click', reject);
    els.nextbutton.addEventListener('click', closeOverlay);

    // Escape closes the overlay without a decision (nothing is written or logged).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !els.overlay.classList.contains('d-none') && !state.busy) {
            closeOverlay();
        }
    });

    // Sticky date bar: remember the choice for the option currently scanned.
    els.dateselect.addEventListener('change', () => {
        if (state.baroptionid) {
            state.datebyoption[state.baroptionid] = parseInt(els.dateselect.value, 10) || 0;
        }
    });

    // Date changed for the ticket on screen: resolve it again for that date (presence differs per date).
    els.overlaydateselect.addEventListener('change', () => {
        if (!state.current || state.busy) {
            return;
        }
        const optiondateid = parseInt(els.overlaydateselect.value, 10) || 0;
        const {code, response} = state.current;
        if (response.optionid) {
            state.datebyoption[response.optionid] = optiondateid;
        }
        lookup(code, optiondateid);
    });

    els.start.addEventListener('click', () => {
        if (state.running) {
            stop();
            setStatus(sStopped);
        } else {
            els.start.disabled = true;
            start().catch((e) => Log.debug(e)).finally(() => {
                els.start.disabled = false;
            });
        }
    });
};
