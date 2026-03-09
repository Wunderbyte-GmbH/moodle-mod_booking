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
 * Plugin internal classes, functions and constants are defined here.
 *
 * @package     mod_booking
 * @copyright   2013 David Bogner <david.bogner@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_booking\booking_rules\rules_info;
use mod_booking\booking_utils;
use mod_booking\output\bookingoption_description;
use mod_booking\singleton_service;

global $CFG;
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Outputs a confirm button on a separate page to confirm a booking.
 *
 * @param int $optionid
 * @param object $user
 * @param object $cm
 * @param string $url
 *
 * @return void
 *
 */
function booking_confirm_booking($optionid, $user, $cm, $url) {
    global $OUTPUT;
    echo $OUTPUT->header();

    $option = singleton_service::get_instance_of_booking_option($cm->id, $optionid);

    $optionidarray['answer'] = $optionid;
    $optionidarray['confirm'] = 1;
    $optionidarray['sesskey'] = $user->sesskey;
    $optionidarray['id'] = $cm->id;
    $requestedcourse = "<br />" . $option->option->text;
    if ($option->option->coursestarttime != 0) {
        $requestedcourse .= "<br />" .
                 userdate($option->option->coursestarttime, get_string('strftimedatetime', 'langconfig')) . " - " .
                 userdate($option->option->courseendtime, get_string('strftimedatetime', 'langconfig'));
    }
    $message = "<h2>" . get_string('confirmbookingoffollowing', 'booking') . "</h2>" .
             $requestedcourse;
    $message .= "<p><b>" . get_string('bookingpolicyagree', 'booking') . ":</b></p>";
    $message .= "<p>" . format_text($option->booking->settings->bookingpolicy) . "<p>";
    echo $OUTPUT->confirm($message, new moodle_url('/mod/booking/view.php', $optionidarray), $url);
    echo $OUTPUT->footer();
}

/**
 * Update start and enddate in booking_option when dates are set or deleted
 * @param int $optionid
 */
function booking_updatestartenddate($optionid) {
    global $DB;

    // Bugfix: Only update start end date depending on session IF there actually are sessions.
    if (booking_utils::booking_option_has_optiondates($optionid)) {
        // Update start and end date of the option depending on the sessions.
        $result = $DB->get_record_sql(
            'SELECT MIN(coursestarttime) AS coursestarttime, MAX(courseendtime) AS courseendtime
             FROM {booking_optiondates}
             WHERE optionid = ?',
            [$optionid]
        );

        $optionobj = new stdClass();
        $optionobj->id = $optionid;

        if (is_null($result->coursestarttime)) {
            $optionobj->coursestarttime = 0;
            $optionobj->courseendtime = 0;
        } else {
            $optionobj->coursestarttime = $result->coursestarttime;
            $optionobj->courseendtime = $result->courseendtime;
        }

        $DB->update_record("booking_options", $optionobj);

        // We need to check if any rules apply for the updated option.
        rules_info::execute_rules_for_option($optionid);
    }
}

/**
 * Helper function to render custom fields data of an option date session.
 * @param numeric $optiondateid the id of the option date for which the custom fields should be rendered
 * @return string the rendered HTML of the session's custom fields
 */
function get_rendered_customfields($optiondateid) {
    global $DB;
    $customfieldshtml = ''; // The rendered HTML.
    if ($customfields = $DB->get_records("booking_customfields", ["optiondateid" => $optiondateid])) {
        foreach ($customfields as $customfield) {
            $customfieldshtml .= '<p><i>' . $customfield->cfgname . ': </i>';
            $customfieldshtml .= $customfield->value . '</p>';
        }
    }
    return $customfieldshtml;
}

/**
 * Helper function to render the full description
 * (including custom fields) of option events or optiondate events.
 * @param int $optionid
 * @param int $cmid the course module id
 * @param int $descriptionparam
 * @param bool $forbookeduser
 * @return string The rendered HTML of the full description.
 */
function get_rendered_eventdescription(
    int $optionid,
    int $cmid,
    int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
    bool $forbookeduser = false
): string {

    global $PAGE;

    // We have the following differences:
    // - Rendered live on the website (eg wihin a modal) -> use button.
    // - Rendered in calendar event -> use link.php? link.
    // - Rendered in ical file for mail -> use link.php? link.

    $data = new bookingoption_description($optionid, null, $descriptionparam, true, $forbookeduser);
    $output = $PAGE->get_renderer('mod_booking');

    if ($descriptionparam == MOD_BOOKING_DESCRIPTION_ICAL) {
        // If this is for ical.
        return $output->render_bookingoption_description_ical($data);
    } else if ($descriptionparam == MOD_BOOKING_DESCRIPTION_MAIL) {
        // If this is used for a mail - placeholder {bookingdetails}.
        return $output->render_bookingoption_description_mail($data);
    } else if ($descriptionparam == MOD_BOOKING_DESCRIPTION_CALENDAR) {
        // If this is used for an event.
        return $output->render_bookingoption_description_event($data);
    }

    return $output->render_bookingoption_description($data);
}

/**
 * Helper function to duplicate custom fields belonging to an option date.
 *
 * @param int $oldoptiondateid id of the option date for which all custom fields will be duplicated.
 * @param int $newoptiondateid
 *
 * @return void
 *
 */
function optiondate_duplicatecustomfields($oldoptiondateid, $newoptiondateid) {
    global $DB;
    // Duplicate all custom fields which belong to this optiondate.
    $customfields = $DB->get_records("booking_customfields", ['optiondateid' => $oldoptiondateid]);
    foreach ($customfields as $customfield) {
        $customfield->optiondateid = $newoptiondateid;
        $DB->insert_record("booking_customfields", $customfield);
    }
}

/**
 * Get booking option status
 *
 * @param int $starttime
 * @param int $endtime
 * @return string
 * @throws coding_exception
 */
function booking_getoptionstatus($starttime = 0, $endtime = 0) {
    if ($starttime == 0 && $endtime == 0) {
        return '';
    } else if ($starttime < time() && $endtime > time()) {
        return get_string('active', 'booking');
    } else if ($endtime < time()) {
        return get_string('terminated', 'booking');
    } else if ($starttime > time()) {
        return get_string('notstarted', 'booking');
    }

    return "";
}
