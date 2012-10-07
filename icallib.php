<?php
// This file is part of the booking module for Moodle - http://moodle.org/
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
 * Support class for generating ical items
 * Note - this code is based on the ical code from mod_facetoface
 *
 * @package   mod_booking
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class booking_ical {

    protected $booking;
    protected $option;
    protected $user;
    protected $fromuser;
    protected $tempfilename = '';

    /**
     * Create a new booking_ical instance
     * @param object $booking the booking activity details
     * @param object $option the option that is being booked
     * @param object $user the user the booking is for
     */
    public function __construct($booking, $option, $user, $fromuser) {
        $this->booking = $booking;
        $this->option = $option;
        $this->user = $user;
        $this->fromuser = $fromuser;
    }

    /**
     * Delete the temporary attachment file (if created) when the instance is destroyed.
     */
    public function __destruct() {
        global $CFG;
        if ($this->tempfilename) {
            @unlink($CFG->dataroot.'/'.$this->tempfilename);
        }
    }

    /**
     * Create an attachment to add to the notification email
     * @param bool $cancel optional - true to generate a 'cancel' ical event
     * @return string the path to the attachment file
     */
    public function get_attachment($cancel = false) {
        global $CFG;

        if (!get_config('booking', 'attachical')) {
            return ''; // ical attachments not enabled.
        }

        if (!$this->option->coursestarttime || !$this->option->courseendtime) {
            return ''; // missing start or end time for course.
        }

        // First, generate the VEVENT block
        $VEVENTS = '';

        // Date that this representation of the calendar information was created -
        // we use the time the option was last modified
        // http://www.kanzaki.com/docs/ical/dtstamp.html
        $DTSTAMP = $this->generate_timestamp($this->option->timemodified);

        // UIDs should be globally unique
        $urlbits = parse_url($CFG->wwwroot);
        $UID = md5($CFG->siteidentifier . $this->option->id . 'mod_booking_option') .   // Unique identifier, salted with site identifier
                '@' . $urlbits['host'];                                                    // Hostname for this moodle installation

        $DTSTART = $this->generate_timestamp($this->option->coursestarttime);
        $DTEND   = $this->generate_timestamp($this->option->courseendtime);

        // FIXME: currently we are not sending updates if the times of the
        // sesion are changed. This is not ideal!
        $SEQUENCE = 0;

        $SUMMARY     = $this->escape($this->booking->name);
        $DESCRIPTION = $this->escape($this->option->text, true);

        // NOTE: Newlines are meant to be encoded with the literal sequence
        // '\n'. But evolution presents a single line text field for location,
        // and shows the newlines as [0x0A] junk. So we switch it for commas
        // here. Remember commas need to be escaped too.
        if ($this->option->courseid) {
            $url = new moodle_url('/course/view.php', array('id' => $this->option->courseid));
            $LOCATION = $this->escape($url->out());
        } else {
            $LOCATION = '';
        }

        $ORGANISEREMAIL = $this->fromuser->email;

        $ROLE = 'REQ-PARTICIPANT';
        $CANCELSTATUS = '';
        if ($cancel) {
            $ROLE = 'NON-PARTICIPANT';
            $CANCELSTATUS = "\nSTATUS:CANCELLED";
        }

        $icalmethod = ($cancel) ? 'CANCEL' : 'REQUEST';

        // FIXME: if the user has input their name in another language, we need
        // to set the LANGUAGE property parameter here
        $USERNAME = fullname($this->user);
        $MAILTO   = $this->user->email;

        $VEVENTS .= <<<EOF
BEGIN:VEVENT
UID:{$UID}
DTSTAMP:{$DTSTAMP}
DTSTART:{$DTSTART}
DTEND:{$DTEND}
SEQUENCE:{$SEQUENCE}
SUMMARY:{$SUMMARY}
LOCATION:{$LOCATION}
DESCRIPTION:{$DESCRIPTION}
CLASS:PRIVATE
TRANSP:OPAQUE{$CANCELSTATUS}
ORGANIZER;CN={$ORGANISEREMAIL}:MAILTO:{$ORGANISEREMAIL}
ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$ROLE};PARTSTAT=NEEDS-ACTION;RSVP=FALSE;CN={$USERNAME};LANGUAGE=en:MAILTO:{$MAILTO}
END:VEVENT

EOF;

        $VEVENTS = trim($VEVENTS);

        // TODO: remove the hard-coded timezone!
        $template = <<<EOF
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Moodle//NONSGML Booking//EN
X-WR-RELCALID:{$UID}
CALSCALE:GREGORIAN
METHOD:{$icalmethod}
BEGIN:VTIMEZONE
TZID:/softwarestudio.org/Tzfile/Pacific/Auckland
X-LIC-LOCATION:Pacific/Auckland
BEGIN:STANDARD
TZNAME:NZST
DTSTART:19700405T020000
RRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=1SU;BYMONTH=4
TZOFFSETFROM:+1300
TZOFFSETTO:+1200
END:STANDARD
BEGIN:DAYLIGHT
TZNAME:NZDT
DTSTART:19700928T030000
RRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=9
TZOFFSETFROM:+1200
TZOFFSETTO:+1300
END:DAYLIGHT
END:VTIMEZONE
{$VEVENTS}
END:VCALENDAR
EOF;

        $template = str_replace("\n", "\r\n", $template);

        $this->tempfilename = md5($template);
        $tempfilepathname = $CFG->dataroot . '/' . $this->tempfilename;
        file_put_contents($tempfilepathname, $template);
        return $this->tempfilename;
    }

    public function get_name() {
        return 'booking.ics';
    }

    protected function generate_timestamp($timestamp) {
        return gmdate('Ymd', $timestamp) . 'T' . gmdate('His', $timestamp) . 'Z';
    }

    protected function escape($text, $converthtml=false) {
        if (empty($text)) {
            return '';
        }

        if ($converthtml) {
            $text = html_to_text($text);
        }

        $text = str_replace(
            array('\\',   "\n", ';',  ','),
            array('\\\\', '\n', '\;', '\,'),
            $text
        );

        // Text should be wordwrapped at 75 octets, and there should be one
        // whitespace after the newline that does the wrapping
        $text = wordwrap($text, 75, "\n ", true);

        return $text;
    }

}