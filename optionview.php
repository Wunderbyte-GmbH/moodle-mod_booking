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

use mod_booking\local\override_user_field;
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
$redirecttocourse = optional_param('redirecttocourse', 0, PARAM_INT);

$cvpwd = optional_param('cvpwd', '', PARAM_TEXT);
$cvfield = optional_param('cvfield', '', PARAM_TEXT);


$modcontext = context_module::instance($cmid);
$syscontext = context_system::instance();

if (
    $userid != $USER->id
    && !has_capability('mod/booking:updatebooking', $modcontext)
) {
    $userid = $USER->id;
}
$overridefield = new override_user_field($cmid);
if ($overridefield->password_is_valid($cvpwd)) {
    $overridefield->set_userprefs($cvfield, $userid);
}

$PAGE->set_context($syscontext);

$url = new moodle_url('/mod/booking/optionview.php', ['cmid' => $cmid, 'optionid' => $optionid]);
$PAGE->set_url($url);

$booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
$settings = singleton_service::get_instance_of_booking_option_settings($optionid);
$ba = singleton_service::get_instance_of_booking_answers($settings);
$courseid = $settings->courseid;

if ($redirecttocourse === 1 && isset($ba->usersonlist[$USER->id])) {
    $url = new moodle_url('/course/view.php', ['id' => $courseid]);
    redirect($url->out());
}

if ($settings && !empty($settings->id)) {
    if ($userid == $USER->id || $userid == 0) {
        $user = $USER;
    } else {
        $user = singleton_service::get_instance_of_user($userid);
    }

    $ba = singleton_service::get_instance_of_booking_answers($settings);

    if (isloggedin() && !isguestuser()) {
        $user = $USER;
    }

    // There can be cases where we are booked, but don't have the right to see.
    // We override this here. If we are booked, we can also see details.
    if (
        (
            isloggedin()
            && !isguestuser()
            && $USER->id == $user->id
            && $ba->user_status($USER->id) > MOD_BOOKING_STATUSPARAM_RESERVED
        )
        && !get_config('booking', 'showbookingdetailstoall')
    ) {
        require_login();

        // If we have this setting.
        if (!get_config('booking', 'bookonlyondetailspage')) {
            require_capability('mod/booking:view', $modcontext);
        }
    }

    // If the user is logged-in, we check if (s)he has accepted the site policy.
    if (isloggedin() && !isguestuser()) {
        $currentpolicyversionids = \tool_policy\api::get_current_versions_ids();
        if (!empty($currentpolicyversionids)) {
            foreach ($currentpolicyversionids as $currentpolicyversionid) {
                if (\tool_policy\api::get_agreement_optional($currentpolicyversionid)) {
                    continue;
                }
                $acceptance = \tool_policy\api::get_user_version_acceptance($USER->id, $currentpolicyversionid);
                if (empty($acceptance)) {
                    // If the user did not yet accept, we redirect to the policy page.
                    $policyurl = new moodle_url('/admin/tool/policy/index.php', ['returnurl' => $url]);
                    redirect($policyurl);
                }
            }
        }
    }

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
