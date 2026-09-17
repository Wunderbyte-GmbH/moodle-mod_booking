// This file is part of Moodle - https://moodle.org/
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
 * Lazy-load sync diagnostics table content.
 *
 * @module      mod_booking/sync_diagnostics
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/config'], function(Config) {
    return {
        /**
         * Initialize lazy diagnostics loading.
         *
         * @param {String} triggerSelector Trigger button selector.
         * @param {String} contentSelector Content container selector.
         * @param {Number} cmid Course module id.
         * @param {Number} optionid Booking option id.
         * @param {Number} limit Number of diagnostics rows.
         * @param {String} loadingText Loading text.
         * @param {String} errorText Error text.
         */
        init: function(triggerSelector, contentSelector, cmid, optionid, limit, loadingText, errorText) {
            const trigger = document.querySelector(triggerSelector);
            const content = document.querySelector(contentSelector);

            if (!trigger || !content) {
                return;
            }

            let loaded = false;
            let loading = false;

            const loadDiagnostics = async function() {
                if (loaded || loading) {
                    return;
                }
                loading = true;
                content.textContent = loadingText || 'Loading...';

                try {
                    const body = new URLSearchParams();
                    body.append('cmid', String(cmid));
                    body.append('optionid', String(optionid));
                    body.append('limit', String(limit || 30));
                    body.append('sesskey', Config.sesskey);

                    const response = await fetch(Config.wwwroot + '/mod/booking/sync_diagnostics.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString()
                    });

                    const raw = await response.text();
                    let data = null;
                    try {
                        data = JSON.parse(raw);
                    } catch (jsonerror) {
                        throw new Error('Invalid diagnostics response');
                    }

                    if (!response.ok || !data || typeof data.html !== 'string') {
                        throw new Error('Could not load diagnostics');
                    }

                    content.innerHTML = data.html;
                    loaded = true;
                } catch (e) {
                    content.textContent = errorText || 'Error loading diagnostics';
                } finally {
                    loading = false;
                }
            };

            trigger.addEventListener('click', loadDiagnostics, {once: true});
        }
    };
});
