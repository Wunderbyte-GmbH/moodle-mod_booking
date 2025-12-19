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
 * AJAX helper for the inline editing a value.
 *
 * This script is automatically included from template core/inplace_editable
 * It registers a click-listener on [data-inplaceeditablelink] link (the "inplace edit" icon),
 * then replaces the displayed value with an input field. On "Enter" it sends a request
 * to web service core_update_inplace_editable, which invokes the specified callback.
 * Any exception thrown by the web service (or callback) is displayed as an error popup.
 *
 * @module     mod_booking/performance_submit
 * @copyright  2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      3.3
 */

/* eslint-disable */
define(['core/ajax', 'core/notification', 'mod_booking/performance_chart'], function(Ajax, Notification, ChartModule) {

    const init = () => {
        const button = document.getElementById('performance-submit');
        const input = document.getElementById('performance-input');

        if (!button || !input) {
            return;
        }

        button.addEventListener('click', () => {
            const value = input.value.trim();

            if (!value) {
                Notification.alert(
                    'Missing value',
                    'Please select an item first.',
                    'OK'
                );
                return;
            }

            const actions = {};
            const actionInputs = document.querySelectorAll('[name^="actions["]');

            actionInputs.forEach(el => {
                const match = el.name.match(/^actions\[([^\]]+)\]\[([^\]]+)\]$/);
                if (!match) {
                    return;
                }

                const actionId = match[1];
                const field = match[2];

                if (!actions[actionId]) {
                    actions[actionId] = {};
                }

                if (el.type === 'checkbox') {
                    actions[actionId][field] = el.checked ? 1 : 0;
                } else {
                    actions[actionId][field] = el.value;
                }
            });
            Ajax.call([{
                methodname: 'mod_booking_submit_performance',
                args: {
                    value: value,
                    actions: JSON.stringify(actions)
                }
            }])[0]
                .then(response => {
                    if (response.status) {
                        Notification.addNotification({
                            message: 'Request successful',
                            type: 'success'
                        });
                        // Now trigger chart reload with submitted value.
                        Ajax.call([{
                            methodname: 'mod_booking_get_performance_chart',
                            args: { value: response.hashedreceived },
                            done: function(chartdata) {
                                ChartModule.updateChart(chartdata);
                            },
                            fail: function(error) {
                                console.error('Failed to reload chart:', error);
                            }
                        }]);
                    } else {
                        Notification.addNotification({
                            message: response.received + ' Shortcode is not valid',
                            type: 'error'
                        });
                    }
                })
                .catch(Notification.exception);
        });
    };

    return { init };
});