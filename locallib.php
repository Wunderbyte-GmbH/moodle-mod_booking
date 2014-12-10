<?php

require_once($CFG->dirroot . '/user/selector/lib.php');

/**
 * Standard base class for mod_booking
 *
 * Module was originally programmed for 1.9 but further adjustments should be made with
 * new Moodle 2.X coding style using this base class.
 *
 * @package   mod_booking
 * @copyright 2013 David Bogner {@link http://www.edulabs.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking {

    /** @var id booking id  */
    public $id = 0;

    /** @var context the context of the course module for this booking instance (or just the course if we are
      creating a new one) */
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
    public $booking;

    /**
     * Constructor for the booking class
     *
     * @param mixed $context context|null the course module context (or the course context if the coursemodule has not been created yet)
     * @param mixed $coursemodule the current course module if it was already loaded - otherwise this class will load one from the context as required
     * @param mixed $course the current course  if it was already loaded - otherwise this class will load one from the context as required
     */
    public function __construct($cmid) {
        global $DB, $USER;
        $this->cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $this->id = $this->cm->instance;
        $this->context = context_module::instance($this->cm->id);
        $this->course = $DB->get_record('course', array('id' => $this->cm->course), 'id, fullname, shortname, groupmode, groupmodeforce, visible', MUST_EXIST);
        $this->booking = $DB->get_record("booking", array("id" => $this->id));
        // if the course has groups and I do not have the capability to see all groups, show only users of my groups
        if ($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)) {
            $this->groupmembers = $this::booking_get_groupmembers($this->course->id);
        }
    }

    public function apply_tags() {
        $tags = new booking_tags($this->cm);

        $this->booking = $tags->bookingReplace($this->booking);
    }

    /**
     * get all the user ids who are allowed to book
     * capability mod/booking:choose
     * available in $htis->canbookusers
     */
    public function get_canbook_userids() {
        //TODO check if course has guest access if not get all enrolled users and check with has_capability if user has right to book
        //$this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose', 'u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);
        $this->canbookusers = get_enrolled_users($this->context, 'mod/booking:choose', null, 'u.id');
    }

    /**
     * get all group members of $USER (of all groups $USER belongs to)
     * 
     * @param int $courseid
     * @return array: all members of all groups $USER belongs to
     */
    public static function booking_get_groupmembers($courseid) {
        global $USER, $DB;
        $groupmembers = array();
        $usergroups = groups_get_all_groups($courseid, $USER->id);

        if (!empty($usergroups)) {
            $groupsparam = implode(',', array_keys($usergroups));
            $groupmembers = $DB->get_records_sql("SELECT u.id
                    FROM {user} u, {groups_members} gm
                    WHERE u.id = gm.userid AND gm.groupid IN (?)
                    ORDER BY lastname ASC", array($groupsparam));
        }
        return $groupmembers;
    }

}

