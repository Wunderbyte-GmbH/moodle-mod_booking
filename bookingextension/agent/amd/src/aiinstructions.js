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
 * @module     bookingextension_agent/aiinstructions
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import Fragment from 'core/fragment';
import {getString} from 'core/str';

/** Pending commands waiting for user confirmation. */
let pendingCommands = null;
// Structured anonymizer collision decision picked via chips (#2226); sent with the next precheck.
let pendingAnonWordDecision = null;
/** Original message that waits for the decision about a suspect word; null when none waits. */
let pendingAnonMessage = null;
/** True from sending a message until its turn has answered. */
let sendInFlight = false;
let pendingQueueItemId = '';
let currentThreadId = 0;
let currentContextId = 0;
let debugModeEnabled = false;
let sessionAutoConfirmEnabled = false;
let privacyCheckRunningLabel = 'Privacy check running...';
let privacyAnswerNoteLabel = 'Privacy note: personal data in this response was de-anonymized for display.';
let stepPlanningLabel = 'Planning...';
let stepExecutingLabel = 'Executing...';
let defaultThinkingLabel = '';
let forceNewThreadOnFirstMessage = true;
let trialTokenInvalidMessageLabel = '';
let bodyHandlersBound = false;
const previewRenderers = {};
/** @type {HTMLElement|null} */
let activePlanBubble = null;

/** Step-progress polling: interval handle, last-seen message id, active step bubble elements. */
let stepPollInterval = null;
let lastSeenStepId = 0;
/** @type {Array<HTMLElement>} */
let activeStepBubbles = [];
/** Set to true when the user clicks Stop to discard the pending LLM response. */
let sendAborted = false;

/** Attachments staged for the next sendMessage call. Each entry: {token, type, display_name}. */
let pendingAttachments = [];

/** @type {Array<string>} */
const TRIAL_TOKEN_ISSUE_CODES = [
    'TRIAL_TOKEN_INVALID',
    'TRIAL_TOKEN_EXPIRED',
    'SUBSCRIPTION_REQUIRED',
    'AI_PROVIDER_AUTH_FAILED',
    'AI_PROVIDER_QUOTA_EXCEEDED',
];


/** @type {Array<string>} */
const READ_ONLY_SKILLS = [
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
        const skill = String((cmd && cmd.skill) || '');
        return READ_ONLY_SKILLS.includes(skill);
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
        'results_count',
        'loop_step',
        'loop_max_steps',
        'llm_commands_json',
        'attempted_skills',
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
        + '<summary>LLM Skill JSON</summary>'
        + `<pre>${escapeHtml(pretty)}</pre>`
        + '</details>';
};

/**
 * Render collapsible LLM debug logs (source, request, response).
 *
 * @param {Array} debugLogs Array of debug log objects {id, timecreated, source, success, requesttext, responsetext}
 * @returns {string}
 */
const renderDebugLogs = (debugLogs) => {
    if (!debugModeEnabled || !Array.isArray(debugLogs) || debugLogs.length === 0) {
        return '';
    }

    const plainTextLogs = formatDebugLogsForClipboard(debugLogs);

    let html = '<details class="booking-ai-debug-logs">'
        + `<summary>LLM Debug Logs (${debugLogs.length})</summary>`
        + '<div class="p-3">';

    // Add copy button
    html += '<button type="button" '
        + 'class="btn btn-sm btn-outline-secondary mb-2 booking-ai-copy-debug-logs" '
        + 'title="Copy all debug logs to clipboard">'
        + '📋 Copy All Logs'
        + '</button>'
        + `<pre class="booking-ai-debug-logs-plain d-none">${escapeHtml(plainTextLogs)}</pre>`;

    debugLogs.forEach((entry, index) => {
        const source = String(entry.source || '').trim();
        const success = Number(entry.success) === 1 ? '✓' : '✗';
        const timestamp = entry.timecreated
            ? new Date(entry.timecreated * 1000).toLocaleTimeString()
            : 'N/A';
        const borderClass = Number(entry.success) === 1 ? 'border-success' : 'border-danger';

        html += `<div class="booking-ai-debug-log-entry mb-3 p-2 border rounded ${borderClass}">`;
        html += `<strong>Entry ${index + 1}</strong> [${success}] <code>${escapeHtml(source)}</code> `
            + `<small class="text-muted">${timestamp}</small>`;

        const requesttext = String(entry.requesttext || '').trim();
        if (requesttext) {
            html += '<details class="ml-3 mt-2">'
                + '<summary>Request</summary>'
                + `<pre class="bg-light p-2 small mb-0"><code>${escapeHtml(requesttext)}</code></pre>`
                + '</details>';
        }

        const responsetext = String(entry.responsetext || '').trim();
        if (responsetext) {
            html += '<details class="ml-3 mt-2">'
                + '<summary>Response</summary>'
                + `<pre class="bg-light p-2 small mb-0"><code>${escapeHtml(responsetext)}</code></pre>`
                + '</details>';
        }

        html += '</div>';
    });

    html += '</div></details>';
    return html;
};

/**
 * Format debug entries into a plain-text export.
 *
 * @param {Array} debugLogs
 * @returns {string}
 */
