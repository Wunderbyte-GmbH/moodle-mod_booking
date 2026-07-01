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

namespace mod_booking\external;

use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Search cohorts or groups for sync rule modal source selector.
 *
 * @package    mod_booking
 * @category   external
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_sync_sources extends external_api {
    /**
     * Parameter schema.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search query', VALUE_REQUIRED),
            'sourcetype' => new external_value(PARAM_ALPHA, 'Source type: cohort or group', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Execute search.
     *
     * @param string $query Query.
     * @param string $sourcetype Source type.
     * @param int $cmid Course module id.
     * @return array
     */
    public static function execute(string $query, string $sourcetype, int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'sourcetype' => $sourcetype,
            'cmid' => $cmid,
        ]);

        if (!in_array($params['sourcetype'], ['cohort', 'group'])) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $cm = get_coursemodule_from_id('booking', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/booking:bookforothers', $context);

        $searchsql = '%' . $DB->sql_like_escape(trim($params['query'])) . '%';
        $results = [];

        if ($params['sourcetype'] === 'cohort') {
            $sql = "SELECT c.id, c.name
                      FROM {cohort} c
                     WHERE " . $DB->sql_like('c.name', ':search', false) . "
                  ORDER BY c.name ASC";
            $records = $DB->get_records_sql($sql, ['search' => $searchsql], 0, 50);
        } else {
            $sql = "SELECT g.id, g.name
                      FROM {groups} g
                     WHERE g.courseid = :courseid
                       AND " . $DB->sql_like('g.name', ':search', false) . "
                  ORDER BY g.name ASC";
            $records = $DB->get_records_sql($sql, ['courseid' => $cm->course, 'search' => $searchsql], 0, 50);
        }

        foreach ($records as $record) {
            $results[] = [
                'id' => (int)$record->id,
                'name' => format_string($record->name),
            ];
        }

        return [
            'list' => $results,
            'warnings' => '',
        ];
    }

    /**
     * Return schema.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'list' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'name' => new external_value(PARAM_TEXT, 'Display name'),
                ])
            ),
            'warnings' => new external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
