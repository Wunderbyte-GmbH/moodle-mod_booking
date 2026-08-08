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
 * Reads a ticket QR from the device camera using the standard BarcodeDetector API and verifies it
 * through the mod_booking_verify_ticket webservice (single source of truth), showing a traffic-light
 * result and a live "admitted / booked" counter.
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
    result: '[data-region="scanner-result"]',
    headline: '[data-region="scanner-result-headline"]',
    name: '[data-region="scanner-result-name"]',
    event: '[data-region="scanner-result-event"]',
    detail: '[data-region="scanner-result-detail"]',
    picture: '[data-region="scanner-result-picture"]',
    confirm: '[data-region="scanner-confirm"]',
    confirmbutton: '[data-action="scanner-confirm"]',
    rejectbutton: '[data-action="scanner-reject"]',
    status: '[data-region="scanner-status"]',
    start: '[data-action="scanner-start"]',
};

const RESULT_CLASSES = ['alert-success', 'alert-warning', 'alert-danger', 'd-none'];

const TYPE_CLASS = {success: 'alert-success', warning: 'alert-warning', danger: 'alert-danger'};

/**
 * Initialise the scanner on the page.
 *
 * @param {object} config
 * @param {number} config.cmid Booking course module id.
 * @param {boolean} config.serialscan Keep scanning after each result.
 * @param {number} config.duplicatewindow Seconds within which a repeat of the same code is ignored.
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
    ] = await getStrings([
        'ticketvalid', 'ticketnotfound', 'ticketscannerscanning', 'ticketscannerstart',
        'ticketscannerstop', 'ticketscannerstopped', 'ticketscannernocamera',
        'ticketscannernodetector', 'ticketscannerpermissiondenied', 'ticketscannerinvalid',
        'ticketscannerduplicate', 'ticketscannererror', 'ticketverifyidentityprompt',
        'ticketentryrejected',
    ].map((key) => ({key, component: 'mod_booking'})));

    const state = {
        running: false, stream: null, detector: null, lastcode: null, lasttime: 0, busy: false,
        // Code awaiting an explicit identity confirmation by the entry staff.
        pendingcode: null,
    };

    const setStatus = (text) => {
        if (els.status) {
            els.status.textContent = text;
        }
    };

    /**
     * Show or hide the identity confirmation card and the holder picture.
     *
     * @param {boolean} show
     * @param {string} pictureurl
     */
    const setConfirmMode = (show, pictureurl) => {
        if (els.picture) {
            if (show && pictureurl) {
                els.picture.src = pictureurl;
                els.picture.classList.remove('d-none');
            } else {
                els.picture.classList.add('d-none');
                els.picture.removeAttribute('src');
            }
        }
        if (els.confirm) {
            els.confirm.classList.toggle('d-none', !show);
        }
    };

    const showResult = (type, headline, name, eventname, detail) => {
        if (!els.result) {
            return;
        }
        els.result.classList.remove(...RESULT_CLASSES);
        els.result.classList.add(TYPE_CLASS[type]);
        els.headline.textContent = headline;
        els.name.textContent = name || '';
        els.event.textContent = eventname || '';
        els.detail.textContent = detail || '';
        if (navigator.vibrate) {
            navigator.vibrate(type === 'success' ? 40 : (type === 'danger' ? [120, 60, 120] : 80));
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

    const renderResponse = async(response, code) => {
        await updateCounter(response.presentcount, response.bookedcount);
        state.pendingcode = null;
        setConfirmMode(false, '');

        if (response.status === 'valid' && response.alreadypresent) {
            const headline = await getString('ticketalreadypresent', 'mod_booking', formatTime(response.presenttime));
            showResult('warning', headline, response.fullname, response.eventname, '');
        } else if (response.status === 'valid' && response.pendingconfirmation) {
            // The option demands an identity check: show name and picture and wait for a decision.
            state.pendingcode = code;
            showResult('warning', sConfirmidentity, response.fullname, response.eventname, '');
            setConfirmMode(true, response.userpictureurl);
        } else if (response.status === 'valid') {
            showResult('success', sValid, response.fullname, response.eventname, '');
        } else if (response.status === 'revoked') {
            const headline = await getString('ticketrevoked', 'mod_booking', formatTime(response.revokedtime));
            showResult('danger', headline, response.fullname, response.eventname, '');
        } else {
            showResult('danger', sNotfound, '', '', '');
        }
    };

    const verify = (code, confirmed = false) => {
        state.busy = true;
        const request = Ajax.call([{
            methodname: 'mod_booking_verify_ticket',
            args: {code, checkin: true, confirmed},
        }]);
        return request[0]
            .then((response) => renderResponse(response, code))
            .catch((error) => {
                Log.debug(error);
                showResult('danger', sError, '', '', '');
            })
            .finally(() => {
                state.busy = false;
            });
    };

    /**
     * Handle a decoded QR value: dedupe, validate, verify.
     *
     * @param {string} raw
     */
    const handleDetection = (raw) => {
        // While a decision on the previous ticket is outstanding, ignore further codes.
        if (state.busy || state.pendingcode) {
            return;
        }
        const code = extractCode(raw);
        if (!code) {
            showResult('danger', sInvalid, '', '', '');
            return;
        }
        const now = Date.now();
        if (code === state.lastcode && (now - state.lasttime) < (config.duplicatewindow * 1000)) {
            setStatus(sDuplicate);
            return;
        }
        state.lastcode = code;
        state.lasttime = now;
        verify(code).then(() => {
            // A ticket awaiting confirmation keeps the scanner open, whatever the serial scan setting is.
            if (!config.serialscan && !state.pendingcode) {
                stop();
            }
            return null;
        }).catch(() => null);
    };

    const scanFrame = async() => {
        if (!state.running || !state.detector) {
            return;
        }
        try {
            const barcodes = await state.detector.detect(els.video);
            if (barcodes && barcodes.length) {
                handleDetection(barcodes[0].rawValue);
            }
        } catch (e) {
            Log.debug(e);
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

    if (els.confirmbutton) {
        els.confirmbutton.addEventListener('click', () => {
            const code = state.pendingcode;
            if (!code) {
                return;
            }
            state.pendingcode = null;
            verify(code, true).then(() => {
                if (!config.serialscan) {
                    stop();
                }
                return null;
            }).catch(() => null);
        });
    }

    if (els.rejectbutton) {
        els.rejectbutton.addEventListener('click', () => {
            state.pendingcode = null;
            setConfirmMode(false, '');
            showResult('danger', sRejected, '', '', '');
            // Allow an immediate re-scan of the same code after a rejection.
            state.lastcode = null;
        });
    }

    els.start.addEventListener('click', () => {
        if (state.running) {
            stop();
            setStatus(sStopped);
        } else {
            els.start.disabled = true;
            start().finally(() => {
                els.start.disabled = false;
            });
        }
    });
};
