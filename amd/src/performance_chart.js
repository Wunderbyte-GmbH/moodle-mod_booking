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
        registerSaveClicks();
        registerDeleteClicks();
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
        const labels = data.labelsjson || '[]';

        const notes = data.notesjson || '[]';

        const rawdatasets = data.datasetsjson || '[]';

        const datasets = rawdatasets.map(ds => ({
            label: ds.label,
            data: ds.data, // y-array aligned to labels
            borderColor: ds.borderColor || ds.backgroundColor,
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.2,
            pointRadius: 3,
            pointHoverRadius: 5,
            spanGaps: false
        }));

        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // IMPORTANT: remove parsing:false (it can interfere depending on Chart.js wrapper/settings)
                plugins: {
                    legend: { position: 'right', align: 'start' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y + ' (' + ctx.label + ')';
                            },
                            afterBody: function(items) {
                                // Show note for the hovered x-index (run index).
                                const idx = items && items.length ? items[0].dataIndex : undefined;
                                if (idx === undefined) {
                                    return [];
                                }

                                const note = (notes[idx] || '').toString().trim();
                                return note ? ['Note: ' + note] : [];
                            }
                        }
                    }
                },
                scales: {
                    x: { type: 'category' }, // equal spacing
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Time (ms)' }
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
            const labels = JSON.parse(data.labelsjson || '[]');
            const datasets = JSON.parse(data.datasetsjson);
            // Normalize datasets to {x, y}
            chartInstance.data.labels = labels;
            chartInstance.data.datasets = datasets.map(ds => ({
                label: ds.label,
                data: ds.data,
                borderColor: ds.borderColor || ds.backgroundColor,
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.2,
                pointRadius: 3,
                pointHoverRadius: 5,
                spanGaps: false
            }));

            chartInstance.update();
            updateShortcodeName(data);
        } catch (e) {
            console.error('Failed to update chart data:', e);
        }
    };

    const registerSidebarClicks = () => {
        // Prevent double-binding if init() runs multiple times.
        $(document).off('click', '#performancetable tbody tr td.shortcodename');

        $(document).on('click', '#performancetable tbody tr td.shortcodename', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $tr = $(this).closest('tr');
            const hash = $tr.data('id') || $tr.attr('data-id'); // your shortcodehash

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

    const registerSaveClicks = () => {
        $(document).on('click', '[data-action="savemeasurement"]', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const $editor = $btn.closest('.card-body');

            const measurementid = $btn.data('id');
            const note = $editor.find('textarea').val();

            if (!measurementid) {
                return;
            }

            Ajax.call([{
                methodname: 'mod_booking_save_measurement',
                args: {
                    measurementid: measurementid,
                    note: note
                }
            }])[0].then(function(response) {
                // Optional UX feedback
                $editor.closest('.collapse').collapse('hide');

                // Optional: visual success hint
                $btn.blur();
                window.location.reload();
            }).catch(function(error) {
                console.error('Saving measurement failed', error);
            });
        });
    };

    const registerDeleteClicks = () => {
        $(document).on('click', '[data-action="deletemeasurement"]', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const $editor = $btn.closest('.card-body');
            const measurementid = $btn.data('id');

            if (!measurementid) {
                return;
            }

            Ajax.call([{
                methodname: 'mod_booking_delete_measurement',
                args: {
                    measurementid: measurementid
                }
            }])[0].then(function(response) {
                // Optional UX feedback
                $editor.closest('.collapse').collapse('hide');

                // Optional: visual success hint
                $btn.blur();
                window.location.reload();
            }).catch(function(error) {
                console.error('Deleting measurement failed', error);
            });
        });
    };

    const updateShortcodeName = (data) => {
        const valueEl = document.getElementById('performance-shortcodename');
        if (!valueEl) {
            return;
        }

        let sc = data.shortcodename ?? '';

        if (typeof sc === 'string') {
            const trimmed = sc.trim();
            if ((trimmed.startsWith('[') && trimmed.endsWith(']')) ||
                (trimmed.startsWith('"') && trimmed.endsWith('"'))) {
                try {
                    sc = JSON.parse(trimmed);
                } catch (e) {
                    // keep as-is
                }
            }
        }
        if (Array.isArray(sc)) {
            sc = sc[0] ?? '';
        }

        sc = (sc ?? '').toString();

        valueEl.textContent = sc;

        const wrapper = document.getElementById('performance-shortcodename-wrapper');
        if (wrapper) {
            wrapper.classList.toggle('d-none', !sc);
        }
    };

    return {
        init,
        updateChart
    };
});