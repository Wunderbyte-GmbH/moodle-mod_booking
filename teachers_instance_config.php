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
 * Overall report for all teachers within a booking instance.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

$urlparams = [
    'cmid' => $cmid,
];

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);
$context = context_module::instance($cm->id);
$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/teachers_instance_config.php', $urlparams);
$PAGE->set_url($baseurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('teachingreportforinstance', 'mod_booking') .
    $bookingsettings->name);
if (wb_payment::pro_version_is_activated()) {
    echo <<<EOT
        <div id="mod-booking-app" contextid="{$context->id}">
          <router-view></router-view>
        </div>
        EOT;
}
$PAGE->requires->js_call_amd('mod_booking/app-lazy', 'init');

echo $OUTPUT->footer();