const formatDebugLogsForClipboard = (debugLogs) => {
    if (!Array.isArray(debugLogs) || debugLogs.length === 0) {
        return '';
    }

    let text = 'LLM DEBUG LOGS\n';
    text += '='.repeat(60) + '\n\n';

    debugLogs.forEach((entry, index) => {
        text += `Entry ${index + 1}:\n`;
        text += `  Source: ${String(entry.source || '')}\n`;
        text += `  Success: ${Number(entry.success) === 1 ? 'Yes' : 'No'}\n`;
        const timestamp = entry.timecreated ? new Date(entry.timecreated * 1000).toISOString() : 'N/A';
        text += `  Time: ${timestamp}\n`;

        const requestText = String(entry.requesttext || '').trim();
        if (requestText !== '') {
            text += `  Request:\n${requestText}\n`;
        }

        const responseText = String(entry.responsetext || '').trim();
        if (responseText !== '') {
            text += `  Response:\n${responseText}\n`;
        }

        const errorMessage = String(entry.errormessage || '').trim();
        if (errorMessage !== '') {
            text += `  Error: ${errorMessage}\n`;
        }

        text += '\n' + '-'.repeat(60) + '\n\n';
    });

    return text;
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
 * Parse a command payload that may be a JSON array or a single command object.
 *
 * @param {string} raw
 * @returns {Array}
 */
const parseCommandPayload = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        if (Array.isArray(parsed)) {
            return parsed;
        }
        if (parsed && typeof parsed === 'object' && parsed.skill) {
            return [parsed];
        }
        return [];
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
 * Show a chat bubble when the trial token is no longer valid.
 * Displayed on every message while the token remains invalid.
 *
 * @param {Object|null} response
 * @param {Array<string>} errors
 * @param {Array<string>} issueCodes
 */
const maybeShowTrialTokenInvalidAlert = (response, errors = [], issueCodes = []) => {
    if (!isTrialTokenInvalidError(response, errors, issueCodes)) {
        return;
    }

    const messageText = String(
        (response && (response.displaymessage || response.message))
        || trialTokenInvalidMessageLabel
        || ''
    ).trim();

    if (messageText === '') {
        return;
    }

    appendMessageHtml('assistant', renderAssistantMessageHtml(messageText));
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
 * Render clickable follow-up suggestions below a completed assistant response.
 *
 * @param {Array<Object>} results
 * @returns {string}
 */
const renderFollowUpSuggestionsHtml = (results = []) => {
    const entries = Array.isArray(results) ? results : [];
    const resultWithSuggestions = entries.find(
        (entry) => Array.isArray(entry && entry.suggestions) && entry.suggestions.length > 0
    );
    if (!resultWithSuggestions) {
        return '';
    }

    const suggestions = Array.isArray(resultWithSuggestions.suggestions) ? resultWithSuggestions.suggestions : [];
    const buttons = suggestions.map((entry) => {
        const query = String((entry && entry.query) || '').trim();
        const label = String((entry && entry.label) || query).trim();
        if (query === '' || label === '') {
            return '';
        }

        return '<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-2 booking-ai-followup-option"'
            + ` data-query="${escapeHtml(query)}"`
            + ` title="${escapeHtml(query)}">${escapeHtml(label)}</button>`;
    }).filter((button) => button !== '').join('');

    if (buttons === '') {
        return '';
    }

    const followUpMessage = String((resultWithSuggestions && resultWithSuggestions.followupmessage) || '').trim();
    const introHtml = followUpMessage !== ''
        ? `<div class="font-weight-bold mb-2">${escapeHtml(followUpMessage)}</div>`
        : '';

    return '<div class="booking-ai-followup-options mt-3 p-3 border rounded bg-light">'
        + introHtml
        + `<div class="d-flex flex-wrap">${buttons}</div>`
        + '</div>';
};

/**
 * Append a chat bubble to the message list.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} content   Message text.
 * @param {Object|null} meta Compact debug metadata.
 * @returns {HTMLElement|null}
 */
const appendMessage = (role, content, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return null;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    const bubbleContent = role === 'assistant'
        ? renderAssistantMessageHtml(content)
        : escapeHtml(content);
    div.innerHTML = `<div class="bubble">${bubbleContent}</div>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    return div;
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
 * @returns {HTMLElement|null}
 */
const appendMessageHtml = (role, html, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return null;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<div class="bubble">${String(html || '')}</div>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    return div;
};

// Opens the initially collapsed side preview pane. Rebound by initResizableLayout();
// the default is a no-op so preview writes stay safe before layout init.
let expandPreviewPane = () => {
    // No-op until the resizable layout is initialized.
};

/**
 * Replace content of the dedicated side preview panel.
 *
 * When the preview carries render-time JS (collected server-side via get_end_code — e.g. question
 * rendering or a Mustache {{#js}} block), it is injected AND run through core/templates
 * replaceNodeContents, the standard Moodle way to ship render-time JS into the page. Without JS we
 * keep the cheap innerHTML path.
 *
 * @param {string} html Trusted HTML.
 * @param {string} [js] Trusted render-time JS to execute after injection.
 * @return {Promise<void>}
 */
const setSidePreviewHtml = async (html, js) => {
    const preview = document.getElementById('booking-ai-side-preview');
    if (!preview) {
        return;
    }
    // Click-to-embed for file hits (#2350): one delegated handler, attached once.
    if (!preview.dataset.embedHandler) {
        preview.dataset.embedHandler = '1';
        preview.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-embed-url]');
            if (!btn) {
                return;
            }
            ev.preventDefault();
            const url = String(btn.getAttribute('data-embed-url') || '');
            const title = String(btn.getAttribute('data-embed-title') || '');
            preview.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-1">'
                + '<strong class="small">' + escapeHtml(title) + '</strong>'
                + '<a href="' + escapeHtml(url) + '" target="_blank" class="small">'
                + escapeHtml(url.replace(/^https?:\/\/[^/]+/, '')) + '</a></div>'
                + '<iframe src="' + escapeHtml(url) + '#toolbar=0&navpanes=0&view=FitH"'
                + ' style="width:100%;height:60vh;border:1px solid #dee2e6;"></iframe>';
        });
    }
    if (String(html || '').trim() !== '') {
        expandPreviewPane();
    }
    const jsSource = String(js || '').trim();
    if (jsSource === '') {
        preview.innerHTML = String(html || '');
        return;
    }
    try {
        // The collected JS is page-footer markup (<script> blocks). Fragment.processCollectedJavascript
        // unwraps it into the raw JS replaceNodeContents expects, and skips already-loaded src scripts —
        // exactly the path Moodle's own fragments use.
        const rawJs = Fragment.processCollectedJavascript(jsSource);
        await Templates.replaceNodeContents(preview, String(html || ''), rawJs);
    } catch (e) {
        // Fall back to plain HTML if template JS execution fails for any reason.
        preview.innerHTML = String(html || '');
    }
};

export const registerPreviewRenderer = (type, renderFn) => {
    previewRenderers[type] = renderFn;
};

// Register core client-side renderers.
// Skill-result previews now arrive as ready-to-insert server HTML (previewDescriptor.html),
// so the only client-side renderer that remains is the pre-execution proposed-command list.
registerPreviewRenderer('command_list', async (payload) => {
    const commands = Array.isArray(payload.commands) ? payload.commands : [];
    if (commands.length === 0) {
        return '';
    }

    let items = commands.map((cmd) => {
        const skill = escapeHtml(cmd.skill || '');
        const inputDesc = escapeHtml(JSON.stringify(cmd.input || {}));
        return `<li><strong>Skill:</strong> ${skill}<br/><small class="text-muted">${inputDesc}</small></li>`;
    }).join('');

    return '<div class="booking-ai-run-status-inline card mb-0">'
        + '<div class="card-body p-3">'
        + '<h6 class="mb-2">Proposed Actions</h6>'
        + `<ul class="mb-0 pl-3">${items}</ul>`
        + '</div></div>';
});

// Anonymizer collision clarification (#2226): two decision chips for the suspect word.
// The chosen decision travels as a STRUCTURED ai_privacy_precheck parameter (never parsed
// from reply text) and is recorded server-side via record_anon_word_decision().
registerPreviewRenderer('anon_word_decision', async(payload) => {
    const word = String((payload && payload.word) || '').trim();
    if (word === '') {
        return '';
    }
    const [personLabel, wordLabel] = await Promise.all([
        getString('agent_anon_chip_person', 'bookingextension_agent', word),
        getString('agent_anon_chip_word', 'bookingextension_agent', word),
    ]);
    const mkChip = (decision, label) =>
        '<button type="button" class="btn btn-outline-primary btn-sm mr-2 mb-2 booking-ai-anonword-option" '
        + `data-word="${escapeHtml(word)}" data-decision="${decision}" `
        + `data-query="${escapeHtml(label)}">${escapeHtml(label)}</button>`;
    return '<div class="booking-ai-anonword-choice">'
        + mkChip('person', personLabel)
        + mkChip('word', wordLabel)
        + '</div>';
});

// Human-readable, skill-agnostic preview of the PROPOSED commands shown before confirmation.
// The server (proposed_action_preview) asks each skill to describe its own input as plain data
// (title/summary/label-value rows); this renderer only lays that data out. It supersedes the raw
// command_list dump whenever the server provides a descriptor.
registerPreviewRenderer('proposed_action', async (payload) => {
    const actions = Array.isArray(payload && payload.actions) ? payload.actions : [];
    if (actions.length === 0) {
        return '';
    }

    const cards = actions.map((action) => {
        const title = escapeHtml(action.title || '');
        const summary = escapeHtml(action.summary || '');
        const rows = Array.isArray(action.rows) ? action.rows : [];
        const rowsHtml = rows.map((row) =>
            '<tr>'
            + `<th class="font-weight-normal text-muted pr-3 align-top">${escapeHtml(row.label || '')}</th>`
            + `<td>${escapeHtml(row.value || '')}</td>`
            + '</tr>'
        ).join('');

        return '<div class="booking-ai-proposed-action card mb-2"><div class="card-body p-3">'
            + (title ? `<h6 class="mb-2">${title}</h6>` : '')
            + (summary ? `<p class="text-muted small mb-2">${summary}</p>` : '')
            + (rowsHtml ? `<table class="table table-sm mb-0"><tbody>${rowsHtml}</tbody></table>` : '')
            + '</div></div>';
    }).join('');

    return cards;
});

export const dispatchSkillPreview = async (previewDescriptor, contextid) => {
    const type = String((previewDescriptor && previewDescriptor.type) || '').trim();
    if (!type) {
        return;
    }

    // 1. Direct server-rendered HTML produced by the skill (the common case), optionally with
    //    render-time JS the client must execute (questions, Mustache {{#js}}).
    if (previewDescriptor.html !== undefined && previewDescriptor.html !== null) {
        await setSidePreviewHtml(previewDescriptor.html, previewDescriptor.js);
        return;
    }

    // 2. Client-side handler registered for this type (e.g. the pre-execution command_list).
    const renderer = previewRenderers[type];
    if (renderer) {
        try {
            const html = await renderer(previewDescriptor.payload, contextid);
            if (html) {
                setSidePreviewHtml(html);
            }
        } catch (e) {
            setSidePreviewHtml(`<div class="text-danger small">Error: ${escapeHtml(e.message)}</div>`);
        }
        return;
    }

    // 3. Skill-provided AMD module (carried per descriptor): lazy-load it and let it render.
    const jsModule = String((previewDescriptor && previewDescriptor.js_module) || '').trim();
    if (!jsModule) {
        return;
    }

    const mod = await new Promise((resolve) => {
        require([jsModule], (loaded) => resolve(loaded), () => resolve(null));
    });
    const renderFn = (mod && typeof mod.render === 'function') ? mod.render : previewRenderers[type];
    if (typeof renderFn !== 'function') {
        return;
    }
    try {
        const html = await renderFn(previewDescriptor.payload, contextid);
        if (html) {
            setSidePreviewHtml(html);
        }
    } catch (e) {
        // Ignore preview render errors.
    }
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
    const storageKey = 'bookingextension_agent_ai_preview_width';

    const applyColumns = (previewPercent) => {
        const safePreview = Math.min(90, Math.max(20, Number(previewPercent || 42)));
        const mainPercent = 100 - safePreview;
        layout.style.gridTemplateColumns = `minmax(0, ${mainPercent}%) 10px minmax(0, ${safePreview}%)`;
        splitter.setAttribute('aria-valuenow', String(Math.round(safePreview)));
    };

    // Keep the collapsed track list structurally identical to applyColumns() output
    // (minmax/…/minmax) so the CSS grid-template-columns transition can interpolate.
    const applyCollapsedColumns = () => {
        layout.style.gridTemplateColumns = 'minmax(0, 100%) 10px minmax(0, 0%)';
        splitter.setAttribute('aria-valuenow', '0');
    };

    const restoreOrDefault = () => {
        if (!desktopMedia.matches) {
            layout.style.gridTemplateColumns = '';
            return;
        }
        if (layout.classList.contains('preview-collapsed')) {
            applyCollapsedColumns();
            return;
        }
        const stored = Number(window.localStorage.getItem(storageKey) || 42);
        applyColumns(stored);
    };

    expandPreviewPane = () => {
        if (!layout.classList.contains('preview-collapsed')) {
            return;
        }
        layout.classList.remove('preview-collapsed');
        restoreOrDefault();
    };

    restoreOrDefault();

    let dragging = false;

    // Below this width the drag snaps to the fully collapsed state: the pane must be
    // completely closable, not stuck at the resize minimum of applyColumns().
    const collapseSnapPercent = 10;

    const onPointerMove = (clientX) => {
        if (!dragging || !desktopMedia.matches) {
            return;
        }
        const rect = layout.getBoundingClientRect();
        if (rect.width <= 0) {
            return;
        }
        const previewPercent = ((rect.right - clientX) / rect.width) * 100;
        if (previewPercent < collapseSnapPercent) {
            layout.classList.add('preview-collapsed');
            applyCollapsedColumns();
            return;
        }
        layout.classList.remove('preview-collapsed');
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
        // Manual drag opens a still-collapsed pane (transition is suppressed while resizing).
        layout.classList.remove('preview-collapsed');
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
 * Safely update the thinking label text without destroying HTML structure.
 *
 * @param {string} label
 */
const updateThinkingLabel = (label) => {
    const labelEl = document.getElementById('booking-ai-thinking-label');
    if (labelEl) {
        labelEl.textContent = String(label || '');
    }
};

/**
 * Copy a text string to clipboard with fallback for older browsers.
 *
 * @param {string} text
 * @returns {Promise<void>}
 */
const copyTextToClipboard = (text) => {
    const value = String(text || '');
    if (value.trim() === '') {
        return Promise.reject(new Error('Empty clipboard payload.'));
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(value);
    }

    return new Promise((resolve, reject) => {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            const copied = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (copied) {
                resolve();
            } else {
                reject(new Error('Copy command failed.'));
            }
        } catch (e) {
            document.body.removeChild(textarea);
            reject(e);
        }
    });
};

/**
 * Show temporary visual feedback on a button.
 *
 * @param {HTMLElement} button
 * @param {string} label
 * @param {string} cssClass
 */
const showButtonFeedback = (button, label, cssClass) => {
    if (!(button instanceof HTMLElement)) {
        return;
    }

    const originalLabel = String(button.dataset.originalLabel || button.textContent || '');
    if (!button.dataset.originalLabel) {
        button.dataset.originalLabel = originalLabel;
    }

    button.textContent = label;
    button.classList.add(cssClass);

    window.setTimeout(() => {
        button.textContent = button.dataset.originalLabel || originalLabel;
        button.classList.remove('btn-success', 'btn-danger', 'btn-outline-secondary');
        button.classList.add('btn-outline-secondary');
    }, 1500);
};

/**
 * Extract docs preview metadata from a link href.
 *
 * Only the mod_booking docs web route is recognised here, mapping to the "mod_booking" corpus.
 * Other corpora have no public route, so their previews are server-rendered and reached via
 * data-corpusid attributes rather than parsed from an href.
 *
 * @param {string} href
 * @returns {{docpath: string, corpusid: string, fragment: string}}
 */
const getDocLinkMeta = (href) => {
    const raw = String(href || '').trim();
    if (raw === '') {
        return {docpath: '', corpusid: '', fragment: ''};
    }

    let normalized = raw;
    const absoluteMatch = normalized.match(/^https?:\/\/[^/]+(\/.*)$/i);
    if (absoluteMatch) {
        normalized = String(absoluteMatch[1] || '');
    }

    const hashIndex = normalized.indexOf('#');
    const fragment = hashIndex >= 0 ? normalized.slice(hashIndex + 1).trim() : '';
    const withoutHash = hashIndex >= 0 ? normalized.slice(0, hashIndex) : normalized;
    const queryIndex = withoutHash.indexOf('?');
    const withoutQuery = queryIndex >= 0 ? withoutHash.slice(0, queryIndex) : withoutHash;

    const match = withoutQuery.match(/^\/(?:public\/)?mod\/booking\/docs\/(.+)$/i);
    if (match) {
        return {docpath: match[1].trim(), corpusid: 'mod_booking', fragment: fragment};
    }

    return {docpath: '', corpusid: '', fragment: ''};
};

/**
 * Render one hyperlink with the correct behavior attributes.
 *
 * @param {string} href
 * @param {string} label
 * @returns {string}
 */
const renderSmartLink = (href, label) => {
    const safeHref = escapeHtml(href);
    const safeLabel = escapeHtml(label);

    // The Get-Pro store link renders as the same pill used in the chat header, so an
    // upgrade hint composed by the synchronizer looks identical to the header CTA.
    if (href.indexOf('showroom.wunderbyte.at/course/view.php?id=62') !== -1) {
        return `<a class="badge badge-warning text-dark booking-ai-getpro-pill" href="${safeHref}"`
            + ` target="_blank" rel="noopener noreferrer">`
            + `<i class="fa fa-star mr-1" aria-hidden="true"></i>${safeLabel}</a>`;
    }

    const meta = getDocLinkMeta(href);

    if (meta.docpath !== '' && meta.corpusid !== '') {
        const dataDocPath = escapeHtml(meta.docpath);
        const dataCorpusId = escapeHtml(meta.corpusid);
        const dataDocFragment = escapeHtml(meta.fragment);
        return `<a href="${safeHref}" class="booking-doc-link"`
            + ` data-docpath="${dataDocPath}" data-corpusid="${dataCorpusId}"`
            + ` data-docfragment="${dataDocFragment}">`
            + `${safeLabel}</a>`;
    }

    return `<a href="${safeHref}" target="_blank" rel="noopener noreferrer">${safeLabel}</a>`;
};

/**
 * Escape text and convert URLs/newlines for rich status rendering.
 *
 * @param {string} text
 * @returns {string}
 */
const renderTextWithLinks = (text) => {
    const input = String(text || '');

    // Combined regex: markdown links [label](url) first, then bare URLs.
    // Supports absolute http(s) and Moodle-relative paths like /mod/booking/....
    // Markdown pattern must come first so bare-URL branch never fires inside [...](...)
    const combinedRegex = new RegExp(
        '\\[([^\\]]+)\\]\\(((?:https?:\\/\\/|\\/(?:mod|admin|course|local)\\/)[^)]+)\\)'
        + '|((?:https?:\\/\\/|\\/(?:mod|admin|course|local)\\/)[^\\s)`"\'<]+)',
        'g'
    );

    let html = '';
    let lastIndex = 0;
    let match;

    while ((match = combinedRegex.exec(input)) !== null) {
        html += escapeHtml(input.slice(lastIndex, match.index));

        if (match[1] !== undefined) {
            // Markdown link: [label](url)
            const label = match[1];
            const url = match[2];
            html += renderSmartLink(url, label);
        } else {
            // Bare URL or Moodle-relative path.
            const url = match[3];
            html += renderSmartLink(url, url);
        }

        lastIndex = match.index + match[0].length;
    }

    html += escapeHtml(input.slice(lastIndex));
    return html.replace(/\n/g, '<br>');
};

/**
 * Render assistant content as HTML.
 *
 * If the backend already returns HTML, keep it as-is. Otherwise render a
 * plain-text fallback with escaped text, links and line breaks.
 *
 * @param {string} content
 * @returns {string}
 */
const renderAssistantMessageHtml = (content) => {
    const raw = String(content || '').trim();
    if (raw === '') {
        return '';
    }

    if (/<\/?[a-z][\s\S]*>/i.test(raw)) {
        return linkifyBareUrlsInHtml(raw);
    }

    return renderTextWithLinks(raw);
};

/**
 * Linkify bare URLs in the TEXT segments of an HTML string (#2350).
 *
 * The model sometimes emits HTML with a naked URL in it; without this pass that URL stays
 * dead text. Tags are left untouched and segments inside <a>…</a> are skipped.
 *
 * @param {string} html
 * @returns {string}
 */
const linkifyBareUrlsInHtml = (html) => {
    const urlRegex = /(?:https?:\/\/|\/(?:mod|admin|course|local)\/)[^\s)`"'<]+/g;
    let anchordepth = 0;
    return String(html || '').split(/(<[^>]*>)/).map((part) => {
        if (part.startsWith('<')) {
            if (/^<a[\s>]/i.test(part)) {
                anchordepth++;
            } else if (/^<\/a>/i.test(part)) {
                anchordepth = Math.max(0, anchordepth - 1);
            }
            return part;
        }
        if (anchordepth > 0) {
            return part;
        }
        return part.replace(urlRegex, (url) => renderSmartLink(url, url));
    }).join('');
};

/**
 * Extract the first doc URL from structured skill results (docs array).
 *
 * @param {Array} results
 * @returns {string}
 */
/**
 * Extract the first doc entry (path + url) from explain_docs_topic results.
 *
 * @param {Array} results
 * @returns {{path:string, url:string}} — both may be empty strings when no doc is found.
 */


/**
 * Extract the first absolute URL from text.
 *
 * @param {string} text
 * @returns {string}
 */
const extractFirstUrl = (text) => {
    const input = String(text || '');
    // Prefer markdown link URL, fall back to bare URL.
    const mdMatch = input.match(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/);
    if (mdMatch) {
        return String(mdMatch[2]);
    }
    const bareMatch = input.match(/https?:\/\/[^\s)]+/);
    return bareMatch ? String(bareMatch[0]) : '';
};

/**
 * Load a URL into the side preview pane using an iframe.
 *
 * @param {string} url
 */
const loadUrlInSidePreview = (url) => {
    const safeUrl = String(url || '').trim();
    if (safeUrl === '') {
        return;
    }

    setSidePreviewHtml(
        '<iframe class="booking-ai-side-preview-frame"'
        + ` src="${escapeHtml(safeUrl)}"`
        + ' loading="lazy" referrerpolicy="no-referrer"'
        + ' style="width:100%;min-height:420px;border:0;" title="Documentation preview"></iframe>'
    );
};

/**
 * Escape a string for use in querySelector id selector.
 *
 * @param {string} value
 * @returns {string}
 */
const escapeCssIdentifier = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(String(value || ''));
    }
    return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '\\$&');
};

/**
 * Scroll the side preview to a fragment id after doc content is rendered.
 *
 * @param {string} fragment
 */
const scrollPreviewToFragment = (fragment) => {
    const raw = String(fragment || '').trim().replace(/^#/, '');
    if (raw === '') {
        return;
    }

    const preview = document.getElementById('booking-ai-side-preview');
    if (!preview) {
        return;
    }

    const decoded = (() => {
        try {
            return decodeURIComponent(raw);
        } catch (e) {
            return raw;
        }
    })();

    const candidates = [raw, decoded, `doc-${raw}`, `doc-${decoded}`];
    const uniqueCandidates = [...new Set(candidates.map((id) => String(id || '').trim()).filter((id) => id !== ''))];

    for (const candidate of uniqueCandidates) {
        const selector = `#${escapeCssIdentifier(candidate)}`;
        const target = preview.querySelector(selector);
        if (target instanceof HTMLElement) {
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
            return;
        }
    }
};

