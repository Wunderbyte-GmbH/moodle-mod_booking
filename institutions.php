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
require_once(__DIR__ . '/../../config.php');
require_once("lib.php");

$courseid = required_param('courseid', PARAM_INT);

$url = new moodle_url('/mod/booking/institutions.php', array('courseid' => $courseid));
$PAGE->set_url($url);

$context = context_course::instance($courseid);

if (!$course = $DB->get_record("course", array("id" => $courseid))) {
    print_error('coursemisconf');
}

require_login($courseid, false);

$PAGE->set_pagelayout('standard');

$title = get_string('institutions', 'booking');

$PAGE->navbar->add(get_string('addnewinstitution', 'booking'));
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

$institutions = $DB->get_records('booking_institutions', array('course' => $courseid));

echo $OUTPUT->header();

echo $OUTPUT->heading(
        get_string('institutions', 'booking') . ' ' . get_string('forcourse', 'booking') . ' ' .
                 $COURSE->fullname, 2);

$message = "<a href=\"institutionadd.php?courseid=$courseid\">" .
         get_string('addnewinstitution', 'booking') . "</a>";
$import = "<a href=\"institutioncsv.php?courseid=$courseid\">" .
         get_string('importcsvtitle', 'booking') . "</a>";
echo $OUTPUT->box("{$message} | {$import}", 'box mdl-align');

echo $OUTPUT->box_start('generalbox', 'tag-blogs');

echo "<ul>";

foreach ($institutions as $institution) {
    $editlink = "<a href=\"institutionadd.php?courseid=$courseid&cid=$institution->id\">" .
             get_string('editcategory', 'booking') . '</a>';
    $deletelink = "<a href=\"institutionadd.php?courseid=$courseid&cid=$institution->id&delete=1\">" .
             get_string('deletecategory', 'booking') . '</a>';
    echo "<li>$institution->name - $editlink - $deletelink</li>";
}

echo "</ul>";

echo $OUTPUT->box_end();

echo $OUTPUT->footer();