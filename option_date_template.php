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
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\form\optiondate_form;

require_once(__DIR__ . '/../../config.php');
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);
$PAGE->set_context(\context_system::instance());
$url = new moodle_url('/mod/booking/option_date_template.php');
$PAGE->set_url($url);
list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false);

if (!$booking = new booking($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

if (!has_capability('mod/booking:manageoptiontemplates', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'manage booking option templates');
}



echo $OUTPUT->header();
$form = new optiondate_form();
//$form->set_data_for_dynamic_submission();
echo html_writer::div($form->render(), '', ['id' => 'formcontainer']);

$PAGE->requires->js_call_amd('mod_booking/dynamicform2', 'init');

echo $OUTPUT->footer();