/**
 * Load a booking/docs markdown file into the side preview via the webservice renderer.
 *
 * Falls back to loadUrlInSidePreview() when the webservice call fails.
 *
 * @param {string} docpath  Relative path inside the corpus, e.g. "booking_rules/README.md".
 * @param {string} corpusid Documentation corpus id, e.g. "mod_booking".
 * @param {string} fallbackUrl  Optional bare URL to use as iframe fallback.
 * @param {string} fragment Optional fragment id (without #) to scroll to after load.
 */
const loadDocInPreview = (docpath, corpusid, fallbackUrl = '', fragment = '') => {
    const safePath = String(docpath || '').trim();
    const safeCorpus = String(corpusid || '').trim();
    if (safePath === '' || safeCorpus === '') {
        if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        }
        return;
    }

    setSidePreviewHtml('<div class="booking-doc-loading p-3 text-muted">Loading documentation…</div>');

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_get_doc_content',
        args: {contextid: currentContextId, corpus_id: safeCorpus, path: safePath},
    }])[0].then((resp) => {
        if (resp && resp.success && String(resp.html || '').trim() !== '') {
            const title = String(resp.title || '').trim();
            const titleHtml = title !== ''
                ? `<h2 class="booking-doc-preview-title">${escapeHtml(title)}</h2>`
                : '';
            setSidePreviewHtml(
                '<div class="booking-doc-preview p-3">'
                + titleHtml
                + String(resp.html)
                + '</div>'
            );
            if (String(fragment || '').trim() !== '') {
                window.requestAnimationFrame(() => scrollPreviewToFragment(fragment));
            }
        } else if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        } else {
            setSidePreviewHtml('<div class="p-3 text-muted small">No content available.</div>');
        }
        return resp;
    }).catch(() => {
        if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        }
    });
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
 * Build a skill-authored debug bubble for developer mode.
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

    // No skill-authored debug payload available.
    // Avoid duplicating the already rendered assistant message in debug mode.
    return '';
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
    appendMessageHtml('assistant', renderAssistantMessageHtml(text));

    const firstUrl = extractFirstUrl(text);
    if (firstUrl !== '') {
        loadUrlInSidePreview(firstUrl);
    }
};

/**
 * Handle an agent response by either auto-confirming, showing the panel, or
 * rendering the final assistant output.
 *
 * @param {Object} resp
 * @param {string} source
 * @param {Object} extra
 * @returns {void}
 */
const buildAgentResponseMeta = (resp, source, extra = {}) => ({
    response_type: String(resp.response_type || ''),
    threadid: Number(resp.threadid || currentThreadId || 0),
    runid: Number(resp.runid || 0),
    source,
    time: (new Date()).toISOString(),
    ...extra,
});

const handleFinalAgentResponse = (resp, source, responseType, messageText) => {
    clearActivePlanBubble();

    let results = [];
    try {
        results = JSON.parse(resp.resultsjson || '[]');
    } catch (e) {
        // Keep empty results on parse errors.
    }

    if (responseType === 'execution_result') {
        const runStatus = responseType === 'error' ? 'failed' : 'completed';
        showRunStatus(runStatus, messageText || responseType, results);
        return;
    }

    appendMessage('assistant', renderAssistantMessageHtml(messageText), buildAgentResponseMeta(resp, source, {
        response_type: responseType,
        issue_codes: parseJsonList(resp.issuecodesjson).join(', '),
        errors: parseJsonList(resp.errorsjson).join(' || '),
    }));
};

const handleAgentCommandResponse = (resp, source, responseType, cmds, messageText) => {
    const errors = parseJsonList(resp.errorsjson);
    const issueCodes = parseJsonList(resp.issuecodesjson);
    const planBubble = appendMessage('assistant', messageText, buildAgentResponseMeta(resp, source, {
        commands_count: Array.isArray(cmds) ? cmds.length : 0,
        llm_commands_json: String(resp.commands || ''),
        issue_codes: issueCodes.join(', '),
        errors: errors.join(' || '),
    }));

    if (cmds.length === 0) {
        return;
    }

    activePlanBubble = planBubble;

    const autoconfirm = Number(resp.autoconfirm || 0) === 1;
    if (autoconfirm) {
        sessionAutoConfirmEnabled = true;
    }

    if (responseType === 'confirmation_request' && (autoconfirm || sessionAutoConfirmEnabled)) {
        pendingCommands = cmds;
        confirmRun(true);
        return;
    }

    if (responseType === 'skill_call' && shouldAutoExecuteReadOnly(cmds)) {
        pendingCommands = cmds;
        confirmRun(sessionAutoConfirmEnabled);
        return;
    }

    showConfirmPanel(messageText, cmds, resp);
};

