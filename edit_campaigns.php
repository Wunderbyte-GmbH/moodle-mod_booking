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
 * Campaigns edit form
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_campaigns\campaigns;
use mod_booking\booking_campaigns\campaigns_info;
use mod_booking\booking_rules\booking_rules;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

// No guest autologin.
require_login(0, false);

admin_externalpage_setup('modbookingeditrules');

$url = new moodle_url('/mod/booking/edit_campaigns.php');
$PAGE->set_url($url);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-booking-edit-campaigns');

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('bookingcampaigns', 'mod_booking')
);

$output = $PAGE->get_renderer('mod_booking');

echo $output->header();
echo $output->heading(get_string('bookingcampaignswithbadge', 'mod_booking'));
echo '<div class="alert alert-secondary alert-dismissible fade show" role="alert">' .
    get_string('bookingcampaignssubtitle', 'mod_booking') .
    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>';

// Check if PRO version is active.
if (wb_payment::pro_version_is_activated()) {
    echo campaigns_info::return_rendered_list_of_saved_campaigns();

} else {
    echo html_writer::div(get_string('infotext:prolicensenecessary', 'mod_booking'), 'alert alert-warning');
}

$PAGE->requires->js_call_amd(
    'mod_booking/dynamiccampaignsform',
    'init',
    ['.booking-campaigns-container']
);

echo $output->footer();

