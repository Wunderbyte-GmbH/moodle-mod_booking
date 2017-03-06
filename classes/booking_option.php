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


/**
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option extends booking {

    /** @var array key is booking_answer id, value userid, all users with answers (waitlist and regular) */
    protected $alluserids = array();

    /** @var array of the users booked for this option key userid */
    public $bookedusers = array();

    /** @var array of booked users visible to the current user (group members) */
    public $bookedvisibleusers = array();

    /** @var array of users that can be subscribed to that booking option if groups enabled, only members of groups user has access to are shown */
    public $potentialusers = array();

    public $optionid = null;

    /** @var booking option confix object */
    public $option = null;

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

    /** @var the number of the page starting with 0 */
    public $page = 0;

    /** @var the number of bookings displayed on a single page */
    public $perpage = 0;

    /** @var filter and other url params */
    public $urparams;

    /**
     * Creates basic booking option
     *
     * @param int $id cm id
     * @param int $optionid
     * @param object $option option object
     */
    public function __construct($id, $optionid, $filters = array(), $page = 0, $perpage = 0, $getusers = true) {
        global $DB;

        parent::__construct($id);
        $this->optionid = $optionid;
        // $this->update_booked_users();
        $this->option = $DB->get_record('booking_options', array('id' => $optionid), '*',
                'MUST_EXIST');
        $times = $DB->get_records_sql(
                "SELECT id, coursestarttime, courseendtime FROM {booking_optiondates} WHERE optionid = ? ORDER BY coursestarttime ASC",
                array($optionid));
        if (!empty($times)) {
            foreach ($times as $time) {
                $this->option->times = $time->coursestarttime . " - " . $time->courseendtime . " - ";
            }
            trim($this->option->times, " - ");
        } else {
            $this->option->times = null;
        }
        $this->filters = $filters;
        $this->page = $page;
        $this->perpage = $perpage;
        if ($getusers) {
            $this->get_users();
        }
    }

    /**
     * TODO: What is that for? Documentation missing!
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

    public function get_teachers() {
        global $DB;

        $this->option->teachers = $DB->get_records_sql(
                'SELECT DISTINCT t.userid, u.firstname, u.lastname FROM {booking_teachers} t LEFT JOIN {user} u ON t.userid = u.id WHERE t.optionid = ' .
                         $this->optionid . '');
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
            $beginofday = strtotime("{$this->urlparams['searchdateday']}-{$this->urlparams['searchdatemonth']}-{$this->urlparams['searchdateyear']}");
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

        $limitfrom = $this->perpage * $this->page;
        $numberofrecords = $this->perpage;
        $mainuserfields = get_all_user_name_fields(true, 'u');

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
                WHERE ' .$options . '
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
     * Get all answers (bookings) as an array from booking_answers
     * id as key, userid as value regular AND waitinglist
     *
     * @return array of userids $this->alluserids key: booking_answers id
     */
    public function get_all_userids() {
        global $DB;
        if (empty($this->alluserids)) {
            $conditions = array('optionid' => $this->optionid);
            $this->alluserids = $DB->get_records_menu('booking_answers', $conditions, null,
                    'id, userid');
        }
        return $this->alluserids;
    }

    /**
     * Updates canbookusers and bookedusers does not check the status (booked or waitinglist) Just gets the registered booking from database
     * Calculates the potential users (bookers able to book, but not yet booked)
     */
    public function update_booked_users() {
        global $DB;

        if (empty($this->canbookusers)) {
            $this->get_canbook_userids();
        }

        $mainuserfields = \user_picture::fields('u', null);
        $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid
        FROM {booking_answers} ba, {user} u
        WHERE ba.userid = u.id AND
        u.deleted = 0 AND
        ba.bookingid = ? AND
        ba.optionid = ?
        ORDER BY ba.timemodified ASC";
        $params = array($this->id, $this->optionid);

        // It is possible that the cap mod/booking:choose has been revoked after the user has booked Therefore do not count them as booked users.
        $allanswers = $DB->get_records_sql($sql, $params);
        $this->bookedusers = array_intersect_key($allanswers, $this->canbookusers);
        // TODO offer users with according caps to delete excluded users from booking option
        // $excludedusers = array_diff_key($allanswers, $this->canbookusers);
        $this->numberofanswers = count($this->bookedusers);

        $this->bookedvisibleusers = $this->bookedusers;
        $this->potentialusers = array_diff_key($this->canbookusers, $this->bookedusers);

        $this->sort_answers();
    }

    /**
     * add booked/waitinglist info to each userobject of users
     */
    public function sort_answers() {
        if (!empty($this->bookedusers) && null != $this->option) {
            foreach ($this->bookedusers as $rank => $userobject) {
                $userobject->bookingcmid = $this->cm->id;
                if (!$this->option->limitanswers) {
                    $userobject->booked = 'booked';
                }
                // rank starts at 0 so add + 1 to corespond to max answer settings
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
     * Deletes a single booking of a user if user cancels the booking, sends mail to bookingmanager. If there is a limit book other user and send mail
     * to the user.
     *
     * @param $userid
     * @return true if booking was deleted successfully, otherwise false
     */
    public function user_delete_response($userid) {
        global $USER, $DB;

        $result = $DB->get_records('booking_answers', array('userid' => $userid, 'optionid' => $this->optionid, 'completed' => 0));

        if (count($result) == 0) {
            return false;
        }

        $DB->delete_records('booking_answers', array('userid' => $userid, 'optionid' => $this->optionid, 'completed' => 0));

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

        booking_check_unenrol_user($this->option, $this->booking, $user->id);

        $params = booking_generate_email_params($this->booking, $this->option, $user, $this->cm->id);

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

        // TODO: user might have been deleted
        $bookingmanager = $DB->get_record('user',
                array('username' => $this->booking->bookingmanager));

        $eventdata = new \stdClass();

        if ($this->booking->sendmail) {
            // Generate ical attachment to go with the message.
            $attachname = '';
            $ical = new \booking_ical($this->booking, $this->option, $user, $bookingmanager);
            if ($attachment = $ical->get_attachment(true)) {
                $attachname = $ical->get_name();
            }

            $messagehtml = text_to_html($messagebody, false, false, true);

            if (isset($this->booking->sendmailtobooker) && $this->booking->sendmailtobooker) {
                $eventdata->userto = $USER;
            } else {
                $eventdata->userto = $user;
            }

            $eventdata->userfrom = $bookingmanager;
            $eventdata->subject = $subject;
            $eventdata->messagetext = $messagebody;
            $eventdata->messagehtml = $messagehtml;
            $eventdata->attachment = $attachment;
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

                booking_check_enrol_user($this->option, $this->booking, $newuser->userid);

                if ($this->booking->sendmail == 1 || $this->booking->copymail) {
                    $newbookeduser = $DB->get_record('user', array('id' => $newuser->userid));
                    $params = booking_generate_email_params($this->booking, $this->option,
                            $newbookeduser, $this->cm->id);
                    $messagetextnewuser = booking_get_email_body($this->booking, 'statuschangetext',
                            'statuschangebookedmessage', $params);
                    $messagehtml = text_to_html($messagetextnewuser, false, false, true);

                    // Generate ical attachment to go with the message.
                    $attachname = '';
                    $ical = new \booking_ical($this->booking, $this->option, $newbookeduser,
                            $bookingmanager);
                    if ($attachment = $ical->get_attachment()) {
                        $attachname = $ical->get_name();
                    }
                    $eventdata->userto = $newbookeduser;
                    $eventdata->userfrom = $bookingmanager;
                    $eventdata->subject = get_string('statuschangebookedsubject', 'booking',
                            $params);
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

        // Remove activity completion
        $course = $DB->get_record('course', array('id' => $this->booking->course));
        $completion = new \completion_info($course);

        if ($completion->is_enabled($this->cm) && $this->booking->enablecompletion) {
            $completion->update_state($this->cm, COMPLETION_INCOMPLETE, $userid);
        }

        return true;
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
                $value->waitinglist = 0;
                $DB->update_record("booking_answers", $value);
            }

            $noverbooking = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid), $this->option->maxanswers, $this->option->maxoverbooking);

            foreach ($noverbooking as $value) {
                $value->waitinglist = 1;
                $DB->update_record("booking_answers", $value);
            }

            $nover = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC',
                    array($this->optionid),
                    $this->option->maxoverbooking + $this->option->maxanswers);

            foreach ($nover as $value) {
                $DB->delete_records('booking_answers', array('id' => $value->id));
            }
        } else {
            $DB->execute("UPDATE {booking_answers} SET waitinglist = 0 WHERE optionid = :optionid",
                    array('optionid' => $this->optionid));
        }
    }

    /**
     * Saves the booking for the user
     *
     * @return boolean true if booking was possible, false if meanwhile the booking got full
     */
    public function user_submit_response($user, $frombookingid = 0) {
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
                 (booking_get_user_booking_count($this, $user) < $this->booking->maxperuser);

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
            // TODO replace
            booking_check_enrol_user($this->option, $this->booking, $user->id);
        }

        $event = \mod_booking\event\bookingoption_booked::create(
                array('objectid' => $this->optionid,
                    'context' => \context_module::instance($this->cm->id),
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();

        if ($this->booking->sendmail) {
            $eventdata = new \stdClass();
            $eventdata->user = $user;
            $eventdata->booking = $this->booking;
            // TODO the next line is for backward compatibility only, delete when finished
            // refurbishing the module ;-)
            $eventdata->booking->option[$this->optionid] = $this->option;
            $eventdata->optionid = $this->optionid;
            $eventdata->cmid = $this->cm->id;
            // TODO replace
            booking_send_confirm_message($eventdata);
        }
        return true;
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
}