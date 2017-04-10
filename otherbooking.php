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
require_once("locallib.php");

$id = required_param('id', PARAM_INT); // Course Module ID
$optionid = required_param('optionid', PARAM_INT);

$url = new moodle_url('/mod/booking/otherbooking.php', array('id' => $id, 'optionid' => $optionid));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$option = new \mod_booking\booking_option($id, $optionid);

$PAGE->navbar->add(get_string("editotherbooking", "booking"));
$PAGE->set_title(format_string(get_string("editotherbooking", "booking")));
$PAGE->set_heading(get_string("editotherbooking", "booking"));
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("editotherbooking", "booking") . " [{$option->option->text}]", 3,
        'helptitle', 'uniqueid');

echo html_writer::link(
        new moodle_url('/mod/booking/report.php', array('id' => $cm->id, 'optionid' => $optionid)),
        get_string('users', 'booking'), array('style' => 'float:right;'));
echo '<br>';

$table = new html_table();
$table->head = array(
    (empty($option->booking->lblacceptingfrom) ? get_string('otherbookingoptions', 'booking') : $option->booking->lblacceptingfrom),
    (empty($option->booking->lblnumofusers) ? get_string('otherbookingnumber', 'booking') : $option->booking->lblnumofusers));

$rules = $DB->get_records_sql(
        "SELECT bo.id, bo.otheroptionid, bo.userslimit, b.text
        FROM {booking_other} bo
        LEFT JOIN {booking_options} b ON b.id = bo.otheroptionid
        WHERE bo.optionid = ?", array($optionid));

$rulestable = array();

foreach ($rules as $rule) {

    $edit = new moodle_url('otherbookingaddrule.php',
            array('id' => $cm->id, 'optionid' => $optionid, 'bookingotherid' => $rule->id));
    $delete = new moodle_url('otherbookingaddrule.php',
            array('id' => $cm->id, 'optionid' => $optionid, 'bookingotherid' => $rule->id,
                'delete' => 1));

    $button = '<div style="width: 100%; text-align: right; display:table;">';
    $buttone = $OUTPUT->single_button($edit, get_string('editrule', 'booking'), 'get');
    $button .= html_writer::tag('span', $buttone,
            array('style' => 'text-align: right; display:table-cell;'));
    $buttond = $OUTPUT->single_button($delete, get_string('deleterule', 'booking'), 'get');
    $button .= html_writer::tag('span', $buttond,
            array('style' => 'text-align: left; display:table-cell;'));
    $button .= '</div>';

    $rulestable[] = array("{$rule->text}", $rule->userslimit,
        html_writer::tag('span', $button,
                array('style' => 'text-align: right; display:table-cell;')));
}

$table->data = $rulestable;
echo html_writer::table($table);

$cancel = new moodle_url('report.php', array('id' => $cm->id, 'optionid' => $optionid));
$addnew = new moodle_url('otherbookingaddrule.php', array('id' => $cm->id, 'optionid' => $optionid));

echo '<div style="width: 100%; text-align: center; display:table;">';
$button = $OUTPUT->single_button($cancel, get_string('cancel', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: right; display:table-cell;'));
$button = $OUTPUT->single_button($addnew, get_string('otherbookingaddrule', 'booking'), 'get');
echo html_writer::tag('span', $button, array('style' => 'text-align: left; display:table-cell;'));
echo '</div>';

echo $OUTPUT->footer();
