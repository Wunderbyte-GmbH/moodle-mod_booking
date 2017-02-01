<?php

namespace mod_booking;


/**
 * Managing a single booking option
 *
 * @package mod_booking
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option extends booking {

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

    /** @var array of users objects - filtered */
    public $users = array();

    public $usersOnList = array();

    public $usersOnWaitingList = array();
    // Pagination
    public $page = 0;

    public $perpage = 0;

    public $canBookToOtherBooking = 0;

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
            foreach ($times as $key => $time) {
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
    public function calculateHowManyCanBookToOther($optionid) {
        global $DB;
        
        if (isset($optionid) && $optionid > 0) {
            $alredyBooked = 0;
            
            $result = $DB->get_records_sql(
                    'SELECT answers.userid FROM {booking_answers} AS answers INNER JOIN {booking_answers} AS parent on parent.userid = answers.userid WHERE answers.optionid = ? AND parent.optionid = ?', 
                    array($this->optionid, $optionid));
            
            $alredyBooked = count($result);
            
            $keys = array();
            
            foreach ($result as $value) {
                $keys[] = $value->userid;
            }
            
            foreach ($this->usersOnWaitingList as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->bookedToOtherBooking = 1;
                } else {
                    $user->bookedToOtherBooking = 0;
                }
            }
            
            foreach ($this->usersOnList as $user) {
                if (in_array($user->userid, $keys)) {
                    $user->usersOnList = 1;
                } else {
                    $user->usersOnList = 0;
                }
            }
            
            $connectedBooking = $DB->get_record("booking", 
                    array('conectedbooking' => $this->booking->id), 'id', IGNORE_MULTIPLE);
            
            if ($connectedBooking) {
                
                $noLimits = $DB->get_records_sql(
                        "SELECT bo.*, b.text
                        FROM mdl_booking_other AS bo
                        LEFT JOIN mdl_booking_options AS b ON b.id = bo.optionid
                        WHERE b.bookingid = ?", array($connectedBooking->id));
                
                if (!$noLimits) {
                    $howManyNum = $this->option->howmanyusers;
                } else {
                    $howMany = $DB->get_record_sql(
                            "SELECT userslimit FROM {booking_other} WHERE optionid = ? AND otheroptionid = ?", 
                            array($optionid, $this->optionid));
                    
                    $howManyNum = 0;
                    if ($howMany) {
                        $howManyNum = $howMany->userslimit;
                    }
                }
            }
            
            if ($howManyNum == 0) {
                $howManyNum = 999999;
            }
            
            return (int) $howManyNum - (int) $alredyBooked;
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
        $this->option = $tags->optionReplace($this->option);
    }

    public function get_url_params() {
        $bu = new \booking_utils();
        $params = $bu->generate_params($this->booking, $this->option);
        $this->option->pollurl = $bu->get_body($params, 'pollurl', $params, TRUE);
        $this->option->pollurlteachers = $bu->get_body($params, 'pollurlteachers', $params, TRUE);
    }

    public function get_teachers() {
        global $DB;
        
        $this->option->teachers = $DB->get_records_sql(
                'SELECT DISTINCT t.userid, u.firstname, u.lastname FROM {booking_teachers} AS t LEFT JOIN {user} AS u ON t.userid = u.id WHERE t.optionid = ' .
                         $this->optionid . '');
    }
    
    // Get all users with filters
    public function get_users() {
        global $DB;
        $params = array();
        
        $options = "ba.optionid = :optionid";
        $params['optionid'] = $this->optionid;
        
        if (isset($this->filters['searchFinished']) && strlen($this->filters['searchFinished']) > 0) {
            $options .= " AND ba.completed = :completed";
            $params['completed'] = $this->filters['searchFinished'];
        }
        
        if (isset($this->filters['searchDate']) && $this->filters['searchDate'] == 1) {
            $options .= " AND FROM_UNIXTIME(ba.timecreated, '%Y') = :searchdateyear AND FROM_UNIXTIME(ba.timecreated, '%m') = :searchdatemonth AND FROM_UNIXTIME(ba.timecreated, '%d') = :searchdateday";
            $params['searchdateyear'] = $this->filters['searchDateYear'];
            $params['searchdatemonth'] = $this->filters['searchDateMonth'];
            $params['searchdateday'] = $this->filters['searchDateDay'];
        }
        
        if (isset($this->filters['searchName']) && strlen($this->filters['searchName']) > 0) {
            $options .= " AND u.firstname LIKE :searchname";
            $params['searchname'] = '%' . $this->filters['searchName'] . '%';
        }
        
        if (isset($this->filters['searchSurname']) && strlen($this->filters['searchSurname']) > 0) {
            $options .= " AND u.lastname LIKE :searchsurname";
            $params['searchsurname'] = '%' . $this->filters['searchSurname'] . '%';
        }
        
        $mainuserfields = explode(',', \user_picture::fields());
        foreach ($mainuserfields as $key => $value) {
            $mainuserfields[$key] = 'u.' . $value;
        }
        $mainuserfields = implode(', ', $mainuserfields);
        $DB->sql_fullname();
        $this->users = $DB->get_records_sql(
                'SELECT ba.id AS aid, 
                ba.bookingid, 
                ba.numrec, 
                ba.userid, 
                ba.optionid, 
                ba.timemodified,
                ba.completed, 
                ba.timecreated, 
                ba.waitinglist, ' . $mainuserfields . ', ' .
                         $DB->sql_fullname('u.firstname', 'u.lastname') .
                         ' AS fullname FROM {booking_answers} ba LEFT JOIN {user} u ON ba.userid = u.id WHERE ' .
                         $options . ' ORDER BY ba.optionid, ba.timemodified DESC', $params, 
                        $this->perpage * $this->page, $this->perpage);
        
        foreach ($this->users as $user) {
            if ($user->waitinglist == 1) {
                $this->usersOnWaitingList[] = $user;
            } else {
                $this->usersOnList[] = $user;
            }
        }
    }
    
    // Get all users...filtered!
    public function get_all_users() {
        global $DB;
        $params = array();
        
        $options = "{booking_answers}.optionid = :optionid";
        $params['optionid'] = $this->optionid;
        
        if (isset($this->filters['searchFinished']) && strlen($this->filters['searchFinished']) > 0) {
            $options .= " AND {booking_answers}.completed = :completed";
            $params['completed'] = $this->filters['searchFinished'];
        }
        
        if (isset($this->filters['searchDate']) && $this->filters['searchDate'] == 1) {
            $options .= " AND FROM_UNIXTIME({booking_answers}.timecreated, '%Y') = :searchdateyear AND FROM_UNIXTIME({booking_answers}.timecreated, '%m') = :searchdatemonth AND FROM_UNIXTIME({booking_answers}.timecreated, '%d') = :searchdateday";
            $params['searchdateyear'] = $this->filters['searchDateYear'];
            $params['searchdatemonth'] = $this->filters['searchDateMonth'];
            $params['searchdateday'] = $this->filters['searchDateDay'];
        }
        
        if (isset($this->filters['searchName']) && strlen($this->filters['searchName']) > 0) {
            $options .= " AND {user}.firstname LIKE :searchname";
            $params['searchname'] = '%' . $this->filters['searchName'] . '%';
        }
        
        if (isset($this->filters['searchSurname']) && strlen($this->filters['searchSurname']) > 0) {
            $options .= " AND {user}.lastname LIKE :searchsurname";
            $params['searchsurname'] = '%' . $this->filters['searchSurname'] . '%';
        }
        
        $mainuserfields = \user_picture::fields('{user}', NULL);
        
        return $DB->get_records_sql(
                'SELECT {booking_answers}.id AS aid, {booking_answers}.bookingid, {booking_answers}.userid, {booking_answers}.optionid, {booking_answers}.timemodified, {booking_answers}.completed, {booking_answers}.timecreated, {booking_answers}.waitinglist, {booking_answers}.numrec, ' .
                         $mainuserfields .
                         ' FROM {booking_answers} LEFT JOIN {user} ON {booking_answers}.userid = {user}.id WHERE ' .
                         $options .
                         ' ORDER BY {booking_answers}.optionid, {booking_answers}.timemodified ASC', 
                        $params);
    }

    /**
     * Count, how man users...for pagination.
     *
     * @return number
     */
    public function count_users() {
        global $DB;
        $params = array();
        
        $options = "{booking_answers}.optionid = :optionid";
        $params['optionid'] = $this->optionid;
        
        if (isset($this->filters['searchFinished']) && strlen($this->filters['searchFinished']) > 0) {
            $options .= " AND {booking_answers}.completed = :completed";
            $params['completed'] = $this->filters['searchFinished'];
        }
        
        if (isset($this->filters['searchDate']) && $this->filters['searchDate'] == 1) {
            $options .= " AND FROM_UNIXTIME({booking_answers}.timecreated, '%Y') = :searchdateyear AND FROM_UNIXTIME({booking_answers}.timecreated, '%m') = :searchdatemonth AND FROM_UNIXTIME({booking_answers}.timecreated, '%d') = :searchdateday";
            $params['searchdateyear'] = $this->filters['searchDateYear'];
            $params['searchdatemonth'] = $this->filters['searchDateMonth'];
            $params['searchdateday'] = $this->filters['searchDateDay'];
        }
        
        if (isset($this->filters['searchName']) && strlen($this->filters['searchName']) > 0) {
            $options .= " AND {user}.firstname LIKE :searchname";
            $params['searchname'] = '%' . $this->filters['searchName'] . '%';
        }
        
        if (isset($this->filters['searchSurname']) && strlen($this->filters['searchSurname']) > 0) {
            $options .= " AND {user}.lastname LIKE :searchsurname";
            $params['searchsurname'] = '%' . $this->filters['searchSurname'] . '%';
        }
        
        $count = $DB->get_record_sql(
                'SELECT COUNT(*) AS count FROM {booking_answers} LEFT JOIN {user} ON {booking_answers}.userid = {user}.id WHERE ' .
                         $options .
                         ' ORDER BY {booking_answers}.optionid, {booking_answers}.timemodified ASC', 
                        $params);
        
        return (int) $count->count;
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
        
        /**
         * it is possible that the cap mod/booking:choose has been revoked after the user has booked Therefore do not count them as booked users.
         */
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
        global $DB;
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
        
        /**
         * log deletion of user *
         */
        $event = \mod_booking\event\booking_cancelled::create(
                array('objectid' => $this->optionid, 
                    'context' => \context_module::instance($this->cm->id), 
                    'relateduserid' => $user->id, 'other' => array('userid' => $user->id)));
        $event->trigger();
        
        booking_check_unenrol_user($this->option, $this->booking, $user->id);
        
        $params = booking_generate_email_params($this->booking, $this->option, $user, $this->cm->id);
        
        $messagetext = get_string('deletedbookingmessage', 'booking', $params);
        if ($userid == $USER->id) {
            // I canceled the booking
            $deletedbookingusermessage = booking_get_email_body($this->booking, 'userleave', 
                    'userleavebookedmessage', $params);
            $subject = get_string('userleavebookedsubject', 'booking', $params);
        } else {
            // Booking manager canceled the booking
            $deletedbookingusermessage = booking_get_email_body($this->booking, 'deletedtext', 
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
            
            $messagehtml = text_to_html($deletedbookingusermessage, false, false, true);
            
            if (isset($this->booking->sendmailtobooker) && $this->booking->sendmailtobooker) {
                $eventdata->userto = $USER;
            } else {
                $eventdata->userto = $user;
            }
            
            $eventdata->userfrom = $bookingmanager;
            $eventdata->subject = $subject;
            $eventdata->messagetext = $deletedbookingusermessage;
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
            $maxplacesavailable = $this->option->maxanswers + $this->option->maxoverbooking;
            $bookedUsers = $DB->count_records("booking_answers", 
                    array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingUsers = $DB->count_records("booking_answers", 
                    array('optionid' => $this->optionid, 'waitinglist' => 1));
            $allUsersCount = $bookedUsers + $waitingUsers;
            
            if ($waitingUsers > 0 && $this->option->maxanswers > $bookedUsers) {
                $newUser = $DB->get_record_sql(
                        'SELECT * FROM {booking_answers} WHERE optionid = ? AND waitinglist = 1 ORDER BY timemodified ASC', 
                        array($this->optionid), IGNORE_MULTIPLE);
                
                $newUser->waitinglist = 0;
                
                $DB->update_record("booking_answers", $newUser);
                
                booking_check_enrol_user($this->option, $this->booking, $newUser->userid);
                
                if ($this->booking->sendmail == 1 || $this->booking->copymail) {
                    $newbookeduser = $DB->get_record('user', array('id' => $newUser->userid));
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
        $cm = get_coursemodule_from_instance('booking', $this->cm->id);
        
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
            
            $nBooking = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC', 
                    array($this->optionid), 0, $this->option->maxanswers);
            
            foreach ($nBooking as $value) {
                $value->waitinglist = 0;
                $DB->update_record("booking_answers", $value);
            }
            
            $nOverBooking = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC', 
                    array($this->optionid), $this->option->maxanswers, $this->option->maxoverbooking);
            
            foreach ($nOverBooking as $value) {
                $value->waitinglist = 1;
                $DB->update_record("booking_answers", $value);
            }
            
            $nOver = $DB->get_records_sql(
                    'SELECT * FROM {booking_answers} WHERE optionid = ? ORDER BY timemodified ASC', 
                    array($this->optionid), 
                    $this->option->maxoverbooking + $this->option->maxanswers);
            
            foreach ($nOver as $value) {
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
        
        $waitingList = $this->check_if_limit();
        
        if ($waitingList === FALSE) {
            return FALSE;
        }
        
        $underlimit = ($this->booking->maxperuser == 0);
        $underlimit = $underlimit ||
                 (booking_get_user_booking_count($this, $user, NULL) < $this->booking->maxperuser);
        
        if (!$underlimit) {
            return FALSE;
        }
        
        if (!($currentanswerid = $DB->get_field('booking_answers', 'id', 
                array('userid' => $user->id, 'optionid' => $this->optionid)))) {
            $newanswer = new \stdClass();
            $newanswer->bookingid = $this->id;
            $newanswer->frombookingid = $frombookingid;
            $newanswer->userid = $user->id;
            $newanswer->optionid = $this->optionid;
            $newanswer->timemodified = time();
            $newanswer->timecreated = time();
            $newanswer->waitinglist = $waitingList;
            
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
            $bookedUsers = $DB->count_records("booking_answers", 
                    array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingUsers = $DB->count_records("booking_answers", 
                    array('optionid' => $this->optionid, 'waitinglist' => 1));
            $allUsersCount = $bookedUsers + $waitingUsers;
            
            if ($maxplacesavailable > $allUsersCount) {
                if ($this->option->maxanswers > $bookedUsers) {
                    return 0;
                } else {
                    return 1;
                }
            } else {
                return FALSE;
            }
        } else {
            return 0;
        }
    }
}