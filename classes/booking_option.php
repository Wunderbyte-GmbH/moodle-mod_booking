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
use coding_exception;
use completion_info;
use context_module;
use dml_exception;
use invalid_parameter_exception;
use stdClass;
use moodle_url;
use mod_booking\booking_utils;
use function get_config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option {

    /** @var booking object  */
    public $booking = null;

    /** @var array of stdClass objects including status: key is booking_answer id $allusers->userid, $allusers->waitinglist */
    protected $allusers = array();

    /** @var array of the users booked for this option key userid */
    public $bookedusers = array();

    /** @var array of booked users visible to the current user (group members) */
    public $bookedvisibleusers = array();

    /** @var array of users subscribeable to booking option if groups enabled, members of groups user has access to */
    public $potentialusers = array();

    /** @var int id of the booking option in table booking_options */
    public $optionid = null;

    /** @var stdClass option config object */
    public $option = null;

    /** @var array booking option teachers defined in booking_teachers table */
    public $teachers = array();

    /** @var int number of answers */
    public $numberofanswers = null;

    /** @var array of users filters */
    public $filters = array();

    /** @var array of all user objects (waitinglist and regular) - filtered */
    public $users = array();

    /** @var array of user objects with regular bookings NO waitinglist userid as key */
    public $usersonlist = array();

    /** @var array of user objects with users on waitinglist userid as key */
    public $usersonwaitinglist = array();

    /** @var int number of the page starting with 0 */
    public $page = 0;

    /** @var int number of bookings displayed on a single page */
    public $perpage = 0;

    /** @var string filter and other url params */
    public $urlparams;

    /** @var string $times course start time - course end time or session times separated with a comma */
    public $optiontimes = '';

    /** @var array $sessions array of objects containing coursestarttime and courseendtime as object values */
    public $sessions = array();

    /** @var boolean if I'm booked */
    public $iambooked = 0;

    /** @var boolean if I'm on waiting list */
    public $onwaitinglist = 0;

    /** @var boolean if I completed? */
    public $completed = 0;

    /** @var int user on waiting list */
    public $waiting = 0;

    /** @var int booked users */
    public $booked = 0;

    /**
     * Creates basic booking option
     *
     * @param int $cmid cmid
     * @param int $optionid id of table booking_options
     * @param array $filters
     * @param int $page the current page
     * @param int $perpage options per page
     * @param bool $getusers Get booked users via DB query
     */
    public function __construct($cmid, $optionid, $filters = array(), $page = 0, $perpage = 0, $getusers = true) {
        global $DB, $USER;

        $this->booking = new booking($cmid);
        $this->optionid = $optionid;

        $this->option = $DB->get_record('booking_options', array('id' => $optionid), '*', MUST_EXIST);

        // TODO: We need to get rid of this in the future.
        // It will be done by the booking_option_settings class and the mustache template optiondates_only.
        $times = $DB->get_records_sql(
                "SELECT id, coursestarttime, courseendtime
                   FROM {booking_optiondates}
                  WHERE optionid = ?
               ORDER BY coursestarttime ASC",
                array($optionid));
        if (!empty($times)) {
            $this->sessions = $times;
            foreach ($times as $time) {
                $this->optiontimes .= $time->coursestarttime . " - " . $time->courseendtime . ",";
            }
            trim($this->optiontimes, ",");
        } else {
            $this->optiontimes = '';
        }

        $this->filters = $filters;
        $this->page = $page;
        $this->perpage = $perpage;
        if ($getusers) {
            $this->get_users();
        }
        // TODO: A lot of DB queries: put into fewer queries. Only get this, when necessary.
        // TODO: Maybe create a separate class for managing booking answers.
        $imbooked = $DB->get_record_sql("SELECT COUNT(*) imbooked FROM {booking_answers} WHERE optionid = :optionid
            AND userid = :userid AND waitinglist = 0", array('optionid' => $optionid, 'userid' => $USER->id));
        $this->iambooked = $imbooked->imbooked;

        $onwaitinglist = $DB->get_record_sql("SELECT COUNT(*) onwaitinglist FROM {booking_answers}
            WHERE optionid = :optionid AND userid = :userid AND waitinglist = 1", array('optionid' => $optionid,
            'userid' => $USER->id));
        $this->onwaitinglist = $onwaitinglist->onwaitinglist;

        $completed = $DB->get_record_sql("SELECT COUNT(*) completed FROM {booking_answers} WHERE optionid = :optionid
            AND userid = :userid AND completed = 1", array('optionid' => $optionid, 'userid' => $USER->id));
        $this->completed = $completed->completed;

        $waiting = $DB->get_record_sql("SELECT COUNT(*) rnum FROM {booking_answers} WHERE optionid = :optionid
            AND waitinglist = 1", array('optionid' => $optionid));
        $this->waiting = $waiting->rnum;

        $booked = $DB->get_record_sql("SELECT COUNT(*) rnum FROM {booking_answers} WHERE optionid = :optionid
            AND waitinglist = 0", array('optionid' => $optionid));
        $this->booked = $booked->rnum;
        // To deal with the necessity of unique booking names.
        booking_utils::transform_unique_bookingoption_name_to_display_name($this->option);
    }

    /**
     * Returns a booking_option object when optionid is passed along. Saves db query when booking id is given as well.
     * TODO: cache this.
     *
     * @param $optionid
     * @param int $boid booking id
     * @return booking_option
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function create_option_from_optionid($optionid, $boid = null) {
        global $DB;
        if (is_null($boid)) {
            $boid = $DB->get_field('booking_options', 'bookingid', ['id' => $optionid]);
        }
        $cm = get_coursemodule_from_instance('booking', $boid);
        return new booking_option($cm->id, $optionid);
    }

    /**
     * This calculates number of user that can be booked to the connected booking option
     * Looks for max participant in the connected booking given the optionid
     *
     * @param number $optionid
     * @return number
     */
    public function calculate_how_many_can_book_to_other($optionid) {
        global $DB;

        if (isset($optionid) && $optionid > 0) {
            $alreadybooked = 0;

            $result = $DB->get_records_sql(
                    'SELECT answers.userid FROM {booking_answers} answers
                    INNER JOIN {booking_answers} parent on parent.userid = answers.userid
                    WHERE answers.optionid = ? AND parent.optionid = ?',
                    array($this->optionid, $optionid));

            $alreadybooked = count($result);

            $keys = array();

            foreach ($result as $value) {
                $keys[] = $value->userid;
            }

            foreach ($this->usersonwaitinglist as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->bookedtootherbooking = 1;
                } else {
                    $user->bookedtootherbooking = 0;
                }
            }

            foreach ($this->usersonlist as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->usersonlist = 1;
                } else {
                    $user->usersonlist = 0;
                }
            }

            $connectedbooking = $DB->get_record("booking",
                    array('conectedbooking' => $this->booking->settings->id), 'id', IGNORE_MULTIPLE);

            if ($connectedbooking) {

                $nolimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM {booking_other} bo
                        LEFT JOIN {booking_options} b ON b.id = bo.optionid
                        WHERE b.bookingid = ?", array($connectedbooking->id));

                if (!$nolimits) {
                    $howmanynum = $this->option->howmanyusers;
                } else {
                    $hownany = $DB->get_record_sql(
                            "SELECT userslimit FROM {booking_other} WHERE optionid = ? AND otheroptionid = ?",
                            array($optionid, $this->optionid));

                    $howmanynum = 0;
                    if ($hownany) {
                        $howmanynum = $hownany->userslimit;
                    }
                }
            }

            if ($howmanynum == 0) {
                $howmanynum = 999999;
            }

            return (int) $howmanynum - (int) $alreadybooked;
        } else {
            return 0;
        }
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see booking::apply_tags()
     */
    public function apply_tags() {
        $this->booking->apply_tags();
        $tags = new booking_tags($this->booking->cm->course);
        $this->option = $tags->option_replace($this->option);
    }

    public function get_url_params() {
        $bu = new booking_utils();
        $params = $bu->generate_params($this->booking->settings, $this->option);
        $this->option->pollurl = $bu->get_body($params, 'pollurl', $params, true);
        $this->option->pollurlteachers = $bu->get_body($params, 'pollurlteachers', $params, true);
    }

    /**
     * Get teachers from booking_teachers if not set
     */
    public function get_teachers() {
        global $DB;
        if (empty($this->teachers)) {
            $this->teachers = $DB->get_records_sql(
                    'SELECT DISTINCT t.userid, u.firstname, u.lastname, u.email, u.institution
                            FROM {booking_teachers} t
                       LEFT JOIN {user} u ON t.userid = u.id
                           WHERE t.optionid = ' . $this->optionid . '');
        }
        return $this->teachers;
    }

    /**
     * Get all users filtered,and save them in
     * $this->users all users (booked and waitinglist)
     * $this->usersonwaitinglist waitinglist users
     * $this->usersonlist booked users
     */
    public function get_users() {
        global $CFG, $DB;
        $params = array();

        $options = "ba.optionid = :optionid";
        $params['optionid'] = $this->optionid;

        if (isset($this->filters['searchcompleted']) && strlen($this->filters['searchcompleted']) > 0) {
            $options .= " AND ba.completed = :completed";
            $params['completed'] = $this->filters['searchcompleted'];
        }
        if (isset($this->filters['searchdate']) && $this->filters['searchdate'] == 1) {
            $beginofday = strtotime("{$this->urlparams['searchdateday']}-{$this->urlparams['searchdatemonth']}-"
                . "{$this->urlparams['searchdateyear']}");
            $endofday = strtotime("tomorrow", $beginofday) - 1;
            $options .= " AND ba.timecreated BETWEEN :beginofday AND :endofday";
            $params['beginofday'] = $beginofday;
            $params['endofday'] = $endofday;
        }

        if (isset($this->filters['searchname']) && strlen($this->filters['searchname']) > 0) {
            $options .= " AND u.firstname LIKE :searchname";
            $params['searchname'] = '%' . $this->filters['searchname'] . '%';
        }

        if (isset($this->filters['searchsurname']) && strlen($this->filters['searchsurname']) > 0) {
            $options .= " AND u.lastname LIKE :searchsurname";
            $params['searchsurname'] = '%' . $this->filters['searchsurname'] . '%';
        }
        if (groups_get_activity_groupmode($this->booking->cm) == SEPARATEGROUPS and
                 !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->booking->course->id))) {
            list($groupsql, $groupparams) = booking::booking_get_groupmembers_sql(
                    $this->booking->course->id);
            $options .= " AND u.id IN ($groupsql)";
            $params = array_merge($params, $groupparams);
        }

        $limitfrom = $this->perpage * $this->page;
        $numberofrecords = $this->perpage;

        if ($CFG->version >= 2021051700) {
            // This only works in Moodle 3.11 and later.
            $mainuserfields = \core_user\fields::for_name()->with_userpic()->get_sql('u')->selects;
            $mainuserfields = trim($mainuserfields, ', ');
        } else {
            // This is only here to support Moodle versions earlier than 3.11.
            $mainuserfields = \user_picture::fields('u');
        }

        $sql = 'SELECT ba.id AS aid,
                ba.bookingid,
                ba.numrec,
                ba.userid,
                ba.optionid,
                ba.timemodified,
                ba.completed,
                ba.timecreated,
                ba.waitinglist,' .
                $mainuserfields . ', ' .
                $DB->sql_fullname('u.firstname', 'u.lastname') . ' AS fullname
                FROM {booking_answers} ba
                LEFT JOIN {user} u ON ba.userid = u.id
                WHERE ' . $options . '
                ORDER BY ba.optionid, ba.timemodified DESC';

        $this->users = $DB->get_records_sql($sql, $params, $limitfrom, $numberofrecords);

        foreach ($this->users as $user) {
            if ($user->waitinglist == 1) {
                $this->usersonwaitinglist[$user->userid] = $user;
            } else {
                $this->usersonlist[$user->userid] = $user;
            }
        }
    }

    /**
     * Get all answers (bookings) as an array of objects
     * booking_answer id as key, ->userid, ->waitinglist
     *
     * @return array of objects
     * @throws dml_exception
     */
    public function get_all_users() {
        global $CFG, $DB;
        if (empty($this->allusers)) {
            $params = array('optionid' => $this->optionid);

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

            $this->allusers = $DB->get_records_sql($sql, $params);
        }
        return $this->allusers;
    }

    /**
     * Get all users on waitinglist as an array of objects
     * booking_answer id as key, ->userid,
     *
     * @return array of userobjects $this->allusers key: booking_answers id
     */
    public function get_all_users_on_waitinglist() {

        if (empty($this->allusers)) {
            $allusers = $this->get_all_users();
        } else {
            $allusers = $this->allusers;
        }

        $waitinglistusers = array();
        foreach ($allusers as $baid => $user) {
            if ($user->waitinglist == 1) {
                $waitinglistusers[$baid] = $user;
            }
        }
        return $waitinglistusers;
    }

    /**
     * Get all users booked users (not on waiting list) as an array of objects
     * booking_answer id as key, ->userid,
     *
     * @return array of userobjects $this->allusers key: booking_answers id
     */
    public function get_all_users_booked() {
        $bookedusers = array();
        if (empty($this->allusers)) {
            $allusers = $this->get_all_users();
        } else {
            $allusers = $this->allusers;
        }
        foreach ($allusers as $baid => $user) {
            if ($user->waitinglist != 1) {
                $bookedusers[$baid] = $user;
            }
        }
        return $bookedusers;
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     *
     * @param number $userid
     * @return number status 0 = not existing, 1 = waitinglist, 2 = regularely booked
     */
    public function user_status($userid = null) {
        global $DB, $USER;
        $booked = false;
        if (is_null($userid)) {
            $userid = $USER->id;
        }

        $booked = $DB->get_field('booking_answers', 'waitinglist',
                array('optionid' => $this->optionid, 'userid' => $userid));

        if ($booked === false) {
            // Check, if it's in teachers table.
            if ($DB->get_field('booking_teachers', 'id',
                    array('optionid' => $this->optionid, 'userid' => $userid)) !== false) {
                return 2;
            }
            return 0;
        } else if ($booked === "0") {
            return 2;
        } else if ($booked === 1 || $booked === "1") {
            return 1;
        } else { // Should never be reached.
            return 0;
        }
    }

    /**
     * Checks booking status of $userid for this booking option. If no $userid is given $USER is used (logged in user)
     *
     * @param number $userid
     * @return number status 0 = activity not completed, 1 = activity completed
     */
    public function is_activity_completed($userid = null) {
        global $DB, $USER;
        if (\is_null($userid)) {
            $userid = $USER->id;
        }

        $userstatus = $DB->get_field('booking_answers', 'completed',
                array('optionid' => $this->optionid, 'userid' => $userid));

        if ($userstatus == 1) {
            return $userstatus;
        } else {
            return 0;
        }
    }

    /**
     * Return if user can rate.
     *
     * @return bool
     */
    public function can_rate() {
        global $USER;

        if ($this->booking->settings->ratings == 0) {
            return false;
        }

        if ($this->booking->settings->ratings == 1) {
            return true;
        }

        if ($this->booking->settings->ratings == 2) {
            if (in_array($this->user_status($USER->id), array(1, 2))) {
                return true;
            } else {
                return false;
            }
        }

        if ($this->booking->settings->ratings == 3) {
            if ($this->is_activity_completed($USER->id)) {
                return true;
            } else {
                return false;
            }
        }

        return false;
    }

    /**
     * Get text for depending on status of users booking.
     *
     * @param null $userid
     * @return string
     */
    public function get_option_text($userid = null) {
        global $USER;

        $text = "";

        $params = booking_generate_email_params($this->booking->settings, $this->option, $USER, $this->booking->cm->id,
            $this->optiontimes);

        if (in_array($this->user_status($userid), array(1, 2))) {
            $ac = $this->is_activity_completed($userid);
            if ($ac == 1) {
                if (!empty($this->option->aftercompletedtext)) {
                    $text = format_text($this->option->aftercompletedtext, FORMAT_HTML, $this->booking->course->id);
                } else if (!empty($this->booking->settings->aftercompletedtext)) {
                    $text = format_text($this->booking->settings->aftercompletedtext, FORMAT_HTML, $this->booking->course->id);
                }
            } else {
                if (!empty($this->option->beforecompletedtext)) {
                    $text = format_text($this->option->beforecompletedtext, FORMAT_HTML, $this->booking->course->id);
                } else if (!empty($this->booking->settings->beforecompletedtext)) {
                    $text = format_text($this->booking->settings->beforecompletedtext, FORMAT_HTML, $this->booking->course->id);
                }
            }
        } else {
            if (!empty($this->option->beforebookedtext)) {
                $text = format_text($this->option->beforebookedtext, FORMAT_HTML, $this->booking->course->id);
            } else if (!empty($this->booking->settings->beforebookedtext)) {
                $text = format_text($this->booking->settings->beforebookedtext, FORMAT_HTML, $this->booking->course->id);
            }
        }

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Updates canbookusers and bookedusers does not check the status (booked or waitinglist)
     * Just gets the registered booking from database
     * Calculates the potential users (bookers able to book, but not yet booked)
     */
    public function update_booked_users() {
        global $CFG, $DB, $USER;

        if (empty($this->booking->canbookusers)) {
            $this->booking->get_canbook_userids();
        }

        if ($CFG->version >= 2021051700) {
            // This only works in Moodle 3.11 and later.
            $mainuserfields = \core_user\fields::for_name()->with_userpic()->get_sql('u')->selects;
            // The $mainuserfields variable already includes a comma in the beginning, so trim it first.
            $mainuserfields = trim($mainuserfields, ', ');
        } else {
            // This is deprecated in Moodle 3.11 and later.
            $mainuserfields = \user_picture::fields('u', null);
        }

        $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
                 FROM {booking_answers} ba, {user} u
                WHERE ba.userid = u.id
                  AND u.deleted = 0
                  AND ba.bookingid = :bookingid
                  AND ba.optionid = :optionid
             ORDER BY ba.timemodified ASC";

        $params = array("bookingid" => $this->booking->id, "optionid" => $this->optionid);

        // Note: mod/booking:choose may have been revoked after the user has booked: not count them as booked.
        $allanswers = $DB->get_records_sql($sql, $params);
        $this->bookedusers = array_intersect_key($allanswers, $this->booking->canbookusers);
        // TODO offer users with according caps to delete excluded users from booking option.
        $this->numberofanswers = count($this->bookedusers);
        if (groups_get_activity_groupmode($this->booking->cm) == SEPARATEGROUPS and
                 !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->booking->course->id))) {
            $mygroups = groups_get_all_groups($this->booking->course->id, $USER->id);
            $mygroupids = array_keys($mygroups);
            list($insql, $inparams) = $DB->get_in_or_equal($mygroupids, SQL_PARAMS_NAMED, 'grp', true, -1);

            $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
            FROM {booking_answers} ba, {user} u, {groups_members} gm
            WHERE ba.userid = u.id AND
            u.deleted = 0 AND
            ba.bookingid = :bookingid AND
            ba.optionid = :optionid AND
            u.id = gm.userid AND gm.groupid $insql
            GROUP BY u.id
            ORDER BY ba.timemodified ASC";
            $groupmembers = $DB->get_records_sql($sql, array_merge($params, $inparams));
            $this->bookedvisibleusers = array_intersect_key($groupmembers, $this->booking->canbookusers);
        } else {
            $this->bookedvisibleusers = $this->bookedusers;
        }
        $this->potentialusers = array_diff_key($this->booking->canbookusers, $this->bookedvisibleusers);
        $this->sort_answers();
    }

    /**
     * Add booked/waitinglist info to each userobject of users.
     */
    public function sort_answers() {
        if (!empty($this->bookedusers) && null != $this->option) {
            foreach ($this->bookedusers as $rank => $userobject) {
                $userobject->bookingcmid = $this->booking->cm->id;
                if (!$this->option->limitanswers) {
                    $userobject->booked = 'booked';
                }
                // Rank starts at 0 so add + 1 to corespond to max answer settings.
                if ($this->option->maxanswers < ($rank + 1) &&
                         $rank + 1 <= ($this->option->maxanswers + $this->option->maxoverbooking)) {
                    $userobject->booked = 'waitinglist';
                } else if ($rank + 1 <= $this->option->maxanswers) {
                    $userobject->booked = 'booked';
                }
            }
        }
    }

    /**
     * Mass delete all users with activity completion.
     *
     * @return array
     * @throws dml_exception
     */
    public function delete_responses_activitycompletion() {
        global $DB;

        $ud = array();
        $oud = array();
        $users = $DB->get_records('course_modules_completion',
                array('coursemoduleid' => $this->booking->settings->completionmodule));
        $ousers = $DB->get_records('booking_answers', array('optionid' => $this->optionid));

        foreach ($users as $u) {
            $ud[] = $u->userid;
        }

        foreach ($ousers as $u) {
            $oud[] = $u->userid;
        }

        $todelete = array_intersect($ud, $oud);

        $results = array();
        foreach ($todelete as $userid) {
            $results[$userid] = $this->user_delete_response($userid);
        }

        return $results;
    }

    /**
     * Mass delete all responses
     *
     * @param array $users array of users
     * @return array
     */
    public function delete_responses($users = array()) {
        $results = array();
        if (!is_array($users) || empty($users)) {
            return $results;
        }
        foreach ($users as $userid) {
            $results[$userid] = $this->user_delete_response($userid);
        }
        return $results;
    }

    /**
     * Deletes a single booking of a user if user cancels the booking, sends mail to bookingmanager.
     * If there is a limit book other user and send mail to the user.
     *
     * @param $userid
     * @return true if booking was deleted successfully, otherwise false
     */
    public function user_delete_response($userid) {
        global $USER, $DB;
        $result = $DB->get_records('booking_answers',
                array('userid' => $userid, 'optionid' => $this->optionid, 'completed' => 0));

        if (count($result) == 0) {
            return false;
        }
        $DB->delete_records('booking_answers',
                array('userid' => $userid, 'optionid' => $this->optionid, 'completed' => 0));

        if ($userid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', array('id' => $userid));
        }

        // Log deletion of user.
        $event = event\booking_cancelled::create(
                array('objectid' => $this->optionid,
                    'context' => \context_module::instance($this->booking->cm->id),
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();
        $this->unenrol_user($user->id);

        $params = booking_generate_email_params($this->booking->settings, $this->option, $user, $this->booking->cm->id,
            $this->optiontimes, false, false, true);

        if ($userid == $USER->id) {
            // I cancelled the booking.
            $messagebody = booking_get_email_body($this->booking->settings, 'userleave',
                    'userleavebookedmessage', $params);
            $subject = get_string('userleavebookedsubject', 'booking', $params);
        } else {
            // Booking manager cancelled the booking.
            $messagebody = booking_get_email_body($this->booking->settings, 'deletedtext',
                    'deletedbookingmessage', $params);
            $subject = get_string('deletedbookingsubject', 'booking', $params);
        }

        if (!$bookingmanager = $DB->get_record('user',
                array('username' => $this->booking->settings->bookingmanager))) {
            // If bookingmanager was deleted, we use global $USER instead.
            $bookingmanager = $USER;
        }

        $eventdata = new stdClass();

        if ($this->booking->settings->sendmail) {
            // Generate ical attachment to go with the message.
            $attachname = '';
            $attachments = '';
            if (get_config('booking', 'icalcancel')) {
                $ical = new ical($this->booking->settings, $this->option, $user, $bookingmanager);
                $attachments = $ical->get_attachments(true);
            }
            $messagehtml = text_to_html($messagebody, false, false, true);

            if (isset($this->booking->settings->sendmailtobooker) && $this->booking->settings->sendmailtobooker) {
                $eventdata->userto = $USER;
            } else {
                $eventdata->userto = $user;
            }

            $eventdata->userfrom = $bookingmanager;
            $eventdata->subject = $subject;
            $eventdata->messagetext = format_text_email($messagebody, FORMAT_HTML);
            $eventdata->messagehtml = $messagehtml;
            $eventdata->attachment = $attachments;
            $eventdata->attachname = $attachname;
            $sendtask = new task\send_confirmation_mails();
            $sendtask->set_custom_data($eventdata);
            \core\task\manager::queue_adhoc_task($sendtask);

            if ($this->booking->settings->copymail) {
                $eventdata->userto = $bookingmanager;
                $sendtask = new task\send_confirmation_mails();
                $sendtask->set_custom_data($eventdata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }
        }

        // Sync the waiting list and send status change mails.
        $this->sync_waiting_list();

        // Remove activity completion.
        $course = $DB->get_record('course', array('id' => $this->booking->settings->course));
        $completion = new completion_info($course);

        $countcompleted = $DB->count_records('booking_answers',
                array('bookingid' => $this->booking->settings->id, 'userid' => $user->id, 'completed' => '1'));

        if ($completion->is_enabled($this->booking->cm) && $this->booking->settings->enablecompletion < $countcompleted) {
            $completion->update_state($this->booking->cm, COMPLETION_INCOMPLETE, $userid);
        }

        return true;
    }

    /**
     * Unsubscribes given users from this booking option and subscribes them to the newoption
     *
     * @param number $newoption
     * @param array of numbers $userids
     * @return stdClass transferred->success = true/false, transferred->no[] errored users,
     *         $transferred->yes transferred users
     */
    public function transfer_users_to_otheroption($newoption, $userids) {
        global $CFG, $DB;
        $transferred = new stdClass();
        $transferred->yes = array(); // Successfully transferred users.
        $transferred->no = array(); // Errored users.
        $transferred->success = false;
        $otheroption = new booking_option($this->booking->cm->id, $newoption);
        if (!empty($userids) && (has_capability('mod/booking:subscribeusers', $this->booking->get_context()) ||
                booking_check_if_teacher($otheroption->option))) {
            $transferred->success = true;
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "limit_");

            if ($CFG->version >= 2021051700) {
                // This only works in Moodle 3.11 and later.
                $mainuserfields = \core_user\fields::for_name()->get_sql('u')->selects;
                $mainuserfields = trim($mainuserfields, ', ');
            } else {
                // This is only here to support Moodle versions earlier than 3.11.
                $mainuserfields = get_all_user_name_fields(true, 'u');
            }

            $sql = 'SELECT ba.userid AS id,
                ba.timecreated,
                ' . $mainuserfields . ', ' .
                     $DB->sql_fullname('u.firstname', 'u.lastname') . ' AS fullname
                FROM {booking_answers} ba
                LEFT JOIN {user} u ON ba.userid = u.id
                WHERE ' . 'ba.userid ' . $insql . '
                AND ba.optionid = ' . $this->optionid . '
                ORDER BY ba.timecreated ASC';
            $users = $DB->get_records_sql($sql, $inparams);
            foreach ($users as $user) {
                if ($otheroption->user_submit_response($user, 0, 1)) {
                    $transferred->yes[] = $user;
                } else {
                    $transferred->no[] = $user;
                    $transferred->success = false;
                }
            }
        }
        if (!empty($transferred->yes)) {
            foreach ($transferred->yes as $user) {
                $this->user_delete_response($user->id);
            }
        }

        return $transferred;
    }

    /**
     * "Sync" users on waiting list, based on edited option - if has limit or not.
     */
    public function sync_waiting_list() {
        global $DB;

        if ($this->option->limitanswers) {

            // If users drop out of the waiting list because of changed limits, delete and inform them.
            $answerstodelete = $DB->get_records_sql(
                'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                array($this->optionid), $this->option->maxoverbooking + $this->option->maxanswers);

            foreach ($answerstodelete as $answertodelete) {
                $DB->delete_records('booking_answers', array('id' => $answertodelete->id));

                $this->send_mail_with_adhoc_task($answertodelete, 'deletedtext',
                    'deletedbookingmessage', 'deletedbookingsubject');
            }

            // Update, enrol and inform users who have switched from the waiting list to status "booked".
            $newbookedanswers = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), 0, $this->option->maxanswers);
            foreach ($newbookedanswers as $newbookedanswer) {
                if ($newbookedanswer->waitinglist != 0) {
                    $newbookedanswer->waitinglist = 0;
                    $DB->update_record("booking_answers", $newbookedanswer);
                    $this->enrol_user_coursestart($newbookedanswer->userid);

                    $this->send_mail_with_adhoc_task($newbookedanswer, 'statuschangetext',
                        'statuschangemessage', 'statuschangesubject');
                }
            }

            // Update and inform users who have been put on the waiting list because of changed limits.
            $newwaitinglistanswers = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), $this->option->maxanswers, $this->option->maxoverbooking);

            foreach ($newwaitinglistanswers as $newwaitinglistanswer) {
                if ($newwaitinglistanswer->waitinglist != 1) {
                    $newwaitinglistanswer->waitinglist = 1;
                    $DB->update_record("booking_answers", $newwaitinglistanswer);

                    $this->send_mail_with_adhoc_task($newwaitinglistanswer, 'statuschangetext',
                        'statuschangemessage', 'statuschangesubject');
                }
            }
        } else {
            // If option was set to unlimited, inform all users that have been on the waiting list of the status change.
            if ($onwaitinglistanswers = $DB->get_records('booking_answers', ['optionid' => $this->optionid,
                                                                            'waitinglist' => 1])) {
                foreach ($onwaitinglistanswers as $onwaitinglistanswer) {
                    $this->send_mail_with_adhoc_task($onwaitinglistanswer, 'statuschangetext',
                    'statuschangemessage', 'statuschangesubject');
                }
            }

            // Now move everybody from the waiting list to booked users.
            $DB->execute("UPDATE {booking_answers} SET waitinglist = 0 WHERE optionid = :optionid",
                    array('optionid' => $this->optionid));
        }
    }

    /**
     * Helper function to queue an adhoc tasks for sending an email.
     *
     * @param stdClass $bookinganswer DB record of a booking_answer.
     * @param stdClass $bookingmanager DB record of the bookingmanager (an admin user)
     * @param string $messagefieldname name of the message field
     * @param string $messagedefaultname default template of the message
     * @param string $messagesubject message subject
     * @throws coding_exception
     * @throws dml_exception
     */
    private function send_mail_with_adhoc_task(stdClass $bookinganswer, string $messagefieldname,
                                               string   $messagedefaultname, string $messagesubject) {
        global $DB, $USER;

        $eventdata = new stdClass();
        $eventdata->attachment = [];
        $eventdata->attachname = '';

        if (!$bookingmanager = $DB->get_record('user',
            array('username' => $this->booking->settings->bookingmanager))) {
            // If booking manager was deleted, we use global $USER instead.
            $bookingmanager = $USER;
        }

        if ($this->booking->settings->sendmail == 1 || $this->booking->settings->copymail) {
            $newbookeduser = $DB->get_record('user', array('id' => $bookinganswer->userid));
            $params = booking_generate_email_params($this->booking->settings, $this->option,
                $newbookeduser, $this->booking->cm->id, $this->optiontimes, false,
                false, true);
            $messagetextnewuser = booking_get_email_body($this->booking->settings, $messagefieldname,
                $messagedefaultname, $params);
            $messagehtml = text_to_html($messagetextnewuser, false, false, true);

            // Generate ical attachment to go with the message.
            // Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $attachname = '';
                $ical = new ical($this->booking->settings, $this->option, $newbookeduser,
                    $bookingmanager);
                if ($attachment = $ical->get_attachments()) {
                    $attachname = $ical->get_name();
                }
                $eventdata->attachment = $attachment;
                $eventdata->attachname = $attachname;
            }

            $eventdata->userto = $newbookeduser;
            $eventdata->userfrom = $bookingmanager;
            $eventdata->subject = get_string($messagesubject, 'booking', $params);
            $eventdata->messagetext = $messagetextnewuser;
            $eventdata->messagehtml = $messagehtml;

            if ($this->booking->settings->sendmail == 1) {
                $sendtask = new task\send_confirmation_mails();
                $sendtask->set_custom_data($eventdata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }
            if ($this->booking->settings->copymail) {
                $eventdata->userto = $bookingmanager;
                $sendtask = new task\send_confirmation_mails();
                $sendtask->set_custom_data($eventdata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }
        }
    }

    /**
     * Enrol users only if either course has already started or booking option is set to immediately enrol users.
     *
     * @param int $userid
     * @throws \moodle_exception
     */
    public function enrol_user_coursestart($userid) {
        if ($this->option->enrolmentstatus == 2 OR
            ($this->option->enrolmentstatus < 2 AND $this->option->coursestarttime < time())) {
            $this->enrol_user($userid);
        }
    }

    /**
     * Subscribe a user to a booking option
     *
     * @param stdClass $user
     * @param number $frombookingid
     * @param number $substractfromlimit this is used for transferring users from one option to
     *        another
     *        The number of bookings for the user has to be decreased by one, because, the user will
     *        be unsubscribed
     *        from the old booking option afterwards (which is not yet taken into account).
     * @return boolean true if booking was possible, false if meanwhile the booking got full
     */
    public function user_submit_response($user, $frombookingid = 0, $substractfromlimit = 0) {
        global $DB;

        if (null == $this->option) {
            echo "<br>Didn't find option to subscribe user $user->username <br>";
            return false;
        }

        $waitinglist = $this->check_if_limit();

        if ($waitinglist === false) {
            echo "Couldn't subscribe user $user->username because of waitinglist <br>";
            return false;
        }

        $underlimit = ($this->booking->settings->maxperuser == 0);
        $underlimit = $underlimit ||
                (($this->booking->get_user_booking_count($user) - $substractfromlimit) < $this->booking->settings->maxperuser);
        if (!$underlimit) {
            mtrace("Couldn't subscribe user $user->username because of maxperuser setting <br>");
            return false;
        }
        $currentanswerid = $DB->get_field('booking_answers', 'id',
                array('userid' => $user->id, 'optionid' => $this->optionid));
        if (!$currentanswerid) {
            $newanswer = new stdClass();
            $newanswer->bookingid = $this->booking->id;
            $newanswer->frombookingid = $frombookingid;
            $newanswer->userid = $user->id;
            $newanswer->optionid = $this->optionid;
            $newanswer->timemodified = time();
            $newanswer->timecreated = time();
            $newanswer->waitinglist = $waitinglist;

            if (!$DB->insert_record("booking_answers", $newanswer)) {
                new \moodle_exception("dmlwriteexception");
            }
            $this->enrol_user_coursestart($newanswer->userid);
        }

        $event = event\bookingoption_booked::create(
                array('objectid' => $this->optionid,
                    'context' => \context_module::instance($this->booking->cm->id),
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();

        // Check if the option is a multidates session.
        if (!$optiondates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid])) {
            $optiondates = false;
        }

        // If the option has optiondates, then add the optiondate events to the user's calendar.
        if ($optiondates) {
            foreach ($optiondates as $optiondate) {
                new \mod_booking\calendar($this->booking->cm->id, $this->optionid, $user->id, 6, $optiondate->id, 1);
            }
        } else {
            // Else add the booking option event to the user's calendar.
            new \mod_booking\calendar($this->booking->cm->id, $this->optionid, $user->id, 1, 0, 1);
        }

        if ($this->booking->settings->sendmail) {
            $this->send_confirm_message($user);
        }
        return true;
    }

    /**
     * Event that sends confirmation notification after user successfully booked.
     *
     * With the second param "optionchanged" set to true, this will send a notification mail to the user,
     * informing him/her that the option event has changed and will include the updated ical.
     *
     * TODO this should be rewritten for moodle 2.6 onwards
     *
     * @param stdClass $user user object
     * @param bool $optionchanged optional param used to inform the user of updates on the option
     * @param array $changes a string containing changes to be replaced in the update message
     * @return bool
     */
    public function send_confirm_message($user, $optionchanged = false, $changes = false) {
        global $DB, $USER;
        $cmid = $this->booking->cm->id;
        // Used to store the ical attachment (if required).
        $attachname = '';
        $attachments = array();

        $user = $DB->get_record('user', array('id' => $user->id));
        $bookingmanager = $DB->get_record('user',
                array('username' => $this->booking->settings->bookingmanager));
        $data = booking_generate_email_params($this->booking->settings, $this->option, $user, $cmid,
                $this->optiontimes, $changes, false, true);

        $cansend = true;

        if ($optionchanged) {
            // When there have been changes relevant to the calendar (or ical) entry, then inform the booked users.
            $subject = get_string('bookingchangedsubject', 'booking', $data);
            $message = booking_get_email_body($this->booking->settings, 'bookingchangedtext', 'bookingchangedmessage',
                $data);

            // Generate ical attachments to go with the message.
            // Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new ical($this->booking->settings, $this->option, $user, $bookingmanager, true);
                $attachments = $ical->get_attachments();
            }
        } else if ($data->status == get_string('booked', 'booking')) {
            $subject = get_string('confirmationsubject', 'booking', $data);
            $subjectmanager = get_string('confirmationsubjectbookingmanager', 'booking', $data);
            $message = booking_get_email_body($this->booking->settings, 'bookedtext', 'confirmationmessage',
                    $data);

            // Generate ical attachments to go with the message.
            // Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new ical($this->booking->settings, $this->option, $user, $bookingmanager);
                $attachments = $ical->get_attachments();
            }
        } else if ($data->status == get_string('onwaitinglist', 'booking')) {
            $subject = get_string('confirmationsubjectwaitinglist', 'booking', $data);
            $subjectmanager = get_string('confirmationsubjectwaitinglistmanager', 'booking', $data);
            $message = booking_get_email_body($this->booking->settings, 'waitingtext',
                    'confirmationmessagewaitinglist', $data);
        } else {
            // This should never be reached.
            $subject = "test";
            $subjectmanager = "tester";
            $message = "message";

            $cansend = false;
        }
        $messagehtml = text_to_html($message, false, false, true);
        $errormessage = get_string('error:failedtosendconfirmation', 'booking', $data);
        $errormessagehtml = text_to_html($errormessage, false, false, true);
        $user->mailformat = FORMAT_HTML; // Always send HTML version as well.

        $messagedata = new stdClass();
        $messagedata->userfrom = $bookingmanager;
        if ($this->booking->settings->sendmailtobooker) {
            $messagedata->userto = $DB->get_record('user', array('id' => $USER->id));
        } else {
            $messagedata->userto = $DB->get_record('user', array('id' => $user->id));
        }
        $messagedata->subject = $subject;
        $messagedata->messagetext = format_text_email($message, FORMAT_HTML);
        $messagedata->messagehtml = $messagehtml;
        $messagedata->attachment = $attachments;
        $messagedata->attachname = $attachname;

        if ($cansend) {
            $sendtask = new task\send_confirmation_mails();
            $sendtask->set_custom_data($messagedata);
            \core\task\manager::queue_adhoc_task($sendtask);
        }

        // If the setting to send a copy to the booking manger has been enabled,
        // then also send a copy to the booking manager.
        // Do not send copies of change notifications to booking managers.
        if ($this->booking->settings->copymail && !$optionchanged) {
            $messagedata->userto = $bookingmanager;
            $messagedata->subject = $subjectmanager;

            if ($cansend) {
                $sendtask = new task\send_confirmation_mails();
                $sendtask->set_custom_data($messagedata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }
        }
        return true;
    }

    /**
     * Automatically enrol the user in the relevant course, if that setting is on and a course has been specified.
     * Added option, to manualy enrol user, with a click of button.
     *
     * @param int $userid
     */
    public function enrol_user($userid, $manual = false, $roleid = 0) {
        global $DB;
        if (!$manual) {
            if (!$this->booking->settings->autoenrol) {
                return; // Autoenrol not enabled.
            }
        }
        if (!$this->option->courseid) {
            return; // No course specified.
        }

        if (!enrol_is_enabled('manual')) {
            return; // Manual enrolment not enabled.
        }

        if (!$enrol = enrol_get_plugin('manual')) {
            return; // No manual enrolment plugin.
        }
        if (!$instances = $DB->get_records('enrol',
                array('enrol' => 'manual', 'courseid' => $this->option->courseid,
                    'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
            return; // No manual enrolment instance on this course.
        }

        $instance = reset($instances); // Use the first manual enrolment plugin in the course.
        if ($this->user_status($userid) === 2) {
            $enrol->enrol_user($instance, $userid, ($roleid > 0 ? $roleid : $instance->roleid)); // Enrol using the default role.
            if ($this->booking->settings->addtogroup == 1) {
                $groups = groups_get_all_groups($this->option->courseid);
                if (!is_null($this->option->groupid) && ($this->option->groupid > 0) &&
                        in_array($this->option->groupid, $groups)) {
                    groups_add_member($this->option->groupid, $userid);
                } else {
                    if ($groupid = $this->create_group()) {
                        groups_add_member($groupid, $userid);
                    } else {
                        throw new \moodle_exception('groupexists', 'booking');
                    }
                }
            }
        }
    }

    /**
     * Unenrol the user from the course, which has been defined as target course
     * in the booking option settings
     *
     * @param number $userid
     */
    public function unenrol_user($userid) {
        global $DB;

        if (!$this->booking->settings->autoenrol) {
            return; // Autoenrol not enabled.
        }
        if (!$this->option->courseid) {
            return; // No course specified.
        }
        if (!enrol_is_enabled('manual')) {
            return; // Manual enrolment not enabled.
        }
        if (!$enrol = enrol_get_plugin('manual')) {
            return; // No manual enrolment plugin.
        }

        if (!$instances = $DB->get_records('enrol',
                array('enrol' => 'manual', 'courseid' => $this->option->courseid,
                        'status' => ENROL_INSTANCE_ENABLED), 'sortorder,id ASC')) {
            return; // No manual enrolment instance on this course.
        }
        if ($this->booking->settings->addtogroup == 1) {
            if (!is_null($this->option->groupid) && ($this->option->groupid > 0)) {
                $groupsofuser = groups_get_all_groups($this->option->courseid, $userid);
                $numberofgroups = count($groupsofuser);
                // When user is member of only 1 group: unenrol from course otherwise remove from group.
                if ($numberofgroups > 1) {
                    groups_remove_member($this->option->groupid, $userid);
                    return;
                }
            }
        }
        $instance = reset($instances); // Use the first manual enrolment plugin in the course.
        $enrol->unenrol_user($instance, $userid); // Unenrol the user.
    }

    /**
     * Create a new group for a booking option if it is not already created
     * Return the id of the group.
     *
     * @return bool|number id of the group
     * @throws \moodle_exception
     */
    public function create_group() {
        global $DB;
        $newgroupdata = self::generate_group_data($this->booking->settings, $this->option);
        $groupids = array_keys(groups_get_all_groups($this->option->courseid));
        // If group name already exists, do not create it a second time, it should be unique.
        if ($groupid = groups_get_group_by_name($newgroupdata->courseid, $newgroupdata->name)) {
            return $groupid;
        }
        if ($groupid = groups_get_group_by_name($newgroupdata->courseid, $newgroupdata->name) &&
                !isset($this->option->id)) {
            $url = new moodle_url('/mod/booking/view.php', array('id' => $this->booking->cm->id));
            throw new \moodle_exception('groupexists', 'booking', $url->out());
        }
        if ($this->option->groupid > 0 && in_array($this->option->groupid, $groupids)) {
            // Group has been created but renamed.
            $newgroupdata->id = $this->option->groupid;
            groups_update_group($newgroupdata);
            return $this->option->groupid;
        } else if (($this->option->groupid > 0 && !in_array($this->option->groupid, $groupids)) || $this->option->groupid == 0) {
            // Group has been deleted and must be created and groupid updated in DB. Or group does not yet exist.
            $data = new stdClass();
            $data->id = $this->option->id;
            $data->groupid = groups_create_group($newgroupdata);
            if ($data->groupid) {
                $DB->update_record('booking_options', $data);
            }
            return $data->groupid;
        }

        return false;
    }

    /**
     * Generate data for creating the group.
     *
     * @param stdClass $bookingsettings
     * @param stdClass $optionsettings
     * @return stdClass
     * @throws \moodle_exception
     */
    public static function generate_group_data(stdClass $bookingsettings, stdClass $optionsettings): stdClass {

        // Replace tags with content. This alters the booking settings so cloning them.
        $newbookingsettings = clone $bookingsettings;
        $newoptionsettings = clone $optionsettings;

        $tags = new booking_tags($newoptionsettings->courseid);
        $newbookingsettings = $tags->booking_replace($newbookingsettings);
        $newoptionsettings = $tags->option_replace($newoptionsettings);

        $newgroupdata = new stdClass();
        $newgroupdata->courseid = $newoptionsettings->courseid;

        $optionname = booking_utils::return_unique_bookingoption_name($newoptionsettings);
        // Before setting name, we have to resolve the id Tag.
        $newgroupdata->name = "{$newbookingsettings->name} - $optionname ({$newoptionsettings->id})";
        $newgroupdata->description = "{$newbookingsettings->name} - $optionname ({$newoptionsettings->id})";
        $newgroupdata->descriptionformat = FORMAT_HTML;

        return $newgroupdata;
    }

    /**
     *
     * Deletes a booking option and the associated user answers
     *
     * @return bool false if not successful, true on success
     * @throws coding_exception
     * @throws dml_exception
     */
    public function delete_booking_option() {
        global $DB, $USER;
        if (!$DB->record_exists("booking_options", array("id" => $this->optionid))) {
            return false;
        }

        $result = true;
        $answers = $this->get_all_users();
        foreach ($answers as $answer) {
            $this->unenrol_user($answer->userid); // Unenrol any users enrolled via this option.
        }
        if (!$DB->delete_records("booking_answers",
                array("bookingid" => $this->booking->id, "optionid" => $this->optionid))) {
            $result = false;
        }

        foreach ($this->get_teachers() as $teacher) {
            unsubscribe_teacher_from_booking_option($teacher->userid, $this->optionid, $this->booking->cm);
        }

        // Delete calendar entry, if any.
        $eventid = $DB->get_field('booking_options', 'calendarid', array('id' => $this->optionid));
        $eventexists = true;
        if ($eventid > 0) {
            // Delete event if exist.
            try {
                $event = \calendar_event::load($eventid);
            } catch (\Exception $e) {
                $eventexists = false;
            }
            if ($eventexists) {
                $event->delete(true);
            }
        }

        // Delete all associated user events for option.

        // Get all the userevents.
        $sql = "SELECT e.* FROM {booking_userevents} ue
              JOIN {event} e
              ON ue.eventid = e.id
              WHERE ue.optionid = :optionid";

        $allevents = $DB->get_records_sql($sql, [
                'optionid' => $this->optionid]);

        // Delete all the events we found associated with a user.
        foreach ($allevents as $item) {
            $DB->delete_records('event', array('id' => $item->id));
        }

        // Delete all the entries in booking_userevents, where we have previously linked users do optiondates and options.
        $DB->delete_records('booking_userevents', array('optionid' => $this->optionid));

        // Delete comments.
        $DB->delete_records("comments",
                array('itemid' => $this->optionid, 'commentarea' => 'booking_option',
                    'contextid' => $this->booking->get_context()->id));

        // Delete calendar events of sessions (option dates).
        if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $this->optionid])) {
            foreach ($optiondates as $record) {
                if (!$DB->delete_records('event', ['id' => $record->eventid])) {
                    $result = false;
                }
            }
        }

        // Delete custom fields belonging to the option.
        if (!$DB->delete_records('booking_customfields', ['optionid' => $this->optionid])) {
            $result = false;
        }

        // Delete sessions (option dates).
        if (!$DB->delete_records('booking_optiondates', ['optionid' => $this->optionid])) {
            $result = false;
        }

        if (!$DB->delete_records("booking_options", array("id" => $this->optionid))) {
            $result = false;
        }

        $event = event\bookingoption_deleted::create(
                array('context' => $this->booking->get_context(), 'objectid' => $this->optionid,
                    'userid' => $USER->id));
        $event->trigger();

        return $result;
    }

    /**
     * Change presence status
     *
     * @param array $allselectedusers
     * @param number $presencestatus
     */
    public function changepresencestatus($allselectedusers, $presencestatus) {
        global $DB;

        foreach ($allselectedusers as $ui) {
            $userdata = $DB->get_record('booking_answers',
                    array('optionid' => $this->optionid, 'userid' => $ui));
            $userdata->status = $presencestatus;

            $DB->update_record('booking_answers', $userdata);
        }
    }

    /**
     * Returns, to which booking option user was sent to.
     *
     * @return array
     */
    public function get_other_options() {
        global $DB;
        return $result = $DB->get_records_sql(
                'SELECT obo.id, obo.text, oba.id, oba.userid
                  FROM {booking_answers} oba
             LEFT JOIN {booking_options} obo ON obo.id = oba.optionid
                 WHERE oba.frombookingid = ?',
                array($this->optionid));
    }

    /**
     * Check if user can enrol
     *
     * @return mixed false on full, or if can enrol or 1 for waiting list.
     */
    private function check_if_limit() {
        global $DB;

        if ($this->option->limitanswers) {
            $maxplacesavailable = $this->option->maxanswers + $this->option->maxoverbooking;
            $bookedusers = $DB->count_records("booking_answers",
                    array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingusers = $DB->count_records("booking_answers",
                    array('optionid' => $this->optionid, 'waitinglist' => 1));
            $alluserscount = $bookedusers + $waitingusers;

            if ($maxplacesavailable > $alluserscount) {
                if ($this->option->maxanswers > $bookedusers) {
                    return 0;
                } else {
                    return 1;
                }
            } else {
                return false;
            }
        } else {
            return 0;
        }
    }

    /**
     * Check if at least one user already completed the option. When yes, deleting users is not possible.
     *
     * @return bool
     * @throws dml_exception
     */
    public function user_completed_option() {
        global $DB;
        return $DB->count_records_select('booking_answers', 'optionid = :optionid AND completed = 1',
            ['optionid' => $this->optionid]);
    }

    /**
     * Transfer the booking option including users to another booking option of the same course.
     *
     * @param $targetcmid
     * @return string error message, empty if no error.
     * @throws coding_exception
     * @throws dml_exception
     * @throws \moodle_exception
     * @throws invalid_parameter_exception
     */
    public function move_option_otherbookinginstance($targetcmid) {
        $error = '';
        list($targetcourse, $targetcm) = get_course_and_cm_from_cmid($targetcmid, 'booking');
        if ($this->booking->course->id !== $targetcourse->id) {
            throw new invalid_parameter_exception("Target booking instance must be in same course");
        }
        $targetbooking = new booking($targetcmid);
        $targetcontext = context_module::instance($targetcmid);
        // Get users of source option.
        // Check for completion errors on unsubscribe.
        if ($this->user_completed_option()) {
            $error = 'You can not move options, when at least one user has the status completed for the option.
            You have to remove the completion status before moving the booking option to another booking instance.';
            return $error;
        }
        // Create target option.
        $newoption = $this->option;
        $newoption->id = -1;
        $newoption->bookingid = $targetbooking->id;
        $newoptionid = booking_update_options($newoption, $targetcontext);
        // Subscribe users.
        $newoption = new booking_option($targetcmid, $newoptionid);
        $users = $this->get_all_users();
        // Unsubscribe users from option.
        $failed = [];
        foreach ($users as $user) {
            $this->user_delete_response($user->userid);
            if (!$newoption->user_submit_response($user)) {
                $failed[$user->userid] = $user->firstname . ' ' . $user->lastname . ' (' . $user->email . ')';
            }
        }
        if (!empty($failed)) {
            $error .= 'The following users could not be registered to the new booking option:';
            $error .= \html_writer::empty_tag('br');
            $error .= \html_writer::alist($failed);
        }
        // Remove source option.
        $this->delete_booking_option();
        return $error;
    }

    /**
     * Retrieves the global booking settings and returns the customfields
     * string[customfieldname][value]
     * Will return the actual text for the custom field string[customfieldname][type]
     * Will return the type: for now only textfield
     *
     * @return array string[customfieldname][value|type]; empty array if no settings set
     */
    public static function get_customfield_settings() {
        $values = array();
        $bkgconfig = get_config('booking');
        $customfieldvals = \get_object_vars($bkgconfig);
        if (!empty($customfieldvals)) {
            foreach (array_keys($customfieldvals) as $customfieldname) {
                $iscustomfield = \strpos($customfieldname, 'customfield');
                $istype = \strpos($customfieldname, 'type');
                $isoptions = \strpos($customfieldname, 'options');
                if ($iscustomfield !== false && $istype === false && $isoptions === false) {
                    $type = $customfieldname . "type";
                    $options = $customfieldname . "options";
                    $values[$customfieldname]['value'] = $bkgconfig->$customfieldname;
                    $values[$customfieldname]['type'] = $bkgconfig->$type;
                    $values[$customfieldname]['options'] = (isset($bkgconfig->$options) ? $bkgconfig->$options : '');
                }
            }
        }
        return $values;
    }

    /**
     * Confirm activity for selected user.
     *
     * @param userid
     */
    public function confirmactivity($userid = null) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $course = $DB->get_record('course', array('id' => $this->booking->cm->course));
        $completion = new completion_info($course);
        $cm = get_coursemodule_from_id('booking', $this->booking->cm->id, 0, false, MUST_EXIST);

        $suser = null;

        foreach ($this->users as $key => $value) {
            if ($value->userid == $userid) {
                $suser = $key;
                break;
            }
        }

        if (is_null($suser)) {
            return;
        }

        if ($this->users[$suser]->completed == 0) {
            $userdata = $DB->get_record('booking_answers',
            array('optionid' => $this->optionid, 'userid' => $userid));
            $userdata->completed = '1';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);

            $countcompleted = $DB->count_records('booking_answers',
                array('bookingid' => $this->booking->id, 'userid' => $userid, 'completed' => '1'));

            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
                $this->booking->settings->enablecompletion <= $countcompleted) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        }
    }

    /**
     * Copy this booking option to template.
     */
    public function copytotemplate() {
        global $DB;

        $option = $DB->get_record('booking_options', array('id' => $this->optionid));

        unset($option->id);
        $option->bookingid = 0;

        $DB->insert_record("booking_options", $option);
    }

    // Print custom report.
    public function printcustomreport() {
        global $CFG;

        include_once($CFG->dirroot . '/mod/booking/TinyButStrong/tbs_class.php');
        include_once($CFG->dirroot . '/mod/booking/OpenTBS/tbs_plugin_opentbs.php');

        $tbs = new \clsTinyButStrong;
        $tbs->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
        $tbs->NoErr = true;

        list($course, $cm) = get_course_and_cm_from_cmid($this->booking->cm->id);
        $context = \context_module::instance($this->booking->cm->id);
        $coursecontext = \context_course::instance($course->id);

        $booking = array(
            'name' => $this->booking->settings->name,
            'eventtype' => $this->booking->settings->eventtype,
            'duration' => $this->booking->settings->duration,
            'organizatorname' => $this->booking->settings->organizatorname,
            'pollurl' => $this->booking->settings->pollurl,
            'pollurlteachers' => $this->booking->settings->pollurlteachers
        );
        $bu = new booking_utils();
        $option = array(
            'name' => $this->option->text,
            'location' => $this->option->location,
            'institution' => $this->option->institution,
            'address' => $this->option->address,
            'maxanswers' => $this->option->maxanswers,
            'maxoverbooking' => $this->option->maxoverbooking,
            'bookingclosingtime' => ($this->option->bookingclosingtime == 0 ? get_string('nodateset', 'booking') : userdate(
                $this->option->bookingclosingtime, get_string('strftimedatetime'))),
            'duration' => $bu->get_pretty_duration($this->option->duration),
            'coursestarttime' => ($this->option->coursestarttime == 0 ? get_string('nodateset', 'booking') : userdate(
                $this->option->coursestarttime, get_string('strftimedatetime'))),
            'courseendtime' => ($this->option->courseendtime == 0 ? get_string('nodateset', 'booking') : userdate(
                $this->option->courseendtime, get_string('strftimedatetime'))),
            'pollurl' => $this->option->pollurl,
            'pollurlteachers' => $this->option->pollurlteachers,
            'shorturl' => $this->option->shorturl
        );

        $allusers = $this->get_all_users();
        $allteachers = $this->get_teachers();

        $users = array();
        foreach ($allusers as $key => $value) {
            $users[] = array(
                'id' => $value->userid,
                'firstname' => $value->firstname,
                'lastname' => $value->lastname,
                'email' => $value->email,
                'institution' => $value->institution
            );
        }

        $teachers = array();
        foreach ($allteachers as $key => $value) {
            $teachers[] = array(
                'id' => $value->userid,
                'firstname' => $value->firstname,
                'lastname' => $value->lastname,
                'email' => $value->email,
                'institution' => $value->institution
            );
        }

        $fs = get_file_storage();

        $files = $fs->get_area_files($coursecontext->id, 'mod_booking', 'templatefile',
            $this->booking->settings->customtemplateid, 'sortorder,filepath,filename', false);

        if ($files) {
            $file = reset($files);

            // Get file.
            $file = $fs->get_file($coursecontext->id, 'mod_booking', 'templatefile',
            $this->booking->settings->customtemplateid, $file->get_filepath(), $file->get_filename());
        }

        $ext = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        $filename = uniqid(rand(), false);

        $tempfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename . ".{$ext}";

        $handle = fopen($tempfile, "w");
        fwrite($handle, $file->get_content());
        fclose($handle);

        $tbs->LoadTemplate($tempfile, OPENTBS_ALREADY_UTF8);

        $tbs->PlugIn(OPENTBS_SELECT_MAIN);
        $tbs->MergeField('booking', $booking);
        $tbs->MergeField('option', $option);
        $tbs->MergeBlock('users', $users);
        $tbs->MergeBlock('teachers', $teachers);

        $tbs->LoadTemplate('#styles.xml');
        $tbs->MergeField('booking', $booking);
        $tbs->MergeField('option', $option);
        $tbs->MergeBlock('users', $users);
        $tbs->MergeBlock('teachers', $teachers);

        $tbs->Show(OPENTBS_STRING);
        $tempfilefull = $tbs->Source;

        $fullfile = array(
            'contextid' => $coursecontext->id, // ID of context.
            'component' => 'mod_booking',     // Usually = table name.
            'filearea' => 'templatefile',     // Usually = table name.
            'itemid' => 0,               // Usually = ID of row in table.
            'filepath' => '/',           // Any path beginning and ending in '/'.
            'filename' => "{$filename}.{$ext}"); // Any filename.

        // Create file containing text 'hello world'.
        $newfile = $fs->create_file_from_string($fullfile, $tbs->Source);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $converter = new \core_files\converter();
        $conversion = $converter->start_conversion($newfile, 'pdf', true);

        if ($conversion->get_destfile() !== false) {
            header('Content-Disposition: attachment; filename="' . $conversion->get_destfile()->get_filename() . '.pdf"');
            echo $conversion->get_destfile()->get_content();
            $conversion->get_destfile()->delete();
        } else {
            header('Content-Disposition: attachment; filename="' . $newfile->get_filename() . '"');
            echo $newfile->get_content();
        }

        unlink($tempfile);
        $newfile->delete();

        exit();
    }

    /**
     * Central function to return a list of booking options with all possible filters applied.
     * Default is a list of all booking options from the whole site.
     *
     * @param integer $bookingid // Should be set.
     * @param array $filters
     * @param string $fields
     * @param [type] $from
     * @return void
     */
    public static function search_all_options_sql($bookingid = 0,
                                    $filters = array(),
                                    $fields = '*',
                                    $from = '',
                                    $where = '',
                                    $params = array(),
                                    $order = 'ORDER BY bo.id ASC'):array {
        $from = $from ?? '{booking_options} bo
                        JOIN {customfield_data} cfd
                        ON bo.id=cfd.instanceid
                        JOIN {customfield_field} cff
                        ON cfd.fieldid=cff.id';

        // If there is no booking id, we look for all booking options.
        if (isset($bookingid)) {
            $where = $where ?? 'bookingid=:bookingid';
            $params['bookingid'] = $bookingid;
        }
        return [$fields, $from, $where, $params, $order];
    }

    public function apply_filters() {

    }

}
