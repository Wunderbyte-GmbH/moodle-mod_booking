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
 * Handling tag page of the booking module
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");
require_once($CFG->dirroot . '/tag/lib.php');
$id = required_param('id', PARAM_INT);
$tagname = optional_param('tag', '', PARAM_TAG);

$url = new moodle_url('/mod/booking/tag.php', ['id' => $id, 'tag' => $tagname]);

$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$tag = core_tag_tag::get_by_name(0, $tagname);
$PAGE->set_pagelayout('standard');
core_tag_tag::make_display_name($tag);
$title = get_string('tag', 'tag') . ' - ' . $tagname;

$PAGE->navbar->add($tagname);
$PAGE->set_heading($COURSE->fullname);

$PAGE->set_title($title);

echo $OUTPUT->header();

echo $OUTPUT->heading($tagname, 2);

$records = $DB->get_records('tag_instance', ['tagid' => $tag->id, 'itemtype' => 'booking']);

echo $OUTPUT->box_start('generalbox', 'tag-blogs');

echo '<ul>';

foreach ($records as $record) {
    $booking = $DB->get_record('booking', ['id' => $record->itemid, 'course' => $cm->course]);
    if ($booking) {
        $cmc = get_coursemodule_from_instance('booking', $booking->id);
        $url = new moodle_url('/mod/booking/view.php', ['id' => $cmc->id]);
        echo '<li><a href="' . $url . '">' . $booking->name . '</a></li>';
    }
}
echo '</ul>';

echo $OUTPUT->box_end();

echo $OUTPUT->footer();