const handleConfirmationResponse = (resp, source = 'ai_send_message') => {
    const responseType = String(resp.response_type || '');
    const cmds = parseCommandPayload(resp.commands || '[]');
    const messageText = String(resp.displaymessage || resp.message || '').trim();
    pendingQueueItemId = String(resp.queueitemid || '').trim();

    appendAssistantPrivacyNote(resp, source);

    if (
        responseType === 'execution_result'
        || responseType === 'sufficient'
        || responseType === 'clarification'
        || responseType === 'error'
    ) {
        pendingQueueItemId = '';
        handleFinalAgentResponse(resp, source, responseType, messageText);
        return;
    }

    handleAgentCommandResponse(resp, source, responseType, cmds, messageText);
};

/**
 * Show the confirmation panel with a preview of the proposed commands.
 *
 * @param {string} message   AI summary message.
 * @param {Array}  commands  Validated command objects.
 * @param {Object} [resp]    Raw WS response; when it carries a server-rendered previewjson the
 *                           side preview was already dispatched, so we skip the raw command dump.
 */
const showConfirmPanel = (message, commands, resp = null) => {
    pendingCommands = commands;
    if (pendingQueueItemId === '' && Array.isArray(commands) && commands.length > 0) {
        pendingQueueItemId = String((commands[0] && commands[0].queue_item_id) || '').trim();
    }

    const panel = document.getElementById('booking-ai-confirm-panel');
    const preview = document.getElementById('booking-ai-confirm-preview');
    if (!panel || !preview) {
        return;
    }

    const messageHtml = renderAssistantMessageHtml(String(message || '').trim());
    preview.innerHTML = messageHtml !== ''
        ? `<div class="booking-ai-confirm-message mb-2">${messageHtml}</div>`
        : '';

    // Pre-execution preview. Prefer the server-rendered, skill-described proposed_action descriptor
    // (already dispatched from previewjson by the response handler); only fall back to the raw
    // client-side command dump when the server provided no descriptor.
    const hasServerPreview = Boolean(
        resp && typeof resp.previewjson === 'string' && resp.previewjson.trim() !== ''
    );
    if (!hasServerPreview) {
        dispatchSkillPreview({type: 'command_list', payload: {commands}}, currentContextId);
    }

    panel.classList.remove('d-none');
};

/**
 * Render booking option previews in the side preview panel.
 *
 * @param {number} contextid
 * @param {Array<number>} optionIds
 * @returns {Promise<void>}
 */


/**
 * Build skill-authored side preview HTML when provided by results.
 *
 * @param {Array} results
 * @returns {string}
 */


/**
 * Hide the confirmation panel.
 *
 * @param {boolean} clearPendingState Whether pending confirmation state should be reset.
 */
const hideConfirmPanel = (clearPendingState = true) => {
    if (clearPendingState) {
        pendingCommands = null;
        pendingQueueItemId = '';
    }
    const panel = document.getElementById('booking-ai-confirm-panel');
    if (panel) {
        panel.classList.add('d-none');
    }
};

/**
 * Remove the assistant bubble that announced the command plan once execution finishes.
 */
const clearActivePlanBubble = () => {
    if (!activePlanBubble) {
        return;
    }
    if (activePlanBubble.parentNode) {
        activePlanBubble.parentNode.removeChild(activePlanBubble);
    }
    activePlanBubble = null;
};

/**
 * Show a run status message.
 *
 * @param {string} status  'queued' | 'running' | 'completed' | 'failed'
 * @param {string} message Optional detail.
 * @param {Array} results Optional structured per-command results.
 */
const showRunStatus = (status, message, results = []) => {
    // Notify the page that AI has finished so other components (e.g. booking list) can reload.
    if (status === 'completed') {
        document.dispatchEvent(new CustomEvent('bookingextension_agent_ai_run_completed', {bubbles: true}));
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

    const followUpHtml = renderFollowUpSuggestionsHtml(results);
    if (followUpHtml && (status === 'completed' || status === 'failed')) {
        appendMessageHtml('assistant', followUpHtml, {
            response_type: 'execution_followup',
            status: String(status || ''),
            source: 'showRunStatus_followup',
            time: (new Date()).toISOString(),
        });
    }



    if (friendlyMessage && (status === 'completed' || status === 'failed')) {
        setSidePreviewHtml(
            `<div class="booking-ai-run-status-inline alert alert-light mb-0">`
            + `${renderTextWithLinks(friendlyMessage)}</div>`
        );
        return;
    }

    // Errors/failures are rendered in the same neutral style as any other message —
    // never red. A failed run reads as a normal, calm notice, not an alarm.
    const alertClass = (status === 'completed') ? 'alert-success'
                     : (status === 'failed')    ? 'alert-light'
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
                    const label = escapeHtml(String(action.label || action.skill || '-'));
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


/**
 * Append an ephemeral step-progress bubble to the message list.
 *
 * These bubbles are shown during the agentic loop and removed (with a short
 * fade transition) as soon as the final answer arrives.
 *
 * @param {string} label  Human-readable step label (e.g. "Step 1: booking.search_options").
 * @param {number} msgId  DB id of the step message — used to deduplicate.
 */
const appendStepBubble = (label, msgId) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }

    // Mark previous steps as done by changing the spinner to a checkmark.
    activeStepBubbles.forEach((el) => {
        const spinner = el.querySelector('.booking-ai-step-spinner');
        if (spinner) {
            spinner.className = 'booking-ai-step-done';
            spinner.innerHTML = '✓';
            spinner.removeAttribute('aria-hidden');
        }
    });

    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', 'booking-ai-msg-step');
    div.dataset.stepMsgId = String(msgId);
    const formattedLabel = renderTextWithLinks(String(label || ''));
    div.innerHTML = '<span class="booking-ai-step-spinner" aria-hidden="true"></span>'
        + `<span class="booking-ai-step-label">${formattedLabel}</span>`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    activeStepBubbles.push(div);
};

/**
 * Fade out and remove all active step bubbles.
 */
const clearStepBubbles = () => {
    activeStepBubbles.forEach((el) => {
        el.classList.add('booking-ai-step-fade');
        setTimeout(() => {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 350);
    });
    activeStepBubbles = [];
};

/**
 * Start polling the thread for new step-progress messages.
 *
 * Runs every 1.5 s and shows each new step as an ephemeral bubble.
 * Call stopStepPolling() to cancel.
 *
 * @param {number} threadid
 * @param {number} contextid
 */
const startStepPolling = (threadid, contextid) => {
    stopStepPolling();
    lastSeenStepId = 0;
    stepPollInterval = setInterval(() => {
        Ajax.call([{
            methodname: 'bookingextension_agent_ai_poll_thread',
            args: {contextid, threadid, lastseenid: lastSeenStepId},
        }])[0].then((resp) => {
            if (stepPollInterval === null) {
                // Polling was stopped while this request was in-flight. Discard results.
                return resp;
            }
            const messages = Array.isArray(resp.messages) ? resp.messages : [];
            messages.forEach((msg) => {
                if (String(msg.role || '') !== 'step') {
                    return;
                }
                const msgId = Number(msg.id || 0);
                if (msgId <= lastSeenStepId) {
                    return;
                }
                lastSeenStepId = msgId;
                appendStepBubble(String(msg.content || ''), msgId);
            });
            return resp;
        }).catch(() => {
            // Polling errors are silently ignored — the main request surfaces real errors.
        });
    }, 1500);
};

/**
 * Fetch and display all LLM debug logs for the current thread.
 * Only available in debug mode.
 */
const refreshThreadDebugLogs = () => {
    if (!debugModeEnabled || currentThreadId <= 0 || currentContextId <= 0) {
        return;
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_get_thread_debug_logs',
        args: {contextid: currentContextId, threadid: currentThreadId, limit: 100},
    }])[0].then((resp) => {
        let debugLogs = [];
        try {
            debugLogs = JSON.parse(resp.debuglogsjson || '[]');
        } catch (e) {
            debugLogs = [];
        }

        if (Array.isArray(debugLogs) && debugLogs.length > 0) {
            const debugHtml = renderDebugLogs(debugLogs);
            if (debugHtml) {
                const list = document.getElementById('booking-ai-messages');
                if (list) {
                    // Remove any existing debug logs panel
                    const existingPanel = list.querySelector('.booking-ai-all-debug-logs');
                    if (existingPanel) {
                        existingPanel.remove();
                    }

                    // Add new debug logs panel
                    const div = document.createElement('div');
                    div.classList.add(
                        'booking-ai-msg', 'assistant',
                        'booking-ai-all-debug-logs', 'mt-3'
                    );
                    div.innerHTML = '<div class="alert alert-secondary mb-0">'
                        + '<strong>📋 All LLM Debug Logs (Thread)</strong>'
                        + `${debugHtml}`
                        + '</div>';
                    list.appendChild(div);
                    list.scrollTop = list.scrollHeight;
                }
            }
        }
    }).catch(() => {
        // Silently fail if API not available or debug mode off
    });
};

// ---------------------------------------------------------------------------
// Attachment handling
// ---------------------------------------------------------------------------

/**
 * Render the attachment tray from the current pendingAttachments list.
 */
const renderAttachmentTray = () => {
    const tray = document.getElementById('booking-ai-attachment-tray');
    if (!tray) {
        return;
    }
    if (pendingAttachments.length === 0) {
        tray.innerHTML = '';
        tray.classList.add('d-none');
        return;
    }
    tray.classList.remove('d-none');
    tray.innerHTML = pendingAttachments.map((att, idx) => {
        const thumb = att.thumbnailHtml || '';
        const name = String(att.displayName || '').replace(/</g, '&lt;');
        const icon = att.attachment_type === 'pdf' ? '&#128196;' : '';
        return `<span class="booking-ai-attachment-chip" data-idx="${idx}">
            ${thumb || icon}
            <span class="booking-ai-attachment-name">${name}</span>
            <button type="button" class="booking-ai-attachment-remove" data-idx="${idx}"
                    aria-label="Remove attachment" title="Remove">&#10005;</button>
        </span>`;
    }).join('');
};

/**
 * Upload a single File via Moodle WS and add the resulting token to pendingAttachments.
 *
 * @param {File} file
 */
const uploadAttachment = (file) => {
    const reader = new FileReader();
    reader.onload = () => {
        const dataUrl = String(reader.result || '');
        Ajax.call([{
            methodname: 'bookingextension_agent_ai_upload_attachment',
            args: {
                contextid: currentContextId,
                filename: file.name,
                mimetype: file.type,
                filedata: dataUrl,
            },
        }])[0].then((resp) => {
            if (!resp.success) {
                Notification.addNotification({
                    message: resp.message || 'Upload failed.',
                    type: 'error',
                });
                return resp;
            }
            pendingAttachments.push({
                token: resp.attachment_token,
                type: resp.attachment_type,
                displayName: resp.display_name,
                thumbnailHtml: resp.thumbnail_html || '',
            });
            renderAttachmentTray();
            return resp;
        }).catch((err) => {
            Notification.addNotification({message: 'File upload failed.', type: 'error'});
            return err;
        });
    };
    reader.readAsDataURL(file);
};

