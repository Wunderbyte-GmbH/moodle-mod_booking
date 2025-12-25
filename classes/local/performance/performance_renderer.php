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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

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

        $sql = "
            SELECT shortcodehash, shortcodename
              FROM {" . self::TABLE . "}
             ORDER BY shortcodename
        ";

        $records = $DB->get_records_sql($sql);

        $sidebar = [];

        foreach ($records as $record) {
            $sidebar[] = [
                'hash' => $record->shortcodehash,
                'name' => $record->shortcodename,
            ];
        }

        $autocompleteitems = array_map(function ($entry) {
            return $entry['name'];
        }, $sidebar);


        return [
            'sidebar' => $sidebar,
            'autocompleteitems' => $autocompleteitems,
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

        /*
        * 1. Build history grouped by starttime
        */
        foreach ($records as $record) {
            $starttime = (int)$record->starttime;

            if (!isset($history[$starttime])) {
                $history[$starttime] = [
                    'timecreated' => (int)($starttime / 1000000), // Transform to normal unixtimestamp.
                    'measurements' => [],
                ];
            }

            if (!empty($record->measurementname)) {
                $legend[$record->measurementname] = $record->measurementname;

                if (!empty($record->endtime) && $record->endtime > 0) {
                    $history[$starttime]['measurements'][$record->measurementname] =
                        (int)$record->endtime + 1 - (int)$record->starttime;
                } else {
                    $history[$starttime]['measurements'][$record->measurementname] = null;
                }
            }
        }

        /*
        * 2. Normalize history order
        */
        ksort($history);
        $history = array_values($history);

        /*
        * 3. Build labels
        */
        $labels = array_map(
            fn($entry) => date('Y-m-d H:i', $entry['timecreated']),
            $history
        );

        /*
        * 4. Build datasets (one per measurementname)
        */
        $datasets = [];

        foreach ($legend as $key => $label) {
            $datasets[$key] = [
                'label' => $label,
                'data' => [],
                'backgroundColor' => '#' . substr(md5($key), 0, 6),
            ];
        }

        foreach ($history as $entry) {
            foreach ($legend as $key => $a) {
                $datasets[$key]['data'][] =
                    $entry['measurements'][$key] ?? null;
            }
        }

        return [
            'labelsjson' => json_encode($labels),
            'datasetsjson' => json_encode(array_values($datasets)),
        ];
    }
}
