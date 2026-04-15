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
import Notification from 'core/notification';

/** Pending commands waiting for user confirmation. */
let pendingCommands = null;
let currentThreadId = 0;
let currentCmid = 0;

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
 * Check if a free-text message is an affirmative confirmation.
 *
 * @param {string} message
 * @returns {boolean}
 */
const isAffirmativeMessage = (message) => {
    const normalized = String(message || '').trim().toLowerCase();
    return ['yes', 'y', 'ok', 'okay', 'confirm', 'confirmed', 'ja', 'j', 'weiter', 'go'].includes(normalized);
};

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
 * Append a chat bubble to the message list.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} content   Message text.
 */
const appendMessage = (role, content) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<span class="bubble">${escapeHtml(content)}</span>`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
};

/**
 * Append a chat bubble with trusted HTML content.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} html      Trusted HTML.
 */
const appendMessageHtml = (role, html) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<span class="bubble">${String(html || '')}</span>`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
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
 * Show the confirmation panel with a preview of the proposed commands.
 *
 * @param {string} message   AI summary message.
 * @param {Array}  commands  Validated command objects.
 */
const showConfirmPanel = (message, commands) => {
    pendingCommands = commands;

    const panel   = document.getElementById('booking-ai-confirm-panel');
    const preview = document.getElementById('booking-ai-confirm-preview');
    if (!panel || !preview) {
        return;
    }

    let previewHtml = `<p>${escapeHtml(message)}</p><ul>`;
    commands.forEach((cmd) => {
        previewHtml += `<li><strong>${escapeHtml(cmd.task)}</strong>: ${escapeHtml(JSON.stringify(cmd.input))}`;
        previewHtml += '</li>';
    });
    previewHtml += '</ul>';
    preview.innerHTML = '';
    appendMessageHtml('assistant', previewHtml);

    Ajax.call([{
        methodname: 'mod_booking_ai_render_command_preview',
        args: {
            cmid: currentCmid,
            commands: JSON.stringify(commands),
        },
    }])[0].then((resp) => {
        if (resp && resp.success && resp.html && resp.html.trim() !== '') {
            appendMessageHtml('assistant', resp.html);
        } else if (resp && resp.message) {
            appendMessage('assistant', String(resp.message));
        }
        return resp;
    }).catch((err) => {
        appendMessage('assistant', String(err.message || ''));
    });

    panel.classList.remove('d-none');
};

/**
 * Render multiple booking option previews inline in the message thread.
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
            appendMessageHtml('assistant', resp.html);
        }
    }).catch((err) => {
        Notification.exception(err);
    });
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
                let detail = resp.status;
                let results = [];
                try {
                    results = JSON.parse(resp.resultsjson || '[]');
                    detail = results.map((r) => `${r.status}: ${r.detail || ''}`).join('\n');
                } catch (e) {
                    // Keep the status string.
                }
                // eslint-disable-next-line no-console
                console.log('[AI Debug] pollRunStatus resp', resp, 'parsed results', results);
                showRunStatus(resp.status, detail, results);

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

    if (pendingCommands && isAffirmativeMessage(message)) {
        confirmRun();
        return;
    }

    appendMessage('user', message);

    const thinking = document.getElementById('booking-ai-thinking');
    const sendBtn  = document.getElementById('booking-ai-send');
    if (thinking) {
        thinking.classList.remove('d-none');
    }
    if (sendBtn) {
        sendBtn.disabled = true;
    }

    Ajax.call([{
        methodname: 'mod_booking_ai_send_message',
        args: {cmid: currentCmid, message},
    }])[0].then((resp) => {
        if (thinking) {
            thinking.classList.add('d-none');
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
            appendMessage('assistant', resp.message);
        } else if (resp.response_type === 'execution_result') {
            appendMessage('assistant', resp.message || '');
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            // eslint-disable-next-line no-console
            console.log('[AI Debug] execution_result resp', resp, 'parsed results', results);

            showRunStatus('completed', resp.message || 'completed', results);

            const optionIds = extractPreviewOptionIds(results);
            if (optionIds.length > 0) {
                renderOptionPreviewsInline(currentCmid, optionIds);
            }
        } else if (resp.response_type === 'confirmation_request' || resp.response_type === 'task_call') {
            appendMessage('assistant', resp.message);
            try {
                const cmds = JSON.parse(resp.commands || '[]');
                if (cmds.length > 0) {
                    if (shouldAutoExecuteReadOnly(cmds)) {
                        pendingCommands = cmds;
                        confirmRun();
                    } else {
                        showConfirmPanel(resp.message, cmds);
                    }
                }
            } catch (e) {
                appendMessage('assistant', resp.commands || '');
            }
        } else {
            appendMessage('assistant', resp.message || '');
        }
        return resp;
    }).catch((err) => {
        if (thinking) {
            thinking.classList.add('d-none');
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
 * Initialise the AI instructions interface.
 *
 * @param {Object} config  Template data from PHP.
 */
export const init = (config) => {
    currentCmid     = config.cmid || 0;
    currentThreadId = config.threadid || 0;

    if (!config.provider_available) {
        return;
    }

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

    if (confirmBtn) {
        confirmBtn.addEventListener('click', confirmRun);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', hideConfirmPanel);
    }
};
