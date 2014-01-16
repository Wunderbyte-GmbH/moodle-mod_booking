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

	/** @var stdClass the booking record that contains the global settings for this booking instance */
	public  $booking = null;
	
	/** @var context the context of the course module for this booking instance (or just the course if we are
	 creating a new one) */
	protected  $context = null;
	
	/** @var stdClass the course this booking instance belongs to */
	public   $course = null;
	
	/** @var stdClass the course module for this assign instance */
	public  $cm = null;	
	
	/** @var array users who have capability to book */
	protected  $canbookusers = array();
	
	/**
	 * Constructor for the booking class
	 *
	 * @param mixed $context context|null the course module context (or the course context if the coursemodule has not been created yet)
	 * @param mixed $coursemodule the current course module if it was already loaded - otherwise this class will load one from the context as required
	 * @param mixed $course the current course  if it was already loaded - otherwise this class will load one from the context as required
	 */
	public function __construct(context $context, stdClass $cm, stdClass $course, stdClass $booking) {
		$this->context = $context;
		$this->cm = $cm;
		$this->course = $course;
		$this->booking = $booking;
		$this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose','u.id, u.firstname, u.lastname, u.email');
	}
}

class booking_option extends booking {
	
	/** @var array the users booked for this option */
	public $bookedusers = array();
	
	/** @var array of users that can be subscribed to that booking option */
	public $potentialusers = array();
	
	public $optionid = null;
	
	public function __construct(context $context, stdClass $cm, stdClass $course, stdClass $booking, $optionid){
		global $DB;
		
		parent::__construct($context, $cm, $course, $booking);
		$this->optionid = $optionid;
		$select = "bookingid = $booking->id";
		$params = array('optionid' => $optionid);
		$this->bookedusers = $DB->get_fieldset_select('booking_answers','userid', $select,$params);
		$bookedidsaskey = array_flip($this->bookedusers);
		$this->potentialusers = array_diff_key($this->canbookusers, $bookedidsaskey);
	}
	
	public function update_booked_users(){
		global $DB;
		
		$select = "bookingid = ".$this->booking->id;
		$params = array('optionid' => $this->optionid);
		$this->bookedusers = $DB->get_fieldset_select('booking_answers','userid', $select,$params);
		$bookedidsaskey = array_flip($this->bookedusers);
		$this->potentialusers = array_diff_key($this->canbookusers, $bookedidsaskey);
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
	 * The context of the booking this selector is being used for
	 * @var object
	 */
	protected $context = null;
	/**
	 * The id of the current group
	 * @var int
	 */
	protected $currentgroup = null;

	/**
	 * The id of the current option
	 * @var int
	 */
	protected $optionid = null;	
	/**
	 * The course module id
	 * @var int
	 */
	protected $cmid = null;	
	/**
	 * The potential users
	 * @var array
	 */
	protected $course = null;
	/**
	 * The course object
	 * @var object
	 */
	protected $potentialusers = null;
	
	/**
	 * Constructor method
	 * @param string $name
	 * @param array $options
	 */
	public function __construct($name, $options) {
		$options['accesscontext'] = $options['context'];
		parent::__construct($name, $options);
		if (isset($options['context'])) {
			$this->context = $options['context'];
		}
		if (isset($options['currentgroup'])) {
			$this->currentgroup = $options['currentgroup'];
		}
		if (isset($options['bookingid'])) {
			$this->bookingid = $options['bookingid'];
		}
		if (isset($options['cmid'])) {
			$this->cmid = $options['cmid'];
		}
		if (isset($options['course'])) {
			$this->course = $options['course'];
		}
		if (isset($options['potentialusers'])) {
			$this->potentialusers = $options['potentialusers'];
		} 
		if (isset($options['optionid'])) {
			$this->optionid = $options['optionid'];
		}
	}

	/**
	 * Returns an array of options to seralise and store for searches
	 *
	 * @return array
	 */
	protected function get_options() {
		global $CFG;
		$options = parent::get_options();
		$options['context'] = $this->context;
		$options['currentgroup'] = $this->currentgroup;
		$options['bookingid'] = $this->bookingid;
		$options['optionid'] = $this->optionid;
		$options['cmid'] = $this->cmid;
		$options['course'] = $this->course;
		return $options;
	}
}

/*
 * User selector for booking other users
 */
class booking_potential_user_selector extends booking_user_selector_base {
	public function __construct($name,$options,$potentialusers) {
		parent::__construct($name,$options);
		$this->potentialusers = $potentialusers;
	}

	public function find_users($search) {
		global $DB, $USER;
		$fields      = "SELECT ".$this->required_fields_sql("u");
		$wherecondition = $this->search_sql($search, 'u');
		
		if($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)){
			$usergroups = groups_get_user_groups($this->course->id, $USER->id);
			if(!empty($usergroups)){
				foreach($usergroups as $usergroup){
					$groupmembers = groups_get_members($this->currentgroup, 'u.id, u.firstname, u.lastname, u.email');
				}
			}
		}
		
		$sql = "$fields
				 FROM {user} u
		RIGHT JOIN {booking_answers} e ON e.userid = u.id
		WHERE e.optionid = 2";
		//$availableusers = $DB->get_records_sql($sql);
		$availableusers = $this->potentialusers;
		if($this->course->groupmode !== 0 && !has_capability('moodle/site:accessallgroups', $this->context)){
			$availableusers = array_intersect_key($availableusers, $groupmembers);
		}
		unset($availableusers[$USER->id]);
		$bookedusers = $DB->get_fieldset_select('booking_answers', 'userid',"optionid = $this->optionid AND bookingid = $this->bookingid");
		if(!empty($bookedusers)){
			foreach ($bookedusers as $bookeduser){
				unset($availableusers[$bookeduser]);
			}
		}
		if ($search) {
			$groupname = get_string('enrolledusersmatching', 'enrol', $search);
		} else {
			$groupname = get_string('enrolledusers', 'enrol');
		}
		return array($groupname => $availableusers);		
	}
	
	/**
	 * Sets the existing subscribers
	 * @param array $users
	 */
	public function set_potential_users(array $users) {
		$this->potentialusers = $users;
	}
	protected function get_options() {
		$options = parent::get_options();
		// Add our custom options to the $options array.
		return $options;
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
		list($wherecondition, $params) = $this->search_sql($search, 'u');
		$params['bookingid'] = $this->bookingid;
		$params['optionid'] = $this->optionid;
		
		// only active enrolled or everybody on the frontpage
		//list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
		$fields = $this->required_fields_sql('u');
		//list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
		//$params = array_merge($params, $eparams, $sortparams);

		$subscribers = $DB->get_records_sql("SELECT $fields
				FROM {user} u
				JOIN {booking_answers} s ON s.userid = u.id
				WHERE s.bookingid = $this->bookingid AND s.optionid = $this->optionid
				");

		return array(get_string("booked", 'booking') => $subscribers);
	}
}