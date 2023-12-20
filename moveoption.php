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
 * Handling move option pahe
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once("lib.php");


$id = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$targetcmid = optional_param('movetocmid', 0, PARAM_INT);
require_sesskey();

$url = new moodle_url('/mod/booking/moveoption.php', ['id' => $id, 'optionid' => $optionid]);
$returnurl = new moodle_url('/mod/booking/view.php', ['id' => $id]);
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'booking');

require_course_login($course, false, $cm);

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

$context = context_module::instance($cm->id);
$contextcourse = context_course::instance($course->id);
if (!has_capability('mod/booking:updatebooking', $contextcourse)) {
    throw new required_capability_exception($contextcourse, 'mod/booking:updatebooking', 'nopermissions', '');
}
$PAGE->set_title(format_string($booking->settings->name));
$PAGE->set_heading(get_string('moveoptionto', 'mod_booking'));
echo $OUTPUT->header();
if ($targetcmid > 0) {
    $option = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
    $errorstring = $option->move_option_otherbookinginstance($targetcmid);
    $button = new single_button($returnurl, get_string('continue'), 'get');
    $renderer = $PAGE->get_renderer('core');
    if (empty($errorstring)) {
        $output = $OUTPUT->notification(get_string('transferoptionsuccess', 'mod_booking'), 'notifysuccess');
        $output .= $renderer->render($button);
        echo $output;
    } else {
        $output = $OUTPUT->notification('The following problems occured during transferring the booking option', 'notifyproblem');
        $output .= $errorstring;
        $output .= $renderer->render($button);
        echo $output;
    }
} else if ($targetcmid == 0) {
    // Display available booking instances, the option should be moved to.
    $bookinginstances = get_all_instances_in_course('booking', $course, true);
    if (empty($bookinginstances)) {
        $output = html_writer::tag('h3', "No other booking instances found");
        $continueurl = new moodle_url('/mod/booking/view.php', ['id' => $id]);
        $button = new single_button($continueurl, get_string('continue'), 'get');
        $button->class = 'continuebutton';
        $OUTPUT->single_button($button);
    } else {
        $content = [];
        $modinfo = get_fast_modinfo($course);
        foreach ($bookinginstances as $bookinginstance) {
            $cm = $modinfo->get_cm($bookinginstance->coursemodule);
            if ($cm->id == $id) {
                continue;
            }
            $url->param('movetocmid', $cm->id);
            $url->param('sesskey', sesskey());
            $button = new single_button($url, get_string('move'), 'get');
            $button->class = 'float-right';
            $renderer = $PAGE->get_renderer('core');
            $content[] = $bookinginstance->name . $renderer->render($button);
        }
        $output = html_writer::start_tag('ul',  ['class' => 'list-group'])."\n";
        foreach ($content as $item) {
            $output .= html_writer::tag('li', $item, ['class' => 'list-group-item'])."\n";
        }
        $output .= html_writer::end_tag('ul');
        echo $output;
    }
} else {
    throw new moodle_exception('This booking option does not exist');
}
echo $OUTPUT->footer();
