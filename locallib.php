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

    /** @var array users who have capability to book */
    protected  $canbookusers = array();

    /** @var array users who are members of the current users group */
    public $groupmembers = array();


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
        $this->course = $DB->get_record('course', array('id' => $this->cm->course), '*', MUST_EXIST);
        $this->booking = $DB->get_record("booking", array("id" => $this->id));
        //TODO: for 2.6 replace user_picture::fields(); with get_all_user_name_fields()
        $mainuserfields = user_picture::fields();
        $this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose', $mainuserfields . ', u.id', 'u.lastname ASC, u.firstname ASC', '', '', '', '', true, true);
        // if the course has groups and I do not have the capability to see all groups, show only users of my groups
        if($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)){
            $this->groupmembers = $this::booking_get_groupmembers($this->course->id);
        }
    }

    public static function booking_get_groupmembers($courseid){
        global $USER;
        $groupmembers = array();
        $usergroups = groups_get_all_groups($courseid,$USER->id);
        if(!empty($usergroups)){
            foreach($usergroups as $usergroup){
                $usergroupmembers[$usergroup->id] = groups_get_members($usergroup->id, 'u.id, u.firstname, u.lastname, u.email');
            }
            foreach ($usergroupmembers as $groups){
                if(!empty($groups)){
                    foreach($groups as $group){
                        $groupmembers[$group->id] = $group;
                    }
                }
            }
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

    /** @var array the users booked for this option */
    public $bookedusers = array();

    /** @var array of booked users visible to the current user (group members) */
    public $bookedvisibleusers = array();

    /** @var array of users that can be subscribed to that booking option if groups enabled, only members of groups user has access to are shown */
    public $potentialusers = array();

    public $optionid = null;

    /** @var booking option confix object */
    public $option = null;

    public function __construct($id, $optionid, $option = null){
        global $DB;

        parent::__construct($id);
        $this->optionid = $optionid;
        $this->update_booked_users();
        if(!$option != null){
            $this->option =  $option;
        } else {
            $this->option = $DB->get_record('booking_options',array('id' => $optionid));
        }
    }

    public function update_booked_users(){
        global $DB;

        $select = "bookingid = $this->id AND optionid =  $this->optionid";

        $this->bookedusers = $DB->get_records_select('booking_answers',$select,array(),'','userid');
        if(!empty($this->groupmembers) && !(has_capability('moodle/site:accessallgroups', $this->context))){
            $this->bookedvisibleusers = array_intersect_key($this->bookedusers,$this->groupmembers);
            $canbookgroupmembers = array_intersect_key($this->canbookusers,$this->groupmembers);
            $this->potentialusers = array_diff_key($canbookgroupmembers,$this->bookedusers);
        } else if(has_capability('moodle/site:accessallgroups', $this->context)) {
            $this->bookedvisibleusers = $this->bookedusers;
            $this->potentialusers = array_diff_key($this->canbookusers, $this->bookedusers);
        }
    }
}

/**
 * Manage the view of all booking options
 * General methods for all options
 * @param cmid int coursemodule id
 */
class booking_options extends booking {

    /** @var array of users booked and on waitinglist $allbookedusers[optionid][sortnumber]->userobject */
    public $allbookedusers = array();

    /** @var array key: optionid numberofbookingsperoption */
    public $numberofbookingsperoption;

    /** @var config objects of options id as key */
    public $options = array();

    public function __construct($cmid){
        global $DB;

        parent::__construct($cmid);
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
         
        /// First get all the users who have access here
        $allresponses = $this->canbookusers;

        /// Get all the recorded responses for this booking
        $rawresponses = $DB->get_records('booking_answers', array('bookingid' => $this->id), "optionid, timemodified ASC",'id,optionid,userid');
        //$optionids = $DB->get_records_select('booking_options', "bookingid = $this->id",array(),'id','id');
        /// Use the responses to move users into the correct column
         
        if ($rawresponses) {
            foreach ($rawresponses as $response) {
                if (isset($allresponses[$response->userid])) {   // This person is enrolled and in correct group
                    $bookinglist[$response->optionid][] = clone($allresponses[$response->userid]);
                    $optionids[$response->optionid] = $response->optionid;
                }
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
     * @return associative array regular, waitinglist
     */
    public function sort_bookings(){
        if(!empty($this->allbookedusers) && !empty($this->options)){
            foreach($this->options as $option){
                if(!empty($this->allbookedusers[$option->id])){
                    foreach($this->allbookedusers[$option->id] as $rank => $userobject){
                        if(!$option->limitanswers){
                            $userobject->status = 'booked';
                        }
                        $userobject->optionid = $option->id;
                        $userobject->bookingid = $option->bookingid;
                        if ($option->maxanswers < ($rank + 1) &&  $rank + 1 <= ($option->maxanswers + $option->maxoverbooking) ){
                            $userobject->status = 'waitinglist';
                        } else if ($rank + 1 <= $option->maxanswers) {
                            $userobject->status = 'booked';
                        }
                    }
                }
            }
        }
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
                        $user->bookingvisible = true;
                    }
                }
            } else if(!empty($this->groupmembers)){
                foreach ($this->allbookedusers as $optionid => $bookedusers){
                    foreach($bookedusers as $user){
                        if(in_array($user->id, array_keys($this->groupmembers))){
                            $user->bookingvisible = true;
                        } else {
                            $user->bookingvisible = false;
                        }
                    }
                }
            } else {
                //empty -> all invisible
                foreach($this->allbookedusers as $optionid => $optionusers){
                    foreach($optionusers as $user){
                        $user->bookingvisible = false;
                    }
                }           
             }
        }
    }
}