/**
 * Managing a single booking option
 * @package mod-booking
 * @copyright 2014 David Bogner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

    /**
     * Creates basic booking option
     *
     * @param int $id cm id
     * @param int $optionid
     * @param object $option option object
     */
    public function __construct($id, $optionid, $filters = array(), $page = 0, $perpage = 0) {
        global $DB;

        parent::__construct($id);
        $this->optionid = $optionid;
        $this->update_booked_users();
        $this->option = $DB->get_record('booking_options', array('id' => $optionid), '*', 'MUST_EXIST');
        $this->filters = $filters;
        $this->page = $page;
        $this->perpage = $perpage;
        $this->get_users();
    }

    public function apply_tags() {
        parent::apply_tags();

        $tags = new booking_tags($this->cm);
        $this->option = $tags->optionReplace($this->option);
    }

    // Get all users with filters
    private function get_users() {
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

        $mainuserfields = explode(',', user_picture::fields());
        foreach ($mainuserfields as $key => $value) {
            $mainuserfields[$key] = '{user}.' . $value;
        }
        $mainuserfields = implode(', ', $mainuserfields);

        $this->users = $DB->get_records_sql('SELECT {booking_answers}.id AS aid, {booking_answers}.bookingid, {booking_answers}.userid, {booking_answers}.optionid, {booking_answers}.timemodified, {booking_answers}.completed, {booking_answers}.timecreated, {booking_answers}.waitinglist, ' . $mainuserfields . ' FROM {booking_answers} LEFT JOIN {user} ON {booking_answers}.userid = {user}.id WHERE ' . $options . ' ORDER BY {booking_answers}.optionid, {booking_answers}.timemodified ASC', $params, $this->perpage * $this->page, $this->perpage);

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

        $mainuserfields = user_picture::fields('{user}', NULL);

        return $DB->get_records_sql('SELECT {booking_answers}.id AS aid, {booking_answers}.bookingid, {booking_answers}.userid, {booking_answers}.optionid, {booking_answers}.timemodified, {booking_answers}.completed, {booking_answers}.timecreated, {booking_answers}.waitinglist, ' . $mainuserfields . ' FROM {booking_answers} LEFT JOIN {user} ON {booking_answers}.userid = {user}.id WHERE ' . $options . ' ORDER BY {booking_answers}.optionid, {booking_answers}.timemodified ASC', $params);
    }

    // Count, how man users...for pagination.
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

        $count = $DB->get_record_sql('SELECT COUNT(*) AS count FROM {booking_answers} LEFT JOIN {user} ON {booking_answers}.userid = {user}.id WHERE ' . $options . ' ORDER BY {booking_answers}.optionid, {booking_answers}.timemodified ASC', $params);

        return (int) $count->count;
    }

    /**
     * Updates canbookusers and bookedusers does not check the status (booked or waitinglist)
     * Just gets the registered booking from database
     * Calculates the potential users (bookers able to book, but not yet booked)
     */
    public function update_booked_users() {
        global $DB;
        $select = "bookingid = $this->id AND optionid =  $this->optionid";
        if (empty($this->canbookusers)) {
            $this->get_canbook_userids();
        }

        $mainuserfields = user_picture::fields('u', null);
        $sql = "SELECT $mainuserfields, ba.id AS answerid, ba.optionid, ba.bookingid 
        FROM {booking_answers} ba, {user} u
        WHERE ba.userid = u.id AND
        u.deleted = 0 AND
        ba.bookingid = ? AND
        ba.optionid = ?
        ORDER BY ba.timemodified ASC";
        $params = array($this->id, $this->optionid);

        /** it is possible that the cap mod/booking:choose has been revoked after the user has booked
         * Therefore do not count them as booked users.
         */
        $allanswers = $DB->get_records_sql($sql, $params);
        $this->bookedusers = array_intersect_key($allanswers, $this->canbookusers);
        // TODO offer users with according caps to delete excluded users from booking option
        //$excludedusers =  array_diff_key($allanswers, $this->canbookusers);
        $this->numberofanswers = count($this->bookedusers);
        if (!empty($this->groupmembers) && !(has_capability('moodle/site:accessallgroups', $this->context))) {
            $this->bookedvisibleusers = array_intersect_key($this->bookedusers, $this->groupmembers);
            $canbookgroupmembers = array_intersect_key($this->canbookusers, $this->groupmembers);
            $this->potentialusers = array_diff_key($canbookgroupmembers, $this->bookedusers);
        } else if (has_capability('moodle/site:accessallgroups', $this->context)) {
            $this->bookedvisibleusers = $this->bookedusers;
            $this->potentialusers = array_diff_key($this->canbookusers, $this->bookedusers);
        }
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
                if ($this->option->maxanswers < ($rank + 1) && $rank + 1 <= ($this->option->maxanswers + $this->option->maxoverbooking)) {
                    $userobject->booked = 'waitinglist';
                } else if ($rank + 1 <= $this->option->maxanswers) {
                    $userobject->booked = 'booked';
                }
            }
        }
    }

    /**
     * Mass delete all responses
     * @param $users Array of users
     * @return void
     */
    public function delete_responses($users = array()) {
        global $DB;
        if (!is_array($users) || empty($users)) {
            return false;
        }

        foreach ($users as $userid) {
            $this->user_delete_response($userid);
        }

        return TRUE;
    }

    /**
     * Deletes a single booking of a user if user cancels the booking, sends mail to bookingmanager. If there is a limit
     * book other user ans send mail to him.
     * @param $userid
     * @return true if booking was deleted successfully, otherwise false
     */
    public function user_delete_response($userid) {
        global $USER, $DB;

        if (!$DB->delete_records('booking_answers', array('userid' => $userid, 'optionid' => $this->optionid))) {
            return false;
        }

        if ($userid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', array('id' => $userid));
        }

        /** log deletion of user * */
        $event = \mod_booking\event\booking_cancelled::create(array(
                    'objectid' => $this->optionid,
                    'context' => context_module::instance($this->cm->id),
                    'relateduserid' => $user->id,
                    'other' => array('userid' => $user->id)
        ));
        $event->trigger();

        booking_check_unenrol_user($this->option, $this->booking, $user->id);

        $params = booking_generate_email_params($this->booking, $this->option, $user, $this->cm->id);
        $messagetext = get_string('deletedbookingmessage', 'booking', $params);
        if ($userid == $USER->id) {
            // I canceled the booking
            $deletedbookingusermessage = booking_get_email_body($this->booking, 'userleave', 'userleavebookedmessage', $params);
            $subject = get_string('userleavebookedsubject', 'booking', $params);
        } else {
            // Booking manager canceled the booking
            $deletedbookingusermessage = booking_get_email_body($this->booking, 'deletedtext', 'deletedbookingmessage', $params);
            $subject = get_string('deletedbookingsubject', 'booking', $params);
        }

        $bookingmanager = $DB->get_record('user', array('username' => $this->booking->bookingmanager));

        $eventdata = new stdClass();

        if ($this->booking->sendmail) {
            // Generate ical attachment to go with the message.
            $attachname = '';
            $ical = new booking_ical($this->booking, $this->option, $user, $bookingmanager);
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
            events_trigger('booking_deleted', $eventdata);

            if ($this->booking->copymail) {
                $eventdata->userto = $bookingmanager;
                events_trigger('booking_deleted', $eventdata);
            }
        }

        if ($this->option->limitanswers) {
            $maxplacesavailable = $this->option->maxanswers + $this->option->maxoverbooking;
            $bookedUsers = $DB->count_records("booking_answers", array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingUsers = $DB->count_records("booking_answers", array('optionid' => $this->optionid, 'waitinglist' => 1));
            $allUsersCount = $bookedUsers + $waitingUsers;

            if ($waitingUsers > 0 && $this->option->maxanswers > $bookedUsers) {
                $newUser = $DB->get_record_sql('SELECT * FROM {booking_answers} WHERE optionid = ? AND waitinglist = 1 ORDER BY timemodified ASC', array($this->optionid), IGNORE_MULTIPLE);

                $newUser->waitinglist = 0;

                $DB->update_record("booking_answers", $newUser);

                booking_check_enrol_user($this->option, $this->booking, $newUser->userid);

                if ($this->booking->sendmail == 1 || $this->booking->copymail) {
                    $newbookeduser = $DB->get_record('user', array('id' => $newUser->userid));
                    $params = booking_generate_email_params($this->booking, $this->option, $newbookeduser, $this->cm->id);
                    $messagetextnewuser = booking_get_email_body($this->booking, 'statuschangetext', 'statuschangebookedmessage', $params);
                    $messagehtml = text_to_html($messagetextnewuser, false, false, true);

                    // Generate ical attachment to go with the message.
                    $attachname = '';
                    $ical = new booking_ical($this->booking, $this->option, $newbookeduser, $bookingmanager);
                    if ($attachment = $ical->get_attachment()) {
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
                        events_trigger('booking_deleted', $eventdata);
                    }
                    if ($this->booking->copymail) {
                        $eventdata->userto = $bookingmanager;
                        events_trigger('booking_deleted', $eventdata);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Saves the booking for the user
     * @return boolean true if booking was possible, false if meanwhile the booking got full
     */
    public function user_submit_response($user) {
        global $DB;

        if (null == $this->option) {
            return false;
        }

        $waitingList = $this->check_if_limit();

        if ($waitingList === FALSE) {
            return FALSE;
        }

        if (!($currentanswerid = $DB->get_field('booking_answers', 'id', array('userid' => $user->id, 'optionid' => $this->optionid)))) {
            $newanswer = new stdClass();
            $newanswer->bookingid = $this->id;
            $newanswer->userid = $user->id;
            $newanswer->optionid = $this->optionid;
            $newanswer->timemodified = time();
            $newanswer->timecreated = time();
            $newanswer->waitinglist = $waitingList;

            if (!$DB->insert_record("booking_answers", $newanswer)) {
                error("Could not register your booking because of a database error");
            }
            //TODO replace
            booking_check_enrol_user($this->option, $this->booking, $user->id);
        }

        $event = \mod_booking\event\bookingoption_booked::create(array(
                    'objectid' => $this->optionid,
                    'context' => context_module::instance($this->cm->id),
                    'relateduserid' => $user->id,
                    'other' => array('userid' => $user->id)
        ));
        $event->trigger();

        if ($this->booking->sendmail) {
            $eventdata = new stdClass();
            $eventdata->user = $user;
            $eventdata->booking = $this->booking;
            // TODO the next line is for backward compatibility only, delete when finished refurbishing the module ;-)
            $eventdata->booking->option[$this->optionid] = $this->option;
            $eventdata->optionid = $this->optionid;
            $eventdata->cmid = $this->cm->id;
            //TODO replace
            booking_send_confirm_message($eventdata);
        }
        return true;
    }

    /**
     * Check if user can enrol 
     * @return mixed false on full, or if can enrol or 1 for waiting list.
     */
    private function check_if_limit() {
        global $DB;

        if ($this->option->limitanswers) {
            $maxplacesavailable = $this->option->maxanswers + $this->option->maxoverbooking;
            $bookedUsers = $DB->count_records("booking_answers", array('optionid' => $this->optionid, 'waitinglist' => 0));
            $waitingUsers = $DB->count_records("booking_answers", array('optionid' => $this->optionid, 'waitinglist' => 1));
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

/**
 * Manage the view of all booking options
 * General methods for all options
 * @param cmid int coursemodule id
 */
class booking_options extends booking {

    /** @var array of users booked and on waitinglist $allbookedusers[optionid][sortnumber]->userobject
     * no user data is stored in the object only id and booking option related data such as
     * bookingvisible = true/false, booked = booked/waitinglist, optionid and bookingid */
    public $allbookedusers = array();

    /** @var array key: optionid numberofbookingsperoption */
    public $numberofbookingsperoption;

    /** @var array: config objects of options id as key */
    public $options = array();

    /** @var boolean verify booked users against canbook users yes/no */
    protected $checkcanbookusers = true;
    private $action = "showonlyactive";

    /** @var array of users filters */
    public $filters = array();
    // Pagination
    public $page = 0;
    public $perpage = 0;
    public $sort = ' ORDER BY bo.coursestarttime ASC';

    public function __construct($cmid, $checkcanbookusers = true, $urlParams = array('searchText' => '', 'searchLocation' => '', 'searchInstitution' => ''), $page = 0, $perpage = 0) {
        parent::__construct($cmid);
        $this->checkcanbookusers = $checkcanbookusers;
        $this->filters = $urlParams;
        $this->page = $page;
        $this->perpage = $perpage;
        if (isset($this->filters['sort']) && $this->filters['sort'] === 1) {
            $this->sort = ' ORDER BY bo.coursestarttime DESC';
        }
        $this->fill_options();
        $this->get_options_data();
        // call only when needed TODO
        $this->set_booked_visible_users();
        $this->add_additional_info();
    }

    private function q_params() {
        global $USER;
        $args = array();

        $conditions = " bo.bookingid = :bookingid ";
        $args['bookingid'] = $this->id;

        if (!empty($this->filters['searchText'])) {
            $conditions .= " AND bo.text LIKE :text ";
            $args['text'] = '%' . $this->filters['searchText'] . '%';
        }

        if (!empty($this->filters['searchLocation'])) {
            $conditions .= " AND bo.location LIKE :location ";
            $args['location'] = '%' . $this->filters['searchLocation'] . '%';
        }

        if (!empty($this->filters['searchInstitution'])) {
            $conditions .= " AND bo.institution LIKE :institution ";
            $args['institution'] = '%' . $this->filters['searchInstitution'] . '%';
        }

        $left = " FROM {booking_options} AS bo WHERE ";
        
        if (isset($this->filters['action'])) {
            switch ($this->filters['action']) {
                case 'mybooking':
                    $left = " FROM {booking_options} AS bo LEFT JOIN {booking_answers} AS ba ON bo.id = ba.optionid WHERE ";
                    $conditions .= " AND ba.userid = " . $USER->id . " ";
                    break;

                case 'showall':
                    break;

                case 'showonlyone':
                    $conditions .= " AND bo.id = :optionid ";
                    $args['optionid'] = $this->filters['optionid'];
                    break;

                case 'showactive':
                    $conditions .= " AND (bo.coursestarttime > " . time() . " OR bo.coursestarttime = 0) ";
                    break;

                default:
                    break;
            }
        }

        $sql = $left . " {$conditions} {$this->sort}";
        
        return array('sql' => $sql, 'args' => $args);
    }

    private function fill_options() {
        global $DB;

        $options = $this->q_params();
        $this->options = $DB->get_records_sql('SELECT bo.* ' . $options['sql'], $options['args'], $this->perpage * $this->page, $this->perpage);
    }

    public function apply_tags() {
        parent::apply_tags();

        $tags = new booking_tags($this->cm);

        foreach ($this->options as $key => $value) {
            $this->options[$key] = $tags->optionReplace($this->options[$key]);
        }
    }

    // Count, how man options...for pagination.
    public function count() {
        global $DB;

        $options = $this->q_params();
        $count = $DB->get_record_sql('SELECT COUNT(*) AS count ' . $options['sql'], $options['args']);

        return (int) $count->count;
    }

    // Add additional info to options (status, availspaces, taken, ...)
    private function add_additional_info() {
        global $DB;

        $answers = $DB->get_records('booking_answers', array('bookingid' => $this->id), 'id');
        $allresponses = array();
        $mainuserfields = user_picture::fields('u', NULL);
        $allresponses = get_users_by_capability($this->context, 'mod/booking:choose', $mainuserfields . ', u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);

        foreach ($this->options as $option) {

            $count = $DB->get_record_sql('SELECT COUNT(*) AS count FROM {booking_answers} WHERE optionid = :optionid', array('optionid' => $option->id));
            $option->count = (int) $count->count;

            if (!$option->coursestarttime == 0) {
                $option->coursestarttimetext = userdate($option->coursestarttime, get_string('strftimedatetime'));
            } else {
                $option->coursestarttimetext = get_string("starttimenotset", 'booking');
            }

            if (!$option->courseendtime == 0) {
                $option->courseendtimetext = userdate($option->courseendtime, get_string('strftimedatetime'), '', false);
            } else {
                $option->courseendtimetext = get_string("endtimenotset", 'booking');
            }

            // we have to change $taken is different from booking_show_results
            $answerstocount = array();
            if ($answers) {
                foreach ($answers as $answer) {
                    if ($answer->optionid == $option->id && isset($allresponses[$answer->userid])) {
                        $answerstocount[] = $answer;
                    }
                }
            }
            $taken = count($answerstocount);
            $totalavailable = $option->maxanswers + $option->maxoverbooking;
            if (!$option->limitanswers) {
                $option->status = "available";
                $option->taken = $taken;
                $option->availspaces = "unlimited";
            } else {
                if ($taken < $option->maxanswers) {
                    $option->status = "available";
                    $option->availspaces = $option->maxanswers - $taken;
                    $option->taken = $taken;
                    $option->availwaitspaces = $option->maxoverbooking;
                } elseif ($taken >= $option->maxanswers && $taken < $totalavailable) {
                    $option->status = "waitspaceavailable";
                    $option->availspaces = 0;
                    $option->taken = $option->maxanswers;
                    $option->availwaitspaces = $option->maxoverbooking - ($taken - $option->maxanswers);
                } elseif ($taken >= $totalavailable) {
                    $option->status = "full";
                    $option->availspaces = 0;
                    $option->taken = $option->maxanswers;
                    $option->availwaitspaces = 0;
                }
            }
            if (time() > $option->bookingclosingtime and $option->bookingclosingtime != 0) {
                $option->status = "closed";
            }
            if ($option->bookingclosingtime) {
                $option->bookingclosingtime = userdate($option->bookingclosingtime, get_string('strftimedate'), '', false);
            } else {
                $option->bookingclosingtime = false;
            }
        }
    }

    /**
     * Gives a list of booked users sorted in an array by booking option
     * former get_spreadsheet_data
     * @return void
     */
    public function get_options_data() {
        global $DB;

        $context = $this->context;
        /// bookinglist $bookinglist[optionid][sortnumber] = userobject;
        $bookinglist = array();
        $optionids = array();
        $totalbookings = array();

        ///TODO from 2.6 on use  get_all_user_name_fields() instead of user_picture
        $mainuserfields = user_picture::fields('u', null);
        $sql = "SELECT ba.id as answerid, $mainuserfields, ba.optionid, ba.bookingid, ba.userid, ba.timemodified, ba.completed, ba.timecreated, ba.waitinglist
        FROM {booking_answers} ba
        JOIN {user} u
        ON ba.userid = u.id 
        WHERE u.deleted = 0 
        AND ba.bookingid = ?
        ORDER BY ba.optionid, ba.timemodified ASC";
        $rawresponses = $DB->get_records_sql($sql, array($this->id));
        if ($rawresponses) {
            if ($this->checkcanbookusers) {
                if (empty($this->canbookusers)) {
                    $this->get_canbook_userids();
                }
                foreach ($rawresponses as $answerid => $userobject) {
                    $sortedusers[$userobject->id] = $userobject;
                }
                $validresponses = array_intersect_key($sortedusers, $this->canbookusers);
            } else {
                $validresponses = $rawresponses;
            }
            foreach ($validresponses as $response) {
                $bookinglist[$response->optionid][] = $response;
                $optionids[$response->optionid] = $response->optionid;
            }
            foreach ($optionids as $optionid) {
                $totalbookings[$optionid] = count($bookinglist[$optionid]);
            }
        }
        $this->allbookedusers = $bookinglist;
        $this->sort_bookings();
        $this->numberofbookingsperoption = $totalbookings;
    }

    /**
     * sorts booking options in booked users and waitinglist users
     * adds the status to userobject
     */
    public function sort_bookings() {
        if (!empty($this->allbookedusers) && !empty($this->options)) {
            foreach ($this->options as $option) {
                if (!empty($this->allbookedusers[$option->id])) {
                    foreach ($this->allbookedusers[$option->id] as $rank => $userobject) {
                        $statusinfo = new stdClass();
                        $statusinfo->bookingcmid = $this->cm->id;
                        if (!$option->limitanswers) {
                            $statusinfo->booked = 'booked';
                            $userobject->status[$option->id] = $statusinfo;
                        } else {
                            if ($userobject->waitinglist) {
                                $statusinfo->booked = 'waitinglist';
                                $userobject->status[$option->id] = $statusinfo;
                            } else {
                                $statusinfo->booked = 'booked';
                                $userobject->status[$option->id] = $statusinfo;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns all bookings of $USER with status
     * @return array of [bookingid][optionid] = userobjects:
     */
    public function get_my_bookings() {
        global $USER;
        $mybookings = array();
        if (!empty($this->allbookedusers) && !empty($this->options)) {
            foreach ($this->options as $optionid => $option) {
                if (!empty($this->allbookedusers[$option->id])) {
                    foreach ($this->allbookedusers[$option->id] as $userobject) {
                        if ($userobject->id == $USER->id) {
                            $userobject->status[$option->id]->coursename = $this->course->fullname;
                            $userobject->status[$option->id]->courseid = $this->course->id;
                            $userobject->status[$option->id]->bookingtitle = $this->booking->name;
                            $userobject->status[$option->id]->bookingoptiontitle = $this->options[$option->id]->text;
                            $mybookings[$optionid] = $userobject;
                        }
                    }
                }
            }
        }
        return $mybookings;
    }

    public static function booking_set_visiblefalse(&$item1, $key) {
        $item1->bookingvisible = false;
    }

    /**
     * sets $user->bookingvisible to true or false dependant on group
     * member status and access all group capability
     */
    public function set_booked_visible_users() {
        if (!empty($this->allbookedusers)) {
            if ($this->course->groupmode == 0 || has_capability('moodle/site:accessallgroups', $this->context)) {
                foreach ($this->allbookedusers as $optionid => $optionusers) {
                    if (isset($user->status[$optionid])) {
                        foreach ($optionusers as $user) {
                            $user->status[$optionid]->bookingvisible = true;
                        }
                    }
                }
            } else if (!empty($this->groupmembers)) {
                foreach ($this->allbookedusers as $optionid => $bookedusers) {
                    foreach ($bookedusers as $user) {
                        if (in_array($user->id, array_keys($this->groupmembers))) {
                            $user->status[$optionid]->bookingvisible = true;
                        } else {
                            $user->status[$optionid]->bookingvisible = false;
                        }
                    }
                }
            } else {
                //empty -> all invisible
                foreach ($this->allbookedusers as $optionid => $optionusers) {
                    array_walk($optionusers, 'self::booking_set_visiblefalse');
                }
            }
        }
    }

}

/**
 * Abstract class used by booking subscriber selection controls
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_user_selector_base extends user_selector_base {

    /**
     * The id of the booking this selector is being used for
     * @var int
     */
    protected $bookingid = null;

    /**
     * The id of the current option
     * @var int
     */
    protected $optionid = null;

    /**
     * The potential users array
     * @var array
     */
    public $potentialusers = null;
    public $bookedvisibleusers = null;

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {

        $this->maxusersperpage = 200;
        parent::__construct($name, $options);

        if (isset($options['bookingid'])) {
            $this->bookingid = $options['bookingid'];
        }
        if (isset($options['potentialusers'])) {
            $this->potentialusers = $options['potentialusers'];
        }
        if (isset($options['optionid'])) {
            $this->optionid = $options['optionid'];
        }
    }

    protected function get_options() {
        $options = parent::get_options();
        $options['file'] = 'mod/booking/locallib.php';
        $options['bookingid'] = $this->bookingid;
        $options['potentialusers'] = $this->potentialusers;
        $options['optionid'] = $this->optionid;
        // Add our custom options to the $options array.
        return $options;
    }

    /**
     * Sets the existing subscribers
     * @param array $users
     */
    public function set_potential_users(array $users) {
        $this->potentialusers = $users;
    }

}

/**
 * User selector for booking other users
 */
class booking_potential_user_selector extends booking_user_selector_base {

    public $potentialusers;

    public function __construct($name, $options) {
        $this->potentialusers = $options['potentialusers'];
        parent::__construct($name, $options);
    }

    public function find_users($search) {
        global $DB, $USER;
        // remove booked users and current user from available users

        $bookedusers = $DB->get_fieldset_select('booking_answers', 'userid', "optionid = $this->optionid AND bookingid = $this->bookingid");
        $bookedusers[] = $USER->id;
        $this->exclude($bookedusers);

        $fields = "SELECT " . $this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');


        if (!empty($this->potentialusers)) {
            $availableuserssql = implode(',', array_keys($this->potentialusers));
        } else {
            return array();
        }

        $sql = " FROM {user} u
        WHERE u.id IN ($availableuserssql) AND
        $searchcondition";
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $searchparams);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($searchparams, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($search) {
            $groupname = get_string('enrolledusersmatching', 'enrol', $search);
        } else {
            $groupname = get_string('enrolledusers', 'enrol');
        }

        return array($groupname => $availableusers);
    }

}

/**
 * User selector control for removing booked users
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_existing_user_selector extends booking_user_selector_base {

    /**
     * Finds all booked users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        // only active enrolled or everybody on the frontpage
        $fields = "SELECT " . $this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;
        if (!empty($this->potentialusers)) {
            $subscriberssql = implode(',', array_keys($this->potentialusers));
        } else {
            return array();
        }


        $sql = " FROM {user} u
        WHERE u.id IN ($subscriberssql) AND
        $searchcondition
        ";

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $searchparams);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($searchparams, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        return array(get_string("booked", 'booking') => $availableusers);
    }

}

/**
 * Utils
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
class booking_utils {

    /**
     * Create or update new group and return id of group.
     *
     * @param object $booking
     * @param object $option
     * @return int
     */
    public function group($bookingtmp = NULL, $optiontmp = NULL) {
        global $DB;

        $booking = clone $bookingtmp;
        $option = clone $optiontmp;

        if ($booking->addtogroup == 1 && $option->courseid > 0) {

            $cm = get_coursemodule_from_instance('booking', $booking->id);

            $tags = new booking_tags($cm);
            $booking = $tags->bookingReplace($booking);
            $option = $tags->optionReplace($option);
            $newGroupData = new stdClass();

            if (isset($option->id)) {
                $groupid = $DB->get_field('booking_options', 'groupid', array('id' => $option->id));

                if (!is_null($groupid) && ($groupid > 0)) {
                    $newGroupData->id = $groupid;
                }

                $newGroupData->courseid = $option->courseid;
                $newGroupData->name = $booking->name . ' - ' . $option->text . ' - ' . $option->id;
                $newGroupData->description = $booking->name . ' - ' . $option->text;
                $newGroupData->descriptionformat = FORMAT_HTML;

                if (isset($newGroupData->id)) {
                    groups_update_group($newGroupData);
                    return $newGroupData->id;
                } else {
                    return groups_create_group($newGroupData);
                }
            }
        } else {
            return 0;
        }
    }

}

/**
 * Tags templates
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
class booking_tags {

    public $cm;
    public $tags;
    public $replaces;
    public $optionsChangeText = array('text', 'description', 'location', 'institution', 'address');
    public $bookingChangeText = array('name', 'intro', 'bookingpolicy', 'bookedtext', 'waitingtext', 'statuschangetext', 'deletedtext', 'duration', 'organizatorname', 'pollurltext', 'eventtype', 'notificationtext', 'userleave', 'pollurlteacherstext');
    private $option;

    public function __construct($cm) {
        global $DB;

        $this->cm = $cm;
        $this->tags = $DB->get_records('booking_tags', array('courseid' => $this->cm->course));
        $this->replaces = $this->prepare_replaces();
    }

    public function get_all_tags() {
        return $this->tags;
    }

    private function prepare_replaces() {

        $keys = array();
        $values = array();

        foreach ($this->tags as $tag) {
            $keys[] = "[{$tag->tag}]";
            $values[] = $tag->text;
        }

        return array('keys' => $keys, 'values' => $values);
    }

    public function getReplaces() {
        return $this->replaces;
    }

    public function tag_replaces($text) {
        return str_replace($this->replaces['keys'], $this->replaces['values'], $text);
    }

    public function bookingReplace($bookingtmp = NULL) {
        $booking = clone $bookingtmp;
        foreach ($booking as $key => $value) {
            if (in_array($key, $this->bookingChangeText)) {
                $booking->{$key} = $this->tag_replaces($booking->{$key});
            }
        }

        return $booking;
    }

    public function optionReplace($option = NULL) {
        $this->option = clone $option;
        foreach ($this->option as $key => $value) {
            if (in_array($key, $this->optionsChangeText)) {
                $this->option->{$key} = $this->tag_replaces($this->option->{$key});
            }
        }

        return $this->option;
    }

}
