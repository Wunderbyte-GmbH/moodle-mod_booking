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
 * Booking category
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");

$id = required_param('id', PARAM_INT);
$categoryid = optional_param('category', '', PARAM_INT);

$url = new moodle_url('/mod/booking/category.php', ['id' => $id, 'category' => $categoryid]);

$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}

if (!$course = $DB->get_record("course", ["id" => $cm->course])) {
    throw new moodle_exception('coursemisconf');
}

require_course_login($course, false, $cm);

$category = $DB->get_record('booking_category', ['id' => $categoryid]);
$PAGE->set_pagelayout('standard');

$PAGE->navbar->add($category->name);
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($category->name);

echo $OUTPUT->header();

echo $OUTPUT->heading($category->name, 2);

$records = $DB->get_records_select('booking', 'categoryid LIKE "%' . $category->id . '%"');

echo $OUTPUT->box_start('generalbox', 'tag-blogs');

echo '<ul>';

foreach ($records as $record) {
    $booking = $DB->get_record('booking', ['id' => $record->id, 'course' => $cm->course]);
    if ($booking) {
        $cmc = get_coursemodule_from_instance('booking', $booking->id);
        $url = new moodle_url('/mod/booking/view.php', ['id' => $cmc->id]);
        echo '<li><a href="' . $url . '">' . $booking->name . '</a></li>';
    }
}
echo '</ul>';

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
