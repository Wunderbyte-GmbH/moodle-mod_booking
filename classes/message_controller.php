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

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

use stdClass;
use core\message\message;
use mod_booking\booking_option;
use mod_booking\booking_settings;
use mod_booking\booking_option_settings;
use mod_booking\output\optiondates_only;
use mod_booking\output\bookingoption_changes;
use moodle_exception;

/**
 * Manage booking messages which will be sent by email.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_controller {

    /** @var int $messageparam the message type */
    private $messageparam;

    /** @var int $cmid course module id */
    private $cmid;

    /** @var int $bookingid booking id */
    private $bookingid;

    /** @var int $optionid */
    private $optionid;

    /** @var booking_option $option */
    private $option;

    /** @var int $userid */
    private $userid;

    /** @var int $optiondateid - optional - needed for session reminders */
    private $optiondateid = null;

    /** @var booking_settings $bookingsettings */
    private $bookingsettings;

    /** @var booking_option_settings $optionsettings */
    private $optionsettings;

    /** @var stdClass $user */
    private $user;

    /** @var message $messagedata */
    private $messagedata;

    /** @var string $messagefieldname */
    private $messagefieldname;

    /** @var string $messagebody */
    private $messagebody;

    /** @var array $changes */
    private $changes;

    /** @var stdClass $bookingmanager */
    private $bookingmanager;

    /**
     * Constructor
     * @param int $messageparam the message type
     * @param int $cmid course module id
     * @param int $bookingid booking id
     * @param int $optionid option id
     * @param int $userid user id
     * @param int|null $optiondateid optional id of a specific session (optiondate)
     */
    public function __construct(int $messageparam, int $cmid, int $bookingid = null, int $optionid, int $userid,
        int $optiondateid = null, $changes = null) {

        global $DB;

        // If bookingid is missing, try to retrieve it from DB via optionid.
        if ($bookingid) {
            $this->bookingid = $bookingid;
        } else {
            if ($bookingid = $DB->get_field('booking_options', 'bookingid', ['id' => $optionid])) {
                $this->bookingid = $bookingid;
            } else {
                debugging('Could not retrieve missing bookingid from optionid: ' . $optionid);
            }
        }

        // Settings.
        $this->bookingsettings = new booking_settings($bookingid);
        $this->optionsettings = new booking_option_settings($optionid);

        // Get the booking manager.
        $this->bookingmanager = $DB->get_record('user', ['username' => $this->bookingsettings->bookingmanager]);

        // Standard params.
        $this->messageparam = $messageparam;
        $this->cmid = $cmid;
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->optiondateid = $optiondateid;
        $this->changes = $changes;

        // Booking_option instance needed to access functions get_all_users_booked and get_all_users_on_waitinglist.
        $this->option = new booking_option($cmid, $optionid);

        // Resolve the correct message fieldname.
        $this->messagefieldname = $this->get_message_fieldname();

        // Generate user data.
        $this->user = $DB->get_record('user', array('id' => $userid));

        // Generate email params.
        $params = $this->get_email_params();

        // Generate the email body.
        $this->messagebody = $this->get_email_body($params);

        // Generate full message data.
        $this->messagedata = $this->get_message_data($params);
    }

    /**
     * Prepares the email parameters.
     * @return stdClass data to be sent via mail
     */
    private function get_email_params(): stdClass {

        global $CFG, $PAGE;

        $params = new stdClass();

        $timeformat = get_string('strftimetime');
        $dateformat = get_string('strftimedate');

        $courselink = '';
        if ($this->optionsettings->courseid) {
            $courselink = new \moodle_url('/course/view.php', array('id' => $this->optionsettings->courseid));
            $courselink = \html_writer::link($courselink, $courselink->out());
        }
        $bookinglink = new \moodle_url('/mod/booking/view.php', array('id' => $this->cmid));
        $bookinglink = \html_writer::link($bookinglink, $bookinglink->out());

        // Default params.
        switch ($this->messageparam) {

            case MSGPARAM_SESSIONREMINDER:

                // We also add the URLs for the user to subscribe to user and course event calendar.
                $bu = new booking_utils();

                // These links will not be clickable (beacuse they will be copied by users).
                $params->usercalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
                $bu->booking_generate_calendar_subscription_link($this->user, 'user') .
                '</a>';

                $params->coursecalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
                $bu->booking_generate_calendar_subscription_link($this->user, 'courses') .
                '</a>';

                // Add a placeholder with a link to go to the current booking option.
                $gotobookingoptionlink = new \moodle_url($CFG->wwwroot . '/mod/booking/view.php', array(
                    'id' => $this->cmid,
                    'optionid' => $this->optionid,
                    'action' => 'showonlyone',
                    'whichview' => 'showonlyone'
                ));
                $params->gotobookingoption = \html_writer::link($gotobookingoptionlink, $gotobookingoptionlink->out());

                // For session reminders we only have ONE session.
                $sessions = [];
                foreach ($this->optionsettings->sessions as $session) {
                    if (!empty($session->optiondateid) && !empty($this->optiondateid)) {
                        if ($session->optiondateid == $this->optiondateid) {
                            $sessions[] = $session;
                        }
                    }
                }

                // Render optiontimes using a template.
                $output = $PAGE->get_renderer('mod_booking');
                $data = new optiondates_only($sessions);
                $params->optiontimes = $output->render_optiondates_only($data);

                // Params for specific session reminders.
                $params->status = $this->option->get_user_status_string($this->userid);
                $params->participant = fullname($this->user);
                $params->email = $this->user->email;
                $params->sessiondescription = get_rendered_eventdescription($this->optionid, $this->cmid, DESCRIPTION_CALENDAR);

                break;

            default:
                $params->qr_id = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
                rawurlencode($this->user->id) . '&choe=UTF-8" title="Link to Google.com" />';
                $params->qr_username = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
                    rawurlencode($this->user->username) . '&choe=UTF-8" title="Link to Google.com" />';

                $params->status = $this->option->get_user_status_string($this->user->id);
                $params->participant = fullname($this->user);
                $params->email = $this->user->email;
                $params->title = format_string($this->optionsettings->text);
                $params->duration = $this->bookingsettings->duration;
                $params->starttime = $this->optionsettings->coursestarttime ?
                    userdate($this->optionsettings->coursestarttime, $timeformat) : '';
                $params->endtime = $this->optionsettings->courseendtime ?
                    userdate($this->optionsettings->courseendtime, $timeformat) : '';
                $params->startdate = $this->optionsettings->coursestarttime ?
                    userdate($this->optionsettings->coursestarttime, $dateformat) : '';
                $params->enddate = $this->optionsettings->courseendtime ?
                    userdate($this->optionsettings->courseendtime, $dateformat) : '';
                $params->courselink = $courselink;
                $params->bookinglink = $bookinglink;
                $params->location = $this->optionsettings->location;
                $params->institution = $this->optionsettings->institution;
                $params->address = $this->optionsettings->address;
                $params->eventtype = $this->bookingsettings->eventtype;
                $params->shorturl = $this->optionsettings->shorturl;
                $params->pollstartdate = $this->optionsettings->coursestarttime ?
                    userdate((int) $this->optionsettings->coursestarttime, get_string('pollstrftimedate', 'booking')) : '';
                if (empty($this->optionsettings->pollurl)) {
                    $params->pollurl = $this->bookingsettings->pollurl;
                } else {
                    $params->pollurl = $this->optionsettings->pollurl;
                }
                if (empty($this->optionsettings->pollurlteachers)) {
                    $params->pollurlteachers = $this->bookingsettings->pollurlteachers;
                } else {
                    $params->pollurlteachers = $this->optionsettings->pollurlteachers;
                }

                // Render optiontimes using a template.
                $output = $PAGE->get_renderer('mod_booking');
                $data = new optiondates_only($this->optionsettings->sessions);
                $params->optiontimes = $output->render_optiondates_only($data);

                // Placeholder for the number of booked users.
                $params->numberparticipants = strval(count($this->option->get_all_users_booked()));
                // Placeholder for the number of users on the waiting list.
                $params->numberwaitinglist = strval(count($this->option->get_all_users_on_waitinglist()));

                // If there are changes, let's render them.
                if (!empty($this->changes)) {
                    $data = new bookingoption_changes($this->changes, $this->cmid);
                    $output = $PAGE->get_renderer('mod_booking');
                    $params->changes = $output->render_bookingoption_changes($data);
                }

                // Add placeholder {bookingdetails} so we can add the detailed option description (similar to calendar, modal...
                // ... and ical) to mails.
                $params->bookingdetails = get_rendered_eventdescription($this->optionsettings->id,
                    $this->cmid, DESCRIPTION_MAIL);

                break;
        }

        return $params;
    }

    /**
     * Generate the email body based on the message type and the booking parameters
     *
     * @param stdClass $params the booking details
     * @return string
     */
    private function get_email_body(stdClass $params): string {

        // List of fieldnames that also have a global template (currently 'activitycompletiontext' has no global template).
        $mailtemplatesfieldnames = [
            'bookedtext', 'waitingtext', 'notifyemail', 'notifyemailteachers', 'statuschangetext', 'userleave',
            'deletedtext', 'bookingchangedtext', 'pollurltext', 'pollurlteacherstext'
        ];

        // Check if global mail templates are enabled and if the field name also has a global mail template.
        if (isset($this->bookingsettings->mailtemplatessource) && $this->bookingsettings->mailtemplatessource == 1
            && in_array($this->messagefieldname, $mailtemplatesfieldnames)) {

            // Get the mail template specified in plugin config.
            $text = get_config('booking', 'global' . $this->messagefieldname);

        } else if (empty($this->bookingsettings->{$this->messagefieldname})) {

            // Use default message if none is specified.
            $text = get_string($this->messagefieldname . 'message', 'booking', $params);

        } else {

            // If there is an instance-specific template, then use it.
            $text = $this->bookingsettings->{$this->messagefieldname};

        }

        // Replace the placeholders.
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Get the actual message data needed to send the message
     * @param stdClass $params params needed for the message
     * @return message
     */
    private function get_message_data(stdClass $params): message {

        global $USER;

        $messagedata = new message();
        $messagedata->modulename = 'booking';

        // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
        if (!empty($this->bookingmanager)) {
            $messagedata->userfrom = $this->bookingmanager;
        } else {
            $messagedata->userfrom = $USER;
        }

        $messagedata->userto = $this->user;
        $messagedata->subject = get_string($this->messagefieldname . 'subject', 'booking', $params);
        $messagedata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $this->messagebody));
        $messagedata->fullmessageformat = FORMAT_HTML;
        $messagedata->fullmessagehtml = $this->messagebody;
        $messagedata->smallmessage = '';
        $messagedata->component = 'mod_booking';
        $messagedata->name = 'bookingconfirmation';
        $messagedata->courseid = $this->bookingsettings->course;

        return $messagedata;
    }

    /**
     * Helper function to get the fieldname for the message type.
     * @return string the field name
     */
    private function get_message_fieldname() {

        switch ($this->messageparam) {
            case MSGPARAM_CONFIRMATION:
                $fieldname = 'bookedtext';
                break;
            case MSGPARAM_WAITINGLIST:
                $fieldname = 'waitingtext';
                break;
            case MSGPARAM_REMINDER_PARTICIPANT:
                $fieldname = 'notifyemail';
                break;
            case MSGPARAM_REMINDER_TEACHER:
                $fieldname = 'notifyemailteachers';
                break;
            case MSGPARAM_STATUS_CHANGED:
                $fieldname = 'statuschangetext';
                break;
            case MSGPARAM_CANCELLED_BY_PARTICIPANT:
                $fieldname = 'userleave';
                break;
            case MSGPARAM_CANCELLED_BY_TEACHER:
                $fieldname = 'deletedtext';
                break;
            case MSGPARAM_CHANGE_NOTIFICATION:
                $fieldname = 'bookingchangedtext';
                break;
            case MSGPARAM_POLLURL_PARTICIPANT:
                $fieldname = 'pollurltext';
                break;
            case MSGPARAM_POLLURL_TEACHER:
                $fieldname = 'pollurlteacherstext';
                break;
            case MSGPARAM_COMPLETED:
                $fieldname = 'activitycompletiontext';
                break;
            case MSGPARAM_SESSIONREMINDER:
                $fieldname = 'sessionremindermail';
                break;
            case MSGPARAM_REPORTREMINDER:
                $fieldname = 'reportreminder';
                break;
            default:
                throw new moodle_exception('ERROR: Unknown message parameter!');
        }
        return $fieldname;
    }

    /**
     * Send the message.
     * @return bool true if sent successfully
     */
    public function send(): bool {

        global $USER;

        // Only send if we have message data and if the user hasn't been deleted.
        if ( !empty( $this->messagedata ) && !$this->user->deleted ) {

            if ($this->messageparam == MSGPARAM_CONFIRMATION ||
                $this->messageparam == MSGPARAM_WAITINGLIST ||
                $this->messageparam == MSGPARAM_CHANGE_NOTIFICATION) {

                $messagedata = new stdClass();

                $messagedata->userfrom = $this->messagedata->userfrom;

                if ($this->bookingsettings->sendmailtobooker) {
                    $messagedata->userto = $USER;
                } else {
                    $messagedata->userto = $this->user;
                }

                $messagedata->subject = $this->messagedata->subject;
                $messagedata->messagetext = format_text_email($this->messagebody, FORMAT_HTML);
                $messagedata->messagehtml = text_to_html($this->messagebody, false, false, true);

                // Add attachments if there are any.
                $attachments = $this->get_attachments();
                if (!empty($attachments)) {
                    $messagedata->attachment = $attachments;
                    $messagedata->attachname = '';
                }

                $sendtask = new task\send_confirmation_mails();
                $sendtask->set_custom_data($messagedata);
                \core\task\manager::queue_adhoc_task($sendtask);

                // If the setting to send a copy to the booking manger has been enabled,
                // then also send a copy to the booking manager.
                // Do not send copies of change notifications to booking managers.
                if ( $this->bookingsettings->copymail &&
                    ($this->messageparam == MSGPARAM_CONFIRMATION || $this->messageparam == MSGPARAM_WAITINGLIST )) {

                    $messagedata->userto = $this->bookingmanager;
                    $messagedata->subject .= 'bookingmanager';

                    $sendtask = new task\send_confirmation_mails();
                    $sendtask->set_custom_data($messagedata);
                    \core\task\manager::queue_adhoc_task($sendtask);
                }

                return true;

            } else {

                // In all other cases, use message_send.
                return message_send( $this->messagedata );

            }
        } else {
            return false;
        }
    }

    /**
     * Get the message body.
     * @return string the message body
     */
    public function get_messagebody(): string {

        return $this->messagebody;

    }

    /**
     * Get ical attachments.
     * @return array attachments
     */
    private function get_attachments(): array {
        $attachments = null;

        // Currently ical attachments can only be added to booking confirmations and change notifications.
        if ($this->messageparam == MSGPARAM_CONFIRMATION) {
            // Generate ical attachments to go with the message. Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, false);
                $attachments = $ical->get_attachments();
            }
        } else if ($this->messageparam == MSGPARAM_CHANGE_NOTIFICATION) {
            // Generate ical attachments to go with the message. Check if ical attachments enabled.
            // Set $updated param to true.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, true);
                $attachments = $ical->get_attachments();
            }
        }

        return $attachments;
    }
}
