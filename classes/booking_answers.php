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
 * Booking answers class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use dml_exception;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for booking answers.
 *
 * An instance is linked to one specific option.
 * But the class provides static functions to get information about a users answers for the whole instance as well.
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
     * The booking answers class is instantiated for all users alike.
     * But it returns information for the individual users.
     *
     * MOD_BOOKING_STATUSPARAM_BOOKED (0) ... user has booked the option
     * MOD_BOOKING_STATUSPARAM_WAITINGLIST (1) ... user is on the waiting list
     * MOD_BOOKING_STATUSPARAM_RESERVED (2) ... user is on the waiting list
     * MOD_BOOKING_STATUSPARAM_NOTBOOKED (4) ... user has not booked the option
     * MOD_BOOKING_STATUSPARAM_DELETED (5) ... user answer was deleted
     *
     * @param booking_option_settings $bookingoptionsettings
     * @throws dml_exception
     */
    public function __construct(booking_option_settings $bookingoptionsettings) {

        global $DB, $CFG;

        $optionid = $bookingoptionsettings->id;
        $this->optionid = $optionid;
        $this->bookingoptionsettings = $bookingoptionsettings;

        $cache = \cache::make('mod_booking', 'bookingoptionsanswers');
        $data = $cache->get($optionid);

        if (!$data) {

            $params = ['optionid' => $optionid];

            if ($CFG->version >= 2021051700) {
                // This only works in Moodle 3.11 and later.
                $userfields = \core_user\fields::for_name()->with_userpic()->get_sql('u')->selects;
                $userfields = trim($userfields, ', ');
            } else {
                // This is only here to support Moodle versions earlier than 3.11.
                $userfields = \user_picture::fields('u');
            }

            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* $sql = "SELECT ba.id as baid, ba.userid, ba.waitinglist, ba.timecreated, $userfields, u.institution
            FROM {booking_answers} ba
            JOIN {user} u ON u.id = ba.userid
            WHERE ba.optionid = :optionid
            AND u.deleted = 0
            ORDER BY ba.timecreated ASC"; */

            $sql = "SELECT
                ba.id as baid,
                ba.userid as id,
                ba.userid,
                ba.waitinglist,
                ba.completed,
                ba.timemodified,
                ba.optionid,
                ba.timecreated,
                ba.json
            FROM {booking_answers} ba
            WHERE ba.optionid = :optionid
            AND ba.waitinglist < 5
            ORDER BY ba.timemodified ASC";

            $answers = $DB->get_records_sql($sql, $params);

            // We don't want to query for empty bookings, so we also cache these.
            if (count($answers) == 0) {
                $answers = 'empty';
            }

            // If the answer has the empty placeholder, we replace it by an array.
            if ($answers === 'empty') {
                $answers = [];
            }

            $this->answers = $answers;

            foreach ($answers as $answer) {
                $answer = customform::append_customform_elements($answer);
                // A user might have one or more 'deleted' entries, but else, there should be only one.
                if ($answer->waitinglist != MOD_BOOKING_STATUSPARAM_DELETED) {
                    $this->users[$answer->userid] = $answer;
                }

                switch ($answer->waitinglist) {
                    case MOD_BOOKING_STATUSPARAM_BOOKED:
                        $this->usersonlist[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                        $this->usersonwaitinglist[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_RESERVED:
                        if (count($this->usersonlist) < $this->bookingoptionsettings->maxanswers) {
                            $this->usersonlist[$answer->userid] = $answer;
                        } else {
                            $this->usersonwaitinglist[$answer->userid] = $answer;
                        }
                        $this->usersreserved[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_DELETED:
                        $this->usersdeleted[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                        $this->userstonotify[$answer->userid] = $answer;
                        break;
                }
            }

            $data = (object)[
                'answers' => $this->answers,
                'users' => $this->users,
                'usersonlist' => $this->usersonlist,
                'usersonwaitinglist' => $this->usersonwaitinglist,
                'usersreserved' => $this->usersreserved,
                'usersdeleted' => $this->usersdeleted,
                'userstonotify' => $this->userstonotify,
            ];

            $cache->set($optionid, $data);
        } else {
            $this->answers = $data->answers;
            $this->users = $data->users;
            $this->usersonlist = $data->usersonlist;
            $this->usersonwaitinglist = $data->usersonwaitinglist;
            $this->usersreserved = $data->usersreserved;
            $this->usersdeleted = $data->usersdeleted;
            $this->userstonotify = $data->userstonotify;
        }
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     * The return value of this function is not equal to the former user_status in booking_option.
     *
     * @param int $userid
     * @return int const MOD_BOOKING_STATUSPARAM_* for booking status.
     */
    public function user_status(int $userid = 0) {
        global $USER;

        if ($userid == 0) {
            $userid = $USER->id;
        }

        if (isset($this->usersreserved[$userid])) {
            return MOD_BOOKING_STATUSPARAM_RESERVED;
        } else if (isset($this->userstonotify[$userid])) {
            return MOD_BOOKING_STATUSPARAM_NOTIFYMELIST;
        } else if (isset($this->usersonwaitinglist[$userid])) {
            return MOD_BOOKING_STATUSPARAM_WAITINGLIST;
        } else if (isset($this->usersonlist[$userid])) {
            return MOD_BOOKING_STATUSPARAM_BOOKED;
        } else {
            return MOD_BOOKING_STATUSPARAM_NOTBOOKED;
        }
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     *
     * @param int $userid
     * @return int status 0 = activity not completed, 1 = activity completed
     */
    public function is_activity_completed(int $userid) {

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
     * @param int $userid
     * @return array
     */
    public function return_all_booking_information(int $userid) {

        $returnarray = [];

        $returnarray['waiting'] = count($this->usersonwaitinglist);
        $returnarray['booked'] = count($this->usersonlist);
        $returnarray['reserved'] = count($this->usersreserved);

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

        $maxoverbooking = $this->bookingoptionsettings->maxoverbooking ?? 0;
        if ($maxoverbooking > 0) {
            $returnarray['maxoverbooking'] = $maxoverbooking;
            $returnarray['freeonwaitinglist'] = $maxoverbooking - $returnarray['waiting'];
        }

        if (!empty($this->bookingoptionsettings->minanswers) && $this->bookingoptionsettings->minanswers > 0) {
            $returnarray['minanswers'] = $this->bookingoptionsettings->minanswers;
        }

        // First check list of booked users.
        if (isset($this->usersonlist[$userid]) && $this->usersonlist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_BOOKED) {

            $answer = $this->usersonlist[$userid];
            if (!empty($answer->json)) {
                $jsonobject = json_decode($answer->json);

                if (!empty($jsonobject->paidwithcredits)) {
                    $returnarray['paidwithcredits'] = true;
                }
            }

            $returnarray = ['iambooked' => $returnarray];
        } else if (isset($this->usersreserved[$userid])
            && $this->usersreserved[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED) {
            $returnarray = ['iamreserved' => $returnarray];
        } else if (isset($this->usersonwaitinglist[$userid]) &&
            $this->usersonwaitinglist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
            // Now check waiting list.
            $returnarray = ['onwaitinglist' => $returnarray];
        } else {
            // Else it's not booked.
            $returnarray = ['notbooked' => $returnarray];
        }

        return $returnarray;
    }

    /**
     * Returns place on waiting list of user.
     * -1 if not on list.
     * @param int $userid
     * @return int
     */
    public function return_place_on_waitinglist(int $userid) {

        $index = 0;
        foreach ($this->usersonwaitinglist as $key => $user) {
            $index++;
            if ($userid == $key) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Verify if a user is actually on the booked list or not.
     *
     * @param int $userid
     * @return void
     */
    public function user_on_notificationlist(int $userid) {

        if (isset($this->userstonotify[$userid])) {
            return true;
        }
        return false;
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

    /**
     * Returns the number of active bookings for a given user for the whole instance.
     * This is not cached!
     *
     * @param int $userid
     * @param int $bookingid not cmid
     * @return int
     */
    public static function number_of_active_bookings_for_user(int $userid, int $bookingid) {
        global $DB;

        $params = [
            'statuswaitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'bookingid' => $bookingid,
            'userid' => $userid,
        ];

        $sql = "SELECT COUNT(ba.id) cnt
                FROM {booking_answers} ba
                JOIN {booking_options} bo
                ON bo.id = ba.optionid
                WHERE ba.waitinglist <= :statuswaitinglist
                AND ba.bookingid = :bookingid
                AND ba.userid = :userid";

        if (get_config('booking', 'maxperuserdontcountpassed')) {
            $params['now'] = time();
            $sql .= " AND (bo.courseendtime > :now OR bo.courseendtime IS NULL OR bo.courseendtime = 0)";
        }
        if (get_config('booking', 'maxperuserdontcountcompleted')) {
            $sql .= " AND ba.completed = 0 AND ba.status NOT IN (1,6)";
        }
        if (get_config('booking', 'maxperuserdontcountnoshow')) {
            $sql .= " AND ba.status <> 3";
        }

        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Uncached function to get booking status of user regarding the subbooking.
     *
     * @param int $subbookingid
     * @param int $userid
     * @return int
     */
    public function subbooking_user_status(int $subbookingid, int $userid = 0) {
        global $DB;

        $sql = "SELECT *
            FROM {booking_subbooking_answers}
            WHERE sboptionid=:subbookingid
            AND optionid=:optionid
            AND status <= :statusparam"; // We get booked, waitinglist and reserved.

        $params = [
            'subbookingid' => $subbookingid,
            'optionid' => $this->optionid,
            'statusparam' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ];

        if ($record = $DB->get_record_sql($sql, $params)) {
            return $record->status;
        } else {
            return MOD_BOOKING_STATUSPARAM_NOTBOOKED;
        }
    }

    /**
     * Helper function to add availability info texts for available places and waiting list.
     *
     * @param  array $bookinginformation reference to booking information array.
     *
     * @return void
     */
    public static function add_availability_info_texts_to_booking_information(array &$bookinginformation) {
        // PRO feature: Availability info texts for booking places and waiting list.
        // Booking places.
        $context = context_system::instance();

        if (
            !empty($bookinginformation['maxanswers'])
        ) {
            if (
                !has_capability('mod/booking:updatebooking', $context)
                && get_config('booking', 'bookingplacesinfotexts')
            ) {
                $bookinginformation['showbookingplacesinfotext'] = true;
            }

            $bookingplaceslowpercentage = get_config('booking', 'bookingplaceslowpercentage');
            $actualpercentage = ($bookinginformation['freeonlist'] / $bookinginformation['maxanswers']) * 100;

            if ($bookinginformation['freeonlist'] == 0) {
                // No places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplacesfullmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-danger fullavail';
                $bookinginformation['bookingplacesiconclass'] = 'fullavail';
            } else if ($actualpercentage <= $bookingplaceslowpercentage) {
                // Only a few places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplaceslowmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-danger lowavail';
                $bookinginformation['bookingplacesiconclass'] = 'lowavail';
            } else {
                // Still enough places left.
                $bookinginformation['bookingplacesinfotext'] = get_string('bookingplacesenoughmessage', 'mod_booking');
                $bookinginformation['bookingplacesclass'] = 'text-success avail';
                $bookinginformation['bookingplacesiconclass'] = 'avail';
            }
        }
        // Waiting list places.
        if (
            !empty($bookinginformation['maxoverbooking'])
        ) {

            if (!has_capability('mod/booking:updatebooking', $context)
                && get_config('booking', 'waitinglistinfotexts')
            ) {
                $bookinginformation['showwaitinglistplacesinfotext'] = true;
            }

            $waitinglistlowpercentage = get_config('booking', 'waitinglistlowpercentage');
            $actualwlpercentage = ($bookinginformation['freeonwaitinglist'] /
                $bookinginformation['maxoverbooking']) * 100;

            if ($bookinginformation['freeonwaitinglist'] == 0) {
                // No places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistfullmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-danger';
            } else if ($actualwlpercentage <= $waitinglistlowpercentage) {
                // Only a few places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistlowmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-danger';
            } else {
                // Still enough places left.
                $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistenoughmessage', 'mod_booking');
                $bookinginformation['waitinglistplacesclass'] = 'text-success';
            }
        }
    }

    /**
     * Check if the booking option is already fully booked.
     * @return bool
     */
    public function is_fully_booked() {

        // If booking option is unlimited, we always return false.
        if (empty($this->bookingoptionsettings->maxanswers)) {
            return false;
        }

        if (count($this->usersonlist) >= $this->bookingoptionsettings->maxanswers) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if the booking option is already fully booked.
     * @return bool
     */
    public function is_fully_booked_on_waitinglist() {

        // If booking option is unlimited, we always return false.
        if (empty($this->bookingoptionsettings->maxoverbooking)) {
            return false;
        }

        if (count($this->usersonwaitinglist) >= $this->bookingoptionsettings->maxoverbooking) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Function to return the number of active bookings of a user.
     * Optionally for booking opitons with a given teacher.
     * @param int $userid
     * @param int $teacherid
     * @return int
     * @throws dml_exception
     */
    public static function number_actively_booked(int $userid, int $teacherid = 0) {

        global $DB;

        $params = [
            'userid' => $userid,
            'teacherid' => $teacherid,
        ];

        $where = !empty($teacherid) ? " AND bt.userid = :teacherid " : "";

        $sql = "SELECT COUNT(ba.id)
                FROM {booking_answers} ba
                JOIN {booking_teachers} bt ON ba.optionid=bt.optionid
                WHERE ba.waitinglist = 0
                AND ba.userid = :userid
                $where";

        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param int $optionid
     * @param int $statusparam
     * @return (string|int[])[]
     */
    public static function return_sql_for_booked_users(int $optionid, int $statusparam) {
        // We need to set a limit for the query in mysqlfamily.
        $fields = 's1.*, ROW_NUMBER() OVER (ORDER BY s1.timemodified, s1.id DESC) AS rank';
        $from = " (SELECT ba.id,
                          u.id as userid,
                          u.firstname,
                          u.lastname,
                          u.email,
                          ba.timemodified,
                          ba.timecreated,
                          ba.optionid,
                          ba.json
                    FROM {booking_answers} ba
                    JOIN {user} u ON ba.userid = u.id
                    WHERE ba.optionid=:optionid AND ba.waitinglist=:statusparam
                    ORDER BY ba.timemodified, ba.id ASC
                    LIMIT 10000000000
                    ) s1";
        $where = '1=1';
        $params = [
            'optionid' => $optionid,
            'statusparam' => $statusparam,
        ];

        return [$fields, $from, $where, $params];
    }
}
