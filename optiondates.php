<?php
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

if (!$booking = booking_get_booking($cm, '', array(), true, null, false)) {
    error("Course module is incorrect");
}

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

$timesTable = array();

foreach ($times as $time) {
    $edit = new moodle_url('optiondatesadd.php',
            array('cmid' => $cm->id, 'boptionid' => $optionid, 'oid' => $time->id));
    $button = $OUTPUT->single_button($edit, get_string('edittag', 'booking'), 'get');
    $delete = new moodle_url('optiondates.php',
            array('id' => $id, 'optionid' => $optionid, 'delete' => $time->id));
    $buttonDelete = $OUTPUT->single_button($delete, get_string('delete', 'booking'), 'get');

    $tmpDate = new stdClass();
    $tmpDate->leftdate = userdate($time->coursestarttime, get_string('leftdate', 'booking'));
    $tmpDate->righttdate = userdate($time->courseendtime, get_string('righttdate', 'booking'));

    $timesTable[] = array(get_string('leftandrightdate', 'booking', $tmpDate),
        html_writer::tag('span', $button . $buttonDelete,
                array('style' => 'text-align: right; display:table-cell;')));
}

$table->data = $timesTable;
echo html_writer::table($table);

$cancel = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
$addnew = new moodle_url('optiondatesadd.php', array('cmid' => $cm->id, 'boptionid' => $optionid));

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
$button = $OUTPUT->single_button($addnew, get_string('addnewoptiondates', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: left; display:table-cell;'));
echo '</div>';
booking_updatestartenddate($optionid);
echo $OUTPUT->footer();
