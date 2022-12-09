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

namespace mod_booking\task;

use mod_booking\booking_option;
use mod_booking\message_controller;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

class send_notification_mails extends \core\task\scheduled_task {

    /**
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task_send_notification_mails', 'mod_booking');
    }

    /**
     * Check for all users and send notification mail for those on the lists.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {

        global $DB;

        $results = $DB->get_records('booking_answers', ['waitinglist' => STATUSPARAM_NOTIFYMELIST]);

        foreach ($results as $result) {

            echo 'send_notification_mails task: sending mail to user with id: ' . $result->userid . PHP_EOL;

            $booking = singleton_service::get_instance_of_booking_by_bookingid($result->bookingid);
            $settings = singleton_service::get_instance_of_booking_option_settings($result->optionid);

            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
            $bookingstatus = $bookinganswer->return_all_booking_information($result->userid);

            $bookingstatus = reset($bookingstatus);

            if (isset($bookingstatus['fullybooked']) && $bookingstatus['fullybooked'] == true) {
                continue;
            }

            $option = new stdClass();
            $option->title = $settings->get_title_with_prefix();
            $url = new moodle_url('/mod/booking/optionview.php', ['cmid' => $booking->cmid, 'optionid' => $result->optionid]);
            $option->url = $url->out(false);

            // Use message controller to send the completion message.
            $messagecontroller = new message_controller(
                    MSGCONTRPARAM_SEND_NOW,
                    MSGPARAM_CUSTOM_MESSAGE,
                    $booking->cmid,
                    null,
                    $result->optionid,
                    $result->userid,
                    null,
                    null,
                    get_string('optionbookabletitle', 'mod_booking', $option),
                    get_string('optionbookablebody', 'mod_booking', $option),
            );

            if ($messagecontroller->send_or_queue()) {
                echo 'send_notification_mails task: mail successfully sent to user with userid: '
                        . $result->userid . PHP_EOL;
            } else {
                echo 'send_notification_mails task: mail could not be sent to user with userid: '
                        . $result->userid . PHP_EOL;
            }

        }
    }
}