/**
 * Process a FileList, filtering to allowed types.
 *
 * @param {FileList} fileList
 */
const handleFileList = (fileList) => {
    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    Array.from(fileList).forEach((f) => {
        if (allowed.includes(f.type)) {
            uploadAttachment(f);
        }
    });
};

/**
 * Wire up attachment button, hidden file input, drag-and-drop on the footer, tray remove buttons.
 */
const initAttachmentHandlers = () => {
    const footer = document.getElementById('booking-ai-card-footer');
    const attachBtn = document.getElementById('booking-ai-attach-btn');
    const fileInput = document.getElementById('booking-ai-file-input');

    if (!footer || !attachBtn || !fileInput) {
        return;
    }

    // Paperclip button opens the file picker.
    attachBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            handleFileList(fileInput.files);
            fileInput.value = '';
        }
    });

    // Drag-and-drop on the card footer.
    footer.addEventListener('dragover', (e) => {
        e.preventDefault();
        footer.classList.add('booking-ai-drop-active');
    });
    footer.addEventListener('dragleave', (e) => {
        if (!footer.contains(e.relatedTarget)) {
            footer.classList.remove('booking-ai-drop-active');
        }
    });
    footer.addEventListener('drop', (e) => {
        e.preventDefault();
        footer.classList.remove('booking-ai-drop-active');
        if (e.dataTransfer && e.dataTransfer.files.length > 0) {
            handleFileList(e.dataTransfer.files);
        }
    });

    // Remove chip buttons (event delegation on tray).
    const tray = document.getElementById('booking-ai-attachment-tray');
    if (tray) {
        tray.addEventListener('click', (e) => {
            const btn = e.target.closest('.booking-ai-attachment-remove');
            if (!btn) {
                return;
            }
            const idx = parseInt(btn.dataset.idx, 10);
            if (!isNaN(idx) && idx >= 0 && idx < pendingAttachments.length) {
                pendingAttachments.splice(idx, 1);
                renderAttachmentTray();
            }
        });
    }
};

// ---------------------------------------------------------------------------

/**
 * The "Debug logs" button is now rendered server-side inside the manage gear (gated on
 * llm_debug_enabled, hidden while debug mode is off) and live-toggled by setDebugMode().
 * No DOM injection here — kept as a no-op so existing init call sites stay valid.
 */
const initDebugRefreshButton = () => {};

/**
 * Stop the step-progress polling interval.
 */
const stopStepPolling = () => {
    if (stepPollInterval !== null) {
        clearInterval(stepPollInterval);
        stepPollInterval = null;
    }
};



/**
 * Whether a new message must wait: a turn is running (and was not stopped) or a decision is open.
 *
 * @returns {boolean}
 */
const isSendBlocked = () => (sendInFlight && !sendAborted) || pendingAnonMessage !== null;

/**
 * Remove the open decision prompt of a suspect word from the chat.
 */
const clearAnonDecisionPrompt = () => {
    document.querySelectorAll('.booking-ai-anon-decision-prompt').forEach((el) => el.remove());
};

/**
 * Ask the user to decide about a suspect word before the turn starts. The send button stays
 * disabled; the stop button cancels the waiting message.
 *
 * @param {string} text Prompt of the precheck, already in the language of the user.
 * @param {string} word The suspect word (shown in the interface only).
 */
const showAnonDecisionPrompt = async(text, word) => {
    clearAnonDecisionPrompt();
    const chips = await previewRenderers.anon_word_decision({word});
    const div = appendMessageHtml('assistant', `${renderAssistantMessageHtml(text)}${chips}`, {
        response_type: 'privacy_decision',
        threadid: Number(currentThreadId || 0),
        status: 'needs_decision',
        source: 'ai_privacy_precheck',
        time: (new Date()).toISOString(),
    });
    if (div) {
        div.classList.add('booking-ai-anon-decision-prompt');
    }
};

/**
 * Send a message to the AI agent.
 *
 * @param {string} message
 * @param {boolean} resume True when the message waited for a decision and is sent now.
 */
const sendMessage = (message, resume = false) => {
    if (!message.trim()) {
        return;
    }
    // One turn at a time, and no new turn while a decision about a suspect word is open.
    if (isSendBlocked() && !resume) {
        return;
    }

    sendAborted = false;
    sendInFlight = true;

    if (!resume) {
        appendMessage('user', message, {
            source: 'chat_input',
            time: (new Date()).toISOString(),
        });
    }

    const thinking = document.getElementById('booking-ai-thinking');
    const sendBtn  = document.getElementById('booking-ai-send');
    const stopBtn  = document.getElementById('booking-ai-btn-stop');
    if (thinking) {
        updateThinkingLabel(privacyCheckRunningLabel);
        thinking.classList.remove('d-none');
    }
    if (stopBtn) {
        stopBtn.classList.remove('d-none');
    }
    if (sendBtn) {
        sendBtn.disabled = true;
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_privacy_precheck',
        args: {
            contextid: currentContextId,
            message,
            forcenewthread: forceNewThreadOnFirstMessage ? 1 : 0,
            anonworddecision: JSON.stringify(pendingAnonWordDecision || {}),
        },
    }])[0].then((precheck) => {
        forceNewThreadOnFirstMessage = false;
        pendingAnonWordDecision = null;

        if (precheck.threadid && precheck.threadid > 0) {
            currentThreadId = precheck.threadid;
        }

        // An undecided suspect word blocks the turn: nothing is sent until the user has chosen.
        // The chip click repeats the precheck for the same message together with the decision.
        if (String(precheck.status || '') === 'needs_decision'
                && Array.isArray(precheck.suspects) && precheck.suspects.length > 0) {
            sendInFlight = false;
            pendingAnonMessage = message;
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            showAnonDecisionPrompt(String(precheck.message || ''), String(precheck.suspects[0].word || ''));
            return precheck;
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
            sendInFlight = false;
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            if (stopBtn) {
                stopBtn.classList.add('d-none');
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            return precheck;
        }

        const sanitizedMessage = String(precheck.sanitizedmessage || message);
        if (thinking) {
            updateThinkingLabel(defaultThinkingLabel);
        }

        // Start polling for intermediate step updates while the LLM is processing.
        startStepPolling(currentThreadId, currentContextId);
        appendStepBubble(stepPlanningLabel, 0);

        const attachmentsPayload = JSON.stringify(
            pendingAttachments.map((a) => ({token: a.token, type: a.type}))
        );
        pendingAttachments = [];
        renderAttachmentTray();

        // Current-page snapshot stashed by the navbar hook (per tab); relayed so the agent knows where
        // the user is. Best-effort: an empty object is fine when sessionStorage is unavailable.
        let pagecontextPayload = '{}';
        try {
            pagecontextPayload = window.sessionStorage.getItem('wizard_pagecontext') || '{}';
        } catch (e) {
            pagecontextPayload = '{}';
        }

        return Ajax.call([{
            methodname: 'bookingextension_agent_ai_send_message',
            args: {
                contextid: currentContextId,
                message: sanitizedMessage,
                threadid: Number(currentThreadId || 0),
                attachments: attachmentsPayload,
                pagecontext: pagecontextPayload,
            },
        }])[0].then((resp) => {
        sendInFlight = false;
        // Stop step polling and remove ephemeral step bubbles before showing final answer.
        stopStepPolling();
        clearStepBubbles();

        // If the user clicked Stop while waiting, discard the response silently.
        if (sendAborted) {
            sendAborted = false;
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            if (stopBtn) {
                stopBtn.classList.add('d-none');
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            return resp;
        }

        if (thinking) {
            thinking.classList.add('d-none');
            updateThinkingLabel(defaultThinkingLabel);
        }
        if (stopBtn) {
            stopBtn.classList.add('d-none');
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }

        if (resp.threadid && resp.threadid > 0) {
            currentThreadId = resp.threadid;
        }

        // Trigger option preview for all non-command response types.
        // previewoptionidsjson carries the full id list; falls back to scalar + results.
        if (resp.previewjson) {
            try {
                const desc = JSON.parse(resp.previewjson);
                if (desc) {
                    dispatchSkillPreview(desc, currentContextId);
                }
            } catch (e) {
                // Ignore.
            }
        }

        if (resp.response_type === 'clarification' || resp.response_type === 'sufficient' || resp.response_type === 'error') {
            // Steps are ephemeral loading indicators — clear them when any response arrives.
            stopStepPolling();
            clearStepBubbles();
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            const errors = parseJsonList(resp.errorsjson);
            const issueCodes = parseJsonList(resp.issuecodesjson);
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            if (isTrialTokenInvalidError(resp, errors, issueCodes)) {
                maybeShowTrialTokenInvalidAlert(resp, errors, issueCodes);
                return resp;
            }
            const ambiguityOptions = parseJsonObjectList(resp.ambiguityoptionsjson || '[]');
            const messageText = String(resp.displaymessage || resp.message || '');
            const ambiguityOptionsHtml = renderAmbiguityOptionsHtml(ambiguityOptions);

            if (ambiguityOptionsHtml !== '' && resp.response_type === 'clarification') {
                appendMessageHtml(
                    'assistant',
                    `${renderAssistantMessageHtml(messageText)}${ambiguityOptionsHtml}`,
                    {
                        response_type: resp.response_type || '',
                        threadid: Number(resp.threadid || currentThreadId || 0),
                        runid: Number(resp.runid || 0),
                        issue_codes: issueCodes.join(', '),
                        results_count: Array.isArray(results) ? results.length : 0,
                        errors: errors.join(' || '),
                        source: 'ai_send_message',
                        time: (new Date()).toISOString(),
                    }
                );

                const debugHtml = buildDebugRunHtml('completed', messageText, results);
                if (debugHtml) {
                    appendMessageHtml('assistant', debugHtml, {
                        response_type: 'execution_debug',
                        status: 'completed',
                        source: 'ai_send_message',
                        time: (new Date()).toISOString(),
                    });
                }

                const followUpHtml = renderFollowUpSuggestionsHtml(results);
                if (followUpHtml) {
                    appendMessageHtml('assistant', followUpHtml, {
                        response_type: 'execution_followup',
                        status: 'completed',
                        source: 'ai_send_message_followup',
                        time: (new Date()).toISOString(),
                    });
                }

                return resp;
            }

            // Errors are rendered as normal, neutral assistant bubbles — never red (same
            // principle as showRunStatus): a failed run reads as a calm notice, not an alarm.
            // response_type stays 'error' so the backend/flowchart contract is unchanged, and
            // the debug meta line below the bubble still carries it for diagnosis.
            const isAvailabilityDenial = resp.response_type === 'error'
                && issueCodes.length > 0
                && issueCodes.every((code) => String(code).trim() === 'SKILL_DENIED');
            const isError = resp.response_type === 'error' && !isAvailabilityDenial;
            const meta = {
                response_type: resp.response_type || '',
                threadid: Number(resp.threadid || currentThreadId || 0),
                runid: Number(resp.runid || 0),
                issue_codes: issueCodes.join(', '),
                results_count: Array.isArray(results) ? results.length : 0,
                errors: errors.join(' || '),
                source: 'ai_send_message',
                time: (new Date()).toISOString(),
            };
            appendMessageHtml(
                'assistant',
                renderAssistantMessageHtml(String(resp.displaymessage || resp.message || '')),
                meta
            );

            const debugHtml = buildDebugRunHtml(
                isError ? 'failed' : 'completed',
                String(resp.displaymessage || resp.message || ''),
                results
            );
            if (debugHtml) {
                appendMessageHtml('assistant', debugHtml, {
                    response_type: 'execution_debug',
                    status: isError ? 'failed' : 'completed',
                    source: 'ai_send_message',
                    time: (new Date()).toISOString(),
                });
            }

            const followUpHtml = renderFollowUpSuggestionsHtml(results);
            if (followUpHtml) {
                appendMessageHtml('assistant', followUpHtml, {
                    response_type: 'execution_followup',
                    status: isError ? 'failed' : 'completed',
                    source: 'ai_send_message_followup',
                    time: (new Date()).toISOString(),
                });
            }


        } else if (resp.response_type === 'execution_result') {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            showRunStatus(resp.status || 'completed', resp.displaymessage || resp.message || '', results);

            startStepPolling(currentThreadId, currentContextId);
        } else if (resp.response_type === 'confirmation_request' || resp.response_type === 'skill_call') {
            try {
                handleConfirmationResponse(resp, 'ai_send_message');
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
        sendInFlight = false;
        stopStepPolling();
        clearStepBubbles();
        if (thinking) {
            thinking.classList.add('d-none');
            updateThinkingLabel(defaultThinkingLabel);
        }
        if (stopBtn) {
            stopBtn.classList.add('d-none');
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }
        Notification.exception(err);
    });
};

/**
 * Confirm and submit the pending commands for execution.
 *
 * @param {boolean} allowSession Whether to allow confirmation for this thread in this session.
 */
const confirmRun = (allowSession = false) => {
    if (!pendingCommands) {
        return;
    }

    if (pendingQueueItemId === '') {
        showRunStatus('failed', 'Missing queue item id. Please confirm the latest assistant proposal again.');
        return;
    }

    clearStepBubbles();
    appendStepBubble(stepExecutingLabel, 0);

    const thinking = document.getElementById('booking-ai-thinking');
    const stopBtn  = document.getElementById('booking-ai-btn-stop');
    if (thinking) {
        updateThinkingLabel(stepExecutingLabel);
        thinking.classList.remove('d-none');
    }
    if (stopBtn) {
        stopBtn.classList.remove('d-none');
    }
    startStepPolling(currentThreadId, currentContextId);

    const effectiveAllowSession = Boolean(allowSession || sessionAutoConfirmEnabled);
    if (effectiveAllowSession) {
        sessionAutoConfirmEnabled = true;
    }
    hideConfirmPanel(false);

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_confirm_run',
        args: {
            contextid: currentContextId,
            threadid: currentThreadId,
            queue_item_id: pendingQueueItemId,
            allow_session: effectiveAllowSession,
        },
    }])[0].then((resp) => {
        // Consume current pending confirmation. A follow-up confirmation_request
        // may set a fresh pendingCommands inside handleConfirmationResponse().
        pendingCommands = null;
        if (resp.success) {
            // Steps are ephemeral loading indicators. Clear them and stop polling when response arrives.
            stopStepPolling();
            clearStepBubbles();

            const thinking = document.getElementById('booking-ai-thinking');
            const stopBtn  = document.getElementById('booking-ai-btn-stop');
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            if (stopBtn) {
                stopBtn.classList.add('d-none');
            }

            if (resp.previewjson) {
                try {
                    const desc = JSON.parse(resp.previewjson);
                    if (desc) {
                        dispatchSkillPreview(desc, currentContextId);
                    }
                } catch (e) {
                    // Ignore.
                }
            }
            handleConfirmationResponse(resp, 'ai_confirm_run');
        } else {
            clearActivePlanBubble();
            showRunStatus('failed', resp.message);
        }
        return resp;
    }).catch((err) => {
        stopStepPolling();
        clearStepBubbles();

        const thinking = document.getElementById('booking-ai-thinking');
        const stopBtn  = document.getElementById('booking-ai-btn-stop');
        if (thinking) {
            thinking.classList.add('d-none');
            updateThinkingLabel(defaultThinkingLabel);
        }
        if (stopBtn) {
            stopBtn.classList.add('d-none');
        }

        Notification.exception(err);
    });
};

/**
 * Discard currently pending confirmation and skip queued mutating item(s).
 */
const discardPendingConfirmation = () => {
    if (currentThreadId <= 0 || pendingQueueItemId === '') {
        hideConfirmPanel();
        return;
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_discard_pending',
        args: {
            contextid: currentContextId,
            threadid: currentThreadId,
        },
    }])[0].then((resp) => {
        const ok = Boolean(resp && resp.success);
        hideConfirmPanel(true);
        if (!ok) {
            const message = String((resp && resp.message) || 'Unable to discard pending confirmation.');
            showRunStatus('failed', message);
            return resp;
        }

        const message = String((resp && resp.message) || 'Pending confirmation was discarded.');
        appendFriendlyAssistantMessage(message);
        return resp;
    }).catch((err) => {
        const fallbackMessage = 'Discard failed. Pending confirmation is still active. '
            + 'If this happened after an update, run the Moodle plugin upgrade and reload the page.';
        appendFriendlyAssistantMessage(fallbackMessage);
        Notification.exception(err);
    });
};

