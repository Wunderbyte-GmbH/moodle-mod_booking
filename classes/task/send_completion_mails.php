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

global $CFG;

use core\message\message;
use mod_booking\booking_option;

require_once($CFG->dirroot . '/mod/booking/lib.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc Task to send notification mails when the completion status of a user changes to complete.
 */
class send_completion_mails extends \core\task\adhoc_task {

    /**
     * Data for sending mail
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('modulename', 'mod_booking');
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
        global $DB, $USER;
        $taskdata = $this->get_custom_data();

        echo 'send_completion_mails task: sending completion mail to user with id: ' . $taskdata->userid . PHP_EOL;

        if ($taskdata != null) {
            $userdata = $DB->get_record('user', array('id' => $taskdata->userid));

            if (!$userdata->deleted) {

                $touser = $DB->get_record('user', array('id' => $taskdata->userid));

                $bookingoption = new booking_option($taskdata->cmid, $taskdata->optionid);

                $params = booking_generate_email_params($bookingoption->booking->settings, $bookingoption->option,
                    $touser, $taskdata->cmid, $bookingoption->optiontimes, false, false,
                    true);

                $message = booking_get_email_body($bookingoption->booking->settings, 'activitycompletiontext',
                    'activitycompletiontextmessage', $params);
                $bookingoption->booking->settings->pollurltext = $message;

                $eventdata = new message();
                $eventdata->modulename = 'booking';

                // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
                if ($bookingmanager = $DB->get_record('user',
                    array('username' => $bookingoption->booking->settings->bookingmanager))) {
                    $eventdata->userfrom = $bookingmanager;
                } else {
                    $eventdata->userfrom = $USER;
                }

                $eventdata->userto = $touser;
                $eventdata->subject = get_string('activitycompletiontextsubject', 'booking', $params);
                $eventdata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $message));
                $eventdata->fullmessageformat = FORMAT_HTML;
                $eventdata->fullmessagehtml = $message;
                $eventdata->smallmessage = '';
                $eventdata->component = 'mod_booking';
                $eventdata->name = 'bookingconfirmation'; // Message providers are defined in messages.php.

                // Now the task will send the message if possible.
                if (!message_send($eventdata)) {
                    echo 'send_completion_mails task: mail could not be sent to user with userid: '
                        . $taskdata->userid . PHP_EOL;
                } else {
                    echo 'send_completion_mails task: mail successfully sent to user with userid: '
                        . $taskdata->userid . PHP_EOL;
                }
            }
        } else {
            throw new \coding_exception(
                    'Completion email was not sent due to lack of custom message data.');
        }
    }
}
