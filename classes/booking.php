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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache_helper;
use context_module;
use course_modinfo;
use html_writer;
use local_entities\local\entities\entitydate;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\modechecker;
use mod_booking\teachers_handler;
use moodle_exception;
use stdClass;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

/**
 * Standard base class for mod_booking.
 *
 * Module was originally programmed for 1.9 but further adjustments should be made with new
 * Moodle 2.X coding style using this base class.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner {@link http://www.edulabs.org}
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
    public $canbookusers = [];

    /** @var array users who are members of the current users group */
    public $groupmembers = [];

    /** @var stdClass settings of the booking instance */
    public $settings = null;

    /** @var array $alloptions option objects indexed by optionid */
    protected $alloptions = [];

    /** @var array of ids */
    protected $optionids = [];

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
    public function __construct(int $cmid, ?course_modinfo $cm = null) {
        global $DB;

        $this->cmid = $cmid;
        if (!$cm || ($cmid != $cm->id)) {
            $this->cm = get_coursemodule_from_id('booking', $cmid);
        } else {
            $this->cm = $cm;
        }

        if (!$this->cm) {
            // ERROR: The instance does not exist.
            // But we do not want our site to crash, so we return null.
            return null;
        }

        // In the constructur, we call the booking_settings, where we get the values from db or cache.
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        $this->settings = $bookingsettings->return_settings_as_stdclass();
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
     * Get context.
     *
     * @return \context
     */
    public function get_context() {
        return $this->context;
    }

    /**
     * Apply tags
     *
     * @return void
     *
     */
    public function apply_tags() {
        if (empty($this->cm->course)) {
            return;
        }
        $tags = new booking_tags($this->cm->course);
        $this->settings = $tags->booking_replace($this->settings);
    }

    /**
     * Get url params.
     */
    public function get_url_params() {
        $bu = new booking_utils();
        $params = $bu->generate_params($this->settings);
        $this->settings->pollurl = $bu->get_body($this->settings, 'pollurl', $params);
        $this->settings->pollurlteachers = $bu->get_body($this->settings, 'pollurlteachers', $params);
    }

    /**
     * Returm number of displayed rows of options per page for pagination (or given default)
     * @return int
     */
    public function get_pagination_setting(): int {
        $paginationnum = (int) $this->settings->paginationnum > 0 ? (int) $this->settings->paginationnum :
            MOD_BOOKING_PAGINATIONDEF;
        return $paginationnum;
    }

    /**
     * Function to lazyload userlist for autocomplete.
     *
     * @param string $query
     * @return array
     */
    public static function load_users(string $query) {
        global $DB;

        $values = explode(' ', $query);

        $fullsql = $DB->sql_concat(
            '\' \'', 'u.id', '\' \'', 'u.firstname', '\' \'', 'u.lastname', '\' \'', 'u.email', '\' \''
        );

        $sql = "SELECT * FROM (
                    SELECT u.id, u.firstname, u.lastname, u.email, $fullsql AS fulltextstring
                    FROM {user} u
                    WHERE u.deleted = 0
                ) AS fulltexttable";
        // Check for u.deleted = 0 is important, so we do not load any deleted users!
        $params = [];
        if (!empty($query)) {
            // We search for every word extra to get better results.
            $firstrun = true;
            $counter = 1;
            foreach ($values as $value) {

                $sql .= $firstrun ? ' WHERE ' : ' AND ';
                $sql .= " " . $DB->sql_like('fulltextstring', ':param' . $counter, false) . " ";
                // If it's numeric, we search for the full number - so we need to add blanks.
                $params['param' . $counter] = is_numeric($value) ? "% $value %" : "%$value%";
                $firstrun = false;
                $counter++;
            }
        }

        // We don't return more than 100 records, so we don't need to fetch more from db.
        $sql .= " limit 102";

        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        $list = [];

        foreach ($rs as $record) {
            $user = (object)[
                    'id' => $record->id,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'email' => $record->email,
            ];

            $count++;
            $list[$record->id] = $user;
        }

        $rs->close();

        return [
                'warnings' => count($list) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
                'list' => count($list) > 100 ? [] : $list,
        ];
    }

    /**
     * Function to lazyload courses list for autocomplete.
     *
     * @param string $query
     * @return array
     */
    public static function load_courses(string $query) {
        global $DB;

        $totalcount = 1;

        $allcourses = get_courses_search([], 'c.fullname ASC', 0, 9999999,
            $totalcount, ['enrol/manual:enrol']);
        $allcourseids = [];
        foreach ($allcourses as $id => $courseobject) {
            $allcourseids[] = $id;
        }
        list($incourseids, $inparams) = $DB->get_in_or_equal($allcourseids, SQL_PARAMS_NAMED, 'inparam');

        $values = explode(' ', $query);

        $fullsql = $DB->sql_concat('\' \'', 'c.id', '\' \'', 'c.shortname', '\' \'', 'c.fullname', '\' \'');

        $sql = "SELECT * FROM (
                    SELECT c.id, c.shortname, c.fullname, $fullsql AS fulltextstring
                    FROM {course} c
                    WHERE c.visible = 1 AND c.id $incourseids
                ) AS fulltexttable";
        // Check for c.visible = 1 is important, so we do not load any inivisble courses!
        $params = $inparams;
        if (!empty($query)) {
            // We search for every word extra to get better results.
            $firstrun = true;
            $counter = 1;
            foreach ($values as $value) {

                $sql .= $firstrun ? ' WHERE ' : ' AND ';
                $sql .= " " . $DB->sql_like('fulltextstring', ':param' . $counter, false) . " ";
                // If it's numeric, we search for the full number - so we need to add blanks.
                $params['param' . $counter] = is_numeric($value) ? "% $value %" : "%$value%";
                $firstrun = false;
                $counter++;
            }
        }

        // We don't return more than 100 records, so we don't need to fetch more from db.
        $sql .= " limit 102";

        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        $coursearray = [];

        foreach ($rs as $record) {
            $course = (object)[
                    'id' => $record->id,
                    'shortname' => $record->shortname,
                    'fullname' => $record->fullname,
            ];

            $count++;
            $coursearray[$record->id] = $course;
        }

        // 0 ... No course has been selected.
        $coursearray[0] = (object)[
            'id' => 0,
            'shortname' => get_string('nocourseselected', 'mod_booking'),
            'fullname' => get_string('nocourseselected', 'mod_booking'),
        ];

        $rs->close();

        return [
                'warnings' => count($coursearray) > 100 ? get_string('toomanytoshow', 'mod_booking') : '',
                'list' => count($coursearray) > 100 ? [] : $coursearray,
        ];
    }

    /**
     * Function to lazyload teacher list for autocomplete.
     *
     * @param string $query
     * @return array
     */
    public static function load_teachers(string $query) {
        global $DB;

        $values = explode(' ', $query);

        $fullsql = $DB->sql_concat('u.firstname', '\'\'', 'u.lastname', '\'\'', 'u.email');

        $sql = "SELECT * FROM (
                    SELECT DISTINCT u.*
                    FROM {user} u
                    JOIN (
                        SELECT DISTINCT userid
                        FROM {booking_teachers}
                    ) bt
                    ON bt.userid = u.id
                    WHERE u.deleted = 0
                ) AS fulltexttable";
        // Check for u.deleted = 0 is important, so we do not load any deleted users!
        $params = [];
        if (!empty($query)) {
            // We search for every word extra to get better results.
            $firstrun = true;
            $counter = 1;
            foreach ($values as $value) {

                $sql .= $firstrun ? ' WHERE ' : ' AND ';
                $sql .= " " . $DB->sql_like('fulltextstring', ':param' . $counter, false) . " ";
                $params['param' . $counter] = "%$value%";
                $firstrun = false;
                $counter++;
            }
        }

        // We don't return more than 100 records, so we don't need to fetch more from db.
        $sql .= " limit 102";

        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        $list = [];

        foreach ($rs as $record) {
            $user = (object)[
                    'id' => $record->id,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'email' => $record->email,
            ];

            $count++;
            $list[$record->id] = $user;
        }

        $rs->close();

        return [
                'warnings' => count($list) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
                'list' => count($list) > 100 ? [] : $list,
        ];
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
        return [$groupsql, $params];
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
        $params = [];

        if (!empty($searchtext)) {
            $searchtext = $DB->sql_like_escape($searchtext);
            $search = " AND ({$DB->sql_like('LOWER(bo.text)', 'LOWER(:text)', false)}" .
            " OR {$DB->sql_like('LOWER(bo.location)', 'LOWER(:location)', false)}" .
            " OR {$DB->sql_like('LOWER(bo.institution)', 'LOWER(:institution)', false)})";
            $params['text'] = "%{$searchtext}%";
            $params['location'] = "%{$searchtext}%";
            $params['institution'] = "%{$searchtext}%";
        }

        return ['params' => $params, 'query' => $search];
    }

    /**
     * Get all booking options as an array of objects indexed by optionid
     *
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     * @param string $fields
     *
     * @return array of booking options records
     *
     */
    public function get_all_options($limitfrom = 0, $limitnum = 0, $searchtext = '', $fields = "*") {

        global $DB;

        list($fields, $from, $where, $params, $filter) = $this->get_all_options_sql($limitfrom, $limitnum,
            $searchtext, $fields, $this->context);

        return $DB->get_records_sql(
            "SELECT $fields FROM $from WHERE $where $filter", $params);
    }

    /**
     * Get all options count
     *
     * @param string $searchtext
     *
     * @return void
     *
     */
    public function get_all_options_count($searchtext = '') {
        global $DB;

        $search = '';
        $params = [];

        $rsearch = $this->searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id], $rsearch['params']);

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
     * @param mixed $bookingid
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     *
     * @return array of ids
     *
     */
    public function get_active_optionids($bookingid, $limitfrom = 0, $limitnum = 0, $searchtext = '') {
        global $DB;

        $limit = '';
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id, 'time' => time()], $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql(
            "SELECT bo.id FROM {booking_options} bo " .
            "WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
            " {$search} {$limit}", $params);
    }

    /**
     * Get active optionids count
     *
     * @param mixed $bookingid
     * @param string $searchtext
     *
     * @return void
     *
     */
    public function get_active_optionids_count($bookingid, $searchtext = '') {
        global $DB;

        $search = '';
        $params = [];

        $rsearch = $this->searchparameters($searchtext);

        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id, 'time' => time()], $rsearch['params']);

        return $DB->count_records_sql(
            "SELECT COUNT(*) FROM {booking_options} bo " .
            "WHERE bo.bookingid = :bookingid AND (bo.courseendtime > :time OR bo.courseendtime = 0)" .
            " {$search}", $params);
    }

    /**
     * Get all booking option ids as an array of numbers - only where is teacher.
     *
     * @param int $bookingid
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
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     *
     * @return array of ids
     *
     */
    public function get_my_bookingids($limitfrom = 0, $limitnum = 0, $searchtext = '') {
        global $DB, $USER;

        $limit = '';
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id, 'userid' => $USER->id], $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom},{$limitnum}";
        }

        return $DB->get_records_sql(
            "SELECT ba.optionid id FROM {booking_options} bo LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id WHERE" .
                " ba.bookingid = :bookingid AND ba.userid = :userid {$search} {$limit}", $params);
    }

    /**
     * Get my bookingids count
     *
     * @param string $searchstring
     *
     * @return void
     *
     */
    public function get_my_bookingids_count($searchstring = '') {
        global $DB, $USER;

        $search = '';
        $params = [];

        $rsearch = $this->searchparameters($searchstring);

        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id, 'userid' => $USER->id], $rsearch['params']);

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
             ['class' => 'alert alert-warning']);
        return $warning;
    }

    /**
     * Determins the number of bookings that a single user has already made in all booking options
     *
     * @param stdClass $user
     * @return int of bookings made by user
     */
    public function get_user_booking_count($user): int {
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
            AND (bo.courseendtime = 0 OR bo.courseendtime > ?)",
            [$this->id, $user->id, MOD_BOOKING_STATUSPARAM_WAITINGLIST, time()]);

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
                WHERE bo.bookingid = :bookingid
                AND ba.userid = :userid
                AND ba.waitinglist = 0'; // Important: Check for waitinglist = 0 (booked).

        $params = [
            'bookingid' => $this->settings->id,
            'userid' => $user->id,
        ];

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get fields for download of booking options.
     * @param bool $download true for download, else for page
     * @return array an array of headers and columns
     */
    public function get_bookingoptions_fields(bool $download = false) {

        if ($download) {
            $fields = explode(',', $this->settings->optionsdownloadfields ?? MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS);
        } else {
            $fields = explode(',', $this->settings->optionsfields ?? MOD_BOOKING_BOOKINGOPTION_DEFAULTFIELDS);
        }

        $columns = [];
        $headers = [];

        foreach ($fields as $value) {
            switch ($value) {
                case 'identifier':
                    $headers[] = get_string('optionidentifier', 'mod_booking');
                    $columns[] = 'identifier';
                    break;
                case 'titleprefix':
                    $headers[] = get_string('titleprefix', 'mod_booking');
                    $columns[] = 'titleprefix';
                    break;
                case 'text':
                    $headers[] = get_string('bookingoption', 'mod_booking');
                    $columns[] = 'text';
                    break;
                case 'description':
                    $headers[] = get_string('description', 'mod_booking');
                    $columns[] = 'description';
                    break;
                case 'teacher':
                    $headers[] = get_string('teachers', 'mod_booking');
                    $columns[] = 'teacher';
                    break;
                case 'responsiblecontact':
                    $headers[] = get_string('responsiblecontact', 'mod_booking');
                    $columns[] = 'responsiblecontact';
                    break;
                case 'attachment':
                    $headers[] = get_string('bookingattachment', 'mod_booking');
                    $columns[] = 'attachment';
                    break;
                case 'showdates':
                    $headers[] = get_string('dates', 'mod_booking');
                    $columns[] = 'showdates';
                    break;
                case 'dayofweektime':
                    $headers[] = get_string('dayofweektime', 'mod_booking');
                    $columns[] = 'dayofweektime';
                    break;
                case 'location':
                    $headers[] = get_string('location', 'mod_booking');
                    $columns[] = 'location';
                    break;
                case 'institution':
                    $headers[] = get_string('institution', 'mod_booking');
                    $columns[] = 'institution';
                    break;
                case 'course':
                    $headers[] = get_string('course', 'core');
                    $columns[] = 'course';
                    break;
                case 'minanswers':
                    $headers[] = get_string('minanswers', 'mod_booking');
                    $columns[] = 'minanswers';
                    break;
                case 'bookings':
                    $headers[] = get_string('bookings', 'mod_booking');
                    $columns[] = 'bookings';
                    break;
                case 'bookingopeningtime':
                    $headers[] = get_string('bookingopeningtime', 'mod_booking');
                    $columns[] = 'bookingopeningtime';
                    break;
                case 'bookingclosingtime':
                    $headers[] = get_string('bookingclosingtime', 'mod_booking');
                    $columns[] = 'bookingclosingtime';
                    break;
            }
        }
        return [$headers, $columns];
    }

    /**
     * Get extra fields to display in report.php.
     *
     * @return string[][]|array[]
     */
    public function get_manage_responses_fields() {
        global $DB;
        $reportfields = explode(',', $this->settings->reportfields);
        list($addquoted, $addquotedparams) = $DB->get_in_or_equal($reportfields);

        $userprofilefields = $DB->get_records_select('user_info_field',
                'id > 0 AND shortname ' . $addquoted, $addquotedparams, 'id', 'id, shortname, name');

        $columns = [];
        $headers = [];

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
                case 'price': // This is only possible, if local shoppingcart is installed.
                    $columns[] = 'price';
                    $headers[] = get_string('price', 'mod_booking');
                    $columns[] = 'currency';
                    $headers[] = get_string('currency', 'local_shopping_cart');
                    break;
                case 'timecreated':
                    $columns[] = 'timecreated';
                    $headers[] = get_string('timecreated', 'mod_booking');
                    break;
            }
        }
        return [$columns, $headers, $userprofilefields];
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

                $nrec = $DB->count_records('booking_teachers', ['userid' => $USER->id, 'bookingid' => $this->id]);

                if ($nrec === 0) {
                    $bookingoption = $DB->get_record('booking_options', ['id' => $this->settings->autcrtemplate]);
                    $bookingoption->text = '';
                    if (!empty($USER->institution)) {
                        $bookingoption->text .= "{$USER->institution} - ";
                    } else {
                        $bookingoption->text .= "[AUTO] ";
                    }
                    $bookingoption->text .= "{$USER->firstname} {$USER->lastname}";
                    $bookingoption->bookingid = $this->id;
                    $bookingoption->description = (empty($bookingoption->description) ? '' : $bookingoption->description);
                    unset($bookingoption->id);

                    $nrecid = $DB->insert_record('booking_options', $bookingoption, true, false);

                    $newteacher = new stdClass();
                    $newteacher->bookingid = $this->id;
                    $newteacher->userid = $USER->id;
                    $newteacher->optionid = $nrecid;
                    $newteacher->completed = 0;

                    $DB->insert_record('booking_teachers', $newteacher, false, false);

                    // When inserting a new teacher, we also need to insert the teacher for each optiondate.
                    teachers_handler::subscribe_teacher_to_all_optiondates($newteacher->optionid, $newteacher->userid);

                    $params = [
                        'id' => $this->cm->id,
                        'optionid' => $nrecid,
                    ];
                    $url = new moodle_url('/mod/booking/report.php', $params);

                    redirect($url);
                }
            }
        }
    }

    // New functions beneath.

    /**
     * Is elective.
     *
     * @return bool
     */
    public function is_elective() {
        if (isset($this->settings->iselective) && $this->settings->iselective == 1) {
            return true;
        }
        return false;
    }

    /**
     * Function to check booking settings if we should use credits function
     * Part of elective functinoality
     * @return bool
     */
    public function uses_credits() {
        if (isset($this->settings->iselective) && $this->settings->iselective == 1
                && isset($this->settings->maxcredits) && $this->settings->maxcredits > 0) {
            return true;
        }
        return false;
    }

    /**
     * Genereate SQL and params array to fetch all options.
     * No prefixes for the columsn we retrieve, so *, id, etc.
     * If we don't pass on the context object, invisible options are excluded.
     *
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     * @param ?string $fields
     * @param ?object $context
     * @return array
     */
    public function get_all_options_sql(
        $limitfrom = 0,
        $limitnum = 0,
        $searchtext = '',
        ?string $fields = null,
        ?object $context = null
    ) {
        global $DB;

        return self::get_options_filter_sql(
            $limitfrom,
            $limitnum,
            $searchtext,
            $fields,
            $context,
            [],
            ['bookingid' => (int)$this->id]
        );
    }


    /**
     * This function is our central way of getting the whole sql calling the standard table as well as the filtered one.
     * We have the simple searchtext also, which will be used from now on as fulltext search.
     * The filterarray should be used for temporary filtering all records, opposed to where. Its a subset of all records.
     * Where means that it restricts the number of total records.
     * This distinction is important for the automatic filter generation of Wunderbyte Table.
     *
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     * @param ?string $fields
     * @param ?object $context
     * @param array $filterarray
     * @param array $wherearray
     * @param ?int $userid
     * @param array $bookingparams
     * @param string $additionalwhere
     * @param string $innerfrom
     * @return array
     */
    public static function get_options_filter_sql(
        $limitfrom = 0,
        $limitnum = 0,
        $searchtext = '',
        $fields = null,
        $context = null,
        $filterarray = [],
        $wherearray = [],
        $userid = null,
        $bookingparams = [MOD_BOOKING_STATUSPARAM_BOOKED],
        $additionalwhere = '',
        $innerfrom = ''
    ) {

        global $DB;

        $columns = $DB->get_columns('booking_options', true);

        $offieldsarray = array_map(fn($a) => "bo.$a->name", $columns);

        if (empty($fields)) {
            $fields = "DISTINCT s1.*";
        }

        $where = '';

        $filter = '';

        $params = [];

        $groupby = " " . implode(", ", $offieldsarray) . " ";

        $outerfrom = "(
                        SELECT DISTINCT $groupby ";

        $innerfrom = empty($innerfrom) ? "FROM {booking_options} bo" : $innerfrom;

        // If the user does not have the capability to see invisible options...
        if (!$context || !has_capability('mod/booking:canseeinvisibleoptions', $context)) {

            // If we have a direct link, we only hide totally invisible options.
            if (isset($where['id'])) {
                $where = " invisible <> 1 ";
            } else {
                // ... then only show visible options.
                $where = "invisible = 0 ";
            }
        } else {
            // The "Where"-clause is always added so we have to have something here for the sql to work.
            $where = "1=1 ";
        }
        // Add where condition for searchtext.
        if (!empty($searchtext)) {
            $where .= " AND " . $DB->sql_like("text", ":searchtext", false);
            $params['searchtext'] = $searchtext;
        }
        // Add where condition for userid.
        if ($userid !== null) {

            list($inorequal, $inparams) = $DB->get_in_or_equal($bookingparams, SQL_PARAMS_NAMED);

            $innerfrom .= " JOIN {booking_answers} ba
                          ON ba.optionid=bo.id ";

            $outerfrom .= ", ba.waitinglist, ba.userid as bookeduserid ";
            $where .= " AND waitinglist $inorequal
                        AND bookeduserid=:bookeduserid ";
            $groupby .= " , ba.waitinglist, ba.userid ";

            $params['bookeduserid'] = $userid;

            $params = array_merge($params, $inparams);
        }

        // Instead of "where" we return "filter". This is to support the filter functionality of wunderbyte table.
        list($select1, $from1, $filter1, $params1) = booking_option_settings::return_sql_for_customfield();
        list($select2, $from2, $filter2, $params2) = booking_option_settings::return_sql_for_teachers();
        list($select3, $from3, $filter3, $params3) = booking_option_settings::return_sql_for_imagefiles();
        list($select4, $from4, $filter4, $params4, $conditionsql) = bo_info::return_sql_from_conditions();

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
        $params = array_merge($params, $params1, $params2, $params3, $params4);

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

            if (gettype($value) == 'array') {

                $where .= " AND ( ";
                $orstring = [];

                // TODO: This could be replaced with in or equal, but not sure of if its worth it.
                foreach ($value as $arrayvalue) {

                    if (is_numeric($arrayvalue)) {
                        $number = (float)$arrayvalue;
                        $orstring[] = " $key = $number ";
                    } else {
                        // Be sure to have a lower key string.
                        $paramsvaluekey = "param";
                        while (isset($params[$paramsvaluekey])) {
                            $paramsvaluekey .= $counter;
                            $counter++;
                        }

                        $orstring[] = " " . $DB->sql_like("$key", ":$paramsvaluekey", false) . " ";
                        $params[$paramsvaluekey] = $arrayvalue;
                    }
                }

                $where .= implode(' OR ', $orstring);

                $where .= " ) ";

            } else if (gettype($value) == 'integer') {
                $where .= " AND   $key = $value";
            } else {
                $where .= " AND " . $DB->sql_like("$key", ":$paramsvaluekey", false);
                $params[$paramsvaluekey] = $value;
            }
        }

        // We add sql from conditions, if there is any.
        if (!empty($conditionsql)) {
            $where .= " AND " . $conditionsql;
        }

        // We add additional conditions to $where, if there are any.
        if (!empty($additionalwhere)) {
            $where .= " AND " . $additionalwhere;
        }

        return [$fields, $from, $where, $params, $filter];

    }

    /**
     * Function to return all bookings for teacher.
     *
     * @param int $teacherid
     * @param int $bookingid booking instance id - not cmid!
     * @return void
     */
    public static function get_all_options_of_teacher_sql(int $teacherid, int $bookingid) {

        $options = [
            'teacherobjects' => '%"id":' . $teacherid . ',%',
        ];

        if (!empty($bookingid)) {
            $options['bookingid'] = $bookingid;
        }

        return self::get_options_filter_sql(0, 0, '', '*', null, [], $options);
    }

    /**
     * Genereate SQL and params array to fetch my options.
     *
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $searchtext
     * @param string $fields
     * @param array $booked
     * @return void
     */
    public function get_my_options_sql($limitfrom = 0, $limitnum = 0, $searchtext = '',
        $fields = "bo.*",
        $booked = [MOD_BOOKING_STATUSPARAM_BOOKED]) {

        global $DB, $USER;

        $fields = "DISTINCT " . $fields;

        $limit = '';
        $rsearch = $this->searchparameters($searchtext);
        $search = $rsearch['query'];
        $params = array_merge(['bookingid' => $this->id,
                                    'userid' => $USER->id,
                                ], $rsearch['params']);

        if ($limitnum != 0) {
            $limit = " LIMIT {$limitfrom} OFFSET {$limitnum}";
        }

        list($inorequal, $inparams) = $DB->get_in_or_equal($booked, SQL_PARAMS_NAMED);

        $params = array_merge($params, $inparams);

        $from = "{booking_options} bo
                JOIN {booking_answers} ba
                ON ba.optionid=bo.id";
        $where = "bo.bookingid = :bookingid
                  AND ba.userid = :userid
                  AND ba.waitinglist = $inorequal {$search}";
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
     * @param object $moodleurl
     */
    public static function encode_moodle_url($moodleurl) {

        global $CFG;

        // See github issue: https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/305.
        // TODO: We currently encode the whole URL, but we should only encode the params.
        // Encoding the whole URL makes migration to a new WWWROOT impossible.

        $encodedurl = base64_encode($moodleurl->out(false));
        $encodedmoodleurl = new moodle_url($CFG->wwwroot . '/mod/booking/bookingredirect.php', [
            'encodedurl' => $encodedurl,
        ]);

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
    public static function return_array_of_entity_dates(array $areas): array {

        // TODO: Now that the SQL has been changed, we need to fix this function!

        global $DB, $USER, $PAGE;

        // Get the SQL to retrieve all the right IDs.
        $sql = self::return_sql_for_options_dates($areas);
        $params = [];

        if (!empty($areas['option'])) {
            list($inoptionsql, $optionparams) = $DB->get_in_or_equal($areas['option'], SQL_PARAMS_NAMED);
            // We only select options with an odcount of NULL meaning there are no optiondates.
            // If there are optiondates, we are only interested in them and ignore the option itself.
            $sql .= " WHERE (
                        s1.area = 'option'
                        AND s2.odcount IS NULL
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

            if (!modechecker::is_ajax_or_webservice_request()) {
                $returnurl = $PAGE->url->out();
            } else {
                $returnurl = '/';
            }

            // The current page is not /mod/booking/optionview.php.
            $link = new moodle_url("/mod/booking/optionview.php", [
                "optionid" => (int)$optionsettings->id,
                "cmid" => (int)$optionsettings->cmid,
                "userid" => (int)$USER->id,
                'returnto' => 'url',
                'returnurl' => $returnurl,
            ]);

            // Invisible options should be in a light gray.
            $isinvisible = !empty($optionsettings->invisible) ? true : false;

            // If the option is invisible and the user has no right to see it, we continue.
            if ($isinvisible && !has_capability('mod/booking:canseeinvisibleoptions',
                context_module::instance($optionsettings->cmid))) {
                continue;
            }

            $bgcolor = $isinvisible ? "#808080" : "#4285F4";
            $optiontitle = $optionsettings->get_title_with_prefix();
            if ($isinvisible) {
                // Show [invisible] prefix icon before title.
                $optiontitle = "[" . get_string('invisible', 'mod_booking') . "] " . $optiontitle;
            }

            $newentittydate = new entitydate(
                $record->instanceid,
                'mod_booking',
                $record->area,
                $optiontitle,
                $record->coursestarttime,
                $record->courseendtime,
                1, $link, $bgcolor);

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
    private static function return_sql_for_options_dates(): string {

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

    /**
     * As the needed json operators are not cross db compatibile and there is no support in Moodle...
     * ... we have to create it ourselves.
     *
     * @param string $dbname
     * @param array $courses
     * @return string|void
     */
    public static function get_sql_for_fieldofstudy(string $dbname, array $courses) {

        switch ($dbname) {

            case 'pgsql_native_moodle_database':
                return "
                    FROM (SELECT bos2.*
                    FROM (
                    SELECT bos1.*, json_array_elements_text(bos1.availability1 -> 'courseids')::int bocourseid
                    FROM (
                    SELECT *, json_array_elements(availability::json) availability1
                    FROM {booking_options}) bos1
                    WHERE bos1.availability1 ->>'id' = '" . MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE . "'
                    ) bos2
                    LEFT JOIN {enrol} e
                    ON e.courseid = bos2.bocourseid
                    LEFT JOIN {cohort} c
                    ON e.customint1 = c.id
                    WHERE e.enrol = 'cohort'
                    AND bocourseid IN (" . implode(', ', $courses) . ")
                    ) bo
                ";

            case 'mariadb_native_moodle_database':

                $where = "";
                $wherearray = [];

                foreach ($courses as $courseid) {

                    $wherearray[] = " JSON_SEARCH(bos1.boscourseids, 'one', '" . $courseid . "') IS NOT NULL ";
                }

                if (count($courses) > 0) {
                    $where = " AND ( ( " . implode(" ) OR ( ", $wherearray) . ") ) ";
                }

                return "
                    FROM (
                        SELECT bos1.*
                        FROM (
                            SELECT *, JSON_EXTRACT(
                                JSON_UNQUOTE(
                                    JSON_EXTRACT(availability, '$[*].id')), '$[0]') AS boavailid,
                                        JSON_EXTRACT(JSON_UNQUOTE(
                                            JSON_EXTRACT(availability, '$[*].courseids')), '$[0]'
                            ) AS boscourseids
                            FROM {booking_options}
                        ) bos1
                        WHERE bos1.boavailid = '". MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE . "'"
                    . $where . " ) bo";
        }
    }

    /**
     * Return the sql for the event logs of booking component.
     *
     * @param string $component
     * @param array $eventnames
     * @param int $objectid
     *
     * @return array
     *
     */
    public static function return_sql_for_event_logs(
            string $component = 'mod_booking',
            array $eventnames = [],
            int $objectid = 0) {
        global $DB;

        $select = "*";

        $params = [];

        $from = "(
                    SELECT
                    lsl.*
                    FROM {logstore_standard_log} lsl
                ) as s1";

        $where = 'component = :component ';

        if (!empty($eventnames)) {
            list($inorequal, $params) = $DB->get_in_or_equal($eventnames, SQL_PARAMS_NAMED);

            $where .= " AND eventname " . $inorequal;
        }

        if (!empty($objectid)) {

            $where .= " AND objectid = :objectid ";
            $params['objectid'] = $objectid;
        }

        $filter = '';

        $params['component'] = $component;

        return [$select, $from, $where, $filter, $params];
    }

    /**
     * A helper class to add data to the json of a booking instance.
     *
     * @param stdClass $data reference to a data object containing the json key
     * @param string $key - for example: "disablecancel", "viewparam"...
     * @param int|string|stdClass|array|null $value - for example: 1
     */
    public static function add_data_to_json(stdClass &$data, string $key, $value) {
        booking_option::add_data_to_json($data, $key, $value);
    }

    /**
     * A helper class to remove a data field from the json of a booking instance.
     *
     * @param stdClass $data reference to a data object containing the json key to remove
     * @param string $key - the key to remove, for example: "disablecancel"
     */
    public static function remove_key_from_json(stdClass &$data, string $key) {
        booking_option::remove_key_from_json($data, $key);
    }

    /**
     * A helper class to get the value of a certain key stored in the json DB field of a booking instance.
     *
     * @param int $bookingid booking instance id - do not confuse with cmid!
     * @param string $key - the key to remove, for example: "disablecancel", "viewparam"...
     * @return mixed|null the value found, false if nothing found
     */
    public static function get_value_of_json_by_key(int $bookingid, string $key) {
        if (!empty($bookingid)) {
            $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
            $json = $settings->json;
            if (!empty($json)) {
                $jsonobject = json_decode($json);
                if (isset($jsonobject->{$key})) {
                    return $jsonobject->{$key};
                }
            }
        }
        return null;
    }

    /**
     * Helper function to return an array containing all relevant instance update changes.
     *
     * @param stdClass $oldoption the original booking option object
     * @param stdClass $newoption the new booking option object
     * @return array an array containing the changes that have been made
     */
    public static function booking_instance_get_changes($oldoption, $newoption) {

        $keystoexclude = [
            'introformat',
            'customtemplateid',
            'timemodified',
            'json', // Changes in JSON are currently not supported.
        ];

        $keyslocalization = [
            'name' => get_string('bookingname', 'mod_booking'),
            'defaultoptionsort' => get_string('sortby'),
            'defaultsortorder' => get_string('sortorder', 'mod_booking'),
            'optionsfield' => get_string('optionsfield', 'mod_booking'),
        ];

        $returnarry = [];

        foreach ($newoption as $key => $value) {

            if (in_array($key, $keystoexclude)) {
                continue;
            }

            if (isset($oldoption->{$key})
                && $oldoption->{$key} != $value) {

                switch ($key) {
                    case 'name':
                        $fieldname = 'bookingname';
                        break;
                    case 'duration':
                        $fieldname = 'bookingduration';
                        break;
                    case 'points':
                        $fieldname = 'bookingpoints';
                        break;
                    case 'intro':
                        $fieldname = 'description';
                        break;
                    case 'defaultsortorder':
                        $fieldname = 'sortorder';
                        break;
                    default:
                        $fieldname = $key;
                        break;
                }

                $returnarry[] = [
                    'fieldname' => $fieldname,
                    'oldvalue' => $oldoption->{$key},
                    'newvalue' => $value,
                ];
            }
        }

        if (count($returnarry) > 0) {
            return $returnarry;
        } else {
            return [];
        }
    }

    /**
     * Helper function to purge all caches for a booking instance.
     * @param int $cmid
     * @param bool $withsemesters
     * @param bool $withencodedtables
     * @param bool $destroysingleton
     */
    public static function purge_cache_for_booking_instance_by_cmid(
        int $cmid,
        bool $withsemesters = true,
        bool $withencodedtables = true,
        bool $destroysingleton = true
    ) {
        cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);
        cache_helper::purge_by_event('setbackoptionsettings');
        cache_helper::purge_by_event('setbackoptionstable');
        cache_helper::purge_by_event('setbackeventlogtable');
        if ($withsemesters) {
            cache_helper::purge_by_event('setbacksemesters');
        }
        if ($withencodedtables) {
            // Wunderbyte table cache.
            cache_helper::purge_by_event('setbackencodedtables');
        }
        if ($destroysingleton) {
            // Make sure, we destroy singletons too.
            singleton_service::destroy_booking_singleton_by_cmid($cmid);
        }
    }

    /**
     * Helper function to generate label descriptions, e.g. for navigation elements.
     * @param string $prefix prefix for classes, e.g. the name of the moodle page like "report2"
     * @param array $scopes an array of scopes, e.g. ["option", "instance", "course", "system"]
     * @return string styling css embedded in html (with surrounding <style> element)
     */
    public static function generate_localized_css_for_navigation_labels(string $prefix, array $scopes) {
        $css = "
            .$prefix-nav {
                display: flex;
                flex-wrap: wrap;
            }
        ";

        $last = end($scopes);

        foreach ($scopes as $scope) {
            $islast = ($last == $scope);
            $css .= '
            .' . $prefix . "-" . $scope . '-border::before {
                content: "' . get_string($prefix . '_label_' . $scope, 'mod_booking') . '";
                position: absolute;
                top: -10px;
                padding: 0 5px;
                font-weight: 200;
                font-size: small;
                background-color: white;
                color: ' . ($islast ? '#000' : '#333') . ';
                white-space: nowrap;
            }
            .' . $prefix . '-' . $scope . '-border {
                display: inline-block;
                position: relative;
                padding: 10px 20px;
                margin-bottom: 10px;
                border: ' . ($islast ? '1px dashed black' : '1px dotted gray') . ';
                border-radius: 5px;
                color: ' . ($islast ? '#0f6cbf' : 'gray') . ';
                font-size: large;
                font-weight: ' . ($islast ? 'bold' : 'lighter') . ';
                white-space: nowrap;
            }
            ';
        }

        return "<style>$css</style>";
    }

    /**
     * Helper function to shorten long texts and add 3 dots "..." at the end.
     * @param string $text input text to be shortened
     * @param int $length maximum length after which the "..." should be added
     * @return string the return string, e.g. "Lorem ipsum..."
     */
    public static function shorten_text($text, $length = 20) {
        return (strlen($text) > $length) ? substr($text, 0, $length) . "..." : $text;
    }
}
