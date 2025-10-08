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

namespace mod_booking\booking_answers;

use context_system;
use core\exception\moodle_exception;
use dml_exception;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking\booking_option_settings;
use mod_booking\booking_answers\scope_base;
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
    private $answers = [];

    /** @var array array of all user objects (waitinglist and booked) */
    private $users = [];

    /** @var array array of all user objects (no waitinglist, only booked) */
    private $usersonlist = [];

    /** @var array array of all user objects (waitinglist, no booked) */
    private $usersonwaitinglist = [];

    /** @var array array of all user objects (only reserved) */
    private $usersreserved = [];

    /** @var array array of all user objects (only with deleted booking answer) */
    private $usersdeleted = [];

    /** @var array array of all user objects (only those to notify) */
    private $userstonotify = [];

    /** @var array array of all user objects (only previously booked) */
    private $userspreviouslybooked = [];

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
     * @param booking_option_settings|null $bookingoptionsettings
     * @throws dml_exception
     */
    public function __construct($bookingoptionsettings = null) {
        global $DB, $CFG;

        if (empty($bookingoptionsettings)) {
            $this->optionid = 0;
            $this->bookingoptionsettings = null;
            $this->answers = [];
            $this->users = [];
            $this->usersonlist = [];
            $this->usersonwaitinglist = [];
            $this->usersreserved = [];
            $this->usersdeleted = [];
            $this->userstonotify = [];
            $this->userspreviouslybooked = [];
            return;
        }

        $optionid = $bookingoptionsettings->id ?? 0;
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
                        $this->usersonlist[$answer->userid] = $answer;
                        $this->usersreserved[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_DELETED:
                        $this->usersdeleted[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                        $this->userstonotify[$answer->userid] = $answer;
                        break;
                    case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                        $this->userspreviouslybooked[$answer->userid] = $answer;
                        break;
                }
            }

            $data = (object)[
                'answers' => $this->answers,
                'users' => $this->users,
                'usersonlist' => $this->usersonlist,
                'usersonwaitinglist' => $this->usersonwaitinglist,
                'usersreserved' => $this->get_usersreserved(),
                'usersdeleted' => $this->usersdeleted,
                'userstonotify' => $this->userstonotify,
                'userspreviouslybooked' => $this->userspreviouslybooked,
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
            $this->userspreviouslybooked = $data->userspreviouslybooked;
        }
    }

    /**
     * Get all raw booking answers for this option.
     *
     * Returns the full array of answer records (instances of stdClass)
     * associated with this booking option.
     *
     * @return array List of booking answer records.
     */
    public function get_answers(): array {
        return $this->answers;
    }

    /**
     * Get all user booking answers (both booked and on waiting list, excluding deleted).
     *
     * Returns an array of user answer records keyed by user ID. These include
     * all users who have a valid (non-deleted) booking answer.
     *
     * @return array Array of user booking answers.
     */
    public function get_users(): array {
        return $this->users;
    }

    /**
     * Get all users who are booked (on the confirmed booking list).
     *
     * Returns an array of user booking answers where users are marked as booked
     * (not on the waiting list or reserved).
     *
     * @return array Array of booked user records indexed by user ID.
     */
    public function get_usersonlist(): array {
        return $this->usersonlist;
    }

    /**
     * Get all users who are on the waiting list.
     *
     * Returns an array of user booking answers for users currently
     * on the waiting list for this booking option.
     *
     * @return array Array of waiting list user records indexed by user ID.
     */
    public function get_usersonwaitinglist(): array {
        return $this->usersonwaitinglist;
    }

    /**
     * Get all users with reserved bookings.
     *
     * Returns an array of user booking answers for users who are currently
     * marked as reserved for this booking option.
     *
     * @return array Array of reserved user records indexed by user ID.
     */
    public function get_usersreserved(): array {
        return $this->usersreserved;
    }

    /**
     * Get all users with deleted booking answers.
     *
     * Returns an array of user booking answers that were marked as deleted
     * for this booking option.
     *
     * @return array Array of deleted user records indexed by user ID.
     */
    public function get_usersdeleted(): array {
        return $this->usersdeleted;
    }

    /**
     * Get all users who are on the notification list.
     *
     * Returns an array of user booking answers for users who have requested
     * to be notified if a place becomes available.
     *
     * @return array Array of user records to notify, indexed by user ID.
     */
    public function get_userstonotify(): array {
        return $this->userstonotify;
    }

    /**
     * Get all users who are on the notification list.
     *
     * Returns an array of user booking answers for users who have requested
     * to be notified if a place becomes available.
     *
     * @return array Array of user records to notify, indexed by user ID.
     */
    public function get_userspreviouslybooked(): array {
        global $DB, $CFG;

        try {
            if (!empty($this->optionid)) {
                [$sql, $params] = self::return_sql_to_get_answers(
                    $this->optionid,
                    0,
                    0,
                    [MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED]
                );

                $answers = $DB->get_records_sql($sql, $params);
                foreach ($answers as $answer) {
                    $answer = customform::append_customform_elements($answer);
                    $this->userspreviouslybooked[$answer->userid][$answer->baid] = $answer;
                }
            } else {
                $this->userspreviouslybooked = [];
            }
        } catch (Throwable $e) {
            if ($CFG->debug === E_ALL) {
                throw $e;
            } else {
                $this->userspreviouslybooked = [];
            }
        }

        return $this->userspreviouslybooked;
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
     * Returns the last completed answer of a booked user.
     *
     * @param int $userid
     * @return \stdClass
     */
    public function return_last_completion(int $userid) {

        if (
            isset($this->usersonlist[$userid])
            && isset($this->usersonlist[$userid]->completed)
            && $this->usersonlist[$userid]->completed == 1
        ) {
            return $this->usersonlist[$userid];
        } else {
            return (object)[];
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

        // As we have m ultiple bookings functionality,
        // we need to check if user has records in any types of booking (booked, reserved, waitinglist).

        // Consider user has no records for this option in booking_answers table.
        // When we found any record we change it to true.
        $hasuseranybookingrecord = false;

        // Check list of booked users.
        if (
            isset($this->usersonlist[$userid])
            && $this->usersonlist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_BOOKED
        ) {
            $hasuseranybookingrecord = true;
            $answer = $this->usersonlist[$userid];
            if (!empty($answer->json)) {
                $jsonobject = json_decode($answer->json);

                if (!empty($jsonobject->paidwithcredits)) {
                    $returnarray['paidwithcredits'] = true;
                }
            }

            $returnarray = ['iambooked' => $returnarray];
        }

        // Check reserved list.
        if (
            isset($this->usersreserved[$userid])
            && $this->usersreserved[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_RESERVED
        ) {
            $hasuseranybookingrecord = true;
            $returnarray = ['iamreserved' => $returnarray];
        }

        // Check waiting list.
        if (
            isset($this->usersonwaitinglist[$userid])
            && $this->usersonwaitinglist[$userid]->waitinglist == MOD_BOOKING_STATUSPARAM_WAITINGLIST
        ) {
            $hasuseranybookingrecord = true;
            $returnarray = ['onwaitinglist' => $returnarray];
        }

        // If the user does not exist in any of the lists (booked, reserved, or waiting list),
        // then the user has no booking record for this booking option.
        if (!$hasuseranybookingrecord) {
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
                if ($bookinginformation['freeonlist'] == 0) {
                    $bookinginformation['bookingplacesinfotext']
                        = get_string('fullybooked', 'mod_booking');
                } else if ($bookinginformation['freeonlist'] == 1) {
                    $bookinginformation['bookingplacesinfotext']
                        = get_string('bookingplacesplacesoneleft', 'mod_booking');
                } else {
                    $bookinginformation['bookingplacesinfotext']
                        = get_string('bookingplacesplacesleft', 'mod_booking', $bookinginformation['freeonlist']);
                }
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
                if ($bookinginformation['freeonwaitinglist'] == 0) {
                    $bookinginformation['waitinglistplacesinfotext']
                        = " (" . get_string('waitinglistfullmessage', 'mod_booking') . ")";
                } else if ($bookinginformation['freeonwaitinglist'] == 1) {
                    $bookinginformation['waitinglistplacesinfotext']
                        = get_string('waitinglistplacesplacesoneleft', 'mod_booking');
                } else {
                    $bookinginformation['waitinglistplacesinfotext']
                        = get_string('waitinglistplacesplacesleft', 'mod_booking', $bookinginformation['freeonwaitinglist']);
                }
            }
        } else {
            if (isset($bookinginformation['freeonwaitinglist']) && $bookinginformation['freeonwaitinglist'] == -1) {
                if (!has_capability('mod/booking:updatebooking', $context) && $waitingplacesinfotexts) {
                    $bookinginformation['showwaitinglistplacesinfotext'] = true;
                    if ($waitingplacesinfotexts == '1') {
                        // Show Still enough places left.
                        $bookinginformation['waitinglistplacesinfotext'] = get_string('waitinglistenoughmessage', 'mod_booking');
                    } else {
                        // Other cases - show unlimited places left.
                        $bookinginformation['waitinglistplacesinfotext'] = get_string(
                            'waitinglistplacesplacesleft',
                            'mod_booking',
                            get_string('bookingplacesunlimitedmessage', 'mod_booking')
                        );
                    }
                }
                $bookinginformation['waitinglistplacesclass'] = 'text-success avail';
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
     * @return array
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam): array {
        global $DB;
        /** @var scope_base $class */
        $class = $this->return_class_for_scope($scope);
        return $class->return_sql_for_booked_users($scope, $scopeid, $statusparam);
    }

    /**
     * Returns class in namespace for scope and throws if it does not exist.
     *
     * @param string $scope
     *
     * @return scope_base
     *
     */
    public function return_class_for_scope(string $scope): scope_base {
        $class = "\\mod_booking\\booking_answers\\scopes\\" . $scope;

        if (!class_exists($class)) {
            throw new moodle_exception('scopedoesnotexist ' . $scope);
        } else {
            return new $class();
        }
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
                    [$sql, $params] = self::return_sql_to_get_answers(0, $bookingid, $userid, $status);

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
                        [$sql, $params] = self::return_sql_to_get_answers(0, $bookingid, $userid, $statustofetch);
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
     * @param bool $onlycompleted
     *
     * @return array
     */
    private static function return_sql_to_get_answers(
        int $optionid = 0,
        int $bookingid = 0,
        int $userid = 0,
        array $status = [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
        ],
        $onlycompleted = false
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

        if ($onlycompleted) {
            $wherearray[] = ' ba.completed = 1 ';
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
                ba.timebooked,
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

    /**
     * This function counts completed booking answers with status booked over all instances.
     * It does not use the caching we implemented in get_all_answers_for_user_cached, so use with care.
     *
     * @param int $userid
     * @param int $optionid
     * @param int $bookingid
     *
     * @return int
     *
     */
    public static function count_answers_of_user(
        int $userid,
        int $optionid = 0,
        int $bookingid = 0
    ): int {
        global $DB;

        [$sql, $params] = self::return_sql_to_get_answers($optionid, $bookingid, $userid, [MOD_BOOKING_STATUSPARAM_BOOKED], true);
        $records = $DB->get_records_sql($sql, $params);
        return count($records);
    }

    /**
     * This function counts completed booking answers with status booked over all instances.
     * It does not use the caching we implemented in get_all_answers_for_user_cached, so use with care.
     *
     * @param int $userid
     * @param int $optionid
     * @param int $bookingid
     *
     * @return int
     *
     */
    public static function count_allanswers_of_user(
        int $userid,
        int $optionid = 0,
        int $bookingid = 0
    ): int {
        global $DB;

        [$sql, $params] = self::return_sql_to_get_answers(
            $optionid,
            $bookingid,
            $userid,
            [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
            ]
        );
        $records = $DB->get_records_sql($sql, $params);
        return count($records);
    }
}
