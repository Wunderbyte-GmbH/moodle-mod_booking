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
 * Add dates to option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\output\bookingoption_description;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing
require_once($CFG->dirroot . '/mod/booking/locallib.php');

global $DB, $PAGE, $OUTPUT, $USER;

// We do not want to check login here...
// ...as this page should also be available for not logged in users!

$cmid = required_param('cmid', PARAM_INT); // Course Module ID.
$optionid = required_param('optionid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$returnto = optional_param('returnto', '', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '', PARAM_URL);

$modcontext = context_module::instance($cmid);
$syscontext = context_system::instance();

// If we have this setting.
if (!get_config('booking', 'bookonlyondetailspage')) {
    require_capability('mod/booking:view', $modcontext);
}

$PAGE->set_context($syscontext);

$url = new moodle_url('/mod/booking/optionview.php', ['cmid' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

// Make sure, we respect module visibility and activity restrictions on the booking instance.
$modinfo = get_fast_modinfo($booking->course);
$cm = $modinfo->get_cm($cmid);
if (!$cm->uservisible && !get_config('booking', 'bookonlyondetailspage')) {
    echo $OUTPUT->header();
    echo html_writer::div(
        get_string('invisibleoption:notallowed', 'mod_booking'),
        "alert alert-danger"
    );
    echo $OUTPUT->footer();
    die();
}

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);
if ($settings && !empty($settings->id)) {
    if ($userid == $USER->id || $userid == 0) {
        $user = $USER;
    } else {
        $user = singleton_service::get_instance_of_user($userid);
    }


    $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

    $PAGE->set_title(format_string($settings->get_title_with_prefix()));
    $PAGE->set_pagelayout('base');

    echo $OUTPUT->header();
    // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
    // TODO: The following lines change the context of the PAGE object...
    // ... and have therefore to be called after printing the header.
    // This needs to be fixed.

    $output = $PAGE->get_renderer('mod_booking');
    $data = new bookingoption_description($settings->id, null, MOD_BOOKING_DESCRIPTION_OPTIONVIEW, true, null, $user, true);
    $data->returnurl = $returnurl ?? false;

    // The isinvisible check ONLY checks the "real" invisible option, not the "visible only with direct link".
    // As the option here is only possible with direct link, we don't need to check this.

    if ($data->is_invisible()) {
        // If the user does have the capability to see invisible options...
        if (has_capability('mod/booking:canseeinvisibleoptions', $modcontext)) {
            // ... then show it.
            echo $output->render_bookingoption_description_view($data);
        } else {
            // User is not entitled to see invisible options.
            echo get_string('invisibleoption:notallowed', 'mod_booking');
        }
    } else {
        echo $output->render_bookingoption_description_view($data);
    }
} else {
    $url = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
    redirect($url);
}

echo $OUTPUT->footer();
