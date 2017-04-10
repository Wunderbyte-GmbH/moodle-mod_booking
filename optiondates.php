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
 * Add dates to option.
 *
 * @package Booking
 * @copyright 2016 Andraž Prinčič www.princic.net
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT); // Course Module ID
$optionid = required_param('optionid', PARAM_INT); // Option ID
$delete = optional_param('delete', '', PARAM_INT);

$url = new moodle_url('/mod/booking/optiondates.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

if ($delete != '') {
    $DB->delete_records("booking_optiondates", array('optionid' => $optionid, 'id' => $delete));

    booking_updatestartenddate($optionid);

    redirect($url, get_string('optiondatessucesfullydelete', 'booking'), 5);
}

$PAGE->navbar->add(get_string("optiondates", "booking"));
$PAGE->set_title(format_string(get_string("optiondates", "booking")));
$PAGE->set_heading(get_string("optiondates", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("optiondates", "booking"), 3, 'helptitle', 'uniqueid');

$table = new html_table();
$table->head = array(get_string('optiondatestime', 'booking'), '');

$times = $DB->get_records('booking_optiondates', array('optionid' => $optionid),
        'coursestarttime ASC');

$timestable = array();

foreach ($times as $time) {
    $edit = new moodle_url('optiondatesadd.php',
            array('id' => $cm->id, 'boptionid' => $optionid, 'optiondateid' => $time->id));
    $button = $OUTPUT->single_button($edit, get_string('edittag', 'booking'), 'get');
    $delete = new moodle_url('optiondates.php',
            array('id' => $id, 'optionid' => $optionid, 'delete' => $time->id));
    $buttondelete = $OUTPUT->single_button($delete, get_string('delete', 'booking'), 'get');

    $tmpdate = new stdClass();
    $tmpdate->leftdate = userdate($time->coursestarttime, get_string('leftdate', 'booking'));
    $tmpdate->righttdate = userdate($time->courseendtime, get_string('righttdate', 'booking'));

    $timestable[] = array(get_string('leftandrightdate', 'booking', $tmpdate),
        html_writer::tag('span', $button . $buttondelete,
                array('style' => 'text-align: right; display:table-cell;')));
}

$table->data = $timestable;
echo html_writer::table($table);

$cancel = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
$addnew = new moodle_url('optiondatesadd.php', array('id' => $cm->id, 'boptionid' => $optionid));

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
$button = $OUTPUT->single_button($addnew, get_string('addnewoptiondates', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: left; display:table-cell;'));
echo '</div>';
booking_updatestartenddate($optionid);
echo $OUTPUT->footer();
