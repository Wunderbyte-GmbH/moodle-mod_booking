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
 * Edit options form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;

global $DB, $OUTPUT, $PAGE, $USER;

$cmid = required_param('id', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$copyoptionid = optional_param('copyoptionid', 0, PARAM_INT);
$createfromoptiondates = optional_param('createfromoptiondates', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$sesskey = optional_param('sesskey', '', PARAM_INT);
$mode = optional_param('mode', '', PARAM_RAW);

// Fallback url when there is no returnurl.
$returnurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
$returnurl = optional_param('returnurl', $returnurl->out(), PARAM_LOCALURL);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

$url = new moodle_url('/mod/booking/editoptions.php', ['id' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');

// Initialize bookingid.
$bookingid = (int) $cm->instance;
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cmid)) {
    throw new invalid_parameter_exception("Course module id is incorrect");
}

if (!$context = context_module::instance($cmid)) {
    throw new moodle_exception('badcontext');
}

if (
    (has_capability('mod/booking:updatebooking', $context) || (has_capability(
        'mod/booking:addeditownoption',
        $context
    ) && booking_check_if_teacher($optionid))) == false
) {
    throw new moodle_exception('nopermissions');
}

// We don't need this anymore.
$optionid = $optionid < 0 ? 0 : $optionid;

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);

if (!empty($settings->cmid) && $settings->cmid != $cmid) {
    throw new moodle_exception('badcontext');
}

// New code.
$params = [
    'cmid' => $cmid,
    'id' => $optionid, // In the context of option_form class, id always refers to optionid.
    'optionid' => $optionid, // Just kept on for legacy reasons.
    'bookingid' => $bookingid,
    'copyoptionid' => $copyoptionid,
    'returnurl' => $returnurl,
];

// In this example the form has arguments ['arg1' => 'val1'].
$form = new mod_booking\form\option_form(null, null, 'post', '', [], true, $params);
// Set the form data with the same method that is called when loaded from JS.
// It should correctly set the data for the supplied arguments.
$form->set_data_for_dynamic_submission();

echo $OUTPUT->header();

if (!empty($optionid)) {
    $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
    echo html_writer::div(
        get_string('youareediting', 'mod_booking', $settings->get_title_with_prefix()),
        'alert alert-info editoption-youareediting-alert'
    );
    if (!wb_payment::pro_version_is_activated()) {
        echo html_writer::div(get_string('optionformconfiggetpro', 'mod_booking'), 'small mt-2');
    }
}

// Render the form in a specific container, there should be nothing else in the same container.
echo html_writer::div($form->render(), '', ['id' => 'editoptionsformcontainer']);
$PAGE->requires->js_call_amd('mod_booking/dynamiceditoptionform', 'init', $params);

echo $OUTPUT->footer();
