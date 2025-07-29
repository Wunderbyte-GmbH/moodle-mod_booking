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
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\singleton_service;
use stdClass;
use Throwable;

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
        if (!get_config('booking', 'cacheturnoffforbookinganswers')) {
            $data = $cache->get($optionid);
        } else {
            $data = false;
        }

        if (!$data) {
            try {
                if (!empty($optionid)) {
                    [$sql, $params] = self::return_sql_to_get_answers($optionid);
                    $answers = $DB->get_records_sql($sql, $params);
                } else {
                    $answers = [];
                }
            } catch (Throwable $e) {
                if ($CFG->debug === E_ALL) {
                    throw $e;
                } else {
                    $answers = [];
                }
            }

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
                        if (self::count_places($this->usersonlist) < $this->bookingoptionsettings->maxanswers) {
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
            if (!get_config('booking', 'cacheturnoffforbookinganswers')) {
                $cache->set($optionid, $data);
            }
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

        if (
            isset($this->users[$userid])
            && isset($this->users[$userid]->completed)
            && $this->users[$userid]->completed == 1
        ) {
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

        $sharedplaceswithoptions = booking_option::get_value_of_json_by_key($this->optionid, "sharedplaceswithoptions") ?? [];

        $returnarray['booked'] = self::count_places($this->usersonlist);

        $highestbookings = 0;
        foreach ($sharedplaceswithoptions as $sharedoptionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($sharedoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $booked = self::count_places($ba->usersonlist);
            if ($booked > $highestbookings) {
                $highestbookings = $booked;
            }
        }
        $returnarray['booked'] += $highestbookings;

        $returnarray['waiting'] = self::count_places($this->usersonwaitinglist);
        $highestbookings = 0;
        foreach ($sharedplaceswithoptions as $sharedoptionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($sharedoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $booked = self::count_places($ba->usersonwaitinglist);
            if ($booked > $highestbookings) {
                $highestbookings = $booked;
            }
        }
        $returnarray['waiting'] += $highestbookings;

        $returnarray['reserved'] = self::count_places($this->usersreserved);
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
        } else if ($maxoverbooking == -1) {
            $returnarray['freeonwaitinglist'] = -1;
        }

        if (!empty($this->bookingoptionsettings->minanswers) && $this->bookingoptionsettings->minanswers > 0) {
            $returnarray['minanswers'] = $this->bookingoptionsettings->minanswers;
        }

        // First check list of booked users.
        if (
            isset($this->usersonlist[$userid])
            && $this->usersonlist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_BOOKED
        ) {
            $answer = $this->usersonlist[$userid];
            if (!empty($answer->json)) {
                $jsonobject = json_decode($answer->json);

                if (!empty($jsonobject->paidwithcredits)) {
                    $returnarray['paidwithcredits'] = true;
                }
            }

            $returnarray = ['iambooked' => $returnarray];
        } else if (
            isset($this->usersreserved[$userid])
            && $this->usersreserved[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED
        ) {
            $returnarray = ['iamreserved' => $returnarray];
        } else if (
            isset($this->usersonwaitinglist[$userid])
            && $this->usersonwaitinglist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_WAITINGLIST
        ) {
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
     * @return bool
     */
    public function user_on_notificationlist(int $userid) {

        if (isset($this->userstonotify[$userid])) {
            return true;
        }
        return false;
    }

    /**
     * Verify if a user is actually on the booked list or not.
     *
     * @param int $userid
     * @return bool
     */
    public function user_booked(int $userid) {

        if (isset($this->usersonlist[$userid])) {
            return true;
        }
        return false;
    }

    /**
     * Verify if a user is actually on the booked list or not.
     *
     * @param int $userid
     * @return stdClass|null
     */
    public function user_get_last_active_booking(int $userid) {

        if (isset($this->usersonlist[$userid])) {
            return $this->usersonlist[$userid];
        }
        return null;
    }

    /**
     * This function checks if the current instance of the booking option is overlapping with other bookings of this given user.
     *
     * @param int $userid
     * @param bool $forbiddenbynewoption
     *
     * @return array
     *
     */
    public function is_overlapping(int $userid, bool $forbiddenbynewoption = true): array {
        if (!isloggedin() || isguestuser()) {
            return [];
        }
        $overlappinganswers = [];

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $myanswers = $this->get_all_answers_for_user_cached(
            $userid,
            0,
            [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
            ],
            true
        );

        // If the user has no answers, then there is no overlap.
        if (empty($myanswers)) {
            return $overlappinganswers;
        }

        foreach ($myanswers as $answer) {
            // Even if in this bookingoption, overlapping is not forbidden,...
            // ...we have to check in the bookinganswers if it overlaps with other options where it is forbidden.

            // First we check if there could be a general overlapping. Only where this can occure, we check sessions.
            // If the courseendtime is smaller than the other coursestarttime we can skip.
            // If the coursestarttime is bigger than the other courseendtime we can skip.
            if (
                (!$forbiddenbynewoption &&
                (!isset($answer->nooverlappinghandling) ||
                $answer->nooverlappinghandling == MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY)) ||
                !self::check_overlap(
                    $answer->coursestarttime,
                    $answer->courseendtime,
                    $settings->coursestarttime,
                    $settings->courseendtime
                )
            ) {
                continue;
            }
            $settingsanswers = singleton_service::get_instance_of_booking_option_settings($answer->optionid);
            // If there are no sessions, we can return true right away.
            if (
                count($settings->sessions) < 2
                && count($settingsanswers->sessions) < 2
            ) {
                $overlappinganswers[$answer->optionid] = $answer;
                continue;
            }
            // Else, we need to check each session.
            foreach ($settings->sessions as $session) {
                foreach ($settingsanswers->sessions as $answersession) {
                    if (
                        self::check_overlap(
                            $answersession->coursestarttime,
                            $answersession->courseendtime,
                            $session->coursestarttime,
                            $session->courseendtime
                        )
                    ) {
                        $overlappinganswers[$answer->optionid] = $answer;
                        continue 2;
                    }
                }
            }
        }
        return $overlappinganswers;
    }

    /**
     * This function checks if the user already booked options similar to the current instance of the booking option.
     * And if the user has already booked the maximum number of options from the same category.
     *
     * @param int $userid
     * @param array $restriction
     * @param string $field
     *
     * @return array
     *
     */
    public function exceeds_max_bookings(int $userid, array $restriction, string $field): array {
        if (!isloggedin() || isguestuser()) {
            return [];
        }
        // Check if restriction applies to current answer.
        $field = get_config('booking', 'maxoptionsfromcategoryfield');

        // First check if the field of the current option contains the entry we are looking for.
        $match = '';
        foreach ($restriction as $key => $data) {
            $localizedentry = $data->localizedstring;
            if (
                !isset($this->bookingoptionsettings->customfields[$field])
                || ($this->bookingoptionsettings->customfields[$field] != $localizedentry
                    && !(is_array($this->bookingoptionsettings->customfields[$field])
                        && in_array($localizedentry, $this->bookingoptionsettings->customfields[$field])))
            ) {
                continue;
            } else {
                $match = $localizedentry;
                break;
            }
        }
        if (empty($match)) {
            return [];
        }
        $answerspercategory = [];
        $myanswers = $this->get_all_answers_for_user_cached(
            $userid,
            0,
            [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
            ],
            false
        );

        // If the user has no answers, then there is no problem.
        if (empty($myanswers)) {
            return [];
        }
        $limittoinstance = booking::get_value_of_json_by_key(
            (int) $this->bookingoptionsettings->bookingid,
            'maxoptionsfrominstance'
        ) ?? 1;
        foreach ($myanswers as $answer) {
            $bosetting = singleton_service::get_instance_of_booking_option_settings($answer->optionid);
            if (!isset($bosetting->customfields[$field])) {
                continue;
            }
            if (
                $bosetting->customfields[$field] === $localizedentry
                || (is_array($bosetting->customfields[$field])
                && in_array($localizedentry, $bosetting->customfields[$field]))
            ) {
                if (
                    !empty($limittoinstance)
                    && $bosetting->bookingid != $this->bookingoptionsettings->bookingid
                ) {
                    // The settings define if comparison is counted only for bookings in the same instance.
                    continue;
                }
                $answerspercategory[$answer->baid] = $answer;
            }
        }
        // If the user has no answers in this category, then there is no problem.
        if (empty($answerspercategory)) {
            return [];
        }
        // Finally count the number of answers and check if it is more than the limit.
        if (count($answerspercategory) >= $restriction[$key]->count) {
            return $answerspercategory;
        }
        return [];
    }

    /**
     * Checks overlapping of dates.
     *
     * @param mixed $starttime1
     * @param mixed $endtime1
     * @param mixed $starttime2
     * @param mixed $endtime2
     *
     * @return bool
     *
     */
    private static function check_overlap($starttime1, $endtime1, $starttime2, $endtime2): bool {
        if (
            ($starttime1 <= $endtime2 && $endtime1 >= $starttime2) ||
            ($starttime1 == $starttime2) ||
            ($endtime1 == $endtime2)
        ) {
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
     *
     * @param int $userid
     * @param int $bookingid not cmid
     * @return int
     */
    public function get_count_of_answers_for_user(int $userid, int $bookingid) {

        $answers = $this->get_all_answers_for_user_cached(
            $userid,
            $bookingid,
            [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]
        );

        // If the config setting 'maxperuserdontcountpassed' is set, we don't count passed bookings.
        if (get_config('booking', 'maxperuserdontcountpassed')) {
            $now = time();
            foreach ($answers as $key => $answer) {
                if (!empty($answer->courseendtime) && $answer->courseendtime < $now) {
                    unset($answers[$key]);
                }
            }
        }
        // If the config setting 'maxperuserdontcountcompleted' is set, we don't count completed bookings.
        if (get_config('booking', 'maxperuserdontcountcompleted')) {
            foreach ($answers as $key => $answer) {
                if (
                    ($answer->completed == 1)
                    || $answer->status == MOD_BOOKING_PRESENCE_STATUS_COMPLETE
                    || $answer->status == MOD_BOOKING_PRESENCE_STATUS_ATTENDING
                ) {
                    unset($answers[$key]);
                }
            }
        }
        if (get_config('booking', 'maxperuserdontcountnoshow')) {
            foreach ($answers as $key => $answer) {
                if ($answer->status == MOD_BOOKING_PRESENCE_STATUS_NOSHOW) {
                    unset($answers[$key]);
                }
            }
        }

        // Do not count reserved options.
        return count($answers);
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

        // Variable $bookingplacesinfotexts can be on of follwing values:
        // 0 => show booked places (Ex. 1/2)
        // 1 => show info texts (Ex. Fully bookd)
        // 2 => show free places only (1 place left).
        $bookingplacesinfotexts = get_config('booking', 'bookingplacesinfotexts');

        if (!has_capability('mod/booking:updatebooking', $context) && $bookingplacesinfotexts) {
            $bookinginformation['showbookingplacesinfotext'] = true;
        }

        if (!empty($bookinginformation['maxanswers'])) {
            $bookingplaceslowpercentage = get_config('booking', 'bookingplaceslowpercentage');
            $actualpercentage = ($bookinginformation['freeonlist'] / $bookinginformation['maxanswers']) * 100;

            // Check if percentage is a negative value. In this case, set it to zero to prevent bugs when the option is overbooked.
            if (($bookinginformation['freeonlist'] < 0)) {
                $bookinginformation['freeonlist'] = 0;
            }

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

            // If (show free places only) is chosen.
            if ($bookingplacesinfotexts == '2') {
                $bookinginformation['bookingplacesinfotext']
                    = get_string('bookingplacesplacesleft', 'mod_booking', $bookinginformation['freeonlist']);
            }
        } else {
            $bookinginformation['bookingplacesinfotext'] = get_string('bookingplacesunlimitedmessage', 'mod_booking');
            $bookinginformation['bookingplacesclass'] = 'text-success avail';
            $bookinginformation['bookingplacesiconclass'] = 'avail';

            if (
                !has_capability('mod/booking:updatebooking', $context)
                && get_config('booking', 'bookingplacesinfotexts')
            ) {
                // We need to set maxanswers to true, to actually show the text when maxanswer is 0 (unlimited).
                $bookinginformation['maxanswers'] = true;
            }
        }

        // Variable $bookingplacesinfotexts can be on of follwing values:
        // 0 => show booked places (Ex. 1/2)
        // 1 => show info texts (Ex. Fully bookd)
        // 2 => show free places only (1 place left).
        $waitingplacesinfotexts = get_config('booking', 'waitinglistinfotexts');
        // Waiting list places.
        if (!empty($bookinginformation['maxoverbooking'])) {
            if (!has_capability('mod/booking:updatebooking', $context) && $waitingplacesinfotexts) {
                $bookinginformation['showwaitinglistplacesinfotext'] = true;
            }

            $waitinglistlowpercentage = get_config('booking', 'waitinglistlowpercentage');
            if ($bookinginformation['freeonwaitinglist'] == -1) {
                $actualwlpercentage = 100;
            } else {
                $actualwlpercentage = ($bookinginformation['freeonwaitinglist'] /
                $bookinginformation['maxoverbooking']) * 100;
            }

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

            // If (show free places only) is chosen.
            if ($waitingplacesinfotexts == '2') {
                $bookinginformation['waitinglistplacesinfotext']
                    = get_string('waitinglistplacesplacesleft', 'mod_booking', $bookinginformation['freeonwaitinglist']);
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

        if (self::count_places($this->usersonlist) >= $this->bookingoptionsettings->maxanswers) {
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

        if (self::count_places($this->usersonwaitinglist) >= $this->bookingoptionsettings->maxoverbooking) {
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
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return (string|int[])[]
     */
    public static function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam) {
        global $DB;
        if (!in_array($scope, ["option", "optiondate"])) {
            $advancedsqlstart = "SELECT
                bo.id,
                bo.id as optionid,
                ba.waitinglist,
                cm.id AS cmid,
                c.id AS courseid,
                c.fullname AS coursename,
                bo.titleprefix,
                bo.text,
                b.name AS instancename,
                COUNT(ba.id) answerscount,
                SUM(pcnt.presencecount) presencecount,
                '" . $scope . "' AS scope
            FROM {booking_options} bo
            LEFT JOIN {booking_answers} ba ON bo.id = ba.optionid
            LEFT JOIN {user} u ON ba.userid = u.id
            JOIN {course_modules} cm ON bo.bookingid = cm.instance
            JOIN {booking} b ON b.id = bo.bookingid
            JOIN {course} c ON c.id = b.course
            JOIN {modules} m ON m.id = cm.module
            LEFT JOIN (
                SELECT boda.optionid, boda.userid, COUNT(*) AS presencecount
                FROM {booking_optiondates_answers} boda
                WHERE boda.status = :statustocount
                GROUP BY boda.optionid, boda.userid
            ) pcnt
            ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id";

            if ($statusparam === 0) {
                $advancedsqlwhere = "WHERE
                    m.name = 'booking'
                    AND (ba.waitinglist = 0 OR ba.waitinglist IS NULL)";
            } else {
                $advancedsqlwhere = "WHERE
                m.name = 'booking'
                AND ba.waitinglist = :statusparam";
            }

            $advancedsqlgroupby = "GROUP BY cm.id, c.id, c.fullname, bo.id, ba.waitinglist, bo.titleprefix, bo.text, b.name";

            $advancedsqlend = "ORDER BY bo.titleprefix, bo.text ASC
                LIMIT 10000000000";
        }

        $where = '1=1';

        switch ($scope) {
            case 'optiondate':
                $optiondateid = $scopeid;
                // We need to set a limit for the query in mysqlfamily.
                $fields = 's1.*';
                $from = " (
                    SELECT " .
                        $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "u.id") .
                        " id,
                        bod.id optiondateid,
                        bod.coursestarttime,
                        bod.courseendtime,
                        ba.userid,
                        ba.waitinglist,
                        boda.status,
                        boda.json,
                        boda.notes,
                        bo.id optionid,
                        bo.titleprefix,
                        bo.text,
                        u.firstname,
                        u.lastname,
                        u.email,
                        '" . $scope . "' AS scope
                    FROM {booking_optiondates} bod
                    JOIN {booking_options} bo
                    ON bo.id = bod.optionid
                    JOIN {booking_answers} ba
                    ON bo.id = ba.optionid
                    JOIN {user} u
                    ON u.id = ba.userid
                    LEFT JOIN {booking_optiondates_answers} boda
                    ON bod.id = boda.optiondateid AND bo.id = boda.optionid AND ba.userid = boda.userid
                    WHERE bod.id = :optiondateid AND ba.waitinglist = :statusparam
                    ORDER BY u.lastname, u.firstname, bod.coursestarttime ASC
                    LIMIT 10000000000
                ) s1";
                $params = [
                    'optiondateid' => $optiondateid,
                    'statusparam' => MOD_BOOKING_STATUSPARAM_BOOKED,
                ];
                break;
            case 'option':
                $optionid = $scopeid;

                $params = [
                    'optionid' => $optionid,
                    'statusparam' => $statusparam,
                ];

                // If presence counter is activated, we add that to SQL.
                $selectpresencecount = '';
                $presencecountsqlpart = '';
                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $selectpresencecount = 'pcnt.presencecount,';
                    $presencecountsqlpart =
                        "LEFT JOIN (
                            SELECT boda.optionid, boda.userid, COUNT(*) AS presencecount
                            FROM {booking_optiondates_answers} boda
                            WHERE boda.optionid = :optionid2 AND boda.status = :statustocount
                            GROUP BY boda.optionid, boda.userid
                        ) pcnt
                        ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id";
                    $params['optionid2'] = $optionid;
                    $params['statustocount'] = get_config('booking', 'bookingstrackerpresencecountervaluetocount');
                }

                // Only for waiting list, we need to add the rank.
                $ranksqlpart = '';
                $orderby = ' ORDER BY lastname, firstname, timemodified ASC';
                if ($statusparam == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
                    // For waiting list, we need to determine the rank order.
                    $ranksqlpart = ', (
                        SELECT COUNT(*)
                        FROM (
                            SELECT
                                ba.id,
                                ba.timemodified
                            FROM {booking_answers} ba
                            WHERE ba.optionid=:optionid3 AND ba.waitinglist=:statusparam2
                        ) s3
                        WHERE (s3.timemodified < s2.timemodified) OR (s3.timemodified = s2.timemodified AND s3.id <= s2.id)
                    ) AS userrank';
                    $orderby = ' ORDER BY userrank ASC';

                    // Params for rank order.
                    $params['statusparam2'] = $statusparam;
                    $params['optionid3'] = $optionid;
                }

                // We need to set a limit for the query in mysqlfamily.
                $fields = 's1.*';
                $from = "
                (
                    SELECT s2.* $ranksqlpart
                    FROM (
                        SELECT
                            ba.id,
                            u.id AS userid,
                            u.username,
                            u.firstname,
                            u.lastname,
                            u.email,
                            ba.waitinglist,
                            ba.status,
                            ba.notes,
                            $selectpresencecount
                            ba.timemodified,
                            ba.timecreated,
                            ba.optionid,
                            ba.json,
                            '" . $scope . "' AS scope
                        FROM {booking_answers} ba
                        JOIN {user} u ON ba.userid = u.id
                        $presencecountsqlpart
                        WHERE ba.optionid=:optionid AND ba.waitinglist=:statusparam
                        LIMIT 1000000
                    ) s2
                    $orderby
                ) s1";

                break;
            case 'instance':
                $cmid = $scopeid;
                $fields = 's1.*';
                $from = " (
                    $advancedsqlstart
                    $advancedsqlwhere
                    AND cm.id = :cmid
                    $advancedsqlgroupby
                    $advancedsqlend
                ) s1";
                $params = [
                    'cmid' => $cmid,
                    'statusparam' => $statusparam,
                    'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
                ];
                break;
            case 'course':
                $courseid = $scopeid;
                $fields = 's1.*';
                $from = " (
                    $advancedsqlstart
                    $advancedsqlwhere
                    AND c.id = :courseid
                    $advancedsqlgroupby
                    $advancedsqlend
                ) s1";
                $params = [
                    'courseid' => $courseid,
                    'statusparam' => $statusparam,
                    'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
                ];
                break;
            case 'system':
            default:
                $fields = 's1.*';
                $from = " (
                    $advancedsqlstart
                    $advancedsqlwhere
                    $advancedsqlgroupby
                    $advancedsqlend
                ) s1";
                $params = [
                    'statusparam' => $statusparam,
                    'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
                ];
                break;
        }
        return [$fields, $from, $where, $params];
    }

    /**
     * Function to sum up places value.
     * If no places key is found, we use 1.
     *
     * @param array $users
     *
     * @return int
     *
     */
    public static function count_places(array $users) {
        $sum = array_reduce($users, function ($carry, $item) {
            return $carry + ($item->places ?? 1);
        }, 0);

        return $sum;
    }

    /**
     * This returns all answer records for a user.
     * The request is cached and uses singleton pattern.
     *
     * @param int $userid
     * @param int $bookingid
     * @param array $status
     * @param bool $excludeselflearningcourses
     *
     * @return array
     *
     */
    private function get_all_answers_for_user_cached(
        int $userid,
        int $bookingid = 0,
        array $status = [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
        ],
        bool $excludeselflearningcourses = false
    ) {

        global $DB, $CFG;

        $answers = [];
        $data = singleton_service::get_answers_for_user($userid, $bookingid);
        if (isset($data['answers'])) {
            $answers = $data['answers'];
        }

        // This is important so we only get instance-specific cache!
        $cachekey = "myanswers$bookingid";

        try {
            // If we don't have the answers in the singleton, we look in the cache.
            if (empty($answers)) {
                $cache = \cache::make('mod_booking', 'bookinganswers');
                if (!get_config('booking', 'cacheturnoffforbookinganswers')) {
                    $data = $cache->get($cachekey);
                } else {
                    $data = false;
                }
                $statustofetch = [];
                $answers = [];
                // We don't have any answers, we get the ones we need.
                if (!$data) {
                    [$sql, $params] = $this->return_sql_to_get_answers(0, $bookingid, $userid, $status);

                    $answers = $DB->get_records_sql($sql, $params);

                    $data = [
                        'status' => $status,
                        'answers' => $answers,
                    ];
                } else {
                    // We need to check if we have all the answers we currently want.
                    // Therefore, we check in the cached object if there it covers the right status.

                    foreach ($status as $bookingstatus) {
                        if (!in_array($bookingstatus, $data['status'])) {
                            $statustofetch[] = $bookingstatus;
                        }
                    }

                    if (!empty($statustofetch)) {
                        [$sql, $params] = $this->return_sql_to_get_answers(0, $bookingid, $userid, $statustofetch);
                        $answers = $DB->get_records_sql($sql, $params);
                    }

                    $data['answers'] = array_merge($data['answers'], $answers ?? []);
                    $data['status'] = array_merge($statustofetch, $data['status'] ?? []);
                }

                $answers = $data['answers'];
                singleton_service::set_answers_for_user($userid, $bookingid, $data);
                if (!get_config('booking', 'cacheturnoffforbookinganswers')) {
                    $cache->set($cachekey, $data);
                }
            }
        } catch (Throwable $e) {
            if ($CFG->debug === E_ALL) {
                throw $e;
            }
        }

        if ($excludeselflearningcourses) {
            foreach ($answers as $key => $answer) {
                if (
                    !empty($answer->nooverlappinghandling)
                    && isset($answer->json) && !empty($answer->json)
                ) {
                    $jsondata = json_decode($answer->json);
                    if (
                        isset($jsondata->selflearningcourse)
                        && $jsondata->selflearningcourse != 1
                    ) {
                        unset($answers[$key]);
                        continue;
                    }
                }
            }
        }
        // Make sure to filter the status if the cache contains more values than supposed.
        if (
            $status != [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
                ]
        ) {
            foreach ($answers as $key => $answer) {
                if (!in_array((int) $answer->waitinglist, $status)) {
                    unset($answers[$key]);
                }
            }
        }
        return $answers;
    }

    /**
     * This returns the sql to fetch all the answers. Might be restricted fo booking optinos or for users or none.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $status
     *
     * @return array
     */
    private function return_sql_to_get_answers(
        int $optionid = 0,
        int $bookingid = 0,
        int $userid = 0,
        array $status = [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
        ]
    ) {
        global $DB;

        [$inorequal, $params] = $DB->get_in_or_equal($status, SQL_PARAMS_NAMED);

        $wherearray = [
            " ba.waitinglist $inorequal ",
        ];

        if (!empty($optionid)) {
            $params['optionid'] = $optionid;
            $wherearray[] = ' ba.optionid = :optionid ';
        }

        if (!empty($bookingid)) {
            $params['bookingid'] = $bookingid;
            $wherearray[] = ' ba.bookingid = :bookingid ';
        }

        if (!empty($userid)) {
            $params['userid'] = $userid;
            $wherearray[] = ' ba.userid = :userid ';
        }
        $overlapping = bo_info::check_for_sqljson_key_in_array('bo.availability', 'nooverlappinghandling');
        $withcoursestarttimesselect = ", $overlapping as nooverlappinghandling";

        $where = implode(' AND ', $wherearray);

        $sql = "SELECT
                ba.id as baid,
                ba.userid as id,
                ba.userid,
                ba.waitinglist,
                ba.completed,
                ba.status,
                ba.timemodified,
                ba.bookingid,
                ba.optionid,
                ba.timecreated,
                ba.json,
                ba.places,
                bo.coursestarttime,
                bo.courseendtime
                $withcoursestarttimesselect
            FROM {booking_answers} ba
            JOIN {booking_options} bo ON ba.optionid = bo.id
            JOIN {user} u ON ba.userid = u.id AND u.deleted = 0
            WHERE $where
            ORDER BY ba.timemodified ASC";

        return [$sql, $params];
    }
}