/**
 * Collect trial UI elements and labels from the wrapper.
 *
 * @returns {Object}
 */
const getTrialUiContext = () => {
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
    const readyHeading = String((wrapper && wrapper.dataset.agentReadyHeading) || '');
    const readyBody = String((wrapper && wrapper.dataset.agentReadyBody) || '');
    const openAgentLabel = String((wrapper && wrapper.dataset.agentOpenAgent) || '');

    return {
        trialBtn,
        activateBtn,
        activateWrap,
        trialSpinner,
        trialResult,
        wrapper,
        trialSuccessDefault,
        trialReloadingLabel,
        trialFailedDefault,
        trialUnexpectedError,
        trialActivateSuccess,
        readyHeading,
        readyBody,
        openAgentLabel,
    };
};

/**
 * Reveal the existing "I already have a key" field (rendered server-side under can_store_key) so the
 * user can recover from a failed trial request by pasting a purchased key.
 *
 * Reuses the same key form the connect screen already renders rather than building a parallel one;
 * a no-op when that field is absent (can_store_key false / no supported provider installed).
 */
const revealKeyEntryFallback = () => {
    const keystep = document.getElementById('booking-ai-key-step');
    if (!keystep) {
        return;
    }
    const consent = document.getElementById('booking-ai-consent-step');
    if (consent) {
        consent.classList.add('d-none');
    }
    keystep.dataset.returnTo = 'consent';
    keystep.classList.remove('d-none');
};

/**
 * Request a trial key and advance the setup wizard.
 *
 * @param {String} strategy Chosen provider path: 'wunderbyte', 'openai', or '' to auto-detect.
 */
const requestTrialKey = (strategy) => {
    const ctx = getTrialUiContext();
    const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-trial-strategy]'));
    const contextid = Number((ctx.wrapper && ctx.wrapper.dataset.contextid) || 0);

    buttons.forEach((btn) => {
        btn.disabled = true;
    });
    if (ctx.trialSpinner) {
        ctx.trialSpinner.classList.remove('d-none');
    }
    if (ctx.trialResult) {
        ctx.trialResult.classList.add('d-none');
        ctx.trialResult.innerHTML = '';
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_request_trial_key',
        // consented:true — requestTrialKey() only runs after the user accepted the consent modal.
        args: {contextid: contextid, consented: true, strategy: String(strategy || '')},
    }])[0].then((resp) => {
        if (resp && resp.success) {
            // Advance the wizard: reload the panel so the server renders the next step
            // (Activate, or straight to the chat when no activation is needed here).
            reloadAgentPanel(ctx);
            return resp;
        }
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                + renderTextWithLinks((resp && resp.message) || ctx.trialFailedDefault)
                + '</div>';
        }
        revealKeyEntryFallback();
        buttons.forEach((btn) => {
            btn.disabled = false;
        });
        return resp;
    }).catch((err) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                    + renderTextWithLinks(err.message || ctx.trialUnexpectedError)
                + '</div>';
        }
        revealKeyEntryFallback();
        buttons.forEach((btn) => {
            btn.disabled = false;
        });
        Notification.exception(err);
    });
};

/**
 * Refresh the agent UI after trial activation.
 *
 * Inside the navbar wand modal a full page reload would close the modal and
 * lose the user's place — instead the aipanel fragment is re-fetched into the
 * modal body. Inline embeddings (booking view tab) keep the page reload.
 *
 * @param {Object} ctx trial UI context
 */
const reloadAgentPanel = (ctx) => {
    const modal = ctx.wrapper ? ctx.wrapper.closest('.bookingextension-agent-wand-modal') : null;
    const body = modal ? modal.querySelector('.modal-body') : null;
    const contextid = Number((ctx.wrapper && ctx.wrapper.dataset.contextid) || 0);
    if (!body || !contextid) {
        window.location.reload();
        return;
    }

    Fragment.loadFragment('bookingextension_agent', 'aipanel', contextid, {contextid: contextid})
        .done((html, js) => {
            Templates.replaceNodeContents(body, html, js);
        })
        .fail(() => window.location.reload());
};

/**
 * Store a purchased Wunderbyte API key (from the connect screen or the admin gear) and reload the panel.
 *
 * The key is sent ONLY to the dedicated store webservice (never as a chat message / to the LLM).
 *
 * @param {HTMLElement} saveBtn The clicked save button (carries contextid + overwrite metadata).
 */
const storeProviderApiKey = (saveBtn) => {
    const ctx = getTrialUiContext();
    const input = document.getElementById('booking-ai-key-input');
    const result = document.getElementById('booking-ai-key-result');
    const contextid = Number(
        saveBtn.dataset.contextid || (ctx.wrapper && ctx.wrapper.dataset.contextid) || 0
    );
    const apikey = input ? String(input.value || '').trim() : '';
    if (apikey === '') {
        return;
    }
    if (String(saveBtn.dataset.providerConfigured || '0') === '1') {
        const confirmtext = String(saveBtn.dataset.overwriteConfirm || '');
        if (confirmtext !== '' && !window.confirm(confirmtext)) {
            return;
        }
    }

    saveBtn.disabled = true;
    if (result) {
        result.classList.add('d-none');
        result.innerHTML = '';
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_store_provider_apikey',
        args: {contextid: contextid, apikey: apikey},
    }])[0].then((resp) => {
        if (resp && resp.success) {
            if (input) {
                input.value = '';
            }
            reloadAgentPanel(ctx);
            return resp;
        }
        saveBtn.disabled = false;
        if (result) {
            result.classList.remove('d-none');
            result.innerHTML = '<span class="text-danger">'
                + renderTextWithLinks((resp && resp.message) || '') + '</span>';
        }
        return resp;
    }).catch((err) => {
        saveBtn.disabled = false;
        if (result) {
            result.classList.remove('d-none');
            result.innerHTML = '<span class="text-danger">'
                + renderTextWithLinks(err.message || '') + '</span>';
        }
        Notification.exception(err);
    });
};

