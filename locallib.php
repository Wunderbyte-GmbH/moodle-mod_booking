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
	public function __construct($id) {
		global $DB, $USER;
		$this->cm = get_coursemodule_from_id('booking', $id, 0, false, MUST_EXIST);
		$this->id = $this->cm->instance;
		$this->context = context_module::instance($this->cm->id);
		$this->course = $DB->get_record('course', array('id' => $this->cm->course), '*', MUST_EXIST);
		$this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose','u.id, u.firstname, u.lastname, u.email');
		// if the course has groups and I do not have the capability to see all groups, show only users of my groups
		if($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)){
			$usergroups = groups_get_all_groups($this->course->id,$USER->id);
			if(!empty($usergroups)){
				foreach($usergroups as $usergroup){
				    $groupmembers = groups_get_members($usergroup->id, 'u.id, u.firstname, u.lastname, u.email');
				    if(!empty($this->groupmembers)){
				        $this->groupmembers = array_merge($this->groupmembers,$groupmembers);
				    } else {
				        $this->groupmembers = $groupmembers;
				    }
				}
			}
		}
	}
}

class booking_option extends booking {
	
	/** @var array the users booked for this option */
	public $bookedusers = array();
	
	/** @var array of booked users visible to the current user (group members) */
	public $bookedvisibleusers = array();
	
	/** @var array of users that can be subscribed to that booking option if groups enabled, only members of groups user has access to are shown */
	public $potentialusers = array();
	
	public $optionid = null;
	
	public function __construct($id, $optionid){
		global $DB;
		
		parent::__construct($id);
		$this->optionid = $optionid;
		$this->update_booked_users();
	}
	
	public function update_booked_users(){
		global $DB;
		
		$select = "bookingid = $this->id";
		$params = array('optionid' => $this->optionid);
		$this->bookedusers = $DB->get_records_select('booking_answers',$select,$params,'','userid');
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

/*
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
        
		$availableuserssql = implode(',',array_keys($this->potentialusers));

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

		$subscriberssql =  implode(',',array_keys($this->potentialusers));
		
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