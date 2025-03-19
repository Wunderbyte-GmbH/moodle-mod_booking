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
 * Unsubscribe - for example from notification list.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking_option;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');

global $DB, $CFG, $USER, $OUTPUT, $PAGE;

require_login();

// Currently the only action we have is 'notification'.
$action = required_param('action', PARAM_ALPHA);
$optionid = required_param('optionid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);
$cmid = $settings->cmid;
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
$context = context_system::instance();

$url = new moodle_url('/mod/booking/unsubscribe.php', [
    'action' => $action,
    'optionid' => $optionid,
    'userid' => $userid,
]);
$PAGE->set_url($url);
$PAGE->set_context($context);

$messagetoshow = "<div class='alert alert-danger'>unknown error</div>";

switch ($action) {
    case 'notification':
        // Unsubscribing is currently only possible for oneself.
        // So we prevent misuse (a user with bad intentions could unsubscribe another user).
        if ($userid != $USER->id) {
            $messagetoshow = "<div class='alert alert-warning'>" . get_string('unsubscribe:errorotheruser', 'mod_booking') .
                "</div>";
            break;
        }
        // As the deletion here has no further consequences, we can do it directly in DB.
        if (
            $DB->record_exists(
                'booking_answers',
                [
                    'userid' => $userid,
                    'optionid' => $optionid,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                ]
            )
        ) {
            $ba = singleton_service::get_instance_of_booking_answers($optionid);
            // Log the deletion in the booking history.
            booking_option::booking_history_insert(
                MOD_BOOKING_STATUSPARAM_DELETED,
                0,
                $optionid,
                0,
                $userid
            );

            $DB->delete_records(
                'booking_answers',
                [
                    'userid' => $userid,
                    'optionid' => $optionid,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                ]
            );
            $messagetoshow = "<div class='alert alert-success'><i class='fa fa-bell-slash-o' aria-hidden='true'></i>&nbsp;" .
                get_string('unsubscribe:successnotificationlist', 'mod_booking', $settings->get_title_with_prefix()) .
                "</div>";

            // Do not forget to purge cache afterwards.
            booking_option::purge_cache_for_option($optionid);
        } else {
            $messagetoshow = "<div class='alert alert-info'>" . get_string('unsubscribe:alreadyunsubscribed', 'mod_booking') .
                "</div>";
        }

        break;
    default:
        $messagetoshow = "<div class='alert alert-danger'>
            Value of parameter 'action' is missing or not supported!
        </div>";
}

echo $OUTPUT->header();
echo $messagetoshow;
echo $OUTPUT->footer();
die();