/**
 * Toggle the site-wide agent debug mode from the admin gear.
 *
 * @param {HTMLInputElement} toggle The debug checkbox (already reflecting the desired state on click).
 */
const setDebugMode = (toggle) => {
    const enabled = !!toggle.checked;
    toggle.disabled = true;
    Ajax.call([{
        methodname: 'bookingextension_agent_set_debug_mode',
        args: {enabled: enabled},
    }])[0].then((resp) => {
        toggle.disabled = false;
        if (!resp || !resp.success) {
            toggle.checked = !enabled;
            return resp;
        }
        // aidebugmode is a SINGLE switch driving both the in-page debug mode and LLM debug logging.
        // Gate the "Debug logs" button on the LIVE toggle state so it appears/disappears immediately —
        // the page-load snapshot used before stayed stale, so the button never showed when debug was
        // turned on after the page had loaded.
        debugModeEnabled = enabled;
        const logsbtn = document.getElementById('booking-ai-debug-refresh');
        if (logsbtn) {
            logsbtn.classList.toggle('d-none', !enabled);
        }
        return resp;
    }).catch((err) => {
        toggle.disabled = false;
        toggle.checked = !enabled;
        Notification.exception(err);
    });
};

/**
 * Activate trial context and refresh the agent UI on success.
 */
const activateTrialContext = () => {
    const ctx = getTrialUiContext();
    if (!ctx.activateBtn) {
        return;
    }

    ctx.activateBtn.disabled = true;
    if (ctx.trialSpinner) {
        ctx.trialSpinner.classList.remove('d-none');
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_activate_trial_context',
        args: {
            contextid: Number(
                (ctx.trialBtn && ctx.trialBtn.dataset.contextid)
                || (ctx.wrapper && ctx.wrapper.dataset.contextid)
                || 0
            ),
        },
    }])[0].then((resp) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            if (resp && resp.success) {
                // Step 3 "Ready": explicit confirmation, then the user opens the agent themselves.
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-success mb-0">'
                    + '<i class="fa fa-check-circle mr-2" aria-hidden="true"></i>'
                    + '<strong>' + escapeHtml(ctx.readyHeading) + '</strong> '
                    + escapeHtml(ctx.readyBody)
                    + '<div class="mt-2"><button type="button" id="booking-ai-open-agent-btn"'
                    + ' class="btn btn-primary btn-sm">' + escapeHtml(ctx.openAgentLabel) + '</button></div>'
                    + '</div>';
            } else {
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-danger mb-0">'
                    + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                    + renderTextWithLinks((resp && resp.message) || ctx.trialFailedDefault)
                    + '</div>';
                ctx.activateBtn.disabled = false;
            }
        }
        return resp;
    }).catch((err) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                + renderTextWithLinks(err.message || ctx.trialUnexpectedError)
                + '</div>';
        }
        ctx.activateBtn.disabled = false;
        Notification.exception(err);
    });
};

/**
 * Auto-configure the Wunderbyte provider from the existing third-party provider's credentials.
 *
 * No consent/trial: reuses the already-configured provider locally. On success the panel reloads
 * (the Wunderbyte provider is now active).
 */
const configureFromExistingProvider = () => {
    const ctx = getTrialUiContext();
    const contextid = Number((ctx.wrapper && ctx.wrapper.dataset.contextid) || 0);
    const cloneBtn = document.getElementById('booking-ai-config-clone');

    if (cloneBtn) {
        cloneBtn.disabled = true;
    }
    if (ctx.trialSpinner) {
        ctx.trialSpinner.classList.remove('d-none');
    }
    if (ctx.trialResult) {
        ctx.trialResult.classList.add('d-none');
        ctx.trialResult.innerHTML = '';
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_configure_provider_from_existing',
        args: {contextid: contextid},
    }])[0].then((resp) => {
        if (resp && resp.success) {
            reloadAgentPanel(ctx);
            return resp;
        }
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                + renderTextWithLinks((resp && resp.message) || ctx.trialFailedDefault)
                + '</div>';
        }
        if (cloneBtn) {
            cloneBtn.disabled = false;
        }
        return resp;
    }).catch((err) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                + renderTextWithLinks(err.message || ctx.trialUnexpectedError)
                + '</div>';
        }
        if (cloneBtn) {
            cloneBtn.disabled = false;
        }
        Notification.exception(err);
    });
};

/**
 * Reveal the inline GDPR consent step (sub-screen of "Connect"), replacing the path decision.
 *
 * No nested modal: the consent lives inside the panel. The chosen path is parked on the accept
 * button; the trial key request only fires once the consent checkbox is ticked and confirmed.
 *
 * @param {String} strategy Chosen provider path ('wunderbyte' | 'openai' | '').
 */
const showConsentStep = (strategy) => {
    const decision = document.getElementById('booking-ai-connect-decision');
    const choice = document.getElementById('booking-ai-config-choice');
    const consent = document.getElementById('booking-ai-consent-step');
    const accept = document.getElementById('booking-ai-consent-accept');
    const checkbox = document.getElementById('booking-ai-trial-consent-checkbox');
    if (!consent) {
        return;
    }
    if (decision) {
        decision.classList.add('d-none');
    }
    if (choice) {
        choice.classList.add('d-none');
    }
    consent.classList.remove('d-none');
    if (accept) {
        accept.dataset.strategy = String(strategy || '');
        accept.disabled = true;
    }
    if (checkbox) {
        checkbox.checked = false;
    }
};

/**
 * Return from the consent sub-screen to the path decision.
 */
const hideConsentStep = () => {
    const decision = document.getElementById('booking-ai-connect-decision');
    const choice = document.getElementById('booking-ai-config-choice');
    const consent = document.getElementById('booking-ai-consent-step');
    if (consent) {
        consent.classList.add('d-none');
    }
    if (decision) {
        decision.classList.remove('d-none');
    }
    if (choice) {
        choice.classList.remove('d-none');
    }
};

/**
 * Kept for backwards compatibility with existing init flow.
 */
