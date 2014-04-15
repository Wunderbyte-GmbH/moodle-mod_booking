<?php
require_once($CFG->dirroot . '/user/selector/lib.php');

/**
 * Standard base class for mod_booking
 *
 *Module was originally programmed for 1.9 but further adjustments should be made with
 *new Moodle 2.X coding style using this base class.
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
    protected  $context = null;

    /** @var stdClass the course this booking instance belongs to */
    public   $course = null;

    /** @var stdClass the course module for this assign instance */
    public  $cm = null;

    /** @var array of user objects who have capability to book. object contains only id */
    public  $canbookusers = array();

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
        if($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)){
            $this->groupmembers = $this::booking_get_groupmembers($this->course->id);
        }
    }
    
    /**
     * get all the user ids who are allowed to book
     * capability mod/booking:choose
     * available in $htis->canbookusers
     */
    public function get_canbook_userids(){
        //TODO check if course has guest access if not get all enrolled users and check with has_capability if user has right to book
        //$this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose', 'u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);
        $this->canbookusers = get_enrolled_users($this->context, 'mod/booking:choose',null,'u.id');
    }
    
    /**
     * get all group members of $USER (of all groups $USER belongs to)
     * 
     * @param int $courseid
     * @return array: all members of all groups $USER belongs to
     */
    public static function booking_get_groupmembers($courseid){
        global $USER, $DB;
        $groupmembers = array();
        $usergroups = groups_get_all_groups($courseid,$USER->id);

        if(!empty($usergroups)){
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

    /**
     * Creates basic booking option
     *
     * @param int $id cm id
     * @param int $optionid
     * @param object $option option object
     */
    public function __construct($id, $optionid){
        global $DB;

        parent::__construct($id);
        $this->optionid = $optionid;
        $this->update_booked_users();
        $this->option = $DB->get_record('booking_options',array('id' => $optionid),'*','MUST_EXIST');
    }
    
    /**
     * Updates canbookusers and bookedusers does not check the status (booked or waitinglist)
     * Just gets the registered booking from database
     * Calculates the potential users (bookers able to book, but not yet booked)
     */
    public function update_booked_users(){
        global $DB;
        $select = "bookingid = $this->id AND optionid =  $this->optionid";
        if(empty($this->canbookusers)){
            $this->get_canbook_userids();
        }

        $mainuserfields = user_picture::fields('u',null);
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
        $allanswers = $DB->get_records_sql($sql,$params);
        $this->bookedusers = array_intersect_key($allanswers, $this->canbookusers);
        // TODO offer users with according caps to delete excluded users from booking option
        //$excludedusers =  array_diff_key($allanswers, $this->canbookusers);
        $this->numberofanswers = count($this->bookedusers);
        if(!empty($this->groupmembers) && !(has_capability('moodle/site:accessallgroups', $this->context))){
            $this->bookedvisibleusers = array_intersect_key($this->bookedusers,$this->groupmembers);
            $canbookgroupmembers = array_intersect_key($this->canbookusers,$this->groupmembers);
            $this->potentialusers = array_diff_key($canbookgroupmembers,$this->bookedusers);
        } else if(has_capability('moodle/site:accessallgroups', $this->context)) {
            $this->bookedvisibleusers = $this->bookedusers;
            $this->potentialusers = array_diff_key($this->canbookusers, $this->bookedusers);
        }
        $this->sort_answers();
    }
    
    /**
     * add booked/waitinglist info to each userobject of users
     */
    public function sort_answers(){
        if(!empty($this->bookedusers) && null != $this->option){
            foreach($this->bookedusers as $rank => $userobject){
                $userobject->bookingcmid = $this->cm->id;
                if(!$this->option->limitanswers){
                    $userobject->booked = 'booked';
                }
                // rank starts at 0 so add + 1 to corespond to max answer settings
                if ($this->option->maxanswers < ($rank + 1) &&  $rank + 1 <= ($this->option->maxanswers + $this->option->maxoverbooking) ){
                    $userobject->booked = 'waitinglist';
                } else if ($rank + 1 <= $this->option->maxanswers) {
                    $userobject->booked = 'booked';
                }
            }
        }
    }

    /**
     * Saves the booking for the user
     * @return boolean true if booking was possible, false if meanwhile the booking got full
     */
    public function user_submit_response($user) {
        global $DB;
        $context = $this->context;
        $option = $this->option;
        if(null == $option){
            return false;
        }
        if($option->limitanswers) {
            $maxplacesavailable = $this->option->maxanswers + $this->option->maxoverbooking;
            
            // retrieve all answers for this option ID
            // if answers for one option are limited and total answers are not exceeded then
            if (!($this->numberofanswers >= $maxplacesavailable)) {
                // check if actual answer is also already made by this user
                //check directly in database, as some time may have passed since array of booked users was created
                if(!($currentanswerid = $DB->get_field('booking_answers','id', array('userid' => $user->id, 'optionid' => $this->optionid)))){
                    $newanswer = new stdClass();
                    $newanswer->bookingid = $this->id;
                    $newanswer->userid = $user->id;
                    $newanswer->optionid = $this->optionid;
                    $newanswer->timemodified = time();
                    if (!$DB->insert_record("booking_answers", $newanswer)) {
                        error("Could not register your booking because of a database error");
                    }
                    //TODO replace
                    booking_check_enrol_user($this->option, $this->booking, $user->id);
                }
                add_to_log($this->cm->course, "booking", "choose", "view.php?id=".$this->cm->id, $this->id, $this->cm->id);
                if ($this->booking->sendmail){
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
            } else { 
                return false;
            }
        } else {
            if(!($currentanswerid = $DB->get_field('booking_answers','id', array('userid' => $user->id, 'optionid' => $this->optionid)))){
                $newanswer = new stdClass();
                $newanswer->bookingid = $this->id;
                $newanswer->userid = $user->id;
                $newanswer->optionid = $this->optionid;
                $newanswer->timemodified = time();
                if (!$DB->insert_record("booking_answers", $newanswer)) {
                    error("Could not register your booking because of a database error");
                }
                booking_check_enrol_user($this->option, $this->booking, $user->id);
            }
            add_to_log($this->cm->course, "booking", "choose", "view.php?id=$cm->id", $booking->id, $cm->id);
            if ($this->booking->sendmail){
                booking_send_confirm_message($eventdata);
            }
            return true;
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

    public function __construct($cmid,$checkcanbookusers = true){
        global $DB;

        parent::__construct($cmid);
        $this->checkcanbookusers = $checkcanbookusers;
        $this->options = $DB->get_records('booking_options',array('bookingid' => $this->id));
        $this->get_options_data();
        // call only when needed TODO
        $this->set_booked_visible_users();
    }

    /**
     * Gives a list of booked users sorted in an array by booking option
     * former get_spreadsheet_data
     * @return void
     */
    public function get_options_data() {
        global $DB;

        //	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
        $context = $this->context;
        /// bookinglist $bookinglist[optionid][sortnumber] = userobject;
        $bookinglist = array();
        $optionids = array();
        $totalbookings = array();
        
        ///TODO from 2.6 on use  get_all_user_name_fields() instead of user_picture
        $mainuserfields = user_picture::fields('u',null);
        $sql = "SELECT ba.id as answerid, $mainuserfields, ba.optionid, ba.bookingid
        FROM {booking_answers} ba
        JOIN {user} u
        ON ba.userid = u.id 
        WHERE u.deleted = 0 
        AND ba.bookingid = ?
        ORDER BY ba.optionid, ba.timemodified ASC";
        $rawresponses = $DB->get_records_sql($sql, array($this->id));
        if ($rawresponses) {
            if($this->checkcanbookusers){
                if(empty($this->canbookusers)){
                    $this->get_canbook_userids();
                }
                foreach($rawresponses as $answerid => $userobject){
                    $sortedusers[$userobject->id] = $userobject;
                }
               $validresponses = array_intersect_key($sortedusers, $this->canbookusers);
               
            } else {
                $validresponses = $rawresponses;
            }
            foreach ($validresponses as &$response) {
                    $bookinglist[$response->optionid][] = $response;
                    $optionids[$response->optionid] = $response->optionid;
            }
            foreach ($optionids as $optionid){
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
    public function sort_bookings(){
        if(!empty($this->allbookedusers) && !empty($this->options)){
            foreach($this->options as $option){
                if(!empty($this->allbookedusers[$option->id])){
                    foreach($this->allbookedusers[$option->id] as $rank => $userobject){
                        $statusinfo = new stdClass();
                        $statusinfo->bookingcmid = $this->cm->id;
                        if(!$option->limitanswers){
                            $statusinfo->booked = 'booked';
                            $userobject->status[$option->id] = $statusinfo;
                        }
                        if ($option->maxanswers < ($rank + 1) &&  $rank + 1 <= ($option->maxanswers + $option->maxoverbooking) ){
                            $statusinfo->booked = 'waitinglist';
                            $userobject->status[$option->id] = $statusinfo;
                        } else if ($rank + 1 <= $option->maxanswers) {
                            $statusinfo->booked = 'booked';
                            $userobject->status[$option->id] = $statusinfo;
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
    public function get_my_bookings(){
        global $USER;
        $mybookings = array();
        if(!empty($this->allbookedusers) && !empty($this->options)){
            foreach($this->options as $optionid => $option){
                if(!empty($this->allbookedusers[$option->id])){
                    foreach($this->allbookedusers[$option->id] as $userobject){
                        if($userobject->id == $USER->id){
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
    
    public static function booking_set_visiblefalse(&$item1, $key){
        $item1->bookingvisible = false;
    }
    
    /**
     * sets $user->bookingvisible to true or false dependant on group
     * member status and access all group capability
     ´     */
    public function set_booked_visible_users(){
        if(!empty($this->allbookedusers)){
            if($this->course->groupmode == 0 || has_capability('moodle/site:accessallgroups', $this->context)){
                foreach($this->allbookedusers as $optionid => $optionusers){
                    foreach($optionusers as $user){
                        $user->status[$optionid]->bookingvisible = true;
                    }
                }
            } else if(!empty($this->groupmembers)){
                foreach ($this->allbookedusers as $optionid => $bookedusers){
                    foreach($bookedusers as $user){
                        if(in_array($user->id, array_keys($this->groupmembers))){
                            $user->status[$optionid]->bookingvisible = true;
                        } else {
                            $user->status[$optionid]->bookingvisible = false;
                        }
                    }
                }
            } else {
                //empty -> all invisible
                foreach($this->allbookedusers as $optionid => $optionusers){
                    array_walk($optionusers,'self::booking_set_visiblefalse');
                }
            }
        }
    }
}

/**
 * Collects all booked options by a user and
 * the bookings of group members, with the right
 * to see bookings of other users for the whole moodle instance
 * TODO: Performance of my bookings, improve overall performance without using booking object, reduce SQl queries
 */
class booking_all_bookings {

    /** @var courses user has access to with booking instances */
    protected $usercourses = array();

    /** @var array courses with booking instances; multidimensional array 
     * [courseid] = array(bookingid1,bookingid2) array of bookingids as values of 2nd array */
    protected $courseswithbookings = array();

    /** @var array of instances of the module booking where $USER has access to, key: bookingid */
    protected $mybookinginstances = array();

    /** @var array of booking instances with subscribe other users prvilige key: bookingid */
    protected $subscribeprivilegeinstances = array();
    
    /** @var array of booking ids, with a response */
    protected $bookingidswithresponse = array();

    /** @var bookings of the current user (still TODO) */
    public $mybookings = array();

    /** @var booking instances with booking data where $USER has cap mod/booking:subscribeusers
     * [bookingid][optionid] = user */
    public $allbookings = array();


    public function __construct(){
        global $USER, $DB;
        //$courseids = get_user_capability_course('moodle/course:view', $USER->id); this function apparently does not work at all
        if(has_capability('moodle/site:config', context_system::instance())){
            $sql =    "SELECT cm.instance, cm.id AS coursemodule, m.*, cw.section, cm.visible AS visible,
                    cm.groupmode, cm.groupingid, cm.groupmembersonly, cm.course
                    FROM {course_modules} cm, {modules} md, {booking} m, {course_sections} cw
                    WHERE md.name = 'booking' AND
                    cm.instance = m.id AND
                    cm.section = cw.id AND
                    md.id = cm.module";
            $this->subscribeprivilegeinstances = $DB->get_records_sql($sql);
            $this->mybookinginstances = &$this->subscribeprivilegeinstances;
            foreach ($this->subscribeprivilegeinstances as $bookinginstance){
                $this->courseswithbookings[$bookinginstance->course][] = $bookinginstance->instance;
            }
        } else {
            //enrol_get_users_courses($userid, sortorder ASC');
            $this->usercourses = enrol_get_all_users_courses($USER->id, 'sortorder ASC');
            $bookings = get_all_instances_in_courses('booking', $this->usercourses);
            foreach ($bookings as $booking){
                $this->mybookinginstances[$booking->id] = $booking;
                if(has_capability('mod/booking:subscribeusers', context_module::instance($booking->coursemodule))){
                    $this->subscribeprivilegeinstances[$booking->id] = $booking;
                    $this->courseswithbookings[$booking->course][] = $booking->id;
                }
            }
        }
    }
    
    /**
     * get all booking data from booking instances
     * save all booking data to display in $this->allbookings
     */
    public function get_all_bookings_visible() {
        $bookinginstances = $this->subscribeprivilegeinstances;
        if (!empty( $this->subscribeprivilegeinstances )) {
            foreach( $bookinginstances as $bookinginstance ) {
                $this->allbookings[$bookinginstance->id] = new booking_options($bookinginstance->coursemodule,false);
            }
        }
    }
    
    
    /**
     * returns all bookings, where responses are present
     * @return array [bookingid]
     */
    public function get_all_bookinginstances_with_responses(){
        global $DB;
        $bookinginstances = $this->mybookinginstances;
        if(!empty($this->mybookinginstances)){
            $bookingids = array_keys($bookinginstances);
            $bookingidsstring = implode(',', $bookingids);
            $sql = "SELECT ba.bookingid, count(ba.bookingid)
                    FROM {booking_answers} AS ba
                    GROUP BY ba.bookingid
		           ";
            $bookingresponses = $DB->get_records_sql($sql);
            if(!empty($bookingresponses)){
                return array_keys($bookingresponses);
            } else {
                return array();
            }
        } else {
            return array();
        }
    }
    
    /**
     * retrieves all responses of $USER and sorts them (waitinglist or booked)
     */
    public function get_my_responses() {
        global $DB, $USER;
        $sql = "SELECT ba.id baid, ba.optionid, ba.bookingid
            FROM {booking_answers} AS ba
            WHERE ba.userid = " . $USER->id . "
            ";
        $answers = $DB->get_records_sql ( $sql );
        if (! empty ( $answers )) {
            foreach ( $answers as $answer ) {
                $bookingids [$answer->bookingid] = $answer->bookingid;
            }
            foreach ( $bookingids as $bookingid ) {
                $cm = get_coursemodule_from_instance( 'booking', $bookingid );
                $bookinginstances[$bookingid] = new booking_options( $cm->id, false );
                $this->mybookings[$bookingid] = $bookinginstances[$bookingid]->get_my_bookings ();
            }
        }
    }
    
    /**
     * given the courseid, returns all elements of $this->allbookings
     * that belong to a single course
     * return array of booking objects with bookingids as key;
     */
    protected function all_bookings_of_course($courseid){
        $allbookings = $this->allbookings;
        foreach($allbookings as $bookingid => $booking){
            if($booking->course->id != $courseid){
                unset($allbookings[$bookingid]);
            }
        }
        return $allbookings;
    }
    
    /**
     * removes empty booking instances from $this->allbookings
     */
    protected function remove_empty_booking_instances(){
        $emptybookings = array_diff(array_keys($this->allbookings),$this->bookingidswithresponse);
        foreach($emptybookings as $bookingid){
            unset($this->allbookings[$bookingid]);
        }
        foreach($this->allbookings as $bookingid => &$bookinginstance){
            $nouservisible = true;
            foreach($bookinginstance->allbookedusers as $optionid => $users){
                foreach($users as $sortorder => $user){
                    if($user->status[$optionid]->bookingvisible){
                        $nouservisible = false;
                    }
                }
            }
            if($nouservisible){
                unset($this->allbookings[$bookingid]);
            }
        }
    }

    /**
     * Prepares user object for rendering 
     * adding course and booking information to userobject
     *
     * @param int $userid
     * @return array of user objects to be rendered
     */
    protected function sort_bookings_per_user(){
        $userstoprint = array();
        if(empty($this->allbookings)){
            $this->get_all_bookings_visible();
        }
        foreach($this->allbookings as $bookingid => $bookingoptionswithdata){
            foreach($bookingoptionswithdata->allbookedusers as $optionid => $alluserofoption){
                foreach($alluserofoption as $rank => $user){
                        $user->status[$optionid]->courseid = $bookingoptionswithdata->course->id;
                        $user->status[$optionid]->coursename = $bookingoptionswithdata->course->fullname;
                        $user->status[$optionid]->bookingtitle = $bookingoptionswithdata->booking->name;
                        $user->status[$optionid]->bookingoptiontitle = $bookingoptionswithdata->options[$optionid]->text;
                        $userstoprint[$user->id][$optionid] = $user;
                }
            }
        }
        return $userstoprint;
    }

    /**
     * Display all bookings of the moodle instance
     * @param sort null for default sorting by course or 'user'
     * @return rendered html
     */
    public function display($sort = null){
        global $PAGE, $USER;
        $boldtext = array('style' =>'font-weight: bold;');
        $attributeuser = null;
        $attributecourse = null;
        $attributemy = null;
        /** output sort links and heading */
        $url = $PAGE->url;
        switch ($sort) {
            case null:
                $attributecourse = $boldtext;
                break;
            case 'user':
                $attributeuser = $boldtext;
                break;
            case 'my':
                $attributemy = $boldtext;
                break;
        }
        if(!empty($this->subscribeprivilegeinstances)){
            $sorturl = new moodle_url($url);
            $sorturl->param('sort','user');
            echo html_writer::link($sorturl, get_string('sortbyuser', 'block_booking'),$attributeuser);
            echo html_writer::span("  //  ");
            echo html_writer::link($url,get_string('sortbycourse', 'block_booking'),$attributecourse);
            echo html_writer::span("  //  ");
        }
        $sorturl->param('sort','my');
        echo html_writer::link($sorturl,get_string('showmybookings', 'booking'),$attributemy);
        
        $this->get_all_bookings_visible();
        $output = '';
        $renderer = $PAGE->get_renderer('mod_booking');
        if ($sort === 'user') {
            $userstorender = $this->sort_bookings_per_user();
            $output .= $renderer->render_bookings_per_user($userstorender);
            return $output;
        } else if ($sort === 'my'){
            $this->get_my_responses();
            $userstorender = $this->mybookings;
            $output .= $renderer->render_bookings_per_user($userstorender);
            return $output;
        }
        if(!empty($this->courseswithbookings)){
            $this->bookingidswithresponse = $this->get_all_bookinginstances_with_responses();
            $this->remove_empty_booking_instances();
            foreach(array_keys($this->courseswithbookings) as $courseid){
                $allcoursebookings = $this->all_bookings_of_course($courseid);
                if(!empty($allcoursebookings)){
                    if(!$sort){
                        $firstelement = reset($allcoursebookings);
                        $output .= html_writer::tag('h2', $firstelement->course->fullname);
                        foreach($allcoursebookings as $booking){
                            $output .= $renderer->render_bookings($booking);
                        }
                    }
                }
            }
        }
        return $output;
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
        $options['file']    = 'mod/booking/locallib.php';
        $options['bookingid']    = $this->bookingid;
        $options['potentialusers']    = $this->potentialusers;
        $options['optionid']    = $this->optionid;
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

    public function __construct($name,$options) {
        $this->potentialusers = $options['potentialusers'];
        parent::__construct($name,$options);
    }

    public function find_users($search) {
        global $DB, $USER;
        // remove booked users and current user from available users

        $bookedusers = $DB->get_fieldset_select('booking_answers', 'userid',"optionid = $this->optionid AND bookingid = $this->bookingid");
        $bookedusers[] = $USER->id;
        $this->exclude($bookedusers);

        $fields      = "SELECT ".$this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');


        if(!empty($this->potentialusers)){
            $availableuserssql = implode(',',array_keys($this->potentialusers));
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
        $fields      = "SELECT ".$this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;
        if(!empty($this->potentialusers)){
            $subscriberssql =  implode(',',array_keys($this->potentialusers));
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