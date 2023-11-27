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
 * Rating REST web service
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

if (!defined('MOD_BOOKING_RATING_AJAX_SCRIPT')) {
    define('MOD_BOOKING_RATING_AJAX_SCRIPT', true);
}

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$value = required_param('value', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

echo $OUTPUT->header();

$record = new stdClass();
$record->userid = $USER->id;
$record->optionid = $optionid;
$record->rate = $value;

$isinserted = false;

$bookingdata = singleton_service::get_instance_of_booking_option($cm->id, $optionid);

try {
    if ($bookingdata->can_rate()) {
        $DB->insert_record('booking_ratings', $record, false, false);
    }
} catch (Exception $e) {
    // I don't allow duplicates.
    $isinserted = true;
}

$avg = $DB->get_record_sql(
        'SELECT IFNULL(AVG(rate), 1) AS rate
        FROM {booking_ratings}
        WHERE optionid = ?', [$optionid]);

echo json_encode(['rate' => (int) $avg->rate, 'duplicate' => $isinserted]);
