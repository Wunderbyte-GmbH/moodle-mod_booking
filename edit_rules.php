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

$PAGE->activityheader->disable();

// Check if PRO version is active.
if (wb_payment::is_currently_valid_licensekey()) {

    $rulesform = new rulesform();

    $output = $PAGE->get_renderer('mod_booking');

    if ($data = $rulesform->get_data()) {

        rules_info::save_booking_rules($data);

        // Now execute the rules.
        rules_info::execute_booking_rules();

        redirect($url, get_string('allchangessaved', 'mod_booking'), 3);
    } else {

        echo $output->header();
        echo $output->heading(get_string('bookingrules', 'mod_booking'));

        $defaultvalues = new stdClass();

        // Defaults for booking rules.
        if ($rulesfromdb = $DB->get_records('booking_rules')) {
            foreach ($rulesfromdb as $rulefromdb) {
                $rulefullpath = "\\mod_booking\\booking_rules\\rules\\" . $rulefromdb->rulename;
                $rule = new $rulefullpath;
                $rule->set_defaults($defaultvalues, $rulefromdb);
            }
        }

        // Processed if form is submitted but data not validated & form should be redisplayed OR first display of form.
        $rulesform->set_data($defaultvalues);
        $rulesform->display();
        echo $output->footer();
    }
} else {
    echo $output->header();
    echo $output->heading(get_string('bookingrules', 'mod_booking'));

    echo html_writer::div(get_string('infotext:prolicensenecessary', 'mod_booking'), 'alert alert-warning');

    echo $output->footer();
}


