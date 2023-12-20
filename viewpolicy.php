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
 * Handling view policy page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once("$CFG->dirroot/mod/booking/locallib.php");
$cmid = required_param('id', PARAM_INT);

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
$booking->apply_tags();

$context = context_course::instance($booking->settings->course);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_login($course->id, false);

$PAGE->set_url('/mod/booking/viewpolicy.php', ['id' => $cmid]);
$PAGE->set_title(get_string("bookingpolicy", "booking"));
$PAGE->set_heading(get_string("bookingpolicy", "booking"));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string("bookingpolicy", "booking"), 2);

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); // Could use an id separate from tag-blogs, but looks the same with that id.

echo format_text($booking->settings->bookingpolicy);

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
