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

use stdClass;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for booking answers.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_answers {

    /** @var string $optionid ID of booking option */
    public $optionid = null;

    /** @var booking_option_settings $bookingoptionsettings instance of bookingoptionsettings class */
    public $bookingoptionsettings = null;

    /** @var array array of records from the booking_answers table. */
    public $answers = [];

    /** @var array array of all user objects (waitinglist and booked) */
    public $users = [];

    /** @var array array of all user objects (no waitinglist, only booked) */
    public $usersonlist = [];

    /** @var array array of all user objects (waitinglist, no booked) */
    public $usersonwaitinglist = [];

    /** @var array array of all user objects (only reserved) */
    public $usersreserved = [];

    /** @var array array of all user objects (only with deleted booking answer) */
    public $usersdeleted = [];

    /** @var array array of all user objects (only those to notify) */
    public $userstonotify = [];

    /**
     * Constructor for the booking answers class.
     *
     * STATUSPARAM_BOOKED (0) ... user has booked the option
     * STATUSPARAM_WAITINGLIST (1) ... user is on the waiting list
     * STATUSPARAM_RESERVED (2) ... user is on the waiting list
     * STATUSPARAM_NOTBOOKED (4) ... user has not booked the option
     * STATUSPARAM_DELETED (5) ... user answer was deleted
     *
     * @param int $optionid Booking option id.
     * @throws dml_exception
     */
    public function __construct(booking_option_settings $bookingoptionsettings, int $userid = 0) {

        global $DB, $USER, $CFG;

        if ($userid == 0) {
            $userid = $USER->id;
        }

        $optionid = $bookingoptionsettings->id;
        $this->optionid = $optionid;
        $this->bookingoptionsettings = $bookingoptionsettings;

        $cache = \cache::make('mod_booking', 'bookingoptionsanswers');
        $answers = $cache->get($optionid);

        if (!$answers) {

            $params = array('optionid' => $optionid);

            if ($CFG->version >= 2021051700) {
                // This only works in Moodle 3.11 and later.
                $userfields = \core_user\fields::for_name()->with_userpic()->get_sql('u')->selects;
                $userfields = trim($userfields, ', ');
            } else {
                // This is only here to support Moodle versions earlier than 3.11.
                $userfields = \user_picture::fields('u');
            }

            $sql = "SELECT ba.id as baid, ba.userid, ba.waitinglist, ba.timecreated, $userfields, u.institution
            FROM {booking_answers} ba
            JOIN {user} u ON u.id = ba.userid
            WHERE ba.optionid = :optionid
            AND u.deleted = 0
            ORDER BY ba.timecreated ASC";

            $answers = $DB->get_records_sql($sql, $params);

            // We don't want to query for empty bookings, so we also cache these.
            if (count($answers) == 0) {
                $answers = 'empty';
            }

            $cache->set($optionid, $answers);
        }

        // If the answer has the empty placeholder, we replace it by an array.
        if ($answers === 'empty') {
            $answers = [];
        }

        $this->answers = $answers;

        // TODO: we have to cache the whole booking_answer class, not only the results from DB.
        // The calculation doesn't change and has'nt to be done every time.

        // Set back all the lists.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->users =
        $this->usersonlist =
        $this->usersonwaitinglist =
        $this->usersreserved =
        $this->usersdeleted = []; */

        // These are the values we are interested in.
        $imbooked = 0;
        $onwaitinglist = 0;
        $completed = 0;

        foreach ($answers as $answer) {
            if ($answer->userid == $userid) {
                // The following two options are mutually exclusive.
                if ($answer->waitinglist == 0) {
                    ++$imbooked;
                } else if ($answer->waitinglist == 1) {
                    ++$onwaitinglist;
                }
                // Completion is independed from the other states.
                if (isset($answer->completed) && $answer->completed == 1) {
                    ++$completed;
                }
            }

            // A user might have one or more 'deleted' entries, but else, there should be only one.
            if ($answer->waitinglist != STATUSPARAM_DELETED) {
                $this->users[$answer->userid] = $answer;
            }

            switch ($answer->waitinglist) {
                case STATUSPARAM_BOOKED:
                    $this->usersonlist[$answer->userid] = $answer;
                    break;
                case STATUSPARAM_WAITINGLIST:
                    $this->usersonwaitinglist[$answer->userid] = $answer;
                    break;
                case STATUSPARAM_RESERVED:
                    if (count($this->usersonlist) < $this->bookingoptionsettings->maxanswers) {
                        $this->usersonlist[$answer->userid] = $answer;
                    } else {
                        $this->usersonwaitinglist[$answer->userid] = $answer;
                    }
                    $this->usersreserved[$answer->userid] = $answer;
                    break;
                case STATUSPARAM_DELETED:
                    $this->usersdeleted[$answer->userid] = $answer;
                    break;
                case STATUSPARAM_NOTIFYMELIST:
                    $this->userstonotify[$answer->userid] = $answer;
                    break;
            }
        }
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     * The return value of this function is not equal to the former user_status in booking_option.
     *
     * @param int $userid
     * @return int const STATUSPARAM_* for booking status.
     */
    public function user_status($userid = null) {

        global $USER;

        if (is_null($userid)) {
            $userid = $USER->id;
        }

        if (isset($this->users[$userid])) {
            return $this->users[$userid]->waitinglist; // The waitinglist key holds all the different status.
        } else {
            return STATUSPARAM_NOTBOOKED;
        }
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     *
     * @param int $userid
     * @return int status 0 = activity not completed, 1 = activity completed
     */
    public function is_activity_completed($userid = null) {
        global $DB, $USER;
        if (is_null($userid)) {
            $userid = $USER->id;
        }

        if (isset($this->users[$userid])
            && isset($this->users[$userid]->completed)
            && $this->users[$userid]->completed == 1) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * This function returns an array of all the relevant information of the booking status.
     * This will be used mainly for displaying the information.
     *
     * - iambooked
     * - iamonwaitinglist
     * - maxanswers
     * - maxoverbooking
     * - booked
     * - onwaitinglist
     *
     * @param integer|null $userid
     * @return array
     */
    public function return_all_booking_information(int $userid = null) {

        global $USER;

        if ($userid == null) {
            $userid = $USER->id;
        }

        $returnarray = [];

        $returnarray['waiting'] = count($this->usersonwaitinglist);
        $returnarray['booked'] = count($this->usersonlist);

        $returnarray['onnotifylist'] = $this->user_on_notificationlist($userid);

        // We can't set the value if it's not true, because of the way mustache templates work.
        if ($this->bookingoptionsettings->maxanswers != 0) {
            $returnarray['maxanswers'] = $this->bookingoptionsettings->maxanswers;

            $returnarray['freeonlist'] = $returnarray['maxanswers'] - $returnarray['booked'];

             // Determine if the option is booked out.
            if ($returnarray['freeonlist'] <= 0) {
                $returnarray['fullybooked'] = true;
            } else {
                $returnarray['fullybooked'] = false;
            }
        } else {
            $returnarray['fullybooked'] = false;
        }

        if ($this->bookingoptionsettings->maxoverbooking != 0) {
            $returnarray['maxoverbooking'] = $this->bookingoptionsettings->maxoverbooking;

            $returnarray['freeonwaitinglist'] = $returnarray['maxoverbooking'] - $returnarray['waiting'];
        }

        if (isset($this->usersonlist[$userid]) && $this->usersonlist[$userid]->waitinglist < 2) {
            if ($this->usersonlist[$userid]->waitinglist == STATUSPARAM_BOOKED) {
                $returnarray = array('iambooked' => $returnarray);
            }

            if ($this->usersonlist[$userid]->waitinglist == STATUSPARAM_WAITINGLIST) {
                $returnarray = array('onwaitinglist' => $returnarray);
            };
        } else {
            $returnarray = array('notbooked' => $returnarray);
        }

        return $returnarray;
    }

    /**
     * Verify if a user is actually on the booked list or not.
     *
     * @param integer $userid
     * @return void
     */
    public function user_on_notificationlist(int $userid) {

        if (isset($this->userstonotify[$userid])) {
            return true;
        }
        return false;
    }

    /**
     * Load values of booking_option from db, should rarely be necessary.
     *
     * @param integer $optionid
     * @return stdClass|null
     */
    private function set_values(int $optionid, object $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if ($dbrecord === null) {
            $dbrecord = $DB->get_record("booking_options", array("id" => $optionid));

        }

        if ($dbrecord) {

            // Fields in DB.
            $this->id = $optionid;
            $this->bookingid = $dbrecord->bookingid;
            $this->text = $dbrecord->text;
            $this->maxanswers = $dbrecord->maxanswers;
            $this->maxoverbooking = $dbrecord->maxoverbooking;
            $this->bookingclosingtime = $dbrecord->bookingclosingtime;
            $this->courseid = $dbrecord->courseid;
            $this->coursestarttime = $dbrecord->coursestarttime;
            $this->courseendtime = $dbrecord->courseendtime;
            $this->enrolmentstatus = $dbrecord->enrolmentstatus;
            $this->description = $dbrecord->description;
            $this->descriptionformat = $dbrecord->descriptionformat;
            $this->limitanswers = $dbrecord->limitanswers;
            $this->timemodified = $dbrecord->timemodified;
            $this->addtocalendar = $dbrecord->addtocalendar;
            $this->calendarid = $dbrecord->calendarid;
            $this->pollurl = $dbrecord->pollurl;
            $this->groupid = $dbrecord->groupid;
            $this->sent = $dbrecord->sent;
            $this->location = $dbrecord->location;
            $this->institution = $dbrecord->institution;
            $this->address = $dbrecord->address;
            $this->credits = $dbrecord->credits;
            $this->pollurlteachers = $dbrecord->pollurlteachers;
            $this->howmanyusers = $dbrecord->howmanyusers;
            $this->pollsend = $dbrecord->pollsend;
            $this->removeafterminutes = $dbrecord->removeafterminutes;
            $this->notificationtext = $dbrecord->notificationtext;
            $this->notificationtextformat = $dbrecord->notificationtextformat;
            $this->disablebookingusers = $dbrecord->disablebookingusers;
            $this->sent2 = $dbrecord->sent2;
            $this->sentteachers = $dbrecord->sentteachers;
            $this->beforebookedtext = $dbrecord->beforebookedtext;
            $this->beforecompletedtext = $dbrecord->beforecompletedtext;
            $this->aftercompletedtext = $dbrecord->aftercompletedtext;
            $this->shorturl = $dbrecord->shorturl;
            $this->duration = $dbrecord->duration;
            $this->parentid = $dbrecord->parentid;

            // If the key "sessions" is not yet set, we need to load from DB.
            if (!isset($dbrecord->sessions)) {
                $this->load_sessions_from_db($optionid);
                $dbrecord->sessions = $this->sessions;
            } else {
                $this->sessions = $dbrecord->sessions;
            }

            return $dbrecord;
        } else {
            debugging('Could not create option settings class for optionid: ' . $optionid);
            return null;
        }
    }

    // Function to load Multisessions from DB.
    private function load_sessions_from_db($optionid) {
        global $DB;
        // Multi-sessions.
        if (!$this->sessions = $DB->get_records_sql(
            "SELECT id optiondateid, coursestarttime, courseendtime
            FROM {booking_optiondates}
            WHERE optionid = ?
            ORDER BY coursestarttime ASC", array($optionid))) {

            // If there are no multisessions, but we still have the option's ...
            // ... coursestarttime and courseendtime, then store them as if they were a session.
            if (!empty($this->coursestarttime) && !empty($this->courseendtime)) {
                $singlesession = new stdClass;
                $singlesession->id = 0;
                $singlesession->coursestarttime = $this->coursestarttime;
                $singlesession->courseendtime = $this->courseendtime;
                $this->sessions[] = $singlesession;
            } else {
                // Else we have no sessions.
                $this->sessions = [];
            }
        }
    }

    /**
     * Static function to construct booking_answers from only optionid.
     *
     * @param int $optionid
     * @return booking_answers
     */
    public static function get_instance_from_optionid($optionid) {
        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return singleton_service::get_instance_of_booking_answers($bookingoptionsettings);
    }
}
