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
 * Adhoc Task to send notification mails when the completion status of a user changes to complete.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use mod_booking\message_controller;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to send notification mails when the completion status of a user changes to complete.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_completion_mails extends \core\task\adhoc_task {
    /**
     * Data for sending mail
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('tasksendcompletionmails', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {

        if (empty(get_config('booking', 'uselegacymailtemplates'))) {
            mtrace("Legacy mails are turned off, this task should be deactivated.");
            return;
        }

        $taskdata = $this->get_custom_data();

        mtrace('send_completion_mails task: sending completion mail to user with id: ' . $taskdata->userid);

        if ($taskdata != null) {
            // Use message controller to send the completion message.
            $messagecontroller = new message_controller(
                MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
                MOD_BOOKING_MSGPARAM_COMPLETED,
                $taskdata->cmid,
                $taskdata->optionid,
                $taskdata->userid,
                null
            );

            if ($messagecontroller->send_or_queue()) {
                mtrace('send_completion_mails task: mail successfully sent to user with userid: '
                        . $taskdata->userid);
            } else {
                mtrace('send_completion_mails task: mail could not be sent to user with userid: '
                        . $taskdata->userid);
            }
        } else {
            throw new \coding_exception(
                'Completion email was not sent due to lack of custom message data.'
            );
        }
    }
}
