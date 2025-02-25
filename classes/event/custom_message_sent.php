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
 * The custom_message_sent event class.
 * This event gets triggered when a custom message has been sent.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The custom_message_sent event class.
 * This event gets triggered when a custom message has been sent.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_message_sent extends \core\event\base {
    /**
     * Init.
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name.
     * @return string
     */
    public static function get_name() {
        return get_string('custommessagesent', 'mod_booking');
    }

    /**
     * Get description.
     * @return string
     */
    public function get_description() {

        // For the collapsibles, we need a uniqueid.
        $uniqueid = uniqid();

        $data = $this->get_data();

        if (is_string($data['other'])) {
            $other = (object)json_decode($data['other']);
            $messageparam = $other->messageparam ?? 0;
            $userid = $data['userid'] ?? 'unknown';
            $relateduserid = $data['relateduserid'] ?? 'unknown';
        } else {
            $other = (object)$data['other'];
            $userid = $data['userid'];
            $relateduserid = $data['relateduserid'];
        }
        $subject = $other->subject ?? '';
        $message = $other->message ?? 'Message body not saved.';
        $messageparam = $other->messageparam ?? 0;

        $messagetype = $this->transform_msgparam($messageparam);

        return '
            <a class=""
                data-toggle="collapse"
                href="#a' . $uniqueid . '"
                role="button" aria-expanded="false"
                aria-controls="collapseExample">

                ' . $messagetype . ' A message e-mail with subject "' . $subject .
                '" has been sent to user with id: ' . $relateduserid .
                ' by the user ' . $userid  . '
            </a>
            <div class="collapse" id="a' . $uniqueid . '">
                <div class="card card-body">
                    ' . $message . '
                </div>
            </div>';
    }

    /**
     * Helper function to transform the message param.
     * @param int $msgparam the message parameter
     * @return string
     */
    private function transform_msgparam(int $msgparam): string {

        switch ($msgparam) {
            case MOD_BOOKING_MSGPARAM_CONFIRMATION:
                return 'Booking confirmation';
            case MOD_BOOKING_MSGPARAM_WAITINGLIST:
                return 'Waiting list confirmation';
            case MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT:
                return 'Reminder';
            case MOD_BOOKING_MSGPARAM_REMINDER_TEACHER:
                return 'Teacher reminder';
            case MOD_BOOKING_MSGPARAM_STATUS_CHANGED:
                return 'Status change';
            case MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT:
                return 'Option cancelled by participant';
            case MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM:
                return 'Option cancelled by teacher or system';
            case MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION:
                return 'Change notification';
            case MOD_BOOKING_MSGPARAM_POLLURL_PARTICIPANT:
                return 'Poll URL message';
            case MOD_BOOKING_MSGPARAM_POLLURL_TEACHER:
                return 'Teacher\'s poll URL message';
            case MOD_BOOKING_MSGPARAM_COMPLETED:
                return 'Booking option completion';
            case MOD_BOOKING_MSGPARAM_SESSIONREMINDER:
                return 'Session reminder';
            case MOD_BOOKING_MSGPARAM_REPORTREMINDER:
                return 'Reminder sent from report';
            case MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE:
                return 'Custom message';
            default:
                return 'Unknown message type';
        }
    }
}
