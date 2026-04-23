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
 * AI Instructions chat interface AMD module.
 *
 * @module     mod_booking/aiinstructions
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Fragment from 'core/fragment';
import Notification from 'core/notification';
import Templates from 'core/templates';

/** Pending commands waiting for user confirmation. */
let pendingCommands = null;
let currentThreadId = 0;
let currentCmid = 0;
let debugModeEnabled = false;
let privacyCheckRunningLabel = 'Privacy check running...';
let privacyAnswerNoteLabel = 'Privacy note: personal data in this response was de-anonymized for display.';
let defaultThinkingLabel = '';
let forceNewThreadOnFirstMessage = true;
let trialTokenInvalidAlertShown = false;
let trialTokenInvalidTitleLabel = '';
let trialTokenInvalidMessageLabel = '';
let trialTokenInvalidOkLabel = '';

/** @type {Array<string>} */
const TRIAL_TOKEN_ISSUE_CODES = [
    'TRIAL_TOKEN_INVALID',
    'TRIAL_TOKEN_EXPIRED',
    'SUBSCRIPTION_REQUIRED',
    'AI_PROVIDER_AUTH_FAILED',
    'AI_PROVIDER_QUOTA_EXCEEDED',
];

/**
 * Execute collected JavaScript returned by Moodle web service responses.
 *
 * @param {string} javascript
 */
const runCollectedJavascript = (javascript) => {
    const js = Fragment.processCollectedJavascript(String(javascript || ''));
    if (js && js.trim() !== '') {
        Templates.runTemplateJS(js);
    }
};

/** @type {Array<string>} */
const READ_ONLY_TASKS = [
    'booking.search_options',
    'booking.search_users',
    'booking.search_courses',
    'booking.get_current_user',
    'booking.list_option_properties',
    'booking.list_actions',
    'entities.search',
    'entities.list_all_entities',
    'shopping_cart.get_items',
    'shopping_cart.get_totals',
];

/**
 * Returns true when all commands are read-only and can run without confirm button.
 *
 * @param {Array} commands
 * @returns {boolean}
 */
const shouldAutoExecuteReadOnly = (commands) => {
    if (!Array.isArray(commands) || commands.length === 0) {
        return false;
    }

    return commands.every((cmd) => {
        const task = String((cmd && cmd.task) || '');
        return READ_ONLY_TASKS.includes(task);
    });
};

/**
 * Render compact debug metadata below a message bubble.
 *
 * @param {Object|null} meta
 * @returns {string}
 */
const renderMessageDebugMeta = (meta) => {
    if (!debugModeEnabled || !meta || typeof meta !== 'object') {
        return '';
    }

    const keys = [
        'response_type',
        'threadid',
        'runid',
        'commands_count',
        'llm_commands_json',
        'attempted_tasks',
        'issue_codes',
        'pending_confirmation_code',
        'errors',
        'status',
        'source',
        'time',
    ];
    const parts = [];
    keys.forEach((key) => {
        if (Object.prototype.hasOwnProperty.call(meta, key) && meta[key] !== null && meta[key] !== '') {
            parts.push(`${key}=${String(meta[key])}`);
        }
    });

    if (parts.length === 0) {
        return '';
    }

    return `<div class="booking-ai-msg-debug">${escapeHtml(parts.join(' | '))}</div>`;
};

/**
 * Render an optional pretty JSON block for debug command payloads.
 *
 * @param {Object|null} meta
 * @returns {string}
 */
const renderMessageDebugJson = (meta) => {
    if (!debugModeEnabled || !meta || typeof meta !== 'object') {
        return '';
    }

    const raw = String(meta.llm_commands_json || '').trim();
    if (raw === '') {
        return '';
    }

    let pretty = raw;
    try {
        pretty = JSON.stringify(JSON.parse(raw), null, 2);
    } catch (e) {
        // Keep raw if parsing fails.
    }

    return '<details class="booking-ai-debug-json">'
        + '<summary>LLM Task JSON</summary>'
        + `<pre>${escapeHtml(pretty)}</pre>`
        + '</details>';
};

/**
 * Parse JSON-encoded list safely.
 *
 * @param {string} raw
 * @returns {Array}
 */
const parseJsonList = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
};

/**
 * Parse JSON-encoded object list safely.
 *
 * @param {string} raw
 * @returns {Array<Object>}
 */
const parseJsonObjectList = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed.filter((entry) => entry && typeof entry === 'object');
    } catch (e) {
        return [];
    }
};

/**
 * Detect whether an AI error indicates an invalid/expired trial token.
 *
 * @param {Object|null} response
 * @param {Array<string>} errors
 * @param {Array<string>} issueCodes
 * @returns {boolean}
 */
const isTrialTokenInvalidError = (response, errors = [], issueCodes = []) => {
    const normalizedCodes = (Array.isArray(issueCodes) ? issueCodes : []).map((code) => String(code || '').trim().toUpperCase());
    if (normalizedCodes.some((code) => TRIAL_TOKEN_ISSUE_CODES.includes(code))) {
        return true;
    }

    const haystack = [
        String((response && response.displaymessage) || ''),
        String((response && response.message) || ''),
        ...(Array.isArray(errors) ? errors : []),
    ].join(' ').toLowerCase();

    if (!haystack) {
        return false;
    }

    const markers = [
        'invalid token',
        'token is invalid',
        'token expired',
        'expired token',
        'invalid api key',
        'incorrect api key',
        'authenticationerror',
        'rate limit exceeded for api_key',
        'unauthorized',
        '429: rate limit exceeded',
        'limit type: tokens',
        'current limit: 0',
        'remaining: 0',
        'insufficient_quota',
        'insufficient quota',
        'insufficient credits',
        'max budget',
        'budget exceeded',
        'credit balance is too low',
    ];

    return markers.some((marker) => haystack.includes(marker));
};

