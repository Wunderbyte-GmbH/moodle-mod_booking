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
 * @module     mod_booking/performance_chart
 * @copyright  2018 David Bogner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      3.3
 */

/* eslint-disable */
define(['core/chartjs', 'core/ajax', 'jquery'], function(Chart, Ajax, $) {

    let chartInstance = null;

    /**
     * Initialize the chart from the DOM.
     * @param {string} canvasId The canvas element ID.
     * @param {string} dataScriptId The script tag with JSON data.
     */
    const init = (canvasId, dataScriptId) => {
        const canvas = document.getElementById(canvasId);
        const dataNode = document.getElementById(dataScriptId);

        if (!canvas || !dataNode) {
            return;
        }

        let parsed;
        try {
            parsed = JSON.parse(dataNode.textContent);
        } catch (e) {
            console.error('Invalid chart data JSON:', e);
            return;
        }

        chartInstance = createChart(canvas, parsed);
        registerSidebarClicks();
    };

    /**
     * Create a chart.js chart instance.
     * @param {HTMLCanvasElement} canvas The canvas to draw on.
     * @param {Object} data JSON data with labels and datasets.
     * @returns {Chart} The chart instance.
     */
    const createChart = (canvas, data) => {
        const labels = data.labels || [];
        const datasets = (data.datasets || []).map(ds => ({
            label: ds.label,
            data: ds.data,
            backgroundColor: ds.backgroundColor,
        }));

        return new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        align: 'start',
                        labels: {
                            boxWidth: 16,
                            padding: 12
                        }
                    },
                    tooltip: { enabled: true }
                },
                scales: {
                    x: { title: { display: false } },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Time (ms)'
                        }
                    }
                }
            }
        });
    };

    /**
     * Update the chart with new data (from AJAX).
     * @param {Object} data JSON object with new labels and datasets.
     */
    const updateChart = (data) => {
        if (!chartInstance) {
            console.warn('Chart not initialized yet');
            return;
        }

        try {
            const labels = JSON.parse(data.labelsjson);
            const datasets = JSON.parse(data.datasetsjson);

            chartInstance.data.labels = labels;
            chartInstance.data.datasets = datasets;
            chartInstance.update();
        } catch (e) {
            console.error('Failed to parse new chart data:', e);
        }
    };

    /**
     * Hook up sidebar clicks to AJAX chart updates.
     */
    const registerSidebarClicks = () => {
        $('.booking-sidebar-item a').on('click', function(e) {
            e.preventDefault();

            const hash = $(this).data('hash');
            if (!hash) return;

            Ajax.call([{
                methodname: 'mod_booking_get_performance_chart',
                args: { value: hash },
                done: function(response) {
                    updateChart(response);
                },
                fail: function(error) {
                    console.error('Error loading chart data via AJAX', error);
                }
            }]);
        });
    };


    return {
        init,
        updateChart
    };
});