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
defined('MOODLE_INTERNAL') || die();

/**
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option extends booking {

    /** @var array of stdClass objects including status: key is booking_answer id $allusers->userid, $allusers->waitinglist */
    protected $allusers = array();

    /** @var array of the users booked for this option key userid */
    public $bookedusers = array();

    /** @var array of booked users visible to the current user (group members) */
    public $bookedvisibleusers = array();

    /** @var array of users subscribeable to booking option if groups enabled, members of groups user has access to */
    public $potentialusers = array();

    public $optionid = null;

    /** @var booking option config object */
    public $option = null;

    /** @var booking option teachers defined in booking_teachers table */
    public $teachers = array();

    /** @var number of answers */
    public $numberofanswers = null;

    /** @var array of users filters */
    public $filters = array();

    /** @var array of all user objects (waitinglist and regular) - filtered */
    public $users = array();

    /** @var array of user objects with regular bookings NO waitinglist userid as key */
    public $usersonlist = array();

    /** @var array of user objects with users on waitinglist userid as key */
    public $usersonwaitinglist = array();

    /** @var number of the page starting with 0 */
    public $page = 0;

    /** @var number of bookings displayed on a single page */
    public $perpage = 0;

    /** @var string filter and other url params */
    public $urparams;

    /** @var string $times course start time - course end time or session times separated with, */
    public $optiontimes = '';

    /** @var array $sessions array of objects containing coursestarttime and courseendtime as object values */
    public $sessions = array();

    /**
     * Creates basic booking option
     *
     * @param int $cmid cmid
     * @param int $optionid
     * @param object $option option object
     */
    public function __construct($cmid, $optionid, $filters = array(), $page = 0, $perpage = 0, $getusers = true) {
        global $DB;
        parent::__construct($cmid);
        $this->optionid = $optionid;
        $this->option = $DB->get_record('booking_options',
                array('id' => $optionid, 'bookingid' => $this->id), '*', MUST_EXIST);
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
                    array('conectedbooking' => $this->booking->id), 'id', IGNORE_MULTIPLE);

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
        parent::apply_tags();

        $tags = new \booking_tags($this->cm);
        $this->option = $tags->option_replace($this->option);
    }

    public function get_url_params() {
        $bu = new \booking_utils();
        $params = $bu->generate_params($this->booking, $this->option);
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
                    'SELECT DISTINCT t.userid, u.firstname, u.lastname
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
        global $DB;
        $params = array();

        $options = "ba.optionid = :optionid";
        $params['optionid'] = $this->optionid;

        if (isset($this->filters['searchfinished']) && strlen($this->filters['searchfinished']) > 0) {
            $options .= " AND ba.completed = :completed";
            $params['completed'] = $this->filters['searchfinished'];
        }
        if (isset($this->filters['searchdate']) && $this->filters['searchdate'] == 1) {
            $beginofday = strtotime(
                    "{$this->urlparams['searchdateday']}-{$this->urlparams['searchdatemonth']}-{$this->urlparams['searchdateyear']}");
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
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS and
                 !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->course->id))) {
            list($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql(
                    $this->course->id);
            $options .= " AND u.id IN ($groupsql)";
            $params = array_merge($params, $groupparams);
        }

        $limitfrom = $this->perpage * $this->page;
        $numberofrecords = $this->perpage;
        $mainuserfields = \user_picture::fields('u');

        $sql = 'SELECT ba.id AS aid,
                ba.bookingid,
                ba.numrec,
                ba.userid,
                ba.optionid,
                ba.timemodified,
                ba.completed,
                ba.timecreated,
                ba.waitinglist,
                ' . $mainuserfields . ', ' .
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
     * @return array of userobjects $this->allusers key: booking_answers id
     */
    public function get_all_users() {
        global $DB;
        if (empty($this->allusers)) {
            $userfields = \user_picture::fields('u');
            $params = array('optionid' => $this->optionid);
            $sql = "SELECT ba.id as baid, ba.userid, ba.waitinglist, $userfields
                      FROM {booking_answers} ba
                      JOIN {user} u ON u.id = ba.userid
                     WHERE ba.optionid = :optionid";
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
    public function get_all_users_onwaitlist() {
        if (empty($this->allusers)) {
            $allusers = $this->get_all_users();
        } else {
            $allusers = $this->allusers;
        }
        foreach ($allusers as $baid => $user) {
            if ($user->waitinglist == 1) {
                $waitlistusers[$baid] = $user;
            }
        }
        return $waitlistusers;
    }

    /**
     * Get all users booked users (not aon waitlist) as an array of objects
     * booking_answer id as key, ->userid,
     *
     * @return array of userobjects $this->allusers key: booking_answers id
     */
    public function get_all_users_booked() {
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
        if (\is_null($userid)) {
            $userid = $USER->id;
        }
        if (empty($this->allusers)) {
            $booked = $DB->get_field('booking_answers', 'waitinglist',
                    array('optionid' => $this->optionid, 'userid' => $userid));
        } else {
            foreach ($this->allusers as $user) {
                if ($userid == $user->userid) {
                    $booked = $user->waitinglist;
                    break;
                }
            }
        }
        if ($booked === false) {
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
        $booked = false;
        if (\is_null($userid)) {
            $userid = $USER->id;
        }

        $userstatus = $DB->get_field('booking_answers', 'completed',
                array('optionid' => $this->optionid, 'userid' => $userid));

        if ($userstatus == 1) {
            return 1;
        } else {
            return 0;
        }
    }

    public function can_rate() {
        global $USER;

        if ($this->booking->ratings == 0) {
            return false;
        }

        if ($this->booking->ratings == 1) {
            return true;
        }

        if ($this->booking->ratings == 2) {
            if (in_array($this->user_status($USER->id), array(1, 2))) {
                return true;
            } else {
                return false;
            }
        }

        if ($this->booking->ratings == 3) {
            if ($this->is_activity_completed($USER->id)) {
                return true;
            } else {
                return false;
            }
        }

        return false;
    }

    public function get_option_text($userid = null) {
        global $USER;

        $text = "";

        $params = booking_generate_email_params($this->booking, $this->option, $USER, $this->cm->id, $this->optiontimes);

        if (in_array($this->user_status($userid), array(1, 2))) {
            $ac = $this->is_activity_completed($userid);
            if ($ac == 1) {
                if (!empty($this->option->aftercompletedtext)) {
                    $text = $this->option->aftercompletedtext;
                } else if (!empty($this->booking->aftercompletedtext)) {
                    $text = $this->booking->aftercompletedtext;
                }
            } else {
                if (!empty($this->option->beforecompletedtext)) {
                    $text = $this->option->beforecompletedtext;
                } else if (!empty($this->booking->beforecompletedtext)) {
                    $text = $this->booking->beforecompletedtext;
                }
            }
        } else {
            if (!empty($this->option->beforebookedtext)) {
                $text = $this->option->beforebookedtext;
            } else if (!empty($this->booking->beforebookedtext)) {
                $text = $this->booking->beforebookedtext;
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
        global $DB, $USER;

        if (empty($this->canbookusers)) {
            $this->get_canbook_userids();
        }

        $mainuserfields = \user_picture::fields('u', null);
        $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
                 FROM {booking_answers} ba, {user} u
                WHERE ba.userid = u.id
                  AND u.deleted = 0
                  AND ba.bookingid = :bookingid
                  AND ba.optionid = :optionid
             ORDER BY ba.timemodified ASC";
        $params = array("bookingid" => $this->id, "optionid" => $this->optionid);

        // mod/booking:choose may have been revoked after the user has booked: not count them as booked.
        $allanswers = $DB->get_records_sql($sql, $params);
        $this->bookedusers = array_intersect_key($allanswers, $this->canbookusers);
        // TODO offer users with according caps to delete excluded users from booking option.
        $this->numberofanswers = count($this->bookedusers);
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS and
                 !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->course->id))) {
            $mygroups = groups_get_all_groups($this->course->id, $USER->id);
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
            $this->bookedvisibleusers = array_intersect_key($groupmembers, $this->canbookusers);
        } else {
            $this->bookedvisibleusers = $this->bookedusers;
        }
        $this->potentialusers = array_diff_key($this->canbookusers, $this->bookedvisibleusers);
        $this->sort_answers();
    }

    /**
     * Add booked/waitinglist info to each userobject of users.
     */
    public function sort_answers() {
        if (!empty($this->bookedusers) && null != $this->option) {
            foreach ($this->bookedusers as $rank => $userobject) {
                $userobject->bookingcmid = $this->cm->id;
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
     */
    public function delete_responses_activitycompletion() {
        global $DB;

        $ud = array();
        $oud = array();
        $users = $DB->get_records('course_modules_completion',
                array('coursemoduleid' => $this->booking->completionmodule));
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
     * @param $users Array of users
     * @return void
     */
    public function delete_responses($users = array()) {
        if (!is_array($users) || empty($users)) {
            return false;
        }
        $results = array();
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
        $event = \mod_booking\event\booking_cancelled::create(
                array('objectid' => $this->optionid,
                    'context' => \context_module::instance($this->cm->id),
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();
        $this->unenrol_user($user->id);

        $params = booking_generate_email_params($this->booking, $this->option, $user, $this->cm->id, $this->optiontimes);

        if ($userid == $USER->id) {
            // I cancelled the booking.
            $messagebody = booking_get_email_body($this->booking, 'userleave',
                    'userleavebookedmessage', $params);
            $subject = get_string('userleavebookedsubject', 'booking', $params);
        } else {
            // Booking manager cancelled the booking.
            $messagebody = booking_get_email_body($this->booking, 'deletedtext',
                    'deletedbookingmessage', $params);
            $subject = get_string('deletedbookingsubject', 'booking', $params);
        }

        // TODO: user might have been deleted.
        $bookingmanager = $DB->get_record('user',
                array('username' => $this->booking->bookingmanager));

        $eventdata = new \stdClass();

        if ($this->booking->sendmail) {
            // Generate ical attachment to go with the message.
            $attachname = '';
            $attachment = '';
            if (\get_config('booking', 'icalcancel')) {
                $ical = new \mod_booking\ical($this->booking, $this->option, $user, $bookingmanager);
                $attachments = $ical->get_attachments(true);
            }
            $messagehtml = text_to_html($messagebody, false, false, true);

            if (isset($this->booking->sendmailtobooker) && $this->booking->sendmailtobooker) {
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
            $sendtask = new \mod_booking\task\send_confirmation_mails();
            $sendtask->set_custom_data($eventdata);
            \core\task\manager::queue_adhoc_task($sendtask);

            if ($this->booking->copymail) {
                $eventdata->userto = $bookingmanager;
                $sendtask = new \mod_booking\task\send_confirmation_mails();
                $sendtask->set_custom_data($eventdata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }
        }
        if ($this->option->limitanswers) {
            $bookedusers = $DB->count_records("booking_answers",
                    array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingusers = $DB->count_records("booking_answers",
                    array('optionid' => $this->optionid, 'waitinglist' => 1));

            if ($waitingusers > 0 && $this->option->maxanswers > $bookedusers) {
                $newuser = $DB->get_record_sql(
                        'SELECT * FROM {booking_answers} WHERE optionid = ? AND waitinglist = 1 ORDER BY timemodified ASC',
                        array($this->optionid), IGNORE_MULTIPLE);

                $newuser->waitinglist = 0;
                $DB->update_record("booking_answers", $newuser);

                $this->enrol_user($newuser->userid);

                if ($this->booking->sendmail == 1 || $this->booking->copymail) {
                    $newbookeduser = $DB->get_record('user', array('id' => $newuser->userid));
                    $params = booking_generate_email_params($this->booking, $this->option,
                            $newbookeduser, $this->cm->id, $this->optiontimes);
                    $messagetextnewuser = booking_get_email_body($this->booking, 'statuschangetext',
                            'statuschangebookedmessage', $params);
                    $messagehtml = text_to_html($messagetextnewuser, false, false, true);

                    // Generate ical attachment to go with the message.
                    $attachname = '';
                    $ical = new \mod_booking\ical($this->booking, $this->option, $newbookeduser,
                            $bookingmanager);
                    if ($attachment = $ical->get_attachments()) {
                        $attachname = $ical->get_name();
                    }
                    $eventdata->userto = $newbookeduser;
                    $eventdata->userfrom = $bookingmanager;
                    $eventdata->subject = get_string('statuschangebookedsubject', 'booking', $params);
                    $eventdata->messagetext = $messagetextnewuser;
                    $eventdata->messagehtml = $messagehtml;
                    $eventdata->attachment = $attachment;
                    $eventdata->attachname = $attachname;
                    if ($this->booking->sendmail == 1) {
                        $sendtask = new \mod_booking\task\send_confirmation_mails();
                        $sendtask->set_custom_data($eventdata);
                        \core\task\manager::queue_adhoc_task($sendtask);
                    }
                    if ($this->booking->copymail) {
                        $eventdata->userto = $bookingmanager;
                        $sendtask = new \mod_booking\task\send_confirmation_mails();
                        $sendtask->set_custom_data($eventdata);
                        \core\task\manager::queue_adhoc_task($sendtask);
                    }
                }
            }
        }

        // Remove activity completion.
        $course = $DB->get_record('course', array('id' => $this->booking->course));
        $completion = new \completion_info($course);

        if ($completion->is_enabled($this->cm) && $this->booking->enablecompletion) {
            $completion->update_state($this->cm, COMPLETION_INCOMPLETE, $userid);
        }

        return true;
    }

    /**
     * Unsubscribes given users from this booking option and subscribes them to the newoption
     *
     * @param number $newoption
     * @param array of numbers $userids
     * @return \stdClass transferred->success = true/false, transferred->no[] errored users,
     *         $transferred->yes transferred users
     */
    public function transfer_users_to_otheroption($newoption, $userids) {
        global $DB, $USER;
        $transferred = new \stdClass();
        $transferred->yes = array(); // Successfully transferred users.
        $transferred->no = array(); // Errored users.
        $transferred->success = false;
        $otheroption = new booking_option($this->cm->id, $newoption);
        if (!empty($userids) && (has_capability('mod/booking:subscribeusers', $this->context) || booking_check_if_teacher(
                $otheroption->option))) {
            $transferred->success = true;
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "limit_");
            $mainuserfields = get_all_user_name_fields(true, 'u');
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

            $nbooking = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), 0, $this->option->maxanswers);
            foreach ($nbooking as $value) {
                if ($value->waitinglist != 0) {
                    $value->waitinglist = 0;
                    $DB->update_record("booking_answers", $value);
                    $this->enrol_user($value->userid);
                }
            }

            $noverbooking = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), $this->option->maxanswers, $this->option->maxoverbooking);

            foreach ($noverbooking as $value) {
                if ($value->waitinglist != 1) {
                    $value->waitinglist = 1;
                    $DB->update_record("booking_answers", $value);
                }
            }

            $nover = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), $this->option->maxoverbooking + $this->option->maxanswers);

            foreach ($nover as $value) {
                $DB->delete_records('booking_answers', array('id' => $value->id));
            }
        } else {
            $DB->execute("UPDATE {booking_answers} SET waitinglist = 0 WHERE optionid = :optionid",
                    array('optionid' => $this->optionid));
        }
    }

    /**
     * Subscribe a user to a booking option
     *
     * @param \stdClass $user
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
            return false;
        }

        $waitinglist = $this->check_if_limit();

        if ($waitinglist === false) {
            return false;
        }

        $underlimit = ($this->booking->maxperuser == 0);
        $underlimit = $underlimit ||
                (($this->get_user_booking_count($user) - $substractfromlimit) < $this->booking->maxperuser);
        if (!$underlimit) {
            return false;
        }
        $currentanswerid = $DB->get_field('booking_answers', 'id',
                array('userid' => $user->id, 'optionid' => $this->optionid));
        if (!$currentanswerid) {
            $newanswer = new \stdClass();
            $newanswer->bookingid = $this->id;
            $newanswer->frombookingid = $frombookingid;
            $newanswer->userid = $user->id;
            $newanswer->optionid = $this->optionid;
            $newanswer->timemodified = time();
            $newanswer->timecreated = time();
            $newanswer->waitinglist = $waitinglist;

            if (!$DB->insert_record("booking_answers", $newanswer)) {
                error("Could not register your booking because of a database error");
            }
            $this->enrol_user($newanswer->userid);
        }

        $event = \mod_booking\event\bookingoption_booked::create(
                array('objectid' => $this->optionid,
                    'context' => \context_module::instance($this->cm->id),
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();

        if ($this->booking->sendmail) {
            $eventdata = new \stdClass();
            $this->send_confirm_message($user);
        }
        return true;
    }

    /**
     * Event that sends confirmation notification after user successfully booked TODO this should be
     * rewritten for moodle 2.6 onwards
     *
     * @param \stdClass $user user object
     * @return bool
     */
    public function send_confirm_message($user) {
        global $DB, $USER;
        $cmid = $this->cm->id;
        $optionid = $this->optionid;
        // Used to store the ical attachment (if required).
        $attachname = '';
        $attachment = '';

        $user = $DB->get_record('user', array('id' => $user->id));
        $bookingmanager = $DB->get_record('user',
                array('username' => $this->booking->bookingmanager));
        $data = booking_generate_email_params($this->booking, $this->option, $user, $cmid,
                $this->optiontimes);

        $cansend = true;

        if ($data->status == get_string('booked', 'booking')) {
            $subject = get_string('confirmationsubject', 'booking', $data);
            $subjectmanager = get_string('confirmationsubjectbookingmanager', 'booking', $data);
            $message = booking_get_email_body($this->booking, 'bookedtext', 'confirmationmessage',
                    $data);

            // Generate ical attachments to go with the message.
            // Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new \mod_booking\ical($this->booking, $this->option, $user, $bookingmanager);
                $attachments = $ical->get_attachments();
            }
        } else if ($data->status == get_string('onwaitinglist', 'booking')) {
            $subject = get_string('confirmationsubjectwaitinglist', 'booking', $data);
            $subjectmanager = get_string('confirmationsubjectwaitinglistmanager', 'booking', $data);
            $message = booking_get_email_body($this->booking, 'waitingtext',
                    'confirmationmessagewaitinglist', $data);
        } else {
            // TODO: should never be reached.
            $subject = "test";
            $subjectmanager = "tester";
            $message = "message";

            $cansend = false;
        }
        $messagehtml = text_to_html($message, false, false, true);
        $errormessage = get_string('error:failedtosendconfirmation', 'booking', $data);
        $errormessagehtml = text_to_html($errormessage, false, false, true);
        $user->mailformat = FORMAT_HTML; // Always send HTML version as well.

        $messagedata = new \stdClass();
        $messagedata->userfrom = $bookingmanager;
        if ($this->booking->sendmailtobooker) {
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
            $sendtask = new \mod_booking\task\send_confirmation_mails();
            $sendtask->set_custom_data($messagedata);
            \core\task\manager::queue_adhoc_task($sendtask);
        }

        if ($this->booking->copymail) {
            $messagedata->userto = $bookingmanager;
            $messagedata->subject = $subjectmanager;

            if ($cansend) {
                $sendtask = new \mod_booking\task\send_confirmation_mails();
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
    public function enrol_user($userid, $manual = false) {
        global $DB;
        if (!$manual) {
            if (!$this->booking->autoenrol) {
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
            $enrol->enrol_user($instance, $userid, $instance->roleid); // Enrol using the default role.

            if ($this->booking->addtogroup == 1) {
                if (!is_null($this->option->groupid) && ($this->option->groupid > 0)) {
                    groups_add_member($this->option->groupid, $userid);
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

        global $DB;

        if (!$this->booking->autoenrol) {
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
        if ($this->booking->addtogroup == 1) {
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
     * Deletes a booking option and the associated user answers
     *
     * @return false if not successful, true on success
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
                array("bookingid" => $this->id, "optionid" => $this->optionid))) {
            $result = false;
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

            // Delete comments.
        $DB->delete_records("comments",
                array('itemid' => $this->optionid, 'commentarea' => 'booking_option',
                    'contextid' => $this->context->id));

        if (!$DB->delete_records("booking_options", array("id" => $this->optionid))) {
            $result = false;
        }

        $event = \mod_booking\event\bookingoption_deleted::create(
                array('context' => $this->context, 'objectid' => $this->optionid,
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
     * Retrieves the global booking settings and returns the customfields
     * string[customfieldname][value]
     * Will return the actual text for the custom field string[customfieldname][type]
     * Will return the type: for now only textfield
     *
     * @return array string[customfieldname][value|type]; empty array if no settings set
     */
    static public function get_customfield_settings() {
        $values = array();
        $bkgconfig = \get_config('booking');
        $customfieldvals = \get_object_vars($bkgconfig);
        if (!empty($customfieldvals)) {
            foreach (array_keys($customfieldvals) as $customfieldname) {
                $iscustomfield = \strpos($customfieldname, 'customfield');
                $istype = \strpos($customfieldname, 'type');
                if ($iscustomfield !== false && $istype === false) {
                    $type = $customfieldname . "type";
                    $values[$customfieldname]['value'] = $bkgconfig->$customfieldname;
                    $values[$customfieldname]['type'] = $bkgconfig->$type;
                }
            }
        }
        return $values;
    }
}
