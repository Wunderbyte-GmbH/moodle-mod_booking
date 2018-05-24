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
require_once('optiondatesadd_form.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$delete = optional_param('delete', '', PARAM_INT);
$duplicate = optional_param('duplicate', '', PARAM_INT);
$edit = optional_param('edit', '', PARAM_INT);
$url = new moodle_url('/mod/booking/optiondates.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}
// Check if optionid is valid.
$optionid = $DB->get_field('booking_options', 'id',
        array('id' => $optionid, 'bookingid' => $cm->instance), MUST_EXIST);

require_capability('mod/booking:updatebooking', $context);

if ($delete != '') {
    $DB->delete_records("booking_optiondates", array('optionid' => $optionid, 'id' => $delete));
    booking_updatestartenddate($optionid);
    redirect($url, get_string('optiondatessuccessfullydelete', 'booking'), 5);
}
if ($duplicate != '') {
    $record = $DB->get_record("booking_optiondates",
            array('optionid' => $optionid, 'id' => $duplicate),
            'bookingid, optionid, coursestarttime, courseendtime');
    $edit = $DB->insert_record("booking_optiondates", $record);
    booking_updatestartenddate($optionid);
}

$mform = new optiondatesadd_form($url, array('optiondateid' => $edit));

if ($mform->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form.
    redirect($url, '', 0);
    die();
} else if ($data = $mform->get_data()) {

    $optiondate = new stdClass();
    $optiondate->id = $data->optiondateid;
    $optiondate->bookingid = $cm->instance;
    $optiondate->optionid = $optionid;
    $optiondate->coursestarttime = $data->coursestarttime;
    $date = date("Y-m-d", $data->coursestarttime);
    $optiondate->courseendtime = strtotime($date . " {$data->endhour}:{$data->endminute}");
    if ($optiondate->id != '') {
        $DB->update_record("booking_optiondates", $optiondate);
    } else {
        $DB->insert_record("booking_optiondates", $optiondate);
    }

    booking_updatestartenddate($optionid);
    redirect($url, get_string('optiondatessuccessfullysaved', 'booking'), 5);
} else {
    $PAGE->navbar->add(get_string('optiondates', 'mod_booking'));
    $PAGE->set_title(format_string(get_string('optiondates', 'mod_booking')));
    $PAGE->set_heading(get_string('optiondates', 'mod_booking'));
    $PAGE->set_pagelayout('standard');

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('optiondates', 'mod_booking'), 3, 'helptitle', 'uniqueid');

    $table = new html_table();
    $table->head = array(get_string('optiondatestime', 'mod_booking'), '');

    $times = $DB->get_records('booking_optiondates', array('optionid' => $optionid),
            'coursestarttime ASC');

    $timestable = array();

    foreach ($times as $time) {
        $editing = '';
        if ($edit == $time->id) {
            $button = html_writer::tag('span', get_string('editingoptiondate', 'mod_booking'),
                    array('class' => 'p-x-2'));
            $editing = 'alert alert-success';
        } else {
            $editurl = new moodle_url('optiondates.php',
                    array('id' => $cm->id, 'optionid' => $optionid, 'edit' => $time->id));
            $button = $OUTPUT->single_button($editurl, get_string('edittag', 'mod_booking'), 'get');
        }
        $delete = new moodle_url('optiondates.php',
                array('id' => $id, 'optionid' => $optionid, 'delete' => $time->id));
        $buttondelete = $OUTPUT->single_button($delete, get_string('delete'), 'get');
        $duplicate = new moodle_url('optiondates.php',
                array('id' => $id, 'optionid' => $optionid, 'duplicate' => $time->id));
        $buttonduplicate = $OUTPUT->single_button($duplicate, get_string('duplicate'), 'get');

        $tmpdate = new stdClass();
        $tmpdate->leftdate = userdate($time->coursestarttime,
                get_string('strftimedatetime', 'langconfig'));
        $tmpdate->righttdate = userdate($time->courseendtime,
                get_string('strftimetime', 'langconfig'));

        $timestable[] = array(get_string('leftandrightdate', 'booking', $tmpdate),
            html_writer::tag('span', $button . $buttondelete . $buttonduplicate,
                    array('style' => 'text-align: right; display:table-cell;', 'class' => $editing)));
    }
    $table->data = $timestable;
    echo html_writer::table($table);

    $cancel = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
    $defaultvalues = new stdClass();
    if ($edit != '') {
        $defaultvalues = $DB->get_record('booking_optiondates', array('id' => $edit), '*',
                MUST_EXIST);
        // The id in the form will be course module id, not the optiondate id.
        $defaultvalues->optiondateid = $defaultvalues->id;
        $defaultvalues->optionid = $optionid;
        $defaultvalues->endhour = date('H', $defaultvalues->courseendtime);
        $defaultvalues->endminute = date('i', $defaultvalues->courseendtime);
        $defaultvalues->id = $cm->id;
    }
    $mform->set_data($defaultvalues);
    $mform->display();
}

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('back'), 'get');
echo html_writer::tag('span', $button, array('style' => 'display:table-cell;'));
echo '</div>';
echo $OUTPUT->footer();
