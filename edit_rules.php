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
use mod_booking\booking_rules\rules_info;
use mod_booking\form\rulesform;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');
require_once('locallib.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingeditrules');

$settingsurl = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

$url = new moodle_url('/mod/booking/edit_rules.php');
$PAGE->set_url($url);

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-booking-edit-rules');

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('bookingrules', 'mod_booking')
);

if ($CFG->version >= 2022041900) {
    $PAGE->activityheader->disable();
}

$output = $PAGE->get_renderer('booking');

echo $output->header();
echo $output->heading(get_string('bookingrules', 'mod_booking'));

// Check if PRO version is active.
if (wb_payment::pro_version_is_activated()) {
    $borules = new booking_rules();
    echo $borules->return_rendered_list_of_saved_rules();

} else {
    echo html_writer::div(get_string('infotext:prolicensenecessary', 'mod_booking'), 'alert alert-warning');
}

$PAGE->requires->js_call_amd(
    'mod_booking/dynamicrulesform',
    'init',
    ['.booking-rules-container']
);

echo $output->footer();