/**
 * Show one-time alert when trial token is no longer valid.
 *
 * @param {Object|null} response
 * @param {Array<string>} errors
 * @param {Array<string>} issueCodes
 */
const maybeShowTrialTokenInvalidAlert = (response, errors = [], issueCodes = []) => {
    if (trialTokenInvalidAlertShown) {
        return;
    }

    if (!isTrialTokenInvalidError(response, errors, issueCodes)) {
        return;
    }

    trialTokenInvalidAlertShown = true;
    Notification.alert(
        trialTokenInvalidTitleLabel,
        trialTokenInvalidMessageLabel,
        trialTokenInvalidOkLabel
    );
};

/**
 * Render clickable ambiguity options below a clarification message.
 *
 * @param {Array<Object>} options
 * @returns {string}
 */
const renderAmbiguityOptionsHtml = (options = []) => {
    const entries = Array.isArray(options) ? options : [];
    if (entries.length === 0) {
        return '';
    }

    const buttons = entries.map((entry) => {
        const query = String((entry && entry.query) || '').trim();
        const title = String((entry && entry.title) || '').trim();
        const label = String((entry && entry.label) || title || query).trim();
        if (query === '' || label === '') {
            return '';
        }

        return '<button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-2 booking-ai-ambiguity-option"'
            + ` data-query="${escapeHtml(query)}"`
            + ` title="${escapeHtml(query)}">${escapeHtml(label)}</button>`;
    }).filter((button) => button !== '').join('');

    if (buttons === '') {
        return '';
    }

    return '<div class="booking-ai-ambiguity-options mt-3 p-3 border rounded bg-light">'
        + '<div class="font-weight-bold mb-1">Please select the topic you mean</div>'
        + '<div class="small text-muted mb-2">I found multiple matching documentation entries.</div>'
        + `<div class="d-flex flex-wrap">${buttons}</div>`
        + '</div>';
};

/**
 * Append a chat bubble to the message list.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} content   Message text.
 * @param {Object|null} meta Compact debug metadata.
 */
const appendMessage = (role, content, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<span class="bubble">${escapeHtml(content)}</span>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
};

/**
 * Append a small privacy status line (not a normal chat bubble).
 *
 * @param {string} content
 * @param {Object|null} meta
 */
const appendPrivacyNote = (content, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-privacy-note');
    div.innerHTML = `<span>${escapeHtml(content)}</span>${renderMessageDebugMeta(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
};

/**
 * Append the privacy note for assistant responses when display de-masking was applied.
 *
 * @param {Object|null} response
 * @param {string} source
 */
const appendAssistantPrivacyNote = (response, source = 'ai_send_message') => {
    if (!response || Number(response.privacyapplied || 0) !== 1) {
        return;
    }

    appendPrivacyNote(privacyAnswerNoteLabel, {
        response_type: 'privacy_response',
        threadid: Number(response.threadid || currentThreadId || 0),
        runid: Number(response.runid || 0),
        status: 'privacy_applied',
        source,
        time: (new Date()).toISOString(),
    });
};

/**
 * Append a chat bubble with trusted HTML content.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} html      Trusted HTML.
 * @param {Object|null} meta Compact debug metadata.
 */
const appendMessageHtml = (role, html, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<span class="bubble">${String(html || '')}</span>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
};

/**
 * Replace content of the dedicated side preview panel.
 *
 * @param {string} html Trusted HTML.
 */
const setSidePreviewHtml = (html) => {
    const preview = document.getElementById('booking-ai-side-preview');
    if (!preview) {
        return;
    }
    preview.innerHTML = String(html || '');
};

/**
 * Initialize drag-to-resize behavior for chat and preview panes on desktop.
 */
const initResizableLayout = () => {
    const layout = document.getElementById('booking-ai-body-layout');
    const splitter = document.getElementById('booking-ai-splitter');
    if (!layout || !splitter) {
        return;
    }

    const desktopMedia = window.matchMedia('(min-width: 992px)');
    const storageKey = 'mod_booking_ai_preview_width';

    const applyColumns = (previewPercent) => {
        const safePreview = Math.min(90, Math.max(20, Number(previewPercent || 42)));
        const mainPercent = 100 - safePreview;
        layout.style.gridTemplateColumns = `minmax(0, ${mainPercent}%) 10px minmax(0, ${safePreview}%)`;
        splitter.setAttribute('aria-valuenow', String(Math.round(safePreview)));
    };

    const restoreOrDefault = () => {
        if (!desktopMedia.matches) {
            layout.style.gridTemplateColumns = '';
            return;
        }
        const stored = Number(window.localStorage.getItem(storageKey) || 42);
        applyColumns(stored);
    };

    restoreOrDefault();

    let dragging = false;

    const onPointerMove = (clientX) => {
        if (!dragging || !desktopMedia.matches) {
            return;
        }
        const rect = layout.getBoundingClientRect();
        if (rect.width <= 0) {
            return;
        }
        const previewPercent = ((rect.right - clientX) / rect.width) * 100;
        applyColumns(previewPercent);
        window.localStorage.setItem(storageKey, String(Math.min(90, Math.max(20, previewPercent))));
    };

    const onMouseMove = (event) => {
        onPointerMove(event.clientX);
    };

    const onTouchMove = (event) => {
        const touch = event.touches && event.touches[0];
        if (!touch) {
            return;
        }
        onPointerMove(touch.clientX);
    };

    const stopDragging = () => {
        dragging = false;
        document.body.classList.remove('booking-ai-resizing');
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', stopDragging);
        document.removeEventListener('touchmove', onTouchMove);
        document.removeEventListener('touchend', stopDragging);
    };

    const startDragging = (event) => {
        if (!desktopMedia.matches) {
            return;
        }
        dragging = true;
        document.body.classList.add('booking-ai-resizing');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', stopDragging);
        document.addEventListener('touchmove', onTouchMove, {passive: true});
        document.addEventListener('touchend', stopDragging);

        const touch = event.touches && event.touches[0];
        if (touch) {
            onPointerMove(touch.clientX);
            return;
        }
        onPointerMove(event.clientX);
    };

    splitter.addEventListener('mousedown', startDragging);
    splitter.addEventListener('touchstart', startDragging, {passive: true});

    desktopMedia.addEventListener('change', () => {
        restoreOrDefault();
    });
};

/**
 * Initialize mobile preview toggle and horizontal swipe gesture.
 */
const initMobilePreviewSwitch = () => {
    const layout = document.getElementById('booking-ai-body-layout');
    const toggle = document.getElementById('booking-ai-mobile-toggle');
    if (!layout || !toggle) {
        return;
    }

    const mobileMedia = window.matchMedia('(max-width: 991.98px)');
    const label = toggle.querySelector('.booking-ai-mobile-toggle-label');

    const setPreviewActive = (active) => {
        const previewActive = Boolean(active);
        layout.classList.toggle('mobile-preview-active', previewActive);
        toggle.setAttribute('aria-pressed', previewActive ? 'true' : 'false');
        if (label) {
            label.textContent = previewActive ? 'Chat' : 'Preview';
        }
    };

    setPreviewActive(false);

    toggle.addEventListener('click', () => {
        setPreviewActive(!layout.classList.contains('mobile-preview-active'));
    });

    let startX = 0;
    let startY = 0;

    layout.addEventListener('touchstart', (event) => {
        const touch = event.touches && event.touches[0];
        if (!touch || !mobileMedia.matches) {
            return;
        }
        startX = touch.clientX;
        startY = touch.clientY;
    }, {passive: true});

    layout.addEventListener('touchend', (event) => {
        const touch = event.changedTouches && event.changedTouches[0];
        if (!touch || !mobileMedia.matches) {
            return;
        }

        const deltaX = touch.clientX - startX;
        const deltaY = touch.clientY - startY;
        if (Math.abs(deltaX) < 50 || Math.abs(deltaY) > 40) {
            return;
        }

        if (deltaX < 0) {
            setPreviewActive(true);
        } else {
            setPreviewActive(false);
        }
    }, {passive: true});

    mobileMedia.addEventListener('change', () => {
        if (!mobileMedia.matches) {
            layout.classList.remove('mobile-preview-active');
        }
    });
};

/**
 * Minimal HTML escape.
 *
 * @param  {string} str
 * @return {string}
 */
const escapeHtml = (str) => {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
};

/**
 * Escape text and convert URLs/newlines for rich status rendering.
 *
 * @param {string} text
 * @returns {string}
 */
const renderTextWithLinks = (text) => {
    const input = String(text || '');
    const urlRegex = /https?:\/\/[^\s)]+/g;

    let html = '';
    let lastIndex = 0;
    let match;

    while ((match = urlRegex.exec(input)) !== null) {
        const url = match[0];
        const index = match.index;

        html += escapeHtml(input.slice(lastIndex, index));
        html += `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
        lastIndex = index + url.length;
    }

    html += escapeHtml(input.slice(lastIndex));
    return html.replace(/\n/g, '<br>');
};

