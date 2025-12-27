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
 * @module     mod_booking/performance_chart
 */

/* eslint-disable */
define(['core/chartjs', 'core/ajax', 'jquery'], function(Chart, Ajax, $) {

    let chartInstance = null;

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
     * Create a line chart with numeric X axis (timestamps).
     *
     * Dataset format:
     * [
     *   {
     *     label: 'Series A',
     *     data: [
     *       { x: 1704067200, y: 120 },
     *       { x: 1704067200, y: 140 },
     *       { x: 1704153600, y: 130 }
     *     ]
     *   }
     * ]
     */
    const createChart = (canvas, data) => {
        const datasets = (data.datasets || []).map(ds => ({
            label: ds.label,
            data: ds.data,
            borderColor: ds.borderColor || ds.backgroundColor,
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.2,
            pointRadius: 3,
            pointHoverRadius: 5
        }));

        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                parsing: false,
                plugins: {
                    legend: {
                        position: 'right',
                        align: 'start'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        ticks: {
                            callback: function(value) {
                                const date = new Date(value * 1000);
                                return date.toISOString().slice(0, 10);
                            }
                        }
                    },
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

    const updateChart = (data) => {
        if (!chartInstance) {
            return;
        }

        try {
            let datasets = JSON.parse(data.datasetsjson);

            // Normalize datasets to {x, y}
            datasets = datasets.map(ds => {
                if (!Array.isArray(ds.data)) {
                    return ds;
                }

                // If already {x, y}, keep as-is
                if (ds.data.length && typeof ds.data[0] === 'object' && ds.data[0] !== null) {
                    return ds;
                }

                // Otherwise, convert from indexed format
                const normalizedData = ds.data
                    .map((y, index) => {
                        const x = chartInstance.data.datasets[0]?.data[index]?.x;
                        if (x == null || y == null) {
                            return null;
                        }
                        return { x, y };
                    })
                    .filter(p => p !== null);

                return Object.assign({}, ds, { data: normalizedData });
            });

            chartInstance.data.datasets = datasets;
            chartInstance.update();

        } catch (e) {
            console.error('Failed to update chart data:', e);
        }
    };

    const registerSidebarClicks = () => {
        $('.booking-sidebar-item a').on('click', function(e) {
            e.preventDefault();

            const hash = $(this).data('hash');
            if (!hash) {
                return;
            }

            Ajax.call([{
                methodname: 'mod_booking_get_performance_chart',
                args: { value: hash },
                done: function(response) {
                    updateChart(response);
                },
                fail: function(error) {
                    console.error('Error loading chart data', error);
                }
            }]);
        });
    };

    return {
        init,
        updateChart
    };
});