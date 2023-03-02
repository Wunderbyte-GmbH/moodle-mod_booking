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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\message_controller;
use context_system;

global $CFG;

class send_confirmation_mails extends \core\task\adhoc_task {

    /**
     * Data for sending mail
     *
     * @var \stdClass
     */
    public function get_name() {
        return get_string('task_send_confirmation_mails', 'mod_booking');
    }

    /**
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        global $CFG, $DB;
        $taskdata = $this->get_custom_data();

        mtrace('send_confirmation_mails task started');

        if ($taskdata != null) {

            // If no messagetext has been defined, we do not send an e-mail.
            $trimmedmessage = strip_tags($taskdata->messagehtml);
            $trimmedmessage = str_replace('&nbsp;', '', $trimmedmessage);
            $trimmedmessage = trim($trimmedmessage);

            if ($trimmedmessage != '0') {
                if (!empty($taskdata->userto)) {
                    $userdata = $DB->get_record('user', array('id' => $taskdata->userto->id));
                    if (!$userdata->deleted) {
                        // Hack to support multiple attachments.
                        if (!message_controller::phpmailer_email_to_user($taskdata->userto, $taskdata->userfrom,
                            $taskdata->subject, $taskdata->messagetext, $taskdata->messagehtml,
                            $taskdata->attachment ?? '', empty($taskdata->attachment) ? '' : 'booking.ics')) {

                            throw new \coding_exception('Confirmation email was not sent');

                        } else {
                            if (!empty($taskdata->attachment)) {
                                foreach ($taskdata->attachment as $key => $attached) {
                                    $search = str_replace($CFG->tempdir . '/', '', $attached);
                                    if ($DB->count_records_select('task_adhoc', "customdata LIKE '%$search%'") == 1) {
                                        if (file_exists($attached)) {
                                            unlink($attached);
                                        }
                                    }
                                }
                            }

                            // Use an event to log that a message has been sent.
                            $event = \mod_booking\event\message_sent::create(array(
                                'context' => context_system::instance(),
                                'userid' => $taskdata->userto->id,
                                'relateduserid' => $taskdata->userfrom->id,
                                'other' => array(
                                    'messageparam' => $taskdata->messageparam,
                                    'subject' => $taskdata->subject
                                )
                            ));
                            $event->trigger();
                        }
                    }
                } else {
                    mtrace('send_confirmation_mails: e-mail with subject "' . $taskdata->subject . '"' .
                    ' was not sent because $taskdata->userto was missing.');
                }
            } else {
                mtrace('send_confirmation_mails: e-mail with subject "' . $taskdata->subject . '"' .
                    ' was not sent because message template is set to "0" (turned off).');
            }
        } else {
            mtrace('Confirmation email was not sent due to lack of custom message data');
        }
        mtrace('send_confirmation_mails task finished');
    }
}
