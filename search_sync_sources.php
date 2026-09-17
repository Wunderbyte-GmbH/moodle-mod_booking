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
 * AJAX endpoint to lazy-load cohorts/groups for sync rule modal.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$sourcetype = required_param('sourcetype', PARAM_ALPHA);
$query = optional_param('query', '', PARAM_TEXT);

$cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/booking:bookforothers', $context);

if (!in_array($sourcetype, ['cohort', 'group'])) {
    throw new moodle_exception('invalidparameter', 'error');
}

$searchsql = '%' . $DB->sql_like_escape(trim($query)) . '%';
$list = [];

if ($sourcetype === 'cohort') {
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
    $records = $DB->get_records_sql($sql, ['courseid' => $course->id, 'search' => $searchsql], 0, 50);
}

foreach ($records as $record) {
    $list[] = [
        'id' => (int)$record->id,
        'name' => format_string($record->name),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['list' => $list]);