/**
 * Detect generic status placeholders that are not user-friendly final answers.
 *
 * @param {string} message
 * @returns {boolean}
 */
const isGenericStatusMessage = (message) => {
    const normalized = String(message || '').trim().toLowerCase();
    if (!normalized) {
        return true;
    }

    const generic = [
        'completed',
        'queued',
        'running',
        'failed',
        'executing',
        'executing the action.',
        'fuehre die aktion aus.',
        'fuehre die aktion aus',
    ];

    return generic.includes(normalized);
};

/**
 * Read the first non-empty string field from structured results.
 *
 * @param {Array} results
 * @param {Array<string>} fields
 * @returns {string}
 */
const getFirstResultField = (results, fields) => {
    const safeResults = Array.isArray(results) ? results : [];
    for (const result of safeResults) {
        if (!result || typeof result !== 'object') {
            continue;
        }

        for (const field of fields) {
            const value = String(result[field] || '').trim();
            if (value !== '') {
                return value;
            }
        }
    }

    return '';
};

/**
 * Build a user-friendly chat message from structured run results.
 *
 * @param {string} status
 * @param {string} message
 * @param {Array} results
 * @returns {string}
 */
const buildFriendlyRunMessage = (status, message, results = []) => {
    const safeStatus = String(status || '').toLowerCase();
    const safeMessage = String(message || '').trim();

    if (safeStatus !== 'completed' && safeStatus !== 'failed') {
        return isGenericStatusMessage(safeMessage) ? '' : safeMessage;
    }

    // For final run states, prefer the top-level backend message first.
    // It already reflects output language and final summarization policy.
    if (!isGenericStatusMessage(safeMessage)) {
        return safeMessage;
    }

    const first = Array.isArray(results) && results.length > 0 ? (results[0] || {}) : {};

    const userMessage = String(first.usermessage || '').trim();
    if (!isGenericStatusMessage(userMessage)) {
        return userMessage;
    }

    const summary = String(first.summary || '').trim();
    if (!isGenericStatusMessage(summary)) {
        return summary;
    }

    const detail = String(first.detail || '').trim();
    if (!isGenericStatusMessage(detail)) {
        return detail;
    }

    return safeStatus === 'failed'
        ? 'The request failed. Please check the details below.'
        : 'Done.';
};

/**
 * Build a task-authored debug bubble for developer mode.
 *
 * @param {string} status
 * @param {string} message
 * @param {Array} results
 * @returns {string}
 */
