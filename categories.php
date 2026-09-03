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
 * Booking categories
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once("categoriesform.class.php");

$courseid = required_param('courseid', PARAM_INT);

$url = new moodle_url('/mod/booking/categories.php', ['courseid' => $courseid]);
$PAGE->set_url($url);

$context = context_course::instance($courseid);

require_login($courseid, false);

$PAGE->set_pagelayout('standard');

$title = get_string('categoryheader', 'booking');

$PAGE->navbar->add(get_string('addcategory', 'booking'));
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

echo $OUTPUT->header();

echo $OUTPUT->heading(
    get_string('categories', 'booking') . ' ' . get_string('forcourse', 'booking') . ' ' .
    $COURSE->fullname,
    2
);

$addnewlink = html_writer::link(
    new moodle_url('/mod/booking/categoryadd.php', ['courseid' => $courseid]),
    get_string('addnewcategory', 'booking')
);
echo $OUTPUT->box($addnewlink, 'box mdl-align');

echo $OUTPUT->box_start('generalbox', 'tag-blogs');

// Render the whole category tree, starting from the root categories.
$categories = booking_get_category_tree(0, $courseid);
echo $OUTPUT->render_from_template('mod_booking/category_list', [
    'hascategories' => !empty($categories),
    'categories' => $categories,
]);

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
