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
    preview.innerHTML = previewHtml;
    panel.classList.remove('d-none');
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
 */
const showRunStatus = (status, message) => {
    const el = document.getElementById('booking-ai-run-status');
    if (!el) {
        return;
    }
    el.className = 'booking-ai-run-status alert m-3';
    const alertClass = (status === 'completed') ? 'alert-success'
                     : (status === 'failed')    ? 'alert-danger'
                     : 'alert-info';
    el.classList.add(alertClass);
    el.textContent = message || status;
    el.classList.remove('d-none');
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
            if (resp.status === 'completed' || resp.status === 'failed') {
                clearInterval(interval);
                let detail = resp.status;
                try {
                    const results = JSON.parse(resp.resultsjson || '[]');
                    detail = results.map((r) => `[${r.status}] ${r.detail || ''}`).join('\n');
                } catch (e) {
                    // Keep the status string.
                }
                showRunStatus(resp.status, detail);
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

        if (resp.response_type === 'clarification' || resp.response_type === 'error') {
            appendMessage('assistant', resp.message);
        } else if (resp.response_type === 'confirmation_request') {
            appendMessage('assistant', resp.message);
            try {
                const cmds = JSON.parse(resp.commands || '[]');
                if (cmds.length > 0) {
                    showConfirmPanel(resp.message, cmds);
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

    hideConfirmPanel();

    Ajax.call([{
        methodname: 'mod_booking_ai_confirm_run',
        args: {
            cmid:     currentCmid,
            threadid: currentThreadId,
            commands: JSON.stringify(pendingCommands),
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
