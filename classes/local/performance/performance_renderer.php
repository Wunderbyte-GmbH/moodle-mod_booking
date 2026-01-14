<?php
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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\performance;

use mod_booking\local\performance\table\peformance_table;
use mod_booking\local\performance\table\performance_table;

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class performance_renderer {
    /**
     * @var string $hash
     */
    const TABLE = 'booking_performance_measurements';

    /**
     * Returns sidebar.
     * @return array
     */
    public function get_sidebar(): array {
        global $DB;

        $from = " (SELECT DISTINCT shortcodehash, shortcodename
              FROM {" . self::TABLE . "}
             ORDER BY shortcodename) as s1
        ";

        $table = new performance_table('performancetable');

        $table->define_columns(['shortcodename', 'actions']);
        $table->sortablecolumns = ['shortcodename'];
        $table->sort_default_column = 'shortcodename';

        $table->set_filter_sql('*', $from, ' 1 = 1 ', '', []);

        $html = $table->outhtml(10, true);
        return [
            'sidebar' => $html,
            'autocompleteitems' => [],
        ];
    }

    /**
     * Returns sidebar.
     * @return array
     */
    public function get_chart($hash): array {
        global $DB;

        $records = $DB->get_records(
            self::TABLE,
            ['shortcodehash' => $hash],
            'starttime ASC'
        );

        if (empty($records)) {
            return [
                'labelsjson' => json_encode([]),
                'datasetsjson' => json_encode([]),
            ];
        }

        $legend = [];
        $runs = [];
        $history = [];

        // Build history grouped by starttime.
        $runs = $this->build_measurement_runs($records, $legend);

        if (empty($runs)) {
            return [
                'labelsjson' => json_encode([]),
                'datasetsjson' => json_encode([]),
            ];
        }

        // Assign all sub-measurements.
        $this->assign_measurements_to_runs($records, $legend, $runs);

        // 3) Convert runs to history (ordered already).
        $history = array_map(function ($run) {
            return [
                'timecreated' => $run['timecreated'],
                'measurements' => $run['measurements'],
            ];
        }, $runs);

        // Create labels (one per run).
        $labels = array_map(function ($entry) {
            return userdate((int)$entry['timecreated'], '%Y-%m-%d %H:%M:%S');
        }, $history);

        // Build datasets aligned to run index.
        $datasets = $this->build_datasets($legend, $history);

        return [
            'labelsjson'   => json_encode($labels),
            'datasetsjson' => json_encode(array_values($datasets)),
        ];
    }

    /**
     * Builds the run-time skeletton.
     * @param array $records
     * @param array $legend
     * @return array
     */
    private function build_measurement_runs($records, &$legend): array {
        foreach ($records as $record) {
            $name = trim((string)$record->measurementname);
            if ($name !== 'Entire time') {
                continue;
            }

            $startus = (int)$record->starttime;
            $endus   = (int)$record->endtime;

            // If endtime is missing, we can't build a reliable interval -> skip.
            if ($endus <= 0 || $endus < $startus) {
                continue;
            }

            $legend[$name] = $name;

            $runs[] = [
                'start' => $startus,
                'end' => $endus,
                'timecreated' => (int)floor($startus / 1000000),
                'measurements' => [
                    $name => $endus + 1 - $startus,
                ],
            ];
        }
        return $runs;
    }

    /**
     * Assign all other measurements to the run interval that contains them.
     * @param array $records
     * @param array $legend
     * @param array $runs
     * @return void
     */
    private function assign_measurements_to_runs($records, &$legend, &$runs): void {
        $runindex = 0;

        foreach ($records as $record) {
            $name = trim((string)$record->measurementname);
            if ($name === '' || $name === 'Entire time') {
                continue;
            }

            $startus = (int)$record->starttime;
            $endus   = (int)$record->endtime;

            // If the sub measurement has no end, we can't place it reliably -> skip (or set null).
            if ($endus <= 0 || $endus < $startus) {
                continue;
            }

            $legend[$name] = $name;

            // Move run pointer forward until the current run could contain this measurement.
            while ($runindex < count($runs) && $startus > $runs[$runindex]['end']) {
                $runindex++;
            }
            if ($runindex >= count($runs)) {
                break; // No more runs can contain anything.
            }

            // Check containment in current run.
            if ($startus >= $runs[$runindex]['start'] && $endus <= $runs[$runindex]['end']) {
                $runs[$runindex]['measurements'][$name] = $endus + 1 - $startus;
            }
        }
        return;
    }

    /**
     * Builds dataset for chart representation.
     * @param array $legend
     * @param array $runs
     * @return array
     */
    private function build_datasets($legend, $history): array {
        $datasets = [];
        foreach ($legend as $key => $label) {
            $datasets[$key] = [
                'label' => $label,
                'data' => array_fill(0, count($history), null),
                'borderColor' => '#' . substr(md5($key), 0, 6),
                'backgroundColor' => 'transparent',
            ];
        }

        foreach ($history as $i => $entry) {
            foreach ($legend as $key => $unused) {
                if (array_key_exists($key, $entry['measurements'])) {
                    $datasets[$key]['data'][$i] = $entry['measurements'][$key];
                }
            }
        }
        return $datasets;
    }
}
