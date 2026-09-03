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
 * Provides lazy source lookup for sync rule modal.
 *
 * @module      mod_booking/form_sync_source_selector
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/config', 'jquery'], function(Config, $) {
    return {
        /**
         * Load source options from server.
         *
         * @param {String} selector The selector id for the autocomplete element.
         * @param {String} query The query string.
         * @param {Function} callback Success callback.
         * @param {Function} failure Failure callback.
         */
        transport: async function(selector, query, callback, failure) {
            const el = $(selector);
            const sourcetype = String(el.closest('form').find('[name="sourcetype"]').val() || 'cohort');
            const cmid = parseInt(el.data('cmid') || 0, 10);

            try {
                const body = new URLSearchParams();
                body.append('query', query);
                body.append('sourcetype', sourcetype);
                body.append('cmid', cmid);
                body.append('sesskey', Config.sesskey);

                const response = await fetch(Config.wwwroot + '/mod/booking/search_sync_sources.php', {
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
                } catch (jsonError) {
                    throw new Error('Failed to decode sync source response');
                }

                if (!response.ok || !data || data.error) {
                    throw new Error((data && data.error) ? data.error : 'Failed to load sync sources');
                }

                const list = Array.isArray(data.list) ? data.list : [];
                callback(list);
            } catch (e) {
                failure(e);
            }
        },

        /**
         * Process server response for autocomplete.
         *
         * @param {String} selector The selector id.
         * @param {Array} results Array returned by transport.
         * @return {Array}
         */
        processResults: function(selector, results) {
            if (!Array.isArray(results)) {
                return [];
            }
            return results.map((result) => ({value: result.id, label: result.name}));
        }
    };
});
