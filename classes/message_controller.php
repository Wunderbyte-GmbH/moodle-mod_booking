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

/**
 * Manage booking messages which will be sent by email.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_controller {

    /** @var int $cmid course module id */
    private $cmid;

    /** @var int $bookingid booking id */
    private $bookingid;

    /** @var int $optionid */
    private $optionid;

    /** @var int $userid */
    private $userid;

    /** @var booking_settings $bookingsettings */
    private $bookingsettings;

    /** @var booking_option_settings $optionsettings */
    private $optionsettings;

    /** @var stdClass $user */
    private $user;

    /** @var message $messagedata */
    private $messagedata;

    /**
     * Constructor
     * @param int $cmid course module id
     * @param int $bookingid booking id
     * @param int $optionid option id
     * @param int $userid user id
     * @param string $messagetype the message type
     */
    public function __construct(int $cmid, int $bookingid, int $optionid, int $userid, string $messagetype) {

        global $DB;

        // Settings.
        $this->bookingsettings = new booking_settings($bookingid);
        $this->optionsettings = new booking_option_settings($optionid);

        // Standard params.
        $this->cmid = $cmid;
        $this->bookingid = $bookingid;
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->messagetype = $messagetype;

        // Generate user data.
        $this->user = $DB->get_record('user', array('id' => $userid));

        // Generate email params.
        $params = $this->get_email_params([], false, true);

        // Generate the email body.
        $messagebody = $this->get_email_body($messagetype, $params);

        // Generate full message data.
        $this->messagedata = $this->get_message_data($messagetype, $messagebody, $params);
    }

    /**
     * Prepares the email parameters.
     * @param array $changes
     * @param bool $issessionreminder
     * @param bool $includebookingdetails
     * @return stdClass data to be sent via mail
     */
    private function get_email_params(array $changes = [],
        bool $issessionreminder = false, bool $includebookingdetails = false): stdClass {

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
        if (!$issessionreminder) {
            $params->qr_id = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
                rawurlencode($this->user->id) . '&choe=UTF-8" title="Link to Google.com" />';
            $params->qr_username = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
                rawurlencode($this->user->username) . '&choe=UTF-8" title="Link to Google.com" />';

            $params->status = booking_get_user_status($this->user->id, $this->optionid, $this->bookingid, $this->cmid);
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
            $params->pollstartdate = $this->optionsettings->coursestarttime ? userdate((int) $this->optionsettings->coursestarttime,
            get_string('pollstrftimedate', 'booking')) : '';
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

            // Booking_option instance needed to access functions get_all_users_booked and get_all_users_on_waitinglist.
            $boption = new booking_option($this->cmid, $this->optionid);

            // Placeholder for the number of booked users.
            $params->numberparticipants = strval(count($boption->get_all_users_booked()));
            // Placeholder for the number of users on the waiting list.
            $params->numberwaitinglist = strval(count($boption->get_all_users_on_waitinglist()));

            // If there are changes, let's render them.
            if ($changes) {
                $data = new bookingoption_changes($changes, $this->cmid);
                $output = $PAGE->get_renderer('mod_booking');
                $params->changes = $output->render_bookingoption_changes($data);
            }

            // Add placeholder {bookingdetails} so we can add the detailed option description (similar to calendar, modal...
            // ... and ical) to mails.
            if ($includebookingdetails) {
                $params->bookingdetails = get_rendered_eventdescription($this->optionsettings->id,
                    $this->cmid, DESCRIPTION_MAIL);
            }

        } else {
            // Params for specific session reminders.
            $params->status = booking_get_user_status($this->userid, $this->optionid, $this->bookingid, $this->cmid);
            $params->participant = fullname($this->user);
            $params->email = $this->user->email;
            $params->sessiondescription = get_rendered_eventdescription($this->optionsettings->id,
                $this->cmid, DESCRIPTION_CALENDAR);
        }

        // We also add the URLs for the user to subscribe to user and course event calendar.
        $bu = new booking_utils();
        // Fix: These links should not be clickable (beacuse they will be copied by users), so add <pre>-Tags.
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

        return $params;
    }

    /**
     * Generate the email body based on the message type and the booking parameters
     *
     * @param string $messagetype the name of the field that contains the custom text
     * @param stdClass $params the booking details
     * @return string
     */
    private function get_email_body(string $messagetype, stdClass $params): string {

        // List of fieldnames that have a corresponding global mail templates.
        // TODO: add activitycompletiontext ??
        $mailtemplatesfieldnames = [
            'bookedtext', 'waitingtext', 'notifyemail', 'notifyemailteachers', 'statuschangetext', 'userleave',
            'deletedtext', 'bookingchangedtext', 'pollurltext', 'pollurlteacherstext'
        ];

        // Check if global mail templates are enabled and if the field name also has a global mail template.
        if (isset($this->bookingsettings->mailtemplatessource) && $this->bookingsettings->mailtemplatessource == 1
            && in_array($messagetype, $mailtemplatesfieldnames)) {
            // Get the mail template specified in plugin config.
            $text = get_config('booking', 'global' . $messagetype);
        } else if (empty($this->bookingsettings->$messagetype)) {
            $text = get_string($messagetype . 'message', 'booking', $params);
        } else {
            $text = $this->bookingsettings->{$messagetype};
        }

        // Replace the placeholders.
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Get the actual message data needed to send the message
     * @param string $messagetype
     * @param string $messagebody
     * @param stdClass $params
     * @return message
     */
    private function get_message_data(string $messagetype, string $messagebody, stdClass $params): message {

        global $DB, $USER;

        $messagedata = new message();
        $messagedata->modulename = 'booking';

        // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
        if ($bookingmanager = $DB->get_record('user',
            array('username' => $this->bookingsettings->bookingmanager))) {
            $messagedata->userfrom = $bookingmanager;
        } else {
            $messagedata->userfrom = $USER;
        }

        $messagedata->userto = $this->user;
        $messagedata->subject = get_string($messagetype . 'subject', 'booking', $params);
        $messagedata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $messagebody));
        $messagedata->fullmessageformat = FORMAT_HTML;
        $messagedata->fullmessagehtml = $messagebody;
        $messagedata->smallmessage = '';
        $messagedata->component = 'mod_booking';
        $messagedata->name = 'bookingconfirmation';

        return $messagedata;
    }

    /**
     * Send the message
     * @return bool true if sent successfully
     */
    public function send(): bool {
        if (!empty($this->messagedata)) {
            return message_send($this->messagedata);
        } else {
            return false;
        }
    }
}
