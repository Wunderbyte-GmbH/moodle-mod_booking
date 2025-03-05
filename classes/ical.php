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
 * Support class for generating ical items Note - this code is based on the ical code from mod_facetoface
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Davo Smith, Synergy Learning, Andras Princic, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

/**
 * MOD_BOOKING_DESCRIPTION_ICAL
 *
 * @var int
 */
const MOD_BOOKING_DESCRIPTION_ICAL = 3;

/**
 * Class for generating ical items Note - this code is based on the ical code from mod_facetoface
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Davo Smith, Synergy Learning, Andras Princic, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ical {
    /**
     * $datesareset
     *
     * @var bool
     */
    private $datesareset = false;

    /**
     * $booking
     *
     * @var mixed
     */
    protected $booking;

    /**
     * $option
     *
     * @var mixed
     */
    protected $option;

    /**
     * $user
     *
     * @var mixed
     */
    protected $user;

    /**
     * $fromuser
     *
     * @var mixed
     */
    protected $fromuser;

    /**
     * $updated
     *
     * @var mixed
     */
    protected $updated;

    /**
     * $tempfilename
     *
     * @var string
     */
    protected $tempfilename = '';

    /**
     * $times
     *
     * @var array
     */
    protected $times = '';

    /**
     * $ical
     *
     * @var string
     */
    protected $ical = '';

    /**
     * $dtstamp
     *
     * @var string
     */
    protected $dtstamp = '';

    /**
     * $summary
     *
     * @var string
     */
    protected $summary = '';

    /**
     * $description
     *
     * @var string
     */
    protected $description = '';

    /**
     * $location
     *
     * @var string
     */
    protected $location = '';

    /**
     * $host
     *
     * @var string
     */
    protected $host = '';

    /**
     * $status
     *
     * @var string
     */
    protected $status = '';

    /**
     * $role
     *
     * @var string
     */
    protected $role = 'REQ-PARTICIPANT';

    /**
     * $partstat
     *
     * @var string
     */
    protected $partstat = 'NEEDS-ACTION';

    /**
     * $userfullname
     *
     * @var string
     */
    protected $userfullname = '';

    /**
     * $attachical
     *
     * @var bool
     */
    protected $attachical = false;

    /**
     * $individualvevents
     *
     * @var array
     */
    protected $individualvevents = [];

    /**
     * Create a new mod_booking\ical instance
     *
     * @param object $booking the booking activity details
     * @param object $option the option that is being booked
     * @param object $user the user the booking is for
     * @param object $fromuser
     * @param bool $updated if set to true, this will create an update ical
     */
    public function __construct($booking, $option, $user, $fromuser, $updated = false) {
        global $DB, $CFG;

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->booking = $booking;
        $this->option = $option;
        $this->fromuser = $fromuser;
        $this->updated = $updated;
        $this->times = $DB->get_records(
            'booking_optiondates',
            ['optionid' => $option->id],
            'coursestarttime ASC'
        );
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
            $now = time();
            $this->dtstamp = $this->generate_timestamp($now);
            $this->summary = $this->escape($settings->get_title_with_prefix());
            $this->description = $this->escape($settings->description ?? '', true);
            $urlbits = parse_url($CFG->wwwroot);
            $this->host = $urlbits['host'];
            $this->userfullname = \fullname($this->user);
            $this->attachical = \get_config('booking', 'attachical');
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
            $this->partstat = 'DECLINED';
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

        // This is where we attach the iCal.
        if (!empty($this->times) && $this->attachical) {
            $this->get_vevents_from_optiondates();
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
     * @return void
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
     * @param bool $time
     * @return void
     */
    protected function add_vevent($uid, $dtstart, $dtend, $time = false) {
        global $CFG, $DB, $PAGE;

        $eventid = false;
        if ($time) {
            // If it's an option date (a session), use the option date's eventid.
            $fulldescription = get_rendered_eventdescription($this->option->id, $this->booking->cmid, MOD_BOOKING_DESCRIPTION_ICAL);
        } else {
            // Use calendarid of the option if it's an option event.
            $fulldescription = get_rendered_eventdescription($this->option->id, $this->booking->cmid, MOD_BOOKING_DESCRIPTION_ICAL);
        }

        // Make sure we have not tags in full description.
        $fulldescriptionhtml = $fulldescription;
        // Remove CR and CRLF from description as the description must be on one line.
        $fulldescriptionhtml = str_replace(["\r\n", "\n", "\r"], ' ', $fulldescriptionhtml);

        // Check for a url and render it as a nice link.
        // Regular Expression Pattern for a basic URL.
        $pattern = '/\b(?:https?:\/\/)[a-zA-Z0-9\.\-]+(?:\.[a-zA-Z]{2,})(?:\/\S*)?/';
        // Array to hold the matched URLs.
        $matches = [];
        // Perform the pattern match.
        preg_match_all($pattern, $fulldescriptionhtml, $matches);

        foreach ($matches[0] as $url) {
            $fulldescriptionhtml = str_replace($url, '<a href="' . $url . '">Link</a>', $fulldescriptionhtml);
        }

        $fulldescription = rtrim(strip_tags(preg_replace("/<br>|<\/p>/", "\n", $fulldescription)));
        $fulldescription = str_replace("\n", "\\n", $fulldescription);

        // Remove CR and CRLF from description as the description must be on one line to work with ical.
        $fulldescription = str_replace(["\r\n", "\n", "\r"], ' ', $fulldescription);

        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

        // If no bookingmanager was set, we fall back to the no-reply address.
        $fromuseremail = empty($this->fromuser->email) ? $noreplyaddress : $this->fromuser->email;

        $veventparts = [
            "BEGIN:VEVENT",
            "CLASS:PUBLIC",
            "DESCRIPTION:{$fulldescription}",
            "X-ALT-DESC;FMTTYPE=text/html:{$fulldescriptionhtml}",
            "DTEND:{$dtend}",
            "DTSTAMP:{$this->dtstamp}",
            "DTSTART:{$dtstart}",
            "LOCATION:{$this->location}",
            "PRIORITY:5",
            "SUMMARY:{$this->summary}",
            "TRANSP:OPAQUE{$this->status}",
            "ORGANIZER;CN={$fromuseremail}:MAILTO:{$fromuseremail}",
            "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$this->role};PARTSTAT={$this->partstat};RSVP=false;" .
                "CN={$this->userfullname};LANGUAGE=en:MAILTO:{$this->user->email}",
            "UID:{$uid}",
        ];

        // If the event has been updated then add the sequence value before END:VEVENT.
        if ($this->updated) {
            if (!$data = $DB->get_record('booking_icalsequence', ['userid' => $this->user->id, 'optionid' => $this->option->id])) {
                $data = new \stdClass();
                $data->userid = $this->user->id;
                $data->optionid = $this->option->id;
                $data->sequencevalue = 2;
                $DB->insert_record('booking_icalsequence', $data);
                $sequencevalue = $data->sequencevalue;
            } else {
                ++$data->sequencevalue;
                $DB->update_record('booking_icalsequence', $data);
                $sequencevalue = $data->sequencevalue;
            }
        } else {
            $sequencevalue = 1;
        }
        array_push($veventparts, "SEQUENCE:$sequencevalue");
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

    /**
     * String escape
     *
     * @param string $text
     * @param bool $converthtml
     *
     * @return string
     *
     */
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
