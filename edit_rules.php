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

require_once(__DIR__ . '/../../config.php');
require_once('locallib.php');
require_once($CFG->libdir . '/adminlib.php');

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

$PAGE->activityheader->disable();

$output = $PAGE->get_renderer('mod_booking');

$rulesform = new rulesform();
$rulesform->set_data_for_dynamic_submission();

echo $output->header();

echo html_writer::div($rulesform->render(), '', ['data-region' => 'rulesform']);

$PAGE->requires->js_call_amd(
    'mod_booking/rulesform',
    'init',
    [rulesform::class]
);

echo $output->footer();
