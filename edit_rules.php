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
 * Rule edit form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_rules\booking_rules;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/adminlib.php');

$cmid = optional_param('cmid', 0, PARAM_INT);
$contextid = optional_param('contextid', 0, PARAM_INT);

global $DB;

// No guest autologin.
require_login(0, false);

$urlparams = [];

if (empty($cmid) && empty($contextid)) {
    $contextid = context_system::instance()->id;
} else if (!empty($cmid)) {
    [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
    require_course_login($course, false, $cm);
    $context = context_module::instance($cmid);
    $contextid = $context->id;
    $urlparams = ['cmid' => $cmid];
}

if (empty($urlparams)) {
    $urlparams = ['contextid' => 1];
}

$context = context::instance_by_id($contextid);

require_capability('mod/booking:editbookingrules', $context);

$PAGE->set_context($context);

$url = new moodle_url('/mod/booking/edit_rules.php', $urlparams);
$PAGE->set_url($url);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

if ($contextid == 1) {
    admin_externalpage_setup('modbookingeditrules');
    $PAGE->set_pagelayout('admin');
} else {
    $PAGE->set_pagelayout('standard');
}

$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-booking-edit-rules');

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('bookingrules', 'mod_booking')
);

$output = $PAGE->get_renderer('booking');

echo $output->header();
echo $output->heading(get_string('bookingrules', 'mod_booking'));

echo get_string('linktoshowroom:bookingrules', 'mod_booking');

// Check if PRO version is active. In free version, up to three rules can be edited for whole plugin, but none for coursemodule.
if (wb_payment::pro_version_is_activated()) {
    echo booking_rules::get_rendered_list_of_saved_rules($contextid);
} else if (!empty($cmid)) {
    echo html_writer::div(get_string('infotext:prolicensenecessary', 'mod_booking'), 'alert alert-warning');
} else {
    $rules = booking_rules::get_list_of_saved_rules($contextid);
    if (isset($rules) && count($rules) < 3) {
        echo booking_rules::get_rendered_list_of_saved_rules($contextid);
    } else if (isset($rules) && count($rules) >= 3) {
        echo booking_rules::get_rendered_list_of_saved_rules($contextid, false);
    }
}

$PAGE->requires->js_call_amd(
    'mod_booking/dynamicrulesform',
    'init',
    ['.booking-rules-container']
);

echo $output->footer();
