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
 * Handling of sending confirmation mains.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\message_controller;
use context_system;
use Exception;

global $CFG;

/**
 * Class for handling of sending confirmation mains.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_confirmation_mails extends \core\task\adhoc_task {
    /**
     * Data for sending mail
     *
     * @var \stdClass
     */
    public function get_name() {
        return get_string('tasksendconfirmationmails', 'mod_booking');
    }

    /**
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        global $CFG, $DB;

        if (empty(get_config('booking', 'uselegacymailtemplates'))) {
            mtrace("Legacy mails are turned off, this task should be deactivated.");
            return;
        }

        $taskdata = $this->get_custom_data();

        mtrace('send_confirmation_mails task started');

        if ($taskdata != null) {
            // If no messagetext has been defined, we do not send an e-mail.
            $trimmedmessage = strip_tags($taskdata->messagehtml);
            $trimmedmessage = str_replace('&nbsp;', '', $trimmedmessage);
            $trimmedmessage = trim($trimmedmessage);

            if ($trimmedmessage != '0') {
                if (!empty($taskdata->userto)) {
                    $userdata = $DB->get_record('user', ['id' => $taskdata->userto->id]);
                    if (!$userdata->deleted) {
                        /* Add try-catch because email_to_user might throw an SMTP exception
                        when recipient mail address is not found. */
                        try {
                            // NOTE: email_to_user does not support multiple attachments.
                            if (
                                !email_to_user(
                                    $taskdata->userto,
                                    $taskdata->userfrom,
                                    $taskdata->subject,
                                    $taskdata->messagetext,
                                    $taskdata->messagehtml,
                                    $taskdata->attachment->{'booking.ics'} ?? '',
                                    empty($taskdata->attachment->{'booking.ics'}) ? '' : 'booking.ics'
                                )
                            ) {
                                mtrace('Confirmation could not be sent.');
                            } else {
                                // After sending we can delete the attachment.
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
                                $event = \mod_booking\event\message_sent::create([
                                    'context' => context_system::instance(),
                                    'userid' => $taskdata->userto->id,
                                    'relateduserid' => $taskdata->userfrom->id,
                                    'objectid' => $taskdata->optionid ?? 0,
                                    'other' => [
                                        'messageparam' => $taskdata->messageparam,
                                        'subject' => $taskdata->subject,
                                        'objectid' => $taskdata->optionid ?? 0,
                                        'message' => $taskdata->messagetext ?? 0,
                                    ],
                                ]);
                                $event->trigger();
                            }
                        } catch (Exception $e) {
                            mtrace('Confirmation could not be sent because of the following exception: ' . $e->getMessage());
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