/**
 * Collects all booked options by a user and
 * the bookings of group members, with the right
 * to see bookings of other users for the whole moodle instance
 */
class booking_all_bookings {

    /** @var booking instances user has access. key: courseid */
    protected $bookinginstances = array();

    /** @var courses user has access to with booking instances */
    protected $usercourses = array();

    /** courses with bookings */
    protected $courseswithbookings = array();

    //booking instances of $USER
    protected $mybookinginstances = array();

    /** @var array of booking instances with subscribe other users prvilige key: bookingid */
    protected $subscribeprivilegeinstances = array();

    /** @var bookings of the current user (still TODO) */
    public $mybookings = array();

    /** @var booking instances with booking data where $USER has cap mod/booking:subscribeusers
     * key: bookingid */
    public $allbookings = array();


    public function __construct(){
        global $USER;

        //$courseids = get_user_capability_course('moodle/course:view', $USER->id); this function apparently does not work at all
        $this->usercourses = enrol_get_all_users_courses($USER->id, 'sortorder ASC');
        $bookings = get_all_instances_in_courses('booking', $this->usercourses);
        foreach ($bookings as $booking){
            $this->mybookinginstances[$booking->id] = $booking;
            if(has_capability('mod/booking:subscribeusers', context_module::instance($booking->coursemodule))){
                $this->subscribeprivilegeinstances[$booking->id] = $booking;
                $this->courseswithbookings[$booking->course] = $booking->course;
            }
        }
    }
    /**
     * get all booking data from booking instances where $USER has the cap mod/booking:subscribeusers
     */
    public function get_all_bookings_visible(){
        $bookinginstances = $this->subscribeprivilegeinstances;
        if(!empty($this->subscribeprivilegeinstances)){
            foreach($bookinginstances as $bookinginstance){
                $this->allbookings[$bookinginstance->id] = new booking_options($bookinginstance->coursemodule);
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
        foreach($this->allbookings as $bookingid => $booking){
            if($booking->course->id != $courseid){
                unset($allbookings[$bookingid]);
            }
        }
        return $allbookings;
    }

    protected function sort_bookings_per_user(){
        $userstoprint = array();
        if(empty($this->allbookings)){
            $this->get_all_bookings_visible();
        }
        foreach($this->allbookings as $bookingid => $bookingoptionswithdata){
            foreach($bookingoptionswithdata->allbookedusers as $optionid => $user){
                    foreach($user as $rank => $user){
                        $user->courseid = $bookingoptionswithdata->course->id;
                        $user->coursename = $bookingoptionswithdata->course->fullname;
                        $user->bookingtitle = $bookingoptionswithdata->booking->name;
                        $user->bookingoptiontitle = $bookingoptionswithdata->options[$optionid]->text;
                        $userstoprint[$user->id][$optionid] = $user;
                    }
            }
            return $userstoprint;
        }
    }

    /**
     * Display all bookings of the moodle instance
     * @param sort null for default sorting by course or 'user'
     * @return rendered html
     */
    public function display($sort = null){
        global $PAGE;
        $output = '';
        $renderer = $PAGE->get_renderer('mod_booking');
        if ($sort == 'user') {
            $userstorender = $this->sort_bookings_per_user();
            $output .= $renderer->render_bookings_per_user($userstorender);
            return $output;
        }
        if(!empty($this->courseswithbookings)){
            foreach($this->courseswithbookings as $courseid){
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