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
define(['core/chartjs', 'core/ajax'], function(Chart, Ajax) {

    let listenersRegistered = false;

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
        registerClicks();
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

    /**
     * One delegated click listener for the measurement table and the note editors.
     * Registered once, even if init() runs several times.
     */
    const registerClicks = () => {
        if (listenersRegistered) {
            return;
        }
        listenersRegistered = true;

        document.addEventListener('click', (e) => {
            if (!e.target.closest) {
                return;
            }
            const namecell = e.target.closest('#performancetable tbody tr td.shortcodename');
            if (namecell) {
                e.preventDefault();
                e.stopPropagation();
                loadChart(namecell);
                return;
            }
            const savebutton = e.target.closest('[data-action="savemeasurement"]');
            if (savebutton) {
                e.preventDefault();
                saveMeasurement(savebutton);
                return;
            }
            const deletebutton = e.target.closest('[data-action="deletemeasurement"]');
            if (deletebutton) {
                e.preventDefault();
                deleteMeasurement(deletebutton);
            }
        });
    };

    const loadChart = (namecell) => {
        const row = namecell.closest('tr');
        const hash = row ? row.dataset.id : ''; // The shortcode hash.
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
    };

    const saveMeasurement = (button) => {
        const editor = button.closest('.card-body');
        const textarea = editor ? editor.querySelector('textarea') : null;
        const measurementid = button.dataset.id;
        if (!measurementid) {
            return;
        }

        Ajax.call([{
            methodname: 'mod_booking_save_measurement',
            args: {
                measurementid: measurementid,
                note: textarea ? textarea.value : ''
            }
        }])[0].then(function() {
            // The page is rebuilt from the server, which also closes the editor.
            window.location.reload();
        }).catch(function(error) {
            console.error('Saving measurement failed', error);
        });
    };

    const deleteMeasurement = (button) => {
        const measurementid = button.dataset.id;
        if (!measurementid) {
            return;
        }

        Ajax.call([{
            methodname: 'mod_booking_delete_measurement',
            args: {
                measurementid: measurementid
            }
        }])[0].then(function() {
            window.location.reload();
        }).catch(function(error) {
            console.error('Deleting measurement failed', error);
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