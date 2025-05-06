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
 * Adhoc Task to send notification mails.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\booking_option;
use mod_booking\message_controller;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle Adhoc Task to send notification mails.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_notification_mails extends \core\task\scheduled_task {
    /**
     * Get name.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('tasksendnotificationmails', 'mod_booking');
    }

    /**
     * Check for all users and send notification mail for those on the lists.
     *
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {

        global $CFG, $DB;

        if (empty(get_config('booking', 'uselegacymailtemplates'))) {
            mtrace("Legacy mails are turned off, this task should be deactivated.");
            return;
        }

        $results = $DB->get_records('booking_answers', ['waitinglist' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST]);

        mtrace('number of records', count($results ?? 0));

        foreach ($results as $result) {
            $bookingid = $result->bookingid;
            $userid = $result->userid;
            $optionid = $result->optionid;

            mtrace("send_notification_mails task: sending mail to user with id $userid for option with id $optionid.");

            $booking = singleton_service::get_instance_of_booking_by_bookingid($bookingid);
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            $now = time();
            if (
                (!empty($settings->courseendtime) && $now > $settings->courseendtime)
                || (empty($booking->id) || empty($settings->id))
            ) {
                // If the booking option lies in the past, we remove the user from notification list...
                // ...and we do not send a notification anymore.
                $DB->delete_records(
                    'booking_answers',
                    [
                        'userid' => $userid,
                        'optionid' => $optionid,
                        'waitinglist' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                    ]
                );
                // Do not forget to purge cache afterwards.
                booking_option::purge_cache_for_answers($optionid);

                if (empty($booking->id) || empty($settings->id)) {
                    mtrace("booking instance ($bookingid) or booking option ($optionid) were deleted.");
                    return;
                }

                mtrace("send_notification_mails task: Option $optionid is already over, " .
                    "so notification was not sent and user $userid was removed from the notification list.");
                // Return, so message won't be sent!
                return;
            }

            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
            $bookingstatus = $bookinganswer->return_all_booking_information($userid);

            $bookingstatus = reset($bookingstatus);

            if (isset($bookingstatus['fullybooked']) && $bookingstatus['fullybooked'] == true) {
                continue;
            }

            $option = new stdClass();
            $option->title = $settings->get_title_with_prefix();
            $url = new moodle_url($CFG->wwwroot . '/mod/booking/optionview.php', [
                'cmid' => $booking->cmid,
                'optionid' => $optionid,
            ]);
            $option->url = $url->out(false);

            $unsubscribemoodleurl = new moodle_url($CFG->wwwroot . '/mod/booking/unsubscribe.php', [
                'action' => 'notification',
                'optionid' => $optionid,
                'userid' => $userid,
            ]);
            $option->unsubscribelink = $unsubscribemoodleurl->out(false);

            $messagetitle = get_string('optionbookabletitle', 'mod_booking', $option);
            $messagebody = get_string('optionbookablebody', 'mod_booking', $option);

            // Use message controller to send the completion message.
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
                MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
                $booking->cmid,
                $optionid,
                $userid,
                null,
                null,
                null,
                $messagetitle,
                $messagebody
            );

            if ($messagecontroller->send_or_queue()) {
                mtrace('send_notification_mails task: mail successfully sent to user with userid: '
                        . $userid);
            } else {
                mtrace('send_notification_mails task: mail could not be sent to user with userid: '
                        . $userid);
            }
        }
    }
}
