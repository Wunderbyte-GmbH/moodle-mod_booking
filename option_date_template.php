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
 * Handling option date template page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);
$PAGE->set_context(\context_system::instance());
$url = new moodle_url('/mod/booking/option_date_template.php');
$PAGE->set_url($url);
list($course, $cm) = get_course_and_cm_from_cmid($id);

require_course_login($course, false);

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cm->id)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

if (!has_capability('mod/booking:manageoptiontemplates', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'manage booking option templates');
}

echo $OUTPUT->header();

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found,moodle.Commenting.InlineComment.NotCapital
// $form->set_data_for_dynamic_submission();
echo html_writer::div(html_writer::link('#', 'Load form', ['data-action' => 'loadform']));
echo html_writer::div('', '', ['data-region' => 'form']);
$PAGE->requires->js_call_amd('mod_booking/dynamicform2');

echo $OUTPUT->footer();
