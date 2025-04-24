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
 * Handling my bookings page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

// No guest autologin.
require_login(0, false);

use mod_booking\mybookings_table;

$url = new moodle_url('/mod/booking/mybookings.php');
$PAGE->set_url($url);

$course = $DB->get_record('course', ['id' => SITEID], '*', MUST_EXIST);

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->navigation->extend_for_user($USER);
$mybookingsurl = new moodle_url('/mod/booking/mybookings.php');
$PAGE->navbar->add(get_string('mybookingoptions', 'mod_booking'), $mybookingsurl);

$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(fullname($USER));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('mybookingoptions', 'mod_booking'));

echo $OUTPUT->box_start();

echo format_text('[mycourselist type="list"]');

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
