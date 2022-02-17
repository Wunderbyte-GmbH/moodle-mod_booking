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
 * The message_sent event.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

/**
 * The message_sent event class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_sent extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    public static function get_name() {
        return get_string('message_sent', 'booking');
    }

    public function get_description() {

        return $this->transform_msgparam( $this->other['messageparam'] ) . ": " .
            "An e-mail with subject '" . $this->other['subject'] . "' has been sent to user with id: '{$this->userid}'. " .
            "The mail was sent from the user with id: '{$this->relateduserid}'.";
    }

    /**
     * Helper function to transform the message param.
     * @param $msgparam the message parameter
     * @return string
     */
    private function transform_msgparam(int $msgparam): string {

        switch ($msgparam) {
            case MSGPARAM_CONFIRMATION:
                return 'Booking confirmation';
            case MSGPARAM_WAITINGLIST:
                return 'Waiting list confirmation';
            case MSGPARAM_REMINDER_PARTICIPANT:
                return 'Reminder';
            case MSGPARAM_REMINDER_TEACHER:
                return 'Teacher reminder';
            case MSGPARAM_STATUS_CHANGED:
                return 'Status change';
            case MSGPARAM_CANCELLED_BY_PARTICIPANT:
                return 'Option cancelled by participant';
            case MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM:
                return 'Option cancelled by teacher or system';
            case MSGPARAM_CHANGE_NOTIFICATION:
                return 'Change notification';
            case MSGPARAM_POLLURL_PARTICIPANT:
                return 'Poll URL message';
            case MSGPARAM_POLLURL_TEACHER:
                return 'Teacher\'s poll URL message';
            case MSGPARAM_COMPLETED:
                return 'Booking option completion';
            case MSGPARAM_SESSIONREMINDER:
                return 'Session reminder';
            case MSGPARAM_REPORTREMINDER:
                return 'Reminder sent from report';
            case MSGPARAM_CUSTOM_MESSAGE:
                return 'Custom message';
        }
    }
}
