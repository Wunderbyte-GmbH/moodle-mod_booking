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

use mod_booking\form\rulesform;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');
require_once('locallib.php');

$cmid = required_param('cmid', PARAM_INT);

$url = new moodle_url('/mod/booking/edit_rules.php',
        array('cmid' => $cmid));

$PAGE->set_url($url);

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-booking-mod');

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

$PAGE->activityheader->disable();

if (!$booking = singleton_service::get_instance_of_booking_by_cmid($cmid)) {
    throw new moodle_exception("Course module is incorrect");
}

$context = context_module::instance($cmid);
if (!has_capability('mod/booking:updatebooking', $context)) {
    throw new moodle_exception('nopermissiontoupdatebooking', 'booking');
}

$output = $PAGE->get_renderer('mod_booking');

$rulesform = new rulesform();
$rulesform->set_data_for_dynamic_submission();

echo $output->header();

echo html_writer::div($rulesform->render(), '', ['data-region' => 'rulesform']);

$PAGE->requires->js_call_amd(
    'mod_booking/rulesform',
    'init',
    [$cmid, rulesform::class]
);

echo $output->footer();
