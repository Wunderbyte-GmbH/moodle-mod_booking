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
 * AJAX endpoint to load sync diagnostics HTML lazily.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$limit = optional_param('limit', 30, PARAM_INT);

$cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/booking:updatebooking', $context);

$limit = max(1, min(100, $limit));
$attempts = \mod_booking\local\sync\booking_enrolment::get_recent_attempts_for_option($optionid, $limit);

if (empty($attempts)) {
    $html = html_writer::tag('p', get_string('syncmanagementempty', 'mod_booking'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [
        get_string('time'),
        get_string('syncrulesource', 'mod_booking'),
        get_string('user'),
        get_string('action'),
        get_string('reason', 'mod_booking'),
    ];
    $table->data = [];

    foreach ($attempts as $attempt) {
        $table->data[] = [
            userdate($attempt->timecreated),
            s($attempt->rulesource ?? ('#' . (int)$attempt->syncruleid)),
            fullname($attempt),
            s($attempt->action),
            s($attempt->reasoncode) . (empty($attempt->reasonmessage) ? '' : ': ' . s($attempt->reasonmessage)),
        ];
    }

    $html = html_writer::table($table);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['html' => $html]);
