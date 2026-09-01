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

use mod_booking\output\description\description_ical;

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
     * iTIP method for a meeting request: the mail client offers accept/decline buttons.
     *
     * @var string
     */
    public const METHOD_REQUEST = 'REQUEST';

    /**
     * iTIP method for publishing events: they can be imported, but there are no accept/decline buttons.
     *
     * @var string
     */
    public const METHOD_PUBLISH = 'PUBLISH';

    /**
     * iTIP method for cancelling events.
     *
     * @var string
     */
    public const METHOD_CANCEL = 'CANCEL';

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
     * $individualvevents
     *
     * @var array
     */
    protected $individualvevents = [];

    /**
     * The iTIP method of the ical which is currently generated, see get_method().
     *
     * @var string
     */
    protected $method = '';

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
        if (($coursedates || $sessiontimes)) {
            $this->datesareset = true;
            $this->user = $DB->get_record('user', ['id' => $user->id]);
            $now = time();
            $this->dtstamp = $this->generate_timestamp($now);
            $urlbits = parse_url($CFG->wwwroot);
            $this->host = $urlbits['host'];
            $this->userfullname = \fullname($this->user);
        }
    }

    /**
     * Set summary, location and description of the event.
     *
     * These are the texts an author can write in several languages, so they are run through
     * format_string / format_text to resolve multilang tags for the language which is current at
     * this moment - which is the language of the receiving user, see get_attachments().
     *
     * @return void
     */
    protected function set_localized_properties(): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);

        $context = !empty($settings->cmid)
            ? \context_module::instance($settings->cmid, IGNORE_MISSING)
            : false;
        $context = $context ?: \context_system::instance();
        // The ical is plain text, so html entities would end up being shown literally.
        $stringoptions = ['context' => $context, 'escape' => false];

        /* NOTE: Newlines are meant to be encoded with the literal sequence
        '\n'. But evolution presents a single line text field for location,
        and shows the newlines as [0x0A] junk. So we switch it for commas
        here. Remember commas need to be escaped too. */
        $icalfieldlocation = (int)\get_config('booking', 'icalfieldlocation');
        if ($this->option->courseid && $icalfieldlocation == 1) {
            $url = new \moodle_url('/course/view.php', ['id' => $this->option->courseid]);
            $this->location = $this->escape($url->out());
        } else if ($icalfieldlocation == 2) {
            $this->location = $this->escape(format_string($this->option->location ?? '', true, $stringoptions));
        } else if ($icalfieldlocation == 3) {
            $this->location = $this->escape(format_string($this->option->institution ?? '', true, $stringoptions));
        } else if ($icalfieldlocation == 4) {
            $this->location = $this->escape(format_string($this->option->address ?? '', true, $stringoptions));
        }

        $this->summary = $this->escape(format_string($settings->get_title_with_prefix(), true, $stringoptions));
        $this->description = $this->escape(
            format_text($settings->description ?? '', FORMAT_HTML, ['noclean' => true, 'context' => $context]),
            true
        );
    }

    /**
     * Create attachments to add to the notification email.
     *
     * @param bool $cancel optional - true to generate a 'cancel' ical event
     * @return array with filename as key and field path as value empty array if no dates are set
     */
    public function get_attachments($cancel = false): array {
        if (!$this->datesareset) {
            return [];
        }

        if ($cancel) {
            $this->role = 'NON-PARTICIPANT';
            $this->partstat = 'DECLINED';
            $this->status = "\nSTATUS:CANCELLED";
        }
        // Determine the correct iCal method.
        $icalmethod = $this->get_method($cancel);
        $this->method = $icalmethod;

        /* The ical is created for one specific user. So everything in it has to be in the language
        of this user and not in the language of whoever - or of whatever cron job - triggered the
        mail. Mails do this in message_controller, but the ical is not always created from there. */
        $originallanguage = '';
        if (!empty($this->user->lang)) {
            $originallanguage = force_current_language($this->user->lang);
        }

        try {
            // Summary, location and description have to be resolved for the language of the user.
            $this->set_localized_properties();

            // This is where we attach the iCal.
            if (!empty($this->times)) {
                $this->get_vevents_from_optiondates();
            }

            $allvevents = trim(implode("\r\n", $this->individualvevents));
            $icaldata = $this->generate_ical_string($icalmethod, $allvevents);
            $filepathname = $this->generate_tempfile($icaldata);
        } finally {
            if (!empty($originallanguage)) {
                force_current_language($originallanguage);
            }
        }

        return ['booking.ics' => $filepathname];
    }

    /**
     * Get the iTIP method (RFC 5546) which is used for the ical.
     *
     * A cancellation is always sent as METHOD:CANCEL.
     *
     * For the creation (or update) of the events, the method depends on the number of dates of the
     * booking option: A METHOD:REQUEST is a meeting request, for which mail clients like Outlook
     * offer the accept/decline buttons. But RFC 5546 (section 3.2.2) demands that all VEVENTs of a
     * REQUEST have the same UID, so it can only carry ONE event. Outlook (Windows and web) strictly
     * follows this and imports just the first VEVENT of a REQUEST with several events, while other
     * clients (Apple Calendar, Outlook for Mac) import all of them. Therefore, a REQUEST is only
     * used as long as the option has one single date. For options with several dates we fall back
     * to METHOD:PUBLISH, which allows any number of independent VEVENTs (RFC 5546, section 3.2.1)
     * and which every client imports completely - at the price of having no accept/decline buttons.
     *
     * @param bool $cancel true if the ical cancels the events
     * @return string one of the METHOD_ constants
     */
    public function get_method(bool $cancel = false): string {
        if ($cancel) {
            return self::METHOD_CANCEL;
        }
        if (count($this->times) > 1) {
            return self::METHOD_PUBLISH;
        }
        return self::METHOD_REQUEST;
    }

    /**
     * Generate temporary ical file and return path to tempfile
     *
     * @param string $icaldata ical conform string
     * @return string path to tempfile
     */
    protected function generate_tempfile($icaldata) {
        // The path is stored in the customdata of an adhoc task and read again by cron in a
        // later request, so this must NOT use make_request_directory(): that directory is
        // deleted at the end of the current request, and the attachment would be gone before
        // the mail is actually sent.
        $this->tempfilename = md5($icaldata . microtime());
        $tempfilepathname = make_temp_directory('mod_booking/ical') . '/' . $this->tempfilename;
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
        // The ical is created for one specific user, so user related placeholders of a user defined
        // description have to be rendered for this user and not for the one triggering the mail.
        if ($time) {
            // If it's an option date (a session), use the option date's eventid.
            $descriptionical = new description_ical($this->option->id, false, $this->user->id);
            $fulldescription = $descriptionical->render();
        } else {
            // Use calendarid of the option if it's an option event.
            $descriptionical = new description_ical($this->option->id, false, $this->user->id);
            $fulldescription = $descriptionical->render();
        }

        // The description we get here is HTML. We need two versions of it: the HTML one for
        // X-ALT-DESC and a plain text one for DESCRIPTION.
        $fulldescriptionhtml = $fulldescription;
        // Remove CR and CRLF from description as the description must be on one line.
        $fulldescriptionhtml = str_replace(["\r\n", "\n", "\r"], ' ', $fulldescriptionhtml);

        // Check for a url and render it as a nice link.
        $fulldescriptionhtml = $this->linkify_plain_urls($fulldescriptionhtml);

        // Limit line length to 75 characters.
        $fulldescriptionhtml = $this->fold_html_line("X-ALT-DESC;FMTTYPE=text/html:" . $fulldescriptionhtml);

        $fulldescription = $this->html_to_ical_text($fulldescription);
        // Limit line length to 75 characters.
        $fulldescription = $this->fold_line("DESCRIPTION:" . $fulldescription);

        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

        // If no bookingmanager was set, we fall back to the no-reply address.
        $fromuseremail = empty($this->fromuser->email) ? $noreplyaddress : $this->fromuser->email;
        if (!empty($this->fromuser->firstname) || !empty($this->fromuser->lastname)) {
            $fromusername = "{$this->fromuser->firstname} {$this->fromuser->lastname}";
        } else {
            $fromusername = "{$CFG->wwwroot}";
        }

        /* Fold the attendee line if it is more than 75 characters long. The language is the one of
        the receiving user, moodle writes it with an underscore (e.g. de_du), the ical with a
        hyphen. */
        $language = str_replace('_', '-', current_language());
        $attendee = "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$this->role};PARTSTAT={$this->partstat};RSVP=TRUE;" .
                "CN={$this->userfullname};LANGUAGE={$language}:MAILTO:{$this->user->email}";
        // The fold_line function keeps the ATTENDEE line valid by adding a space at the start of the next line
        // whenever the line breaks.
        $attendee = $this->fold_line($attendee);

        $veventparts = [
            "BEGIN:VEVENT",
            "CLASS:PUBLIC",
            "{$fulldescription}",
            "{$fulldescriptionhtml}",
            "DTEND:{$dtend}",
            "DTSTAMP:{$this->dtstamp}",
            "DTSTART:{$dtstart}",
            "PRIORITY:5",
            "SUMMARY:{$this->summary}",
            "TRANSP:OPAQUE{$this->status}",
            "ORGANIZER;CN={$fromusername}:MAILTO:{$fromuseremail}",
        ];

        // A published event has no attendees, RFC 5546 (section 3.2.1) does not allow the ATTENDEE
        // property for METHOD:PUBLISH. The attendee only belongs to a REQUEST or CANCEL, where the
        // recipient is asked to accept or decline the meeting.
        if ($this->method !== self::METHOD_PUBLISH) {
            $veventparts[] = "{$attendee}";
        }
        $veventparts[] = "UID:{$uid}";

        if (!empty($this->location)) {
            $veventparts[] = "LOCATION:{$this->location}";
        }

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
     * Turn bare URLs of an HTML string into links.
     *
     * Placeholders like {bookingoptiondetaillink} already return ready made anchors and the
     * description may contain other tags with URLs in their attributes (e.g. images). Those must be
     * left alone, otherwise we would end up with anchors nested into anchors and with URLs replaced
     * inside of attributes.
     *
     * @param string $html
     * @return string
     */
    protected function linkify_plain_urls(string $html): string {
        // Regular Expression Pattern for a basic URL.
        $pattern = '/\b(?:https?:\/\/)[a-zA-Z0-9\.\-]+(?:\.[a-zA-Z]{2,})(?:\/\S*)?/';

        // Split off complete anchor elements and all other tags, so that only the text nodes which
        // are not part of a link are left in the even numbered segments.
        $segments = preg_split(
            '/(<a\b[^>]*>.*?<\/a>|<[^>]+>)/is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($segments === false) {
            return $html;
        }

        foreach ($segments as $index => $segment) {
            // Odd indexes hold the captured anchors and tags, they stay untouched.
            if ($index % 2 === 1 || $segment === '') {
                continue;
            }
            $segments[$index] = preg_replace_callback($pattern, function ($matches) {
                return '<a href="' . $matches[0] . '">Link</a>';
            }, $segment);
        }

        return implode('', $segments);
    }

    /**
     * Convert the HTML description into a plain text value which is valid for an iCal TEXT property.
     *
     * @param string $html
     * @return string
     */
    protected function html_to_ical_text(string $html): string {
        // Keep the line breaks of the block level elements before we remove the tags.
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>#i', "\n", $html);
        $text = strip_tags($text);

        // Without decoding, entities like &amp; would show up in the plain text and break URLs.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize the whitespace: no CR, no runs of spaces and no runs of empty lines.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Escape the special characters as defined in RFC 5545. The backslash has to come first.
        $text = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $text);

        // The value has to be on one single line, so line breaks become the literal \n sequence.
        return str_replace("\n", '\n', $text);
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

        /* Text should be wordwrapped at 75 octets, and there should be one whitespace after the
        newline that does the wrapping. The lines of an ical are separated by CRLF, a single LF
        would make the file invalid. */
        $text = wordwrap($text, 75, "\r\n ", true);

        return $text;
    }

    /**
     * Fold a single iCalendar content line to <=75 octets using RFC 5545 folding.
     * Preserves UTF-8 characters (does not split mid-char) and adds CRLF + space.
     * @param string $line
     * @param int $limit
     * @return string
     */
    public function fold_line(string $line, int $limit = 75): string {
        $out = '';
        $bytes = 0;
        $chunk = '';

        // Iterate by Unicode chars but track byte length.
        $len = mb_strlen($line, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($line, $i, 1, 'UTF-8');
            $chbytes = strlen($ch); // Bytes for this char in UTF-8.

            if ($bytes + $chbytes >= $limit) {
                // Emit current chunk, then start a new folded line.
                $out .= $chunk . "\r\n" . ' ';
                $chunk = $ch;
                $bytes = $chbytes + 1; // Account for the leading space on next physical line.
            } else {
                $chunk .= $ch;
                $bytes += $chbytes;
            }
        }
        return $out . $chunk;
    }

    /**
     * Fold a long iCalendar HTML line (X-ALT-DESC) without breaking tags or URLs.
     *
     * - Ensures each physical line is <=75 octets (including the leading space on
     *   continuation lines).
     * - Prefers folding at spaces or after '>' (end of tag).
     * - Avoids breaking inside "http://", "https://".
     * - Uses CRLF line endings.
     *
     * @param string $line
     * @param int $limit
     * @return string
     */
    public function fold_html_line(string $line, int $limit = 75): string {
        $encoding = 'UTF-8';
        $out = '';
        $offset = 0;
        $first = true;
        $total = strlen($line);

        while ($offset < $total) {
            $maxcontent = $first ? $limit : $limit - 1; // Continuation reserves 1 byte for space.
            if (($total - $offset) <= $maxcontent) {
                // Last chunk fits.
                $chunk = substr($line, $offset);
                $offset = $total;
            } else {
                // Propose a chunk.
                $chunk = mb_strcut($line, $offset, $maxcontent, $encoding);

                // Look for safe break position inside chunk.
                $safepos = max(
                    strrpos($chunk, ' '), // Last space.
                    strrpos($chunk, '>') // Or after tag.
                );

                // Avoid cutting inside URLs.
                $httppos = strrpos($chunk, 'http');
                if ($httppos !== false && $httppos > $safepos) {
                    $safepos = strrpos(substr($chunk, 0, $httppos), ' ');
                }

                if ($safepos !== false && $safepos > 0) {
                    $chunk = substr($chunk, 0, $safepos + 1); // Keep delimiter.
                }
                $offset += strlen($chunk);
            }

            if ($first) {
                $out .= $chunk;
                $first = false;
            } else {
                $out .= "\r\n" . ' ' . $chunk;
            }
        }
        return $out;
    }

    /**
     * Returns the dates records from the optiondates table.
     * @return array
     */
    public function get_times(): array {
        return $this->times;
    }
}