const buildDebugRunHtml = (status, message, results = []) => {
    if (!debugModeEnabled) {
        return '';
    }

    const debugMessages = [];
    (Array.isArray(results) ? results : []).forEach((result) => {
        const debugMessage = String((result && result.debugmessage) || '').trim();
        if (debugMessage !== '') {
            debugMessages.push(debugMessage);
        }
    });

    if (debugMessages.length > 0) {
        const items = debugMessages.map((debugMessage) => `<li>${renderTextWithLinks(debugMessage)}</li>`).join('');
        return '<div class="booking-ai-run-status-inline alert alert-secondary mb-0">'
            + '<strong>Debug</strong>'
            + `<ul class="mb-0">${items}</ul>`
            + '</div>';
    }

    const fallback = getFirstResultField(results, ['detail']);
    const safeMessage = String(message || fallback || status).trim();
    if (safeMessage === '') {
        return '';
    }

    return '<div class="booking-ai-run-status-inline alert alert-secondary mb-0">'
        + `<strong>${escapeHtml(String(status || 'debug'))}</strong>: ${renderTextWithLinks(safeMessage)}`
        + '</div>';
};

/**
 * Append user-friendly assistant text while preserving line breaks.
 *
 * @param {string} content
 */
const appendFriendlyAssistantMessage = (content) => {
    const text = String(content || '').trim();
    if (!text) {
        return;
    }
    appendMessageHtml('assistant', `<span>${renderTextWithLinks(text)}</span>`);
};

/**
 * Show the confirmation panel with a preview of the proposed commands.
 *
 * @param {string} message   AI summary message.
 * @param {Array}  commands  Validated command objects.
 */
const showConfirmPanel = (message, commands) => {
    pendingCommands = commands;

    const panel = document.getElementById('booking-ai-confirm-panel');
    const preview = document.getElementById('booking-ai-confirm-preview');
    if (!panel || !preview) {
        return;
    }

    preview.innerHTML = '';

    if (debugModeEnabled) {
        let previewHtml = `<p>${escapeHtml(message)}</p><ul>`;
        commands.forEach((cmd) => {
            previewHtml += `<li><strong>${escapeHtml(cmd.task)}</strong>: ${escapeHtml(JSON.stringify(cmd.input))}`;
            previewHtml += '</li>';
        });
        previewHtml += '</ul>';
        preview.innerHTML = previewHtml;
        setSidePreviewHtml(previewHtml);

        Ajax.call([{
            methodname: 'mod_booking_ai_render_command_preview',
            args: {
                cmid: currentCmid,
                commands: JSON.stringify(commands),
            },
        }])[0].then((resp) => {
            if (resp && resp.success && resp.html && resp.html.trim() !== '') {
                setSidePreviewHtml(resp.html);
                runCollectedJavascript(resp.javascript);
            } else if (resp && resp.message) {
                setSidePreviewHtml(`<div class="text-muted small">${escapeHtml(String(resp.message))}</div>`);
            }
            return resp;
        }).catch((err) => {
            setSidePreviewHtml(`<div class="text-danger small">${escapeHtml(String(err.message || ''))}</div>`);
        });
    }

    panel.classList.remove('d-none');
};

/**
 * Render booking option previews in the side preview panel.
 *
 * @param {number} cmid
 * @param {Array<number>} optionIds
 * @returns {Promise<void>}
 */
const renderOptionPreviewsInline = (cmid, optionIds) => {
    const uniqueIds = [...new Set((optionIds || []).map((id) => Number(id || 0)).filter((id) => id > 0))].slice(0, 10);

    if (uniqueIds.length === 0) {
        return Promise.resolve();
    }

    return Ajax.call([{
        methodname: 'mod_booking_ai_render_command_preview',
        args: {
            cmid,
            optionids: JSON.stringify(uniqueIds),
        },
    }])[0].then((resp) => {
        if (resp && resp.success && resp.html && resp.html.trim() !== '') {
            setSidePreviewHtml(resp.html);
            runCollectedJavascript(resp.javascript);
        }
        return resp;
    }).catch((err) => {
        Notification.exception(err);
    });
};

/**
 * Build task-authored side preview HTML when provided by results.
 *
 * @param {Array} results
 * @returns {string}
 */
const buildTaskPreviewHtml = (results = []) => {
    const entries = Array.isArray(results) ? results : [];
    if (entries.length === 0) {
        return '';
    }

    // Preferred path: task explicitly requests a user-profile preview.
    for (const result of entries) {
        const previewMode = String((result && result.previewmode) || '').trim();
        if (previewMode === 'user_profile') {
            const payload = (result && typeof result.previewdata === 'object' && result.previewdata)
                ? result.previewdata
                : result;
            const fullname = escapeHtml(String((payload && payload.fullname) || result.fullname || '-'));
            const email = escapeHtml(String((payload && payload.email) || result.email || '-'));
            const userid = Number((payload && payload.userid) || result.userid || 0);
            const userIdText = userid > 0 ? String(userid) : '-';

            return '<div class="booking-ai-run-status-inline card mb-0">'
                + '<div class="card-body p-3">'
                + '<h6 class="mb-2">User profile</h6>'
                + `<div><strong>Name:</strong> ${fullname}</div>`
                + `<div><strong>E-Mail:</strong> ${email}</div>`
                + `<div><strong>User ID:</strong> ${escapeHtml(userIdText)}</div>`
                + '</div></div>';
        }
    }

    // Backward-compatible fallback: infer user-profile preview from result fields.
    const userResult = entries.find((result) => Number((result && result.userid) || 0) > 0);
    if (!userResult) {
        return '';
    }

    const fullname = escapeHtml(String(userResult.fullname || '-'));
    const email = escapeHtml(String(userResult.email || '-'));
    const userid = Number(userResult.userid || 0);
    const userIdText = userid > 0 ? String(userid) : '-';

    return '<div class="booking-ai-run-status-inline card mb-0">'
        + '<div class="card-body p-3">'
        + '<h6 class="mb-2">User profile</h6>'
        + `<div><strong>Name:</strong> ${fullname}</div>`
        + `<div><strong>E-Mail:</strong> ${email}</div>`
        + `<div><strong>User ID:</strong> ${escapeHtml(userIdText)}</div>`
        + '</div></div>';
};

