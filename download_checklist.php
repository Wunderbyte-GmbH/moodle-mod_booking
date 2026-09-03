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
 * Download endpoint for the checklist PDF of a booking option.
 *
 * Replaces the old report.php?action=downloadchecklist flow.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\checklist\checklist_generator;
use mod_booking\singleton_service;

require_once("../../config.php");
require_once($CFG->dirroot . '/mod/booking/lib.php');

global $PAGE;

$cmid = required_param('cmid', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_url(new moodle_url('/mod/booking/download_checklist.php', ['cmid' => $cmid, 'optionid' => $optionid]));
$PAGE->set_context($context);

// The option must belong to the booking instance the URL claims.
$optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
if (empty($optionsettings->id) || (int)$optionsettings->cmid !== (int)$cm->id) {
    throw new moodle_exception('nooptionid', 'mod_booking');
}

// The checklist download has its own capability (the old report.php endpoint
// relied on page access only - the dedicated capability is the stricter,
// correct gate and matches the button in bookingoption_description).
require_capability('mod/booking:downloadchecklist', $context);

$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);

$generator = new checklist_generator($bookingoption);
$generator->generate_pdf();
die();
