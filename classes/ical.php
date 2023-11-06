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

const DESCRIPTION_ICAL = 3;

/**
 * Support class for generating ical items Note - this code is based on the ical code from mod_facetoface
 *
 * @package mod_booking
 * @copyright 2012-2017 Davo Smith, Synergy Learning, Andras Princic, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ical {

    private $datesareset = false;

    protected $booking;

    protected $option;

    protected $user;

    protected $fromuser;

    protected $updated;

    protected $tempfilename = '';

    protected $times = '';

    protected $ical = '';

    protected $dtstamp = '';

    protected $summary = '';

    protected $description = '';

    protected $location = '';

    protected $host = '';

    protected $status = '';

    protected $role = 'REQ-PARTICIPANT';

    protected $userfullname = '';

    protected $attachical = false;

    protected $attachicalsessions = false;

    protected $individualvevents = [];

    /**
     * Create a new mod_booking\ical instance
     *
     * @param object $booking the booking activity details
     * @param object $option the option that is being booked
     * @param object $user the user the booking is for
     * @param bool $updated if set to true, this will create an update ical (METHOD: REQUEST, SEQUENCE: 1)
     */
    public function __construct($booking, $option, $user, $fromuser, $updated = false) {
        global $DB, $CFG;

        $this->booking = $booking;
        $this->option = $option;
        $this->fromuser = $fromuser;
        $this->updated = $updated;
        $this->times = $DB->get_records('booking_optiondates', ['optionid' => $option->id],
                'coursestarttime ASC');
        // Check if start and end dates exist.
        $coursedates = ($this->option->coursestarttime && $this->option->courseendtime);
        $sessiontimes = !empty($this->times);
        // NOTE: Newlines are meant to be encoded with the literal sequence
        // '\n'. But evolution presents a single line text field for location,
        // and shows the newlines as [0x0A] junk. So we switch it for commas
        // here. Remember commas need to be escaped too.
        if ($this->option->courseid && (\get_config('booking', 'icalfieldlocation') == 1)) {
            $url = new \moodle_url('/course/view.php', ['id' => $this->option->courseid]);
            $this->location = $this->escape($url->out());
        } else if (\get_config('booking', 'icalfieldlocation') == 2) {
            $this->location = $this->option->location;
        } else if (\get_config('booking', 'icalfieldlocation') == 3) {
            $this->location = $this->option->institution;
        } else if (\get_config('booking', 'icalfieldlocation') == 4) {
            $this->location = $this->option->address;
        }
        if (($coursedates || $sessiontimes)) {
            $this->datesareset = true;
            $this->user = $DB->get_record('user', ['id' => $user->id]);
            // Date that this representation of the calendar information was created -
            // See http://www.kanzaki.com/docs/ical/dtstamp.html.
            $this->dtstamp = $this->generate_timestamp($this->option->timemodified);
            $this->summary = $this->escape($this->booking->name . " - " . $this->option->text);
            $this->description = $this->escape($this->option->text, true);
            $urlbits = parse_url($CFG->wwwroot);
            $this->host = $urlbits['host'];
            $this->userfullname = \fullname($this->user);
            $this->attachical = \get_config('booking', 'attachical');
            $this->attachicalsessions = \get_config('booking', 'attachicalsessions');
        }
    }

    /**
     * Create attachments to add to the notification email
     *
     * @param bool $cancel optional - true to generate a 'cancel' ical event
     * @return array with filename as key and fielpath as value empty array if no dates are set
     */
    public function get_attachments($cancel = false) {
        global $CFG;
        if (!$this->datesareset) {
            return [];
        }

        // UIDs should be globally unique. @$this->host: Hostname for this moodle installation.
        $uid = md5($CFG->siteidentifier . $this->option->id . 'mod_booking_option') . '@' . $this->host;
        $dtstart = $this->generate_timestamp($this->option->coursestarttime);
        $dtend = $this->generate_timestamp($this->option->courseendtime);

        if ($cancel) {
            $this->role = 'NON-PARTICIPANT';
            $this->status = "\nSTATUS:CANCELLED";
        }

        // Determine the correct iCal method.
        if ($cancel) {
            $icalmethod = 'CANCEL';
        } else if ($this->updated) {
            $icalmethod = 'REQUEST';
        } else {
            $icalmethod = 'PUBLISH';
        }

        if (!empty($this->times) && $this->attachicalsessions) {
            $this->get_vevents_from_optiondates();
        } else if ($this->attachical && $this->option->coursestarttime) {
            $this->add_vevent($uid, $dtstart, $dtend);
        }

        $allvevents = trim(implode("\r\n", $this->individualvevents));
        $icaldata = $this->generate_ical_string($icalmethod, $allvevents);
        $filepathname = $this->generate_tempfile($icaldata);
        return ['booking.ics' => $filepathname];
    }

    /**
     * Generate temporary ical file and return path to tempfile
     *
     * @param string $icaldata ical conform string
     * @return string path to tempfile
     */
    protected function generate_tempfile($icaldata) {
        global $CFG;
        $this->tempfilename = md5($icaldata . microtime());
        $tempfilepathname = $CFG->tempdir . '/' . $this->tempfilename;
        file_put_contents($tempfilepathname, $icaldata);
        return $tempfilepathname;
    }

    /**
     * Generate ical data for ical.ics conform string
     *
     * @param string $icalmethod
     * @param string $vevents
     * @return string ical
     */
    protected function generate_ical_string($icalmethod, $vevents) {
        $icalparts = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'METHOD:' . $icalmethod,
            'PRODID:Data::ICal 0.22',
            'CALSCALE:GREGORIAN',
            $vevents,
            'END:VCALENDAR',
        ];
        return implode("\r\n", $icalparts);
    }

    /**
     * Get vevents based on session times that are defined in the booking options.
     *
     * @return string $vevent
     */
    protected function get_vevents_from_optiondates() {
        global $CFG;
        $vevents = [];
        foreach ($this->times as $time) {
            $dtstart = $this->generate_timestamp($time->coursestarttime);
            $dtend = $this->generate_timestamp($time->courseendtime);
            $uid = md5($CFG->siteidentifier . $time->id . $this->option->id . 'mod_booking_option') . '@' . $this->host;
            $this->add_vevent($uid, $dtstart, $dtend, $time);
        }
    }

    /**
     * Add vevent data to ical string
     *
     * @param string $uid
     * @param string $dtstart
     * @param string $dtend
     * @return string $vevent vevent
     */
    protected function add_vevent ($uid, $dtstart, $dtend, $time = false) {
        global $CFG, $DB, $PAGE;

        $eventid = false;
        if ($time) {
            // If it's an option date (a session), use the option date's eventid.
            $fulldescription = get_rendered_eventdescription($this->option->id, $this->booking->cmid, DESCRIPTION_ICAL);
        } else {
            // Use calendarid of the option if it's an option event.
            $fulldescription = get_rendered_eventdescription($this->option->id, $this->booking->cmid, DESCRIPTION_ICAL);
        }

        // Make sure we have not tags in full description.
        $fulldescription = rtrim(strip_tags(preg_replace( "/<br>|<\/p>/", "\n", $fulldescription)));
        $fulldescription = str_replace("\n", "\\n", $fulldescription );

        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

        // If no bookingmanager was set, we fall back to the no-reply address.
        $fromuseremail = empty($this->fromuser->email) ? $noreplyaddress : $this->fromuser->email;

        $veventparts = [
            "BEGIN:VEVENT",
            "CLASS:PUBLIC",
            "DESCRIPTION:{$fulldescription}",
            "DTEND:{$dtend}",
            "DTSTAMP:{$this->dtstamp}",
            "DTSTART:{$dtstart}",
            "LOCATION:{$this->location}",
            "PRIORITY:5",
            // phpcs:ignore moodle.Commenting.InlineComment.NotCapital,Squiz.PHP.CommentedOutCode.Found
            // "SEQUENCE:0",
            "SUMMARY:{$this->summary}",
            "TRANSP:OPAQUE{$this->status}",
            "ORGANIZER;CN={$fromuseremail}:MAILTO:{$fromuseremail}",
            "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$this->role};PARTSTAT=NEEDS-ACTION;RSVP=false;" .
                "CN={$this->userfullname};LANGUAGE=en:MAILTO:{$this->user->email}",
            "UID:{$uid}",
        ];

        // If the event has been updated then add SEQUENCE:1 before END:VEVENT.
        if ($this->updated) {
            if (!$data = $DB->get_record('booking_icalsequence', ['userid' => $this->user->id, 'optionid' => $this->option->id])) {
                $data = new \stdClass();
                $data->userid = $this->user->id;
                $data->optionid = $this->option->id;
                $data->sequencevalue = 1;
                $DB->insert_record('booking_icalsequence', $data);
            } else {
                ++$data->sequencevalue;
                $DB->update_record('booking_icalsequence', $data);
            }
            array_push($veventparts, "SEQUENCE:$data->sequencevalue");
        }
        array_push($veventparts, "END:VEVENT");

        $vevent = implode("\r\n", $veventparts);
        $this->individualvevents[] = $vevent;
    }

    /**
     * Filename of attached ical file.
     *
     * @return string
     */
    public function get_name() {
        return 'booking.ics';
    }

    /**
     * Format timestamp.
     * @param int $timestamp
     * @return string
     */
    protected function generate_timestamp($timestamp) {
        return gmdate('Ymd', $timestamp) . 'T' . gmdate('His', $timestamp) . 'Z';
    }

    protected function escape($text, $converthtml = false) {
        if (empty($text)) {
            return '';
        }

        if ($converthtml) {
            $text = html_to_text($text);
        }

        $text = str_replace(['\\', "\n", ';', ','], ['\\\\', '\n', '\;', '\,'], $text);

        // Text should be wordwrapped at 75 octets, and there should be one whitespace after the newline that does the wrapping.
        $text = wordwrap($text, 75, "\n ", true);

        return $text;
    }
}