/**
 * Hide the confirmation panel.
 */
const hideConfirmPanel = () => {
    pendingCommands = null;
    const panel = document.getElementById('booking-ai-confirm-panel');
    if (panel) {
        panel.classList.add('d-none');
    }
};

/**
 * Show a run status message.
 *
 * @param {string} status  'queued' | 'running' | 'completed' | 'failed'
 * @param {string} message Optional detail.
 * @param {Array} results Optional structured per-command results.
 */
const showRunStatus = (status, message, results = []) => {
    // eslint-disable-next-line no-console
    console.log('[AI Debug] showRunStatus called', {status, message, results});

    // Notify the page that AI has finished so other components (e.g. booking list) can reload.
    if (status === 'completed') {
        document.dispatchEvent(new CustomEvent('mod_booking_ai_run_completed', {bubbles: true}));
    }

    const friendlyMessage = buildFriendlyRunMessage(status, message, results);
    if (friendlyMessage) {
        appendFriendlyAssistantMessage(friendlyMessage);
    }

    const debugHtml = buildDebugRunHtml(status, message, results);
    if (debugHtml) {
        appendMessageHtml('assistant', debugHtml, {
            response_type: 'execution_debug',
            status: String(status || ''),
            source: 'showRunStatus',
            time: (new Date()).toISOString(),
        });
    }

    const taskPreviewHtml = buildTaskPreviewHtml(results);
    if (taskPreviewHtml && (status === 'completed' || status === 'failed')) {
        setSidePreviewHtml(taskPreviewHtml);
        return;
    }

    if (friendlyMessage && (status === 'completed' || status === 'failed')) {
        setSidePreviewHtml(
            `<div class="booking-ai-run-status-inline alert alert-light mb-0">`
            + `${renderTextWithLinks(friendlyMessage)}</div>`
        );
        return;
    }

    const alertClass = (status === 'completed') ? 'alert-success'
                     : (status === 'failed')    ? 'alert-danger'
                     : 'alert-info';
    const statusLabel = escapeHtml(String(status || 'info'));
    let html = `<div class="booking-ai-run-status-inline alert ${alertClass} mb-0">`;
    if (Array.isArray(results) && results.length > 0) {
        const items = results.map((result) => {
            const properties = Array.isArray(result.properties) ? result.properties : [];
            if (properties.length > 0) {
                return properties.map((property) => {
                    const label = escapeHtml(String(property.label || property.name || '-'));
                    return `<li>${label}</li>`;
                }).join('');
            }

            const actions = Array.isArray(result.actions) ? result.actions : [];
            if (actions.length > 0) {
                return actions.map((action) => {
                    const label = escapeHtml(String(action.label || action.task || '-'));
                    return `<li>${label}</li>`;
                }).join('');
            }

            const resultStatus = escapeHtml(String(result.status || status));
            const resultDetail = renderTextWithLinks(String(result.detail || ''));

            const options = Array.isArray(result.options) ? result.options : [];
            let optionsHtml = '';
            if (options.length > 0) {
                optionsHtml = options.map((option) => {
                    const optionName = escapeHtml(String(option.name || '-'));
                    const optionId = Number(option.id || 0);
                    const optionLink = escapeHtml(String(option.link || '#'));
                    return `<a href="${optionLink}" target="_blank" rel="noopener noreferrer">${optionName} (${optionId})</a>`;
                }).join('<br>');
            }

            let extraHtml = '';
            if (result.fullname) {
                extraHtml += `<div><strong>Name:</strong> ${escapeHtml(String(result.fullname))}</div>`;
            }
            if (result.email) {
                extraHtml += `<div><strong>E-Mail:</strong> ${escapeHtml(String(result.email))}</div>`;
            }

            return `<li><strong>${resultStatus}</strong>${resultDetail ? `: ${resultDetail}` : ''}`
                + `${extraHtml ? `<div class="mt-1">${extraHtml}</div>` : ''}`
                + `${optionsHtml ? `<div class="mt-1">${optionsHtml}</div>` : ''}`
                + '</li>';
        }).join('');
        html += `<ul class="mb-0">${items}</ul>`;
    } else {
        html += `<strong>${statusLabel}</strong>: ${renderTextWithLinks(message || status)}`;
    }
    html += '</div>';
    appendMessageHtml('assistant', html);

    // Keep execution output visible in the dedicated preview pane.
    // If a richer option/table preview arrives later, it will replace this content.
    setSidePreviewHtml(html);
};

/**
 * Extract all preview option ids from run results.
 *
 * @param {Array} results
 * @returns {Array<number>}
 */
const extractPreviewOptionIds = (results) => {
    const ids = [];
    (Array.isArray(results) ? results : []).forEach((result) => {
        const isUserCentricResult = Number(result.userid || 0) > 0;
        const singleId = Number(result.resultid || 0);
        if (!isUserCentricResult && singleId > 0) {
            ids.push(singleId);
        }

        const many = Array.isArray(result.previewoptionids) ? result.previewoptionids : [];
        many.forEach((id) => {
            const normalized = Number(id || 0);
            if (normalized > 0) {
                ids.push(normalized);
            }
        });
    });

    return [...new Set(ids)];
};

/**
 * Poll a run until it is completed or failed.
 *
 * @param {number} runid
 * @param {number} cmid
 */
const pollRunStatus = (runid, cmid) => {
    const interval = setInterval(() => {
        Ajax.call([{
            methodname: 'mod_booking_ai_poll_run_status',
            args: {cmid, runid},
        }])[0].then((resp) => {
            if (resp.status === 'notfound') {
                clearInterval(interval);
                showRunStatus('failed', resp.status);
                return resp;
            }

            if (resp.status === 'completed' || resp.status === 'failed') {
                clearInterval(interval);
                let results = [];
                try {
                    results = JSON.parse(resp.resultsjson || '[]');
                } catch (e) {
                    // Keep empty results on parse errors.
                }
                // eslint-disable-next-line no-console
                console.log('[AI Debug] pollRunStatus resp', resp, 'parsed results', results);

                appendAssistantPrivacyNote(resp, 'ai_poll_run_status');
                showRunStatus(resp.status, resp.displaymessage || resp.message || resp.status, results);

                if (resp.status === 'completed') {
                    const optionIds = extractPreviewOptionIds(results);
                    if (optionIds.length > 0) {
                        renderOptionPreviewsInline(cmid, optionIds);
                    }
                }
            }
            return resp;
        }).catch((err) => {
            clearInterval(interval);
            Notification.exception(err);
        });
    }, 2000);
};

