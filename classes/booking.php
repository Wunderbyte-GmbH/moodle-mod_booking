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

use html_writer;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * Standard base class for mod_booking
 * Module was originally programmed for 1.9 but further adjustments should be made with new
 * Moodle 2.X coding style using this base class.
 *
 * @package mod_booking
 * @copyright 2013 David Bogner {@link http://www.edulabs.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking {

    /** @var number id booking id  */
    public $id = 0;

    /**
     *
     * @var \context the context of the course module for this booking instance (or just the course if we are
     */
    protected $context = null;

    /** @var stdClass the course this booking instance belongs to */
    public $course = null;

    /** @var stdClass the course module for this assign instance */
    public $cm = null;

    /** @var array of user objects who have capability to book. object contains only id */
    public $canbookusers = array();

    /** @var array users who are members of the current users group */
    public $groupmembers = array();

    /** @var booking booking object from booking instance settings */
    public $settings;

    /**
     * @var array $alloptions option objects indexed by optionid
     */
    protected $alloptions = array();

    /**
     * @var array of ids
     */
    protected $optionids = array();

    /**
     *
     * @var number of bookings a user has made
     */
    protected $userbookings = null;

    /**
     * Constructor for the booking class
     *
     * @param $cmid
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function __construct($cmid) {
        global $DB;
        $this->cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $this->course = $DB->get_record('course', array('id' => $this->cm->course),
                'id, fullname, shortname, groupmode, groupmodeforce, visible', MUST_EXIST);
        $this->id = $this->cm->instance;
        $this->context = \context_module::instance($cmid);
        $this->settings = $DB->get_record("booking", array("id" => $this->id));
        // If the course has groups and I do not have the capability to see all groups, show only
        // users of my groups.
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $this->context)) {
            list($sql, $params) = $this::booking_get_groupmembers_sql($this->course->id);
            $this->groupmembers = $DB->execute($sql, $params);
        }
    }

    /**
     *
     * @return \context
     */
    public function get_context() {
        return $this->context;
    }

    public function apply_tags() {
        $tags = new booking_tags($this->cm->course);
        $this->settings = $tags->booking_replace($this->settings);
    }

    /**
     *
     */
    public function get_url_params() {
        $bu = new booking_utils();
        $params = $bu->generate_params($this->settings);
        $this->settings->pollurl = $bu->get_body($this->settings, 'pollurl', $params);
        $this->settings->pollurlteachers = $bu->get_body($this->settings, 'pollurlteachers', $params);
    }

    /**
     * get all the user ids who are allowed to book capability mod/booking:choose available in
     * $this->canbookusers
     */
    public function get_canbook_userids() {
        // TODO check if course has guest access if not get all enrolled users and check with
        // has_capability if user has right to book
        // $this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose',
        // 'u.id', 'u.lastname ASC, u.firstname ASC', '', '', '',
        // '', true, true);
        $this->canbookusers = get_enrolled_users($this->context, 'mod/booking:choose', null, 'u.id');
    }

    /**
     * get sql for all group member ids of $USER (of all groups $USER belongs to a course)
     *
     * @param int $courseid
     * @return array: all members of all groups $USER belongs to
     */
    public static function booking_get_groupmembers_sql($courseid) {
        global $DB, $USER;
        $mygroups = groups_get_all_groups($courseid, $USER->id);
        $mygroupids = array_keys($mygroups);
        list($insql, $params) = $DB->get_in_or_equal($mygroupids, SQL_PARAMS_NAMED, 'book_', true, -1);
        $groupsql = "SELECT u.id
                       FROM {user} u, {groups_members} gm
                      WHERE u.deleted = 0
                        AND u.id = gm.userid AND gm.groupid $insql
                   GROUP BY u.id";
        return array($groupsql, $params);
    }

    private function searchparameters($searchtext = '') {
        global $DB;
        $search = '';
        $params = array();

        if (!empty($searchtext)) {
            $searchtext = $DB->sql_like_escape($searchtext);
            $search = " AND ({$DB->sql_like('bo.text', ':text', false)} OR {$DB->sql_like('bo.location', ':location', false)}" .
            " OR {$DB->sql_like('bo.institution', ':institution', false)})";
            $params['text'] = "%{$searchtext}%";
            $params['location'] = "%{$searchtext}%";
            $params['institution'] = "%{$searchtext}%";
        }

        return array('params' => $params, 'query' => $search);
    }

    /**
     * Get all booking options as an array of objects indexed by optionid
     *
     * @return array of booking options records
     */
    public function get_all_options($limitfrom = 0, $limitnum = 0, $searchtext = '', $fields = "bo.id") {
        global $DB;

        $limit = '';
        $rsearch = self::searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql("SELECT {$fields} FROM {booking_options} bo WHERE bo.bookingid = :bookingid {$search} {$limit}", $params);
    }

    public function get_all_options_count($searchtext = '') {
        global $DB;

        $search = '';
        $params = array();

        $rsearch = self::searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id), $rsearch['params']);

        return $DB->count_records_sql("SELECT COUNT(*) FROM {booking_options} bo WHERE bo.bookingid = :bookingid {$search}", $params);
    }

    /**
     * Get all booking option ids as an array of numbers.
     *
     * @param number $bookingid
     * @return array of ids
     */
    static public function get_all_optionids($bookingid) {
        global $DB;
        return $DB->get_fieldset_select('booking_options', 'id', "bookingid = {$bookingid}");
    }

    /**
     * Get active booking option ids as an array of numbers.
     *
     * @param number $bookingid
     * @return array of ids
     */
    public function get_active_optionids($bookingid, $limitfrom = 0, $limitnum = 0, $searchtext = '') {
        global $DB;

        $limit = '';
        $rsearch = self::searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'time' => time()), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql("SELECT bo.id FROM {booking_options} bo WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
        " {$search} {$limit}", $params);
    }

    public function get_active_optionids_count($bookingid, $searchtext = '') {
        global $DB;

        $search = '';
        $params = array();

        $rsearch = self::searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'time' => time()), $rsearch['params']);

        return $DB->count_records_sql("SELECT COUNT(*) FROM {booking_options} bo WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
        " {$search}", $params);
    }

    /**
     * Get all booking option ids as an array of numbers - only where is teacher.
     *
     * @param integer bookingid
     * @return array of ids
     */
    public static function get_all_optionids_of_teacher($bookingid) {
        global $DB, $USER;

        return $DB->get_fieldset_select('booking_teachers', 'optionid',
            "userid = {$USER->id} AND bookingid = $bookingid");
    }

    /**
     * Get all user booking option ids as an array of numbers.
     *
     * @return array of ids
     */
    public function get_my_bookingids($limitfrom = 0, $limitnum = 0, $searchtext= '') {
        global $DB, $USER;

        $limit = '';
        $rsearch = self::searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'userid' => $USER->id), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql("SELECT ba.optionid id FROM {booking_options} bo LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id WHERE" .
                " ba.bookingid = :bookingid AND ba.userid = :userid {$search} {$limit}", $params);
    }

    public function get_my_bookingids_count($searchstring = '') {
        global $DB, $USER;

        $search = '';
        $params = array();

        $rsearch = self::searchparameters($searchstring);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'userid' => $USER->id), $rsearch['params']);

        return $DB->count_records_sql("SELECT COUNT(*) FROM {booking_options} bo LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id" .
                " WHERE ba.bookingid = :bookingid AND ba.userid = :userid {$search}", $params);
    }

    /**
     * Display a message about the maximum nubmer of bookings this user is allowed to make.
     *
     * @param stdClass $user
     * @return string
     */
    public function show_maxperuser($user) {
        global $USER;

        $warning = '';

        if (!empty($this->settings->banusernames)) {
            $disabledusernames = explode(',', $this->settings->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $warning = html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
                }
            }
        }

        if (!$this->settings->maxperuser) {
            return $warning; // No per-user limits.
        }

        $outdata = new stdClass();
        $outdata->limit = $this->settings->maxperuser;
        $outdata->count = $this->get_user_booking_count($user);
        $outdata->eventtype = $this->settings->eventtype;

        $warning .= html_writer::tag('div', get_string('maxperuserwarning', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));
        return $warning;
    }

    /**
     * Determins the number of bookings that a single user has already made in all booking options
     *
     * @param stdClass $user
     * @return number of bookings made by user
     */
    public function get_user_booking_count($user) {
        global $DB;
        if (!empty($this->userbookings)) {
            return $this->userbookings;
        }

        $activebookingcount = $DB->count_records_sql("SELECT COUNT(*)
            FROM {booking_answers} ba
            LEFT JOIN {booking_options} bo ON bo.id = ba.optionid
            WHERE ba.bookingid = ?
            AND ba.userid = ?
            AND (bo.courseendtime = 0 OR bo.courseendtime > ?)", array($this->id, $user->id, time()));

        return $activebookingcount;
    }

    /**
     * Get array of option names, to which user is booked.
     *
     * @param stdClass $user
     * @return array of option names
     */
    public function get_user_booking($user) {
        global $DB;

        $sql = 'SELECT bo.id, bo.text
                FROM {booking_answers} ba
                LEFT JOIN {booking_options} bo
                ON bo.id = ba.optionid
                WHERE bo.bookingid = ?
                AND ba.userid = ?';

        return $DB->get_records_sql($sql, array($this->settings->id, $user->id));
    }

    /**
     * Get extra fields to display in report.php and view.php
     *
     * @return string[][]|array[]
     */
    public function get_fields() {
        global $DB;
        $reportfields = explode(',', $this->settings->reportfields);
        list($addquoted, $addquotedparams) = $DB->get_in_or_equal($reportfields);

        $userprofilefields = $DB->get_records_select('user_info_field',
                'id > 0 AND shortname ' . $addquoted, $addquotedparams, 'id', 'id, shortname, name');

        $columns = array();
        $headers = array();

        foreach ($reportfields as $value) {
            switch ($value) {
                case 'optionid':
                    $columns[] = 'optionid';
                    $headers[] = get_string("optionid", "booking");
                    break;
                case 'booking':
                    $columns[] = 'booking';
                    $headers[] = get_string("bookingoptionname", "booking");
                    break;
                case 'institution':
                    if (has_capability('moodle/site:viewuseridentity', $this->context)) {
                        $columns[] = 'institution';
                        $headers[] = get_string("institution", "booking");
                    }
                    break;
                case 'location':
                    $columns[] = 'location';
                    $headers[] = get_string("location", "booking");
                    break;
                case 'coursestarttime':
                    $columns[] = 'coursestarttime';
                    $headers[] = get_string("coursestarttime", "booking");
                    break;
                case 'courseendtime':
                    $columns[] = 'courseendtime';
                    $headers[] = get_string("courseendtime", "booking");
                    break;
                case 'numrec':
                    if ($this->settings->numgenerator) {
                        $columns[] = 'numrec';
                        $headers[] = get_string("numrec", "booking");
                    }
                    break;
                case 'userid':
                    $columns[] = 'userid';
                    $headers[] = get_string("userid", "booking");
                    break;
                case 'username':
                    $columns[] = 'username';
                    $headers[] = get_string("username");
                    break;
                case 'firstname':
                    $columns[] = 'firstname';
                    $headers[] = get_string("firstname");
                    break;
                case 'lastname':
                    $columns[] = 'lastname';
                    $headers[] = get_string("lastname");
                    break;
                case 'email':
                    $columns[] = 'email';
                    $headers[] = get_string("email");
                    break;
                case 'city':
                    $columns[] = 'city';
                    $headers[] = get_string("city");
                    break;
                case 'completed':
                    $columns[] = 'completed';
                    $headers[] = get_string("completed", "booking");
                    break;
                case 'waitinglist':
                    $columns[] = 'waitinglist';
                    $headers[] = get_string("waitinglist", "booking");
                    break;
                case 'status':
                    if ($this->settings->enablepresence) {
                        $columns[] = 'status';
                        $headers[] = get_string('presence', 'mod_booking');
                    }
                    break;
                case 'groups':
                    $columns[] = 'groups';
                    $headers[] = get_string("group");
                    break;
                case 'notes':
                    $columns[] = 'notes';
                    $headers[] = get_string('notes', 'mod_booking');
                    break;
                case 'idnumber':
                    if ($DB->count_records_select('user', ' idnumber <> \'\'') > 0 &&
                            has_capability('moodle/site:viewuseridentity', $this->context)) {
                        $columns[] = 'idnumber';
                        $headers[] = get_string("idnumber");
                    }
                    break;
            }
        }
        return array($columns, $headers, $userprofilefields);
    }
}