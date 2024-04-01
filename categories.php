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

// Get root categories.
$categories = $DB->get_records('booking_category', ['course' => $courseid, 'cid' => 0]);

echo $OUTPUT->header();

echo $OUTPUT->heading(
        get_string('categories', 'booking') . ' ' . get_string('forcourse', 'booking') . ' ' .
                 $COURSE->fullname, 2);

$message = "<a href=\"categoryadd.php?courseid=$courseid\">" .
         get_string('addnewcategory', 'booking') . "</a>";
echo $OUTPUT->box($message, 'box mdl-align');

echo $OUTPUT->box_start('generalbox', 'tag-blogs');

echo "<ul>";

foreach ($categories as $category) {
    $editlink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$category->id\">" .
             get_string('editcategory', 'booking') . '</a>';
    $deletelink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$category->id&delete=1\">" .
             get_string('deletecategory', 'booking') . '</a>';
    echo "<li>$category->name - $editlink - $deletelink</li>";
    $subcategories = $DB->get_records('booking_category',
            ['course' => $courseid, 'cid' => $category->id]);
    if (count($subcategories) > 0) {
        echo "<ul>";
        foreach ($subcategories as $subcat) {
            $editlink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$subcat->id\">" .
                     get_string('editcategory', 'booking') . '</a>';
            $deletelink = "<a href=\"categoryadd.php?courseid=$courseid&cid=$subcat->id&delete=1\">" .
                     get_string('deletecategory', 'booking') . '</a>';
            echo "<li>$subcat->name - $editlink - $deletelink</li>";
            booking_show_subcategories($subcat->id, $courseid);
        }
        echo "</ul>";
    }
}

echo "</ul>";

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
