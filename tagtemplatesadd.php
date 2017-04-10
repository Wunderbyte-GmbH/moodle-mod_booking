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
 * Import options or just add new users from CSV
 *
 * @package Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("lib.php");
require_once("locallib.php");
require_once('tagtemplatesadd_form.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$tagid = optional_param('tagid', '', PARAM_INT);

$url = new moodle_url('/mod/booking/tagtemplatesadd.php', array('id' => $id, 'tagid' => $tagid));
$urlredirect = new moodle_url('/mod/booking/tagtemplates.php', array('id' => $id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("addnewtagtemplate", "booking"));
$PAGE->set_title(format_string(get_string("addnewtagtemplate", "booking")));
$PAGE->set_heading(get_string("addnewtagtemplate", "booking"));
$PAGE->set_pagelayout('standard');

$mform = new tagtemplatesadd_form($url);

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form
    redirect($urlredirect, '', 0);
    die();
} else if ($data = $mform->get_data()) {

    // Add new record
    $tag = new stdClass();
    $tag->id = $data->tagid;
    $tag->courseid = $cm->course;
    $tag->tag = $data->tag;
    $tag->text = $data->text;
    $tag->textformat = FORMAT_HTML;

    if ($tag->id != '') {
        $DB->update_record("booking_tags", $tag);
    } else {
        $DB->insert_record("booking_tags", $tag);
    }

    redirect($urlredirect, get_string('tagsucesfullysaved', 'booking'), 5);
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("addnewtagtemplate", "booking"), 3, 'helptitle', 'uniqueid');

    $defaultvalues = new stdClass();
    if ($tagid != '') {
        $defaultvalues = $DB->get_record('booking_tags', array('id' => $tagid));
        $defaultvalues->tagid = $tagid;
        unset($defaultvalues->id);
        $defaultvalues->text = array('text' => $defaultvalues->text, 'format' => FORMAT_HTML);
    }

    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    // displays the form
    $mform->set_data($defaultvalues);
    $mform->display();
}

echo $OUTPUT->footer();
