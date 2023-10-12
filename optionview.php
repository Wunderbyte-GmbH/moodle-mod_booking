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
 * @copyright 2016 Andraž Prinčič www.princic.net
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

if (!$context = context_system::instance()) {
    throw new moodle_exception('badcontext');
}

$PAGE->set_context($context);

$url = new moodle_url('/mod/booking/optionview.php', ['cmid' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

if ($settings = singleton_service::get_instance_of_booking_option_settings($optionid)) {

    if ($userid == $USER->id || $userid == 0) {
        $user = $USER;
    } else {
        $user = singleton_service::get_instance_of_user($userid);
    }


    $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

    $PAGE->navbar->add($settings->text);
    $PAGE->set_title(format_string($settings->text));
    $PAGE->set_pagelayout('standard');

    echo $OUTPUT->header();

    // TODO: The following lines change the contedt of the PAGE object and have therefore to be called after printing the header.
    // This needs to be fixed.

    $output = $PAGE->get_renderer('mod_booking');
    $data = new bookingoption_description($settings->id, null, DESCRIPTION_OPTIONVIEW, true, null, $user);

    if ($data->is_invisible()) {
        // If the user does have the capability to see invisible options...
        if (has_capability('mod/booking:canseeinvisibleoptions', $context)) {
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