/**
 * Send a message to the AI agent.
 *
 * @param {string} message
 */
const sendMessage = (message) => {
    if (!message.trim()) {
        return;
    }

    appendMessage('user', message, {
        source: 'chat_input',
        time: (new Date()).toISOString(),
    });

    const thinking = document.getElementById('booking-ai-thinking');
    const sendBtn  = document.getElementById('booking-ai-send');
    if (thinking) {
        thinking.textContent = privacyCheckRunningLabel;
        thinking.classList.remove('d-none');
    }
    if (sendBtn) {
        sendBtn.disabled = true;
    }

    Ajax.call([{
        methodname: 'mod_booking_ai_privacy_precheck',
        args: {
            cmid: currentCmid,
            message,
            forcenewthread: forceNewThreadOnFirstMessage ? 1 : 0,
        },
    }])[0].then((precheck) => {
        forceNewThreadOnFirstMessage = false;

        if (precheck.threadid && precheck.threadid > 0) {
            currentThreadId = precheck.threadid;
        }

        const strictMode = Number(precheck.strictmode || 0) === 1;
        const anonymizedCount = Number(precheck.anonymizedcount || 0);
        if (strictMode || anonymizedCount > 0) {
            appendPrivacyNote(precheck.message || '', {
                response_type: 'privacy_precheck',
                threadid: Number(precheck.threadid || currentThreadId || 0),
                status: String(precheck.status || ''),
                source: 'ai_privacy_precheck',
                time: (new Date()).toISOString(),
            });
        }

        if (String(precheck.status || '') !== 'ok') {
            if (thinking) {
                thinking.classList.add('d-none');
                thinking.textContent = defaultThinkingLabel;
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            return precheck;
        }

        const sanitizedMessage = String(precheck.sanitizedmessage || message);
        if (thinking) {
            thinking.textContent = defaultThinkingLabel;
        }

        return Ajax.call([{
        methodname: 'mod_booking_ai_send_message',
        args: {cmid: currentCmid, message: sanitizedMessage},
    }])[0].then((resp) => {
        if (thinking) {
            thinking.classList.add('d-none');
            thinking.textContent = defaultThinkingLabel;
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }

        if (resp.threadid && resp.threadid > 0) {
            currentThreadId = resp.threadid;
        }

        if (Number(resp.previewoptionid || 0) > 0) {
            renderOptionPreviewsInline(currentCmid, [Number(resp.previewoptionid)]);
        }

        if (resp.response_type === 'clarification' || resp.response_type === 'error') {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            const attemptedTasks = parseJsonList(resp.attemptedtasksjson);
            const errors = parseJsonList(resp.errorsjson);
            const issueCodes = parseJsonList(resp.issuecodesjson);
            maybeShowTrialTokenInvalidAlert(resp, errors, issueCodes);
            const ambiguityOptions = parseJsonObjectList(resp.ambiguityoptionsjson || '[]');
            const messageText = String(resp.displaymessage || resp.message || '');
            const ambiguityOptionsHtml = renderAmbiguityOptionsHtml(ambiguityOptions);

            if (ambiguityOptionsHtml !== '' && resp.response_type === 'clarification') {
                appendMessageHtml(
                    'assistant',
                    `<span>${renderTextWithLinks(messageText)}</span>${ambiguityOptionsHtml}`,
                    {
                        response_type: resp.response_type || '',
                        threadid: Number(resp.threadid || currentThreadId || 0),
                        runid: Number(resp.runid || 0),
                        attempted_tasks: attemptedTasks.join(', '),
                        issue_codes: issueCodes.join(', '),
                        pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
                        errors: errors.join(' || '),
                        source: 'ai_send_message',
                        time: (new Date()).toISOString(),
                    }
                );
                return resp;
            }

            appendMessage('assistant', resp.displaymessage || resp.message, {
                response_type: resp.response_type || '',
                threadid: Number(resp.threadid || currentThreadId || 0),
                runid: Number(resp.runid || 0),
                attempted_tasks: attemptedTasks.join(', '),
                issue_codes: issueCodes.join(', '),
                pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
                errors: errors.join(' || '),
                source: 'ai_send_message',
                time: (new Date()).toISOString(),
            });
        } else if (resp.response_type === 'execution_result') {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            // eslint-disable-next-line no-console
            console.log('[AI Debug] execution_result resp', resp, 'parsed results', results);
            showRunStatus(resp.status || 'completed', resp.displaymessage || resp.message || '', results);

            const optionIds = extractPreviewOptionIds(results);
            if (optionIds.length > 0) {
                renderOptionPreviewsInline(currentCmid, optionIds);
            }
        } else if (resp.response_type === 'confirmation_request' || resp.response_type === 'task_call') {
            try {
                const parsedCommands = JSON.parse(resp.commands || '[]');
                const cmds = Array.isArray(parsedCommands)
                    ? parsedCommands
                    : (parsedCommands && typeof parsedCommands === 'object' && parsedCommands.task
                        ? [parsedCommands]
                        : []);
                const attemptedTasks = parseJsonList(resp.attemptedtasksjson);
                const errors = parseJsonList(resp.errorsjson);
                const issueCodes = parseJsonList(resp.issuecodesjson);
                appendAssistantPrivacyNote(resp, 'ai_send_message');
                appendMessage('assistant', resp.displaymessage || resp.message, {
                    response_type: resp.response_type || '',
                    threadid: Number(resp.threadid || currentThreadId || 0),
                    runid: Number(resp.runid || 0),
                    commands_count: Array.isArray(cmds) ? cmds.length : 0,
                    llm_commands_json: String(resp.commands || ''),
                    attempted_tasks: attemptedTasks.join(', '),
                    issue_codes: issueCodes.join(', '),
                    pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
                    errors: errors.join(' || '),
                    source: 'ai_send_message',
                    time: (new Date()).toISOString(),
                });
                if (cmds.length > 0) {
                    if (shouldAutoExecuteReadOnly(cmds)) {
                        pendingCommands = cmds;
                        confirmRun();
                    } else {
                        showConfirmPanel(resp.message, cmds);
                    }
                }
            } catch (e) {
                appendAssistantPrivacyNote(resp, 'ai_send_message');
                appendMessage('assistant', resp.commands || '', {
                    response_type: resp.response_type || '',
                    threadid: Number(resp.threadid || currentThreadId || 0),
                    runid: Number(resp.runid || 0),
                    source: 'ai_send_message',
                    time: (new Date()).toISOString(),
                });
            }
        } else {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            appendMessage('assistant', resp.displaymessage || resp.message || '', {
                response_type: resp.response_type || '',
                threadid: Number(resp.threadid || currentThreadId || 0),
                runid: Number(resp.runid || 0),
                source: 'ai_send_message',
                time: (new Date()).toISOString(),
            });
        }
        return resp;
    });
    }).catch((err) => {
        if (thinking) {
            thinking.classList.add('d-none');
            thinking.textContent = defaultThinkingLabel;
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }
        Notification.exception(err);
    });
};

/**
 * Confirm and submit the pending commands for execution.
 */
const confirmRun = () => {
    if (!pendingCommands) {
        return;
    }

    const commandsToSend = pendingCommands;
    hideConfirmPanel();

    Ajax.call([{
        methodname: 'mod_booking_ai_confirm_run',
        args: {
            cmid:     currentCmid,
            threadid: currentThreadId,
            commands: JSON.stringify(commandsToSend),
        },
    }])[0].then((resp) => {
        if (resp.success) {
            showRunStatus('queued', resp.message);
            pollRunStatus(resp.runid, currentCmid);
        } else {
            showRunStatus('failed', resp.message);
        }
        return resp;
    }).catch((err) => {
        Notification.exception(err);
    });
};

/**
 * Bind one-click trial activation button.
 */
const bindTrialButton = () => {
    const trialBtn     = document.getElementById('booking-ai-trial-btn');
    const activateBtn  = document.getElementById('booking-ai-activate-btn');
    const activateWrap = document.getElementById('booking-ai-trial-activate-wrap');
    const trialSpinner = document.getElementById('booking-ai-trial-spinner');
    const trialResult  = document.getElementById('booking-ai-trial-result');
    const wrapper = document.getElementById('booking-ai-wrapper');
    const trialSuccessDefault = String((wrapper && wrapper.dataset.trialSuccessDefault) || '');
    const trialReloadingLabel = String((wrapper && wrapper.dataset.trialReloadingLabel) || '');
    const trialFailedDefault = String((wrapper && wrapper.dataset.trialFailedDefault) || '');
    const trialUnexpectedError = String((wrapper && wrapper.dataset.trialUnexpectedError) || '');
    const trialActivateSuccess = String((wrapper && wrapper.dataset.trialActivateSuccess) || trialSuccessDefault);

    if (trialBtn) {
        trialBtn.addEventListener('click', () => {
            trialBtn.disabled = true;
            if (trialSpinner) {
                trialSpinner.classList.remove('d-none');
            }
            if (trialResult) {
                trialResult.classList.add('d-none');
                trialResult.innerHTML = '';
            }

            Ajax.call([{
                methodname: 'mod_booking_request_trial_key',
                args: {cmid: Number(trialBtn.dataset.cmid || 0)},
            }])[0].then((resp) => {
                if (trialSpinner) {
                    trialSpinner.classList.add('d-none');
                }
                if (trialResult) {
                    trialResult.classList.remove('d-none');
                    if (resp && resp.success) {
                        trialResult.innerHTML =
                            '<div class="alert alert-success mb-0">'
                            + '<i class="fa fa-check-circle mr-2" aria-hidden="true"></i>'
                            + renderTextWithLinks(resp.message || trialSuccessDefault)
                            + '</div>';
                        if (activateWrap) {
                            activateWrap.classList.remove('d-none');
                        }
                        if (activateBtn) {
                            activateBtn.disabled = false;
                        }
                    } else {
                        trialResult.innerHTML =
                            '<div class="alert alert-danger mb-0">'
                            + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                            + renderTextWithLinks((resp && resp.message) || trialFailedDefault)
                            + '</div>';
                        trialBtn.disabled = false;
                    }
                }
                return resp;
            }).catch((err) => {
                if (trialSpinner) {
                    trialSpinner.classList.add('d-none');
                }
                if (trialResult) {
                    trialResult.classList.remove('d-none');
                    trialResult.innerHTML =
                        '<div class="alert alert-danger mb-0">'
                            + renderTextWithLinks(err.message || trialUnexpectedError)
                        + '</div>';
                }
                trialBtn.disabled = false;
                Notification.exception(err);
            });
        });
    }

    if (!activateBtn) {
        return;
    }

    activateBtn.addEventListener('click', () => {
        activateBtn.disabled = true;
        if (trialSpinner) {
            trialSpinner.classList.remove('d-none');
        }

        Ajax.call([{
            methodname: 'mod_booking_activate_trial_context',
            args: {cmid: Number((trialBtn && trialBtn.dataset.cmid) || (wrapper && wrapper.dataset.cmid) || 0)},
        }])[0].then((resp) => {
            if (trialSpinner) {
                trialSpinner.classList.add('d-none');
            }
            if (trialResult) {
                trialResult.classList.remove('d-none');
                if (resp && resp.success) {
                    trialResult.innerHTML =
                        '<div class="alert alert-success mb-0">'
                        + '<i class="fa fa-check-circle mr-2" aria-hidden="true"></i>'
                        + renderTextWithLinks(resp.message || trialActivateSuccess)
                        + ' <strong>' + escapeHtml(trialReloadingLabel) + '</strong></div>';
                    setTimeout(() => window.location.reload(), 1800);
                } else {
                    trialResult.innerHTML =
                        '<div class="alert alert-danger mb-0">'
                        + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                        + renderTextWithLinks((resp && resp.message) || trialFailedDefault)
                        + '</div>';
                    activateBtn.disabled = false;
                }
            }
            return resp;
        }).catch((err) => {
            if (trialSpinner) {
                trialSpinner.classList.add('d-none');
            }
            if (trialResult) {
                trialResult.classList.remove('d-none');
                trialResult.innerHTML =
                    '<div class="alert alert-danger mb-0">'
                    + renderTextWithLinks(err.message || trialUnexpectedError)
                    + '</div>';
            }
            activateBtn.disabled = false;
            Notification.exception(err);
        });
    });
};

/**
 * Display the welcome message based on booking statistics.
 *
 * @param {number} numOptions
 * @param {number} numBooked
 */
const displayWelcomeMessage = (numOptions, numBooked) => {
    const welcomeText = document.getElementById('booking-ai-welcome-text');
    if (!welcomeText) {
        return;
    }

    // The template already renders a server-side welcome text.
    // Only inject via JS when the container is still empty.
    if (String(welcomeText.textContent || '').trim() !== '') {
        return;
    }

    let message = '';
    if (numOptions === 0) {
        message = 'Welcome! Would you like me to help you create your first booking option?';
    } else {
        message = `Welcome! You have ${numOptions} booking options here, and ${numBooked} people are already booked. ` +
            'How can I help you?';
    }

    const p = document.createElement('p');
    p.textContent = message;
    welcomeText.appendChild(p);
};

/**
 * Initialise the AI instructions interface.
 *
 * @param {Object|null} config Template data from DOM or explicit config.
 */
export const init = (config = null) => {
    let runtimeConfig = config;

    if (!runtimeConfig) {
        const wrapper = document.getElementById('booking-ai-wrapper');
        if (!wrapper) {
            return;
        }

        runtimeConfig = {
            cmid: Number(wrapper.dataset.cmid || 0),
            threadid: Number(wrapper.dataset.threadid || 0),
            ready_for_chat: String(wrapper.dataset.readyForChat || '0') === '1',
            num_options: Number(wrapper.dataset.numOptions || 0),
            num_booked: Number(wrapper.dataset.numBooked || 0),
            debug_mode: String(wrapper.dataset.debugMode || '0') === '1',
            privacy_check_running: String(wrapper.dataset.privacyCheckRunning || 'Privacy check running...'),
            privacy_answer_note: String(
                wrapper.dataset.privacyAnswerNote
                || 'Privacy note: personal data in this response was de-anonymized for display.'
            ),
            trial_token_invalid_title: String(wrapper.dataset.aiTrialTokenInvalidTitle || ''),
            trial_token_invalid_message: String(wrapper.dataset.aiTrialTokenInvalidMessage || ''),
            trial_token_invalid_ok: String(wrapper.dataset.aiTrialTokenInvalidOk || ''),
        };
    }

    currentCmid     = runtimeConfig.cmid || 0;
    currentThreadId = runtimeConfig.threadid || 0;
    debugModeEnabled = Boolean(runtimeConfig.debug_mode);
    privacyCheckRunningLabel = String(runtimeConfig.privacy_check_running || privacyCheckRunningLabel);
    privacyAnswerNoteLabel = String(runtimeConfig.privacy_answer_note || privacyAnswerNoteLabel);
    trialTokenInvalidTitleLabel = String(runtimeConfig.trial_token_invalid_title || '');
    trialTokenInvalidMessageLabel = String(runtimeConfig.trial_token_invalid_message || '');
    trialTokenInvalidOkLabel = String(runtimeConfig.trial_token_invalid_ok || '');

    const thinking = document.getElementById('booking-ai-thinking');
    if (thinking) {
        defaultThinkingLabel = String(thinking.textContent || '').trim() || 'Thinking...';
    }

    // Must be available in onboarding mode where ready_for_chat is false.
    bindTrialButton();

    if (!runtimeConfig.ready_for_chat) {
        return;
    }

    initResizableLayout();
    initMobilePreviewSwitch();

    // Display welcome message based on booking statistics.
    displayWelcomeMessage(runtimeConfig.num_options || 0, runtimeConfig.num_booked || 0);

    const sendBtn    = document.getElementById('booking-ai-send');
    const inputEl    = document.getElementById('booking-ai-input');
    const confirmBtn = document.getElementById('booking-ai-btn-confirm');
    const cancelBtn  = document.getElementById('booking-ai-btn-cancel');

    if (sendBtn && inputEl) {
        sendBtn.addEventListener('click', () => {
            const msg = inputEl.value;
            inputEl.value = '';
            sendMessage(msg);
        });

        inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const msg = inputEl.value;
                inputEl.value = '';
                sendMessage(msg);
            }
        });
    }

    const messageList = document.getElementById('booking-ai-messages');
    if (messageList) {
        messageList.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest('.booking-ai-ambiguity-option');
            if (!(button instanceof HTMLElement)) {
                return;
            }

            const query = String(button.getAttribute('data-query') || '').trim();
            if (query !== '') {
                button.classList.remove('btn-outline-primary');
                button.classList.add('btn-primary');
                button.setAttribute('aria-disabled', 'true');
                sendMessage(query);
            }
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', confirmRun);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', hideConfirmPanel);
    }

};
