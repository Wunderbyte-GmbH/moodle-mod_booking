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

/**
 * Manage booking messages which will be sent by email
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_message {

    /** @var int $cmid course module id */
    private $cmid;

    /** @var int $bookingid booking id */
    private $bookingid;

    /** @var int $optionid */
    private $optionid;

    /** @var int $userid */
    private $userid;

    /** @var string $messagetype */
    private $messagetype;

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

        global $DB, $USER;

        $this->cmid = $cmid;
        $this->bookingid = $bookingid;
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->messagetype = $messagetype;

        // Generate settings.
        $this->bookingsettings = new booking_settings($bookingid);
        $this->optionsettings = new booking_option_settings($optionid);

        // TODO: We should get rid of this somehow. Currently we need it only because of optiontimes.
        $bookingoption = new booking_option($this->cmid, $this->optionid);
        $optiontimes = $bookingoption->optiontimes;

        // Generate user data.
        $this->user = $DB->get_record('user', array('id' => $userid));

        // Generate email params.
        $params = $this->generate_email_params($optiontimes, false, false, true);

        // TODO: Generate email body.
        $messagebody = '';
        // $pollurlmessage = booking_get_email_body($bookingoption->booking->settings, 'pollurlteacherstext',
        // 'pollurlteacherstextmessage', $params);

        // Generate full message data.
        $this->messagedata = new message();
        $this->messagedata->modulename = 'booking';

        // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
        if ($bookingmanager = $DB->get_record('user',
            array('username' => $this->bookingsettings->bookingmanager))) {
            $this->messagedata->userfrom = $bookingmanager;
        } else {
            $this->messagedata->userfrom = $USER;
        }

        $this->messagedata->userto = $this->user;
        $this->messagedata->subject = get_string($this->messagetype . 'subject', 'booking', $params);
        $this->messagedata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $messagebody));
        $this->messagedata->fullmessageformat = FORMAT_HTML;
        $this->messagedata->fullmessagehtml = $messagebody;
        $this->messagedata->smallmessage = '';
        $this->messagedata->component = 'mod_booking';
        $this->messagedata->name = $this->messagetype;

    }

    /**
     * Prepares the email parameters.
     * @param string $optiontimes
     * @param bool|array $changes
     * @param bool $issessionreminder
     * @param bool $includebookingdetails
     * @return stdClass data to be sent via mail
     */
    private function generate_email_params($optiontimes = '', $changes = false,
        $issessionreminder = false, $includebookingdetails = false) {

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

            $val = '';

            if (!empty($optiontimes)) {

                $times = explode(',', trim($optiontimes, ','));
                $i = 1;
                foreach ($times as $time) {
                    $slot = explode('-', $time);
                    $tmpdate = new stdClass();
                    $tmpdate->number = $i;
                    $tmpdate->date = userdate($slot[0], get_string('strftimedate', 'langconfig'));
                    $tmpdate->starttime = userdate($slot[0], get_string('strftimetime', 'langconfig'));
                    $tmpdate->endtime = userdate($slot[1], get_string('strftimetime', 'langconfig'));
                    $val .= get_string('optiondatesmessage', 'mod_booking', $tmpdate) . '<br><br>';
                    $i++;
                }
            } else {
                if ($this->optionsettings->coursestarttime && $this->optionsettings->courseendtime) {
                    $tmpdate = new stdClass();
                    $tmpdate->number = '';
                    $tmpdate->date = userdate($this->optionsettings->coursestarttime, get_string('strftimedate', 'langconfig'));
                    $tmpdate->starttime = userdate($this->optionsettings->coursestarttime,
                        get_string('strftimetime', 'langconfig'));
                    $tmpdate->endtime = userdate($this->optionsettings->courseendtime, get_string('strftimetime', 'langconfig'));
                    $val .= get_string('optiondatesmessage', 'mod_booking', $tmpdate) . '<br><br>';
                    $params->times = "$params->startdate $params->starttime - $params->enddate $params->endtime";
                }
            }
            $params->times = $val;

            // Booking_option instance needed to access functions get_all_users_booked and get_all_users_on_waitinglist.
            $boption = new booking_option($this->cmid, $this->optionid);

            // Placeholder for the number of booked users.
            $params->numberparticipants = strval(count($boption->get_all_users_booked()));
            // Placeholder for the number of users on the waiting list.
            $params->numberwaitinglist = strval(count($boption->get_all_users_on_waitinglist()));

            // If there are changes, let's render them.
            if ($changes) {
                $data = new \mod_booking\output\bookingoption_changes($changes, $this->cmid);
                $output = $PAGE->get_renderer('mod_booking');
                $params->changes = $output->render_bookingoption_changes($data);
            }

            // Add placeholder {bookingdetails} so we can add the detailed option description (similar to calendar, modal...
            // ... and ical) to mails.
            if ($includebookingdetails) {
                $params->bookingdetails = get_rendered_eventdescription($this->optionsettings,
                    $this->cmid, false, DESCRIPTION_MAIL);
            }

        } else {
            // Params for specific session reminders.
            $params->status = booking_get_user_status($this->userid, $this->optionid, $this->bookingid, $this->cmid);
            $params->participant = fullname($this->user);
            $params->email = $this->user->email;
            $params->sessiondescription = get_rendered_eventdescription($this->optionsettings,
                $this->cmid, $optiontimes[0], DESCRIPTION_CALENDAR);
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
     * Send the message
     * @return bool
     */
    public function send(): bool {
        if (!empty($this->messagedata)) {
            return message_send($this->messagedata);
        } else {
            return false;
        }
    }
}