const bindTrialButton = () => {
    // Interaction handlers are delegated centrally on document.body.
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
 * Stop active request UI and discard pending response.
 */
const stopCurrentRun = () => {
    sendAborted = true;
    // Stop also cancels a message that waits for the decision about a suspect word.
    pendingAnonMessage = null;
    clearAnonDecisionPrompt();
    stopStepPolling();
    clearStepBubbles();
    const thinkingEl = document.getElementById('booking-ai-thinking');
    if (thinkingEl) {
        thinkingEl.classList.add('d-none');
        updateThinkingLabel(defaultThinkingLabel);
    }
    const stopBtnEl = document.getElementById('booking-ai-btn-stop');
    if (stopBtnEl) {
        stopBtnEl.classList.add('d-none');
    }
    const sendBtnEl = document.getElementById('booking-ai-send');
    if (sendBtnEl) {
        sendBtnEl.disabled = false;
    }
};

/**
 * Delegated body click handler for all aiinstructions interactions.
 *
 * @param {MouseEvent} event
 */
const handleBodyClick = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    // Handle confirm controls globally via event delegation first.
    // Confirm panel markup may be re-rendered, so avoid wrapper-scoped binding assumptions.
    const confirmBtn = target.closest('#booking-ai-btn-confirm');
    if (confirmBtn instanceof HTMLElement) {
        confirmRun();
        return;
    }

    const confirmSessionBtn = target.closest('#booking-ai-btn-confirm-session');
    if (confirmSessionBtn instanceof HTMLElement) {
        confirmRun(true);
        return;
    }

    const cancelBtn = target.closest('#booking-ai-btn-cancel');
    if (cancelBtn instanceof HTMLElement) {
        discardPendingConfirmation();
        return;
    }

    const wrapper = target.closest('#booking-ai-wrapper');
    if (!(wrapper instanceof HTMLElement)) {
        return;
    }

    const mobileToggle = target.closest('#booking-ai-mobile-toggle');
    if (mobileToggle instanceof HTMLElement) {
        const layout = document.getElementById('booking-ai-body-layout');
        const label = mobileToggle.querySelector('.booking-ai-mobile-toggle-label');
        if (layout) {
            const previewActive = !layout.classList.contains('mobile-preview-active');
            layout.classList.toggle('mobile-preview-active', previewActive);
            mobileToggle.setAttribute('aria-pressed', previewActive ? 'true' : 'false');
            if (label) {
                label.textContent = previewActive ? 'Chat' : 'Preview';
            }
        }
        return;
    }

    const copyBtn = target.closest('.booking-ai-copy-debug-logs');
    if (copyBtn instanceof HTMLElement) {
        const panel = copyBtn.closest('.booking-ai-all-debug-logs, .booking-ai-debug-logs');
        const plain = panel ? panel.querySelector('.booking-ai-debug-logs-plain') : null;
        const plainText = plain ? String(plain.textContent || '') : '';

        copyTextToClipboard(plainText).then(() => {
            showButtonFeedback(copyBtn, 'Copied!', 'btn-success');
        }).catch(() => {
            showButtonFeedback(copyBtn, 'Copy failed', 'btn-danger');
            Notification.alert('Failed to copy debug logs to clipboard.');
        });
        return;
    }

    const refreshBtn = target.closest('#booking-ai-debug-refresh');
    if (refreshBtn instanceof HTMLElement) {
        refreshThreadDebugLogs();
        return;
    }

    const sendBtn = target.closest('#booking-ai-send');
    if (sendBtn instanceof HTMLElement) {
        const inputEl = document.getElementById('booking-ai-input');
        if (isSendBlocked()) {
            return;
        }
        if (inputEl instanceof HTMLTextAreaElement || inputEl instanceof HTMLInputElement) {
            const msg = inputEl.value;
            inputEl.value = '';
            sendMessage(msg);
        }
        return;
    }

    const stopBtn = target.closest('#booking-ai-btn-stop');
    if (stopBtn instanceof HTMLElement) {
        stopCurrentRun();
        return;
    }

    const configurePill = target.closest('#booking-ai-configure-pill');
    if (configurePill instanceof HTMLElement) {
        // Reveal the configure choice panel (use existing provider's credentials, or a trial).
        const choice = document.getElementById('booking-ai-config-choice');
        if (choice) {
            choice.classList.toggle('d-none');
        }
        return;
    }

    const cloneBtn = target.closest('#booking-ai-config-clone');
    if (cloneBtn instanceof HTMLElement) {
        configureFromExistingProvider();
        return;
    }

    const pathBtn = target.closest('[data-trial-strategy]');
    if (pathBtn instanceof HTMLElement) {
        // On Moodle 4.5 the trial configures (and may overwrite) the site's single standard
        // provider config -- confirm first. The confirm text is rendered only there, so this
        // is a no-op on Moodle 5.x (multi-instance, no overwrite).
        const strategyConfirm = pathBtn.dataset.strategyConfirm || '';
        if (strategyConfirm !== '' && !window.confirm(strategyConfirm)) {
            return;
        }
        // Path chosen -> reveal the inline consent step (no nested modal). The strategy is
        // parked on the accept button; the key request runs only after consent + confirm.
        showConsentStep(pathBtn.dataset.trialStrategy || '');
        return;
    }

    const consentBack = target.closest('#booking-ai-consent-back');
    if (consentBack instanceof HTMLElement) {
        hideConsentStep();
        return;
    }

    const consentAccept = target.closest('#booking-ai-consent-accept');
    if (consentAccept instanceof HTMLElement) {
        requestTrialKey(consentAccept.dataset.strategy || '');
        return;
    }

    const activateBtn = target.closest('#booking-ai-activate-btn');
    if (activateBtn instanceof HTMLElement) {
        activateTrialContext();
        return;
    }

    const gearBtn = target.closest('#booking-ai-manage-gear');
    if (gearBtn instanceof HTMLElement) {
        const menu = document.getElementById('booking-ai-manage-menu');
        if (menu) {
            const nowhidden = menu.classList.toggle('d-none');
            gearBtn.setAttribute('aria-expanded', nowhidden ? 'false' : 'true');
        }
        return;
    }

    const keyBack = target.closest('#booking-ai-key-back');
    if (keyBack instanceof HTMLElement) {
        const decision = document.getElementById('booking-ai-connect-decision');
        const consent = document.getElementById('booking-ai-consent-step');
        const keystep = document.getElementById('booking-ai-key-step');
        const returnTo = keystep ? keystep.dataset.returnTo : 'decision';
        if (keystep) {
            keystep.classList.add('d-none');
        }
        if (returnTo === 'consent' && consent) {
            consent.classList.remove('d-none');
        } else if (decision) {
            decision.classList.remove('d-none');
        }
        return;
    }

    const keySave = target.closest('#booking-ai-key-save');
    if (keySave instanceof HTMLElement) {
        storeProviderApiKey(keySave);
        return;
    }

    const debugToggle = target.closest('#booking-ai-debug-toggle');
    if (debugToggle instanceof HTMLInputElement) {
        setDebugMode(debugToggle);
        return;
    }

    const openAgentBtn = target.closest('#booking-ai-open-agent-btn');
    if (openAgentBtn instanceof HTMLElement) {
        // Step 3 -> chat: reload the panel, which now renders the ready chat UI.
        reloadAgentPanel(getTrialUiContext());
        return;
    }

    const anchor = target.closest('a');
    if (anchor instanceof HTMLAnchorElement) {
        const href = String(anchor.getAttribute('href') || '').trim();
        const inlineDocPath = String(anchor.getAttribute('data-docpath') || '').trim();
        const inlineDocCorpus = String(anchor.getAttribute('data-corpusid') || '').trim();
        const inlineDocFragment = String(anchor.getAttribute('data-docfragment') || '').trim();
        if (inlineDocPath !== '' && inlineDocCorpus !== '') {
            event.preventDefault();
            loadDocInPreview(inlineDocPath, inlineDocCorpus, '', inlineDocFragment);
            return;
        }

        const resolvedDocMeta = getDocLinkMeta(href);
        if (resolvedDocMeta.docpath !== '' && resolvedDocMeta.corpusid !== '') {
            event.preventDefault();
            loadDocInPreview(resolvedDocMeta.docpath, resolvedDocMeta.corpusid, '', resolvedDocMeta.fragment);
            return;
        }

        if (href !== '' && !href.startsWith('#')) {
            event.preventDefault();
            window.open(href, '_blank', 'noopener,noreferrer');
            return;
        }
    }

    const anonBtn = target.closest('.booking-ai-anonword-option');
    if (anonBtn instanceof HTMLElement) {
        const word = String(anonBtn.getAttribute('data-word') || '').trim();
        const decision = String(anonBtn.getAttribute('data-decision') || '').trim();
        const reply = String(anonBtn.getAttribute('data-query') || '').trim();
        const choice = anonBtn.closest('.booking-ai-anonword-choice');
        const decided = choice instanceof HTMLElement && choice.dataset.decided === '1';
        // A chip never starts a second turn next to a running one, and it answers only once.
        if (decided || (sendInFlight && !sendAborted)) {
            return;
        }
        if (word !== '' && (decision === 'person' || decision === 'word') && reply !== '') {
            pendingAnonWordDecision = {word, decision};
            if (choice instanceof HTMLElement) {
                choice.dataset.decided = '1';
                choice.querySelectorAll('.booking-ai-anonword-option').forEach((chip) => {
                    chip.setAttribute('aria-disabled', 'true');
                    chip.setAttribute('disabled', 'disabled');
                });
            }
            anonBtn.classList.remove('btn-outline-primary');
            anonBtn.classList.add('btn-primary');
            if (pendingAnonMessage !== null) {
                // The decision belongs to the waiting message: repeat its precheck with the
                // decision and start the turn. The chip label never becomes a message.
                const original = pendingAnonMessage;
                pendingAnonMessage = null;
                sendMessage(original, true);
            } else {
                sendMessage(reply);
            }
        }
        return;
    }

    const optionBtn = target.closest('.booking-ai-ambiguity-option, .booking-ai-followup-option');
    if (!(optionBtn instanceof HTMLElement)) {
        return;
    }

    const query = String(optionBtn.getAttribute('data-query') || '').trim();
    if (query === '') {
        return;
    }

    optionBtn.classList.remove('btn-outline-primary', 'btn-outline-secondary');
    optionBtn.classList.add('btn-primary');

    const isFollowUp = optionBtn.classList.contains('booking-ai-followup-option');
    if (isFollowUp) {
        const inputElement = document.getElementById('booking-ai-input');
        if (inputElement instanceof HTMLTextAreaElement || inputElement instanceof HTMLInputElement) {
            inputElement.value = query;
            inputElement.focus();
            inputElement.setSelectionRange(inputElement.value.length, inputElement.value.length);
        }
        return;
    }

    optionBtn.setAttribute('aria-disabled', 'true');
    sendMessage(query);
};

/**
 * Delegated key handler for sending messages with Enter.
 *
 * @param {KeyboardEvent} event
 */
const handleBodyKeydown = (event) => {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (!target.matches('#booking-ai-input')) {
        return;
    }

    const wrapper = target.closest('#booking-ai-wrapper');
    if (!(wrapper instanceof HTMLElement)) {
        return;
    }

    event.preventDefault();
    if (isSendBlocked()) {
        // Keep what the user typed: the running turn or the open decision comes first.
        return;
    }
    if (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement) {
        const msg = target.value;
        target.value = '';
        sendMessage(msg);
    }
};

/**
 * Delegated change handler: gate the inline consent "accept" button on the consent checkbox.
 *
 * @param {Event} event
 */
const handleBodyChange = (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement && target.id === 'booking-ai-trial-consent-checkbox') {
        const accept = document.getElementById('booking-ai-consent-accept');
        if (accept instanceof HTMLButtonElement) {
            accept.disabled = !target.checked;
        }
    }
};

/**
 * Bind one central delegated handler on body for aiinstructions interactions.
 */
const initCentralBodyHandlers = () => {
    if (bodyHandlersBound || !(document.body instanceof HTMLElement)) {
        return;
    }

    document.body.addEventListener('click', handleBodyClick);
    document.body.addEventListener('keydown', handleBodyKeydown);
    document.body.addEventListener('change', handleBodyChange);
    bodyHandlersBound = true;
};

/**
 * Initialise the AI instructions interface.
 *
 * @param {Object} jsModulesOrConfig Registered JS modules map or main configuration object.
 * @param {Object|null} config Main configuration object if JS modules map is provided first.
 */
/**
 * Lazily mount the Wunderbyte AI-provider usage bar, if its container is present.
 *
 * The container is rendered server-side only for users with the
 * aiprovider/wunderbyte:viewusage capability and an active Wunderbyte provider,
 * so this just wires up the shared bar component when that container exists.
 *
 * @return {void}
 */
const maybeInitUsageBar = () => {
    const container = document.querySelector('#booking-ai-wrapper [data-region="wb-usage-bar"]');
    if (!container) {
        return;
    }
    import('aiprovider_wunderbyte/usage_bar')
        .then((UsageBar) => {
            // Compact pill next to "AI provider active", fetched only once visible.
            UsageBar.init(container, 0, {compact: true, lazy: true});
            return UsageBar;
        })
        .catch(() => {
            // Provider plugin unavailable or module failed to load: leave the slot empty.
        });
};

export const init = (jsModulesOrConfig = {}, config = null) => {
    let runtimeConfig = config;

    // First argument is kept for template compatibility; it may be a runtime config object.
    // Preview AMD modules are no longer pre-registered globally (skills carry their own per result).
    if (jsModulesOrConfig && jsModulesOrConfig.contextid !== undefined) {
        runtimeConfig = jsModulesOrConfig;
    }

    if (!runtimeConfig) {
        const wrapper = document.getElementById('booking-ai-wrapper');
        if (!wrapper) {
            return;
        }

        runtimeConfig = {
            contextid: Number(wrapper.dataset.contextid || 0),
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
            trial_token_invalid_message: String(wrapper.dataset.aiTrialTokenInvalidMessage || ''),
            step_planning_label: String(wrapper.dataset.stepPlanningLabel || ''),
            step_executing_label: String(wrapper.dataset.stepExecutingLabel || ''),
        };
    }

    currentContextId = runtimeConfig.contextid || 0;
    currentThreadId = runtimeConfig.threadid || 0;
    debugModeEnabled = Boolean(runtimeConfig.debug_mode);
    privacyCheckRunningLabel = String(runtimeConfig.privacy_check_running || privacyCheckRunningLabel);
    privacyAnswerNoteLabel = String(runtimeConfig.privacy_answer_note || privacyAnswerNoteLabel);
    trialTokenInvalidMessageLabel = String(runtimeConfig.trial_token_invalid_message || '');
    stepPlanningLabel = String(runtimeConfig.step_planning_label || stepPlanningLabel);
    stepExecutingLabel = String(runtimeConfig.step_executing_label || stepExecutingLabel);

    const thinking = document.getElementById('booking-ai-thinking');
    if (thinking) {
        const thinkingLabel = document.getElementById('booking-ai-thinking-label');
        if (thinkingLabel) {
            defaultThinkingLabel = String(thinkingLabel.textContent || '').trim() || 'Thinking...';
        } else {
            defaultThinkingLabel = String(thinking.textContent || '').trim() || 'Thinking...';
        }
    }

    // Must be available in onboarding mode where ready_for_chat is false.
    bindTrialButton();
    initCentralBodyHandlers();
    maybeInitUsageBar();

    if (!runtimeConfig.ready_for_chat) {
        return;
    }

    initResizableLayout();
    initMobilePreviewSwitch();
    initAttachmentHandlers();

    // Display welcome message based on booking statistics.
    displayWelcomeMessage(runtimeConfig.num_options || 0, runtimeConfig.num_booked || 0);

    initDebugRefreshButton();

};
