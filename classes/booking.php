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
 * Standard base class for mod_booking
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use block_xp\local\sql\limit;
use course_modinfo;
use html_writer;
use local_entities\local\entities\entitydate;
use moodle_exception;
use stdClass;
use moodle_url;

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

    /** @var int id booking id  */
    public $id = 0;

    /** @var int $cmid course module id  */
    public $cmid = 0;

    /** @var \context the context of the course module for this booking instance (or just the course) */
    public $context = null;

    /** @var stdClass the course this booking instance belongs to */
    public $course = null;

    /** @var stdClass the course module for this assign instance */
    public $cm = null;

    /** @var array of user objects who have capability to book. object contains only id */
    public $canbookusers = array();

    /** @var array users who are members of the current users group */
    public $groupmembers = array();

    /** @var stdClass settings of the booking instance */
    public $settings = null;

    /** @var array $alloptions option objects indexed by optionid */
    protected $alloptions = array();

    /** @var array of ids */
    protected $optionids = array();

    /** @var int number of bookings a user has made */
    protected $userbookings = null;

    /**
     * Constructor for the booking class
     *
     * @param int $cmid
     * @param course_modinfo|null $cm
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function __construct(int $cmid, course_modinfo $cm = null) {
        global $DB;

        $this->cmid = $cmid;
        if (!$cm || ($cmid != $cm->id)) {
            $this->cm = get_coursemodule_from_id('booking', $cmid);
        } else {
            $this->cm = $cm;
        }

        if (!$this->cm) {
            throw new moodle_exception('instancedoesnotexist');
        }

        // In the constructur, we call the booking_settings, where we get the values from db or cache.
        $bosettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        $this->settings = $bosettings->return_settings_as_stdclass();
        $this->id = $this->settings->id;

        $this->course = get_course($this->cm->course);
        $this->context = \context_module::instance($cmid);

        // If the course has groups and I do not have the capability to see all groups, show only
        // users of my groups.
        // TODO: Move this potentially expensive function to settings and, with its own cache.
        // It needs to use the live information from cm & context and be invalidated by group change events in this course.
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

        $this->canbookusers = get_enrolled_users($this->context, 'mod/booking:choose', null, 'u.id');

        // TODO check if course has guest access if not get all enrolled users and check with...
        // ...has_capability if user has right to book.
        // CODEBEGIN $this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose', CODEEND.
        // CODEBEGIN 'u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', CODEEND.
        // CODEBEGIN '', true, true); CODEEND.
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
        $groupsql = "SELECT DISTINCT u.id
                       FROM {user} u, {groups_members} gm
                      WHERE u.deleted = 0
                        AND u.id = gm.userid AND gm.groupid $insql";
        return array($groupsql, $params);
    }

    /**
     * Function to $params and $sqlquery for searching booking option.
     *
     * @param string $searchtext
     * @return array
     */
    private function searchparameters($searchtext = '') {
        global $DB;
        $search = '';
        $params = array();

        if (!empty($searchtext)) {
            $searchtext = $DB->sql_like_escape($searchtext);
            $search = " AND ({$DB->sql_like('LOWER(bo.text)', 'LOWER(:text)', false)}" .
            " OR {$DB->sql_like('LOWER(bo.location)', 'LOWER(:location)', false)}" .
            " OR {$DB->sql_like('LOWER(bo.institution)', 'LOWER(:institution)', false)})";
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
    public function get_all_options($limitfrom = 0, $limitnum = 0, $searchtext = '', $fields = "*") {

        global $DB;

        list($fields, $from, $where, $params, $filter) = $this->get_all_options_sql($limitfrom, $limitnum,
            $searchtext, $fields, $this->context);

        return $DB->get_records_sql(
            "SELECT $fields FROM $from WHERE $where $filter", $params);
    }

    public function get_all_options_count($searchtext = '') {
        global $DB;

        $search = '';
        $params = array();

        $rsearch = $this->searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id), $rsearch['params']);

        return $DB->count_records_sql(
            "SELECT COUNT(*) FROM {booking_options} bo WHERE bo.bookingid = :bookingid {$search}", $params);
    }

    /**
     * Get all booking option ids as an array of numbers.
     *
     * @param int $bookingid
     * @return array of ids
     */
    public static function get_all_optionids($bookingid) {
        global $DB;
        return $DB->get_fieldset_select('booking_options', 'id', "bookingid = {$bookingid}");
    }

    /**
     * Get active booking option ids as an array of numbers.
     *
     * @param int $bookingid
     * @return array of ids
     */
    public function get_active_optionids($bookingid, $limitfrom = 0, $limitnum = 0, $searchtext = '') {
        global $DB;

        $limit = '';
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'time' => time()), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql(
            "SELECT bo.id FROM {booking_options} bo " .
            "WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
            " {$search} {$limit}", $params);
    }

    public function get_active_optionids_count($bookingid, $searchtext = '') {
        global $DB;

        $search = '';
        $params = array();

        $rsearch = $this->searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'time' => time()), $rsearch['params']);

        return $DB->count_records_sql(
            "SELECT COUNT(*) FROM {booking_options} bo " .
            "WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
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
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'userid' => $USER->id), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql(
            "SELECT ba.optionid id FROM {booking_options} bo LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id WHERE" .
                " ba.bookingid = :bookingid AND ba.userid = :userid {$search} {$limit}", $params);
    }

    public function get_my_bookingids_count($searchstring = '') {
        global $DB, $USER;

        $search = '';
        $params = array();

        $rsearch = $this->searchparameters($searchstring);

        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id, 'userid' => $USER->id), $rsearch['params']);

        return $DB->count_records_sql(
            "SELECT COUNT(*) FROM {booking_options} bo LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id" .
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

        $warning .= html_writer::tag('div', get_string('maxperuserwarning', 'mod_booking', $outdata),
            array ('class' => 'alert alert-warning'));
        return $warning;
    }

    /**
     * Determins the number of bookings that a single user has already made in all booking options
     *
     * @param stdClass $user
     * @return int of bookings made by user
     */
    public function get_user_booking_count($user):int {
        global $DB;
        if (!empty($this->userbookings)) {
            return $this->userbookings;
        }

        $activebookingcount = $DB->count_records_sql("SELECT COUNT(*)
            FROM {booking_answers} ba
            LEFT JOIN {booking_options} bo ON bo.id = ba.optionid
            WHERE ba.bookingid = ?
            AND ba.userid = ?
            AND ba.waitinglist <= ?
            AND (bo.courseendtime = 0 OR bo.courseendtime > ?)", array($this->id, $user->id, STATUSPARAM_WAITINGLIST, time()));

        return (int)$activebookingcount;
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
                case 'department':
                    $columns[] = 'department';
                    $headers[] = get_string("department");
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

    /**
     * Check, if auto create of option is enabled and do the logic.
     *
     * @return void
     */
    public function checkautocreate() {
        global $USER, $DB;

        if ($this->settings->autcractive && !empty($this->settings->autcrprofile)
            && !empty($this->settings->autcrvalue) && !empty($this->settings->autcrtemplate)) {
            $customfields = profile_user_record($USER->id);

            if (isset($customfields->{$this->settings->autcrprofile}) &&
                $customfields->{$this->settings->autcrprofile} == $this->settings->autcrvalue) {

                $nrec = $DB->count_records('booking_teachers', array('userid' => $USER->id, 'bookingid' => $this->id));

                if ($nrec === 0) {
                    $bookingoption = $DB->get_record('booking_options', array('id' => $this->settings->autcrtemplate));
                    $bookingoption->text = "{$USER->institution} - " . fullname($USER);
                    $bookingoption->bookingid = $this->id;
                    $bookingoption->description = (is_null($bookingoption->description) ? '' : $bookingoption->description);
                    unset($bookingoption->id);

                    $nrecid = $DB->insert_record('booking_options', $bookingoption, true, false);

                    $newteacher = new \stdClass;
                    $newteacher->bookingid = $this->id;
                    $newteacher->userid = $USER->id;
                    $newteacher->optionid = $nrecid;
                    $newteacher->completed = 0;

                    $DB->insert_record('booking_teachers', $newteacher, false, false);

                    // When inserting a new teacher, we also need to insert the teacher for each optiondate.
                    dates_handler::subscribe_teacher_to_all_optiondates($newteacher->optionid, $newteacher->userid);

                    $params = array(
                        'id' => $this->cm->id,
                        'optionid' => $nrecid
                    );
                    $url = new moodle_url('/mod/booking/report.php', $params);

                    redirect($url);
                }
            }
        }
    }

    // New functions beneath.

    /**
     * Genereate SQL and params array to fetch all options.
     * No prefixes for the columsn we retrieve, so *, id, etc.
     * If we don't pass on the context object, invisible options are excluded.
     *
     * @param integer $limitfrom
     * @param integer $limitnum
     * @param string $searchtext
     * @param string $fields
     * @param object $context
     * @return array
     */
    public function get_all_options_sql($limitfrom = 0, $limitnum = 0, $searchtext = '', $fields = null, $context = null) {
        global $DB;

        return self::get_options_filter_sql($limitfrom, $limitnum, $searchtext, $fields, $context, [],
            ['bookingid' => (int)$this->id]);
    }


    /**
     * This function is our central way of getting the whole sql calling the standard table as well as the filtered one.
     * We have the simple searchtext also, which will be used from now on as fulltext search.
     * The filterarray should be used for temporary filtering all records, opposed to where. Its a subset of all records.
     * Where means that it restricts the number of total records.
     * This distinction is important for the automatic filter generation of Wunderbyte Table.
     *
     * @param integer $limitfrom
     * @param integer $limitnum
     * @param string $searchtext
     * @param string $fields
     * @param [type] $context
     * @param array $filterarray
     * @param array $wherearray
     * @return void
     */
    public static function get_options_filter_sql($limitfrom = 0,
                                                $limitnum = 0,
                                                $searchtext = '',
                                                $fields = "*",
                                                $context = null,
                                                $filterarray = [],
                                                $wherearray = [],
                                                $userid = null,
                                                $bookingparam = STATUSPARAM_BOOKED) {

        global $DB;

        $groupby = " bo.id ";

        if (empty($fields)) {
            $fields = " DISTINCT s1.*";
        }

        $where = '';

        $filter = '';

        $params = [];

        $outerfrom = "(
                        SELECT DISTINCT bo.* ";

        $innerfrom = "FROM {booking_options} bo";

        // If the user does not have the capability to see invisible options...
        if (!$context || !has_capability('mod/booking:canseeinvisibleoptions', $context)) {
            // ... then only show visible options.
            $where = "invisible = 0 ";
        } else {
            // The "Where"-clause is always added so we have to have something here for the sql to work.
            $where = "1=1 ";
        }

        if ($userid !== null) {
            $innerfrom .= " JOIN {booking_answers} ba
                          ON ba.optionid=bo.id ";

            $outerfrom .= ", ba.waitinglist, ba.userid as bookeduserid ";
            $where .= " AND waitinglist=:bookingparam
                        AND bookeduserid=:bookeduserid ";
            $groupby .= " , ba.waitinglist, ba.userid ";

            $params['bookeduserid'] = $userid;
            $params['bookingparam'] = $bookingparam;
        }

        // Instead of "where" we return "filter". This is to support the filter functionality of wunderbyte table.
        list($select1, $from1, $filter1, $params1) = booking_option_settings::return_sql_for_customfield();
        list($select2, $from2, $filter2, $params2) = booking_option_settings::return_sql_for_teachers();
        list($select3, $from3, $filter3, $params3) = booking_option_settings::return_sql_for_imagefiles();

        // The $outerfrom takes all the select from the supplementary selects.
        $outerfrom .= !empty($select1) ? ", $select1 " : '';
        $outerfrom .= !empty($select2) ? ", $select2 " : '';
        $outerfrom .= !empty($select3) ? ", $select3 " : '';

        // The innerfrom takes all the froms from the supplementary froms.
        $innerfrom .= " $from1 ";
        $innerfrom .= " $from2 ";
        $innerfrom .= " $from3 ";

        $pattern = '/as.*?,/';
        $addgroupby = preg_replace($pattern, ',', $select1 . ",");

        $groupby .= !empty($addgroupby) ? ' , ' . $addgroupby : '';

        $addgroupby = preg_replace($pattern, ',', $select3 . ",");
        $groupby .= !empty($addgroupby) ? ' , ' . $addgroupby : '';

        $groupbyarray = (array)explode(',', $groupby);

        foreach ($groupbyarray as $key => $value) {
            if (empty(trim($value))) {
                unset($groupbyarray[$key]);
            }
        }

        $groupby = implode(" , ", $groupbyarray);

        // Now we merge all the params arrays.
        $params = array_merge($params, $params1, $params2, $params3);

        // We build everything together.
        $from = $outerfrom;
        $from .= $innerfrom;

        // Finally, we add the outer group by.
        $groupby = "GROUP BY " . $groupby . "
                    ) s1";

        $from .= $groupby;

        // Add the where at the right place.
        $filter .= " $filter1 ";
        $filter .= " $filter2 ";
        $filter .= " $filter3 ";

        $counter = 1;
        foreach ($filterarray as $key => $value) {

            // Be sure to have a lower key string.
            $paramsvaluekey = "param";
            while (isset($params[$paramsvaluekey])) {
                $paramsvaluekey .= $counter;
                $counter++;
            }

            if (gettype($value) == 'integer') {
                $filter .= " AND   $key = $value";
            } else {
                $filter .= " AND " . $DB->sql_like("$key", ":$paramsvaluekey", false);
                $params[$paramsvaluekey] = $value;
            }
        }

        foreach ($wherearray as $key => $value) {

            // Be sure to have a lower key string.
            $paramsvaluekey = "param";
            while (isset($params[$paramsvaluekey])) {
                $paramsvaluekey .= $counter;
                $counter++;
            }

            if (gettype($value) == 'integer') {
                $where .= " AND   $key = $value";
            } else {
                $where .= " AND " . $DB->sql_like("$key", ":$paramsvaluekey", false);
                $params[$paramsvaluekey] = $value;
            }
        }

        return [$fields, $from, $where, $params, $filter];

    }

    /**
     * Function to return all bookings for teacher.
     *
     * @param integer $limitfrom
     * @param integer $limitnum
     * @param [type] $teacherid
     * @return void
     */
    public function get_all_options_of_teacher_sql($teacherid) {

        return self::get_options_filter_sql(0, 0, '', '*', null, [], ['teacherobjects' => '%"id":' . $teacherid . ',%']);
    }

    /**
     * Genereate SQL and params array to fetch my options.
     *
     * @param integer $limitfrom
     * @param integer $limitnum
     * @param string $searchtext
     * @param string $fields
     * @return void
     */
    public function get_my_options_sql($limitfrom = 0, $limitnum = 0, $searchtext = '',
        $fields = "bo.*") {

        global $DB, $USER;

        $fields = "DISTINCT " . $fields;

        $limit = '';
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(array('bookingid' => $this->id,
                                    'userid' => $USER->id,
                                    'booked' => STATUSPARAM_BOOKED), $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom} OFFSET {$limitnum}";
        }

        $from = "{booking_options} bo
                JOIN {booking_answers} ba
                ON ba.optionid=bo.id";
        $where = "bo.bookingid = :bookingid
                  AND ba.userid = :userid
                  AND ba.waitinglist = :booked {$search}";
        if (strlen($searchtext) !== 0) {
            $from .= "
                JOIN {customfield_data} cfd
                ON bo.id=cfd.instanceid
                JOIN {customfield_field} cff
                ON cfd.fieldid=cff.id
                ";
            // Strip column close.
            $where = substr($where, 0, -1);
            // Add another tag.
            $where .= " OR {$DB->sql_like('cfd.value', ':cfsearchtext', false)}) ";
            // In a future iteration, we can add the specification in which customfield we want to search.
            // For From JOIN {customfield_field} cff.
            // ON cfd.fieldid=cff.id .
            // And for Where.
            // AND cff.name like 'fieldname'.
            $params['cfsearchtext'] = $searchtext;
        }

        return [$fields, $from, $where, $params];
    }

    /**
     * Helper function to encode a moodle_url with base64.
     * This can be used in combination with bookingredirect.php.
     * @param $moodleurl
     */
    public static function encode_moodle_url($moodleurl) {

        global $CFG;

        $encodedurl = base64_encode($moodleurl->out(false));
        $encodedmoodleurl = new \moodle_url($CFG->wwwroot . '/mod/booking/bookingredirect.php', array(
            'encodedurl' => $encodedurl
        ));

        $encodedlink = $encodedmoodleurl->out(false);

        return $encodedlink;
    }

    /**
     * This function is called by the entities callback service_provider class.
     * It's used to return all the booking dates of the given IDs in a special format.
     *
     * @param array $areas
     * @return array
     */
    public static function return_array_of_dates(array $areas): array {

        // TODO: Now that the SQL has been changed, we need to fix this function!

        global $DB;

        // Get the SQL to retrieve all the right IDs.
        $sql = self::return_sql_for_options_dates($areas);
        $params = [];

        if (!empty($areas['option'])) {
            list($inoptionsql, $optionparams) = $DB->get_in_or_equal($areas['option'], SQL_PARAMS_NAMED);
            // We only select options with an odcount of NULL meaning there are no optiondates.
            // If there are optiondates, we are only interested in them and ignore the option itself.
            $sql .= " WHERE (s1.area = 'option' AND s2.odcount IS NULL
                    AND s1.coursestarttime <> 0 AND s1.courseendtime <> 0
                    AND s1.instanceid $inoptionsql)";
            $params = array_merge($params, $optionparams);
        }

        if (!empty($areas['optiondate'])) {

            // Do we need WHERE or OR?
            $sql .= empty($inoptionsql) ? " WHERE " : " OR ";

            list($inoptiondatesql, $optiondateparams) = $DB->get_in_or_equal($areas['optiondate'], SQL_PARAMS_NAMED);

            $sql .= "(s1.area = 'optiondate' AND s1.instanceid $inoptiondatesql)";
            $params = array_merge($params, $optiondateparams);
        }

        // If neither one is true, we can just skip the whole request.
        if (!isset($inoptiondatesql) && !isset($inoptionsql)) {
            return [];
        }

        // Now we make an SQL call to return all the relevant dates.
        $records = $DB->get_records_sql($sql, $params);

        $returnarray = [];

        // Bring the result in the correct form.
        foreach ($records as $record) {

            $optionsettings = singleton_service::get_instance_of_booking_option_settings($record->optionid);

            // Link is always the same.
            $link = new moodle_url('/mod/booking/view.php', [
                'optionid' => $record->optionid,
                'id' => $optionsettings->cmid,
                'action' => 'showonlyone',
                'whichview' => 'showonlyone']);

            $newentittydate = new entitydate(
                $record->instanceid,
                'mod_booking',
                $record->area,
                $optionsettings->get_title_with_prefix(),
                $record->coursestarttime,
                $record->courseendtime,
                1,
                $link);

            $returnarray[] = $newentittydate;
        }

        return $returnarray;
    }

    /**
     * SQL to return all the booked and reserved dates.
     * The Where clause has to be added, to either go on s1.id (for optiondates) or bo.id (for options)
     *
     * @return string
     */
    private static function return_sql_for_options_dates():string {

        global $DB;

        $sql = "SELECT s1.*, s2.odcount FROM
                (
                    SELECT " .
                        $DB->sql_concat("'optiondate-'", "bod.id") . " uniqueid, " .
                        "bod.id instanceid,
                        'optiondate' area,
                        bo.id optionid,
                        bo.text,
                        bod.coursestarttime,
                        bod.courseendtime
                    FROM {booking_optiondates} bod
                    JOIN (
                        SELECT id, text
                        FROM {booking_options}
                    ) bo
                    ON bod.optionid = bo.id
                UNION
                    SELECT " .
                    $DB->sql_concat("'option-'", "id") . " uniqueid, " .
                    "id instanceid,
                    'option' area,
                    id optionid,
                    text,
                    coursestarttime,
                    courseendtime
                    FROM {booking_options}
            ) s1
            LEFT JOIN (
                SELECT optionid, count(id) odcount
                FROM {booking_optiondates}
                GROUP BY optionid
            ) s2
            ON s1.optionid = s2.optionid";

        return $sql;
    }
}
