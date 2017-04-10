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
require_once("../../config.php");
require_once("lib.php");
require_once($CFG->dirroot . '/tag/lib.php');
if ($CFG->branch < 31) {
    require_once($CFG->dirroot . '/tag/locallib.php');
}
$id = required_param('id', PARAM_INT);
$tagname = optional_param('tag', '', PARAM_TAG);

$url = new moodle_url('/mod/booking/tag.php', array('id' => $id, 'tag' => $tagname));

$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if ($CFG->branch >= 31) {
    $tag = core_tag_tag::get_by_name(0, $tagname);
} else {
    $tag = tag_get('name', $tagname, '*');
}
$PAGE->set_pagelayout('standard');

if ($CFG->branch >= 31) {
    core_tag_tag::make_display_name($tag);
} else {
    $tagname = tag_display_name($tag);
}

$title = get_string('tag', 'tag') . ' - ' . $tagname;

$PAGE->navbar->add($tagname);
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

echo $OUTPUT->header();

echo $OUTPUT->heading($tagname, 2);

$records = $DB->get_records('tag_instance', array('tagid' => $tag->id, 'itemtype' => 'booking'));

echo $OUTPUT->box_start('generalbox', 'tag-blogs'); // could use an id separate from tag-blogs, but would have to copy the css style to make it look
                                                    // the same

echo '<ul>';

foreach ($records as $record) {
    $booking = $DB->get_record('booking', array('id' => $record->itemid, 'course' => $cm->course));
    if ($booking) {
        $cmc = get_coursemodule_from_instance('booking', $booking->id);
        $url = new moodle_url('/mod/booking/view.php', array('id' => $cmc->id));
        echo '<li><a href="' . $url . '">' . $booking->name . '</a></li>';
    }
}
echo '</ul>';

echo $OUTPUT->box_end();

echo $OUTPUT->footer();