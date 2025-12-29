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
        $history = [];

        // Build history grouped by starttime.
        foreach ($records as $record) {
            $starttime = (int)$record->starttime;

            $timecreated = (int) floor($starttime / 60000000) * 60;

            if (!isset($history[$timecreated])) {
                $history[$timecreated] = [
                    'timecreated' => $timecreated, // Transform to normal unixtimestamp.
                    'measurements' => [],
                ];
            }

            if (!empty($record->measurementname)) {
                $legend[$record->measurementname] = $record->measurementname;

                if (!empty($record->endtime) && $record->endtime > 0) {
                    $history[$timecreated]['measurements'][$record->measurementname] =
                        (int)$record->endtime + 1 - (int)$record->starttime;
                } else {
                    $history[$timecreated]['measurements'][$record->measurementname] = null;
                }
            }
        }

        /*
        * Normalize history order.
        */
        ksort($history);
        $history = array_values($history);

        // Build datasets (one per measurement name).
        // We will now emit {x, y} points instead of index-based arrays.
        $datasets = [];

        foreach ($legend as $key => $label) {
            $datasets[$key] = [
                'label' => $label,
                'data' => [],
                'borderColor' => '#' . substr(md5($key), 0, 6),
                'backgroundColor' => 'transparent',
            ];
        }

        // Fill datasets with {x, y} points.
        // Timecreated MUST be a unix timestamp in SECONDS (minute-bucketed).
        foreach ($history as $entry) {
            $x = (int) $entry['timecreated']; // already minute-aligned

            foreach ($legend as $key => $unused) {
                if (!array_key_exists($key, $entry['measurements'])) {
                    continue;
                }

                $y = $entry['measurements'][$key];

                if ($y === null) {
                    continue; // important for line charts.
                }

                $datasets[$key]['data'][] = [
                    'x' => $x,
                    'y' => $y,
                ];
            }
        }

        // Return ONLY datasetsjson.
        return [
            'labelsjson'   => json_encode([]), // required by external API
            'datasetsjson' => json_encode(array_values($datasets)),
        ];
    }
}
