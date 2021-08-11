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
 *
 * @package mod
 * @subpackage booking
 * @copyright 2015 onwards David Bogner {@link http://www.edulabs.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

class site_overview implements \renderable {

    /** @var number of entries to show */
    protected $perpage = 200;

    /** @var array courses user has access to with booking instances */
    protected $usercourses = array();

    /** @var array booking instances user has access to */
    protected $allbookinginstanceobjects = array();

    /** @var array booking instances where user is allowed to read responses */
    protected $readresponsesprivilegeinstances = array();

    /** @var array courses w/booking instances; multid. array [courseid] = array(bookingid1,bookingid2) array of bookingids values 2nd array */
    protected $courseswithbookings = array();

    /** @var array of instances of the module booking where $USER has access to, key: bookingid */
    protected $mybookinginstances = array();

    /** @var array of booking instances with subscribe other users prvilige key: bookingid */
    protected $bookingidsvisible = array();

    /** @var array of booking ids, with a response */
    protected $bookingidswithresponse = array();

    /** @var array of mod_booking\booking_option of the current user */
    public $mybookings = array();

    /** @var array of \mod_booking\booking_option instances with booking data where $USER has cap mod/booking:readresponses [bookingid][optionid] = user */
    public $allbookingoptionobjects = array();

    public function __construct() {
        global $USER, $DB;
        $isadmin = has_capability('moodle/site:config', \context_system::instance());
        if ($isadmin) {
            $this->usercourses = \get_courses("all", "c.sortorder ASC",
                    "c.id, c.fullname, c.shortname");
        } else {
            $this->usercourses = enrol_get_all_users_courses($USER->id, false,
                    array('id', 'fullname', 'shortname'), 'visible DESC, sortorder ASC');
        }
        $this->allbookinginstanceobjects = \get_all_instances_in_courses('booking',
                $this->usercourses);
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $this->readresponsesprivilegeinstances = $this->allbookinginstanceobjects;
            foreach ($this->readresponsesprivilegeinstances as $cmid => $bookinginstance) {
                $optionids = \mod_booking\booking::get_all_optionids($bookinginstance->id);
                $this->readresponsesprivilegeinstances[$cmid]->optionids = $optionids;
                $this->courseswithbookings[$bookinginstance->course][$cmid] = $this->readresponsesprivilegeinstances[$cmid];
            }
        } else {
            foreach ($this->allbookinginstanceobjects as $booking) {
                if (has_capability('mod/booking:readresponses',
                        \context_module::instance($booking->coursemodule))) {
                    $optionids = \mod_booking\booking::get_all_optionids($booking->id);
                    $booking->optionids = $optionids;
                    $this->readresponsesprivilegeinstances[$booking->coursemodule] = $booking;
                    $this->courseswithbookings[$booking->course][$booking->coursemodule] = $booking;
                } else {
                    // Bookings of $USER
                    $myoptionids = $this->get_my_optionids();
                    $optionidsbkng = \array_intersect($myoptionids, array($booking->id));
                    $booking->optionids = $optionidsbkng;
                    if (\in_array($booking->id, $optionidsbkng)) {
                        $this->courseswithbookings[$booking->course][$booking->coursemodule] = $booking;
                    }
                }
            }
        }
    }

    /**
     * Returns all instances of \mod_booking\booking_option visible to $USER
     *
     * @return array \mod_booking\booking_option[]
     */
    public function get_all_booking_option_instances() {
        global $DB;
        if (empty($this->allbookingoptionobjects)) {
            if (!empty($this->readresponsesprivilegeinstances)) {
                foreach ($this->readresponsesprivilegeinstances as $response) {
                    if (!empty($response->optionids)) {
                        foreach ($response->optionids as $id) {
                            $this->allbookingoptionobjects[$id] = new \mod_booking\booking_option(
                                    $response->coursemodule, $id);
                        }
                    }
                }
            }
        }
        return $this->allbookingoptionobjects;
    }

    /**
     * retrieves all responses of $USER and sorts them (waitinglist or booked)
     */
    public function get_my_responses() {
        global $DB, $USER;
        $sql = "SELECT ba.optionid, ba.bookingid, ba.waitinglist
            FROM {booking_answers} ba
            WHERE ba.userid = :userid ";
        $options = $DB->get_records_sql($sql, array('userid' => $USER->id));
        foreach ($options as $option) {
            $cm = get_coursemodule_from_instance('booking', $option->bookingid);
            $this->mybookings[$option->optionid] = new \mod_booking\booking_option($cm->id, $option->optionid);
        }
        return $this->mybookings;
    }

    /**
     * Get opionids booked by $USER
     *
     * @return array of optionids as keys and bookingids as values
     */
    public function get_my_optionids($optionid = null) {
        global $USER, $DB;
        return $optionids = $DB->get_records_menu('booking_answers', array('userid' => $USER->id),
                '', 'optionid, bookingid');
    }

    /**
     * Given the courseid, returns all booking option objects.
     *
     * @return array booking option objects or empty array, when not bookings are found
     */
    protected function all_bookingoptions_of_course($courseid) {
        if (!empty($this->courseswithbookings[$courseid])) {
            return $this->courseswithbookings[$courseid];
        } else {
            return array();
        }
    }

    /**
     * Prepares user object for rendering adding course and booking information to userobject
     *
     * @return array of user objects to be rendered
     */
    protected function sort_bookings_per_user() {
        $userstoprint = array();
        foreach ($this->get_all_booking_option_instances() as $bookingid => $bookingoptionswithdata) {
            $allusers = $bookingoptionswithdata->users;
            foreach ($allusers as $user) {
                $user->optionid = $bookingoptionswithdata->optionid;
                $user->courseid = $bookingoptionswithdata->course->id;
                $user->coursename = $bookingoptionswithdata->course->fullname;
                $user->bookingtitle = $bookingoptionswithdata->booking->name;
                $user->bookingoptionname = $bookingoptionswithdata->option->text;
                $user->bookingvisible = $bookingoptionswithdata->cm->visible;
                $user->cmid = $bookingoptionswithdata->cm->id;
                $userstoprint[$user->id][$bookingoptionswithdata->optionid] = $user;
            }
        }
        return $userstoprint;
    }

    /**
     * Display all bookings of the moodle instance
     *
     * @param sort null for default sorting by course or 'user'
     * @return string rendered html
     */
    public function display($sort = 'my') {
        global $PAGE, $USER;
        if (\is_null($sort)) {
            $sort = 'my';
        }
        $boldtext = array('style' => 'font-weight: bold;');
        $attributeuser = null;
        $attributecourse = null;
        $attributemy = null;
        // Output sort links and heading.
        $url = $PAGE->url;
        $sorturl = new \moodle_url($url);
        switch ($sort) {
            case 'course':
                $attributecourse = $boldtext;
                break;
            case 'user':
                $attributeuser = $boldtext;
                break;
            case 'my':
                $attributemy = $boldtext;
                break;
            default:
                $attributemy = $boldtext;
                break;
        }
        if (!empty($this->readresponsesprivilegeinstances)) {
            $sorturl->param('sort', 'user');
            echo \html_writer::link($sorturl, get_string('sortbyuser', 'block_booking'),
                    $attributeuser);
            echo \html_writer::span("  //  ");
            $sorturl->param('sort', 'course');
            echo \html_writer::link($sorturl, get_string('sortbycourse', 'block_booking'),
                    $attributecourse);
            echo \html_writer::span("  //  ");
        }
        $sorturl->param('sort', 'my');
        echo \html_writer::link($sorturl, get_string('showmybookingsonly', 'mod_booking'),
                $attributemy);
        $bookingoptions = $this->get_all_booking_option_instances();
        $output = '';
        $renderer = $PAGE->get_renderer('mod_booking');
        if ($sort === 'user') {
            $userstorender = $this->sort_bookings_per_user();
            $output .= $renderer->render_bookings_per_user($userstorender);
            return $output;
        }
        if (!empty($this->courseswithbookings)) {
            foreach (array_keys($this->courseswithbookings) as $courseid) {
                $allcoursebookings = $this->all_bookingoptions_of_course($courseid);
                if (!empty($allcoursebookings)) {
                    if ($sort == 'my' || $sort == 'course') {
                        $firstelement = reset($allcoursebookings);
                        $output .= \html_writer::tag('h2',
                                $this->usercourses[$firstelement->course]->fullname);
                        $mybookings = $this->get_my_optionids();
                        foreach ($allcoursebookings as $booking) {
                            if (!empty($booking->optionids)) {
                                $booking->options = array_intersect_key(
                                        $this->allbookingoptionobjects, $booking->optionids);
                                if ($sort === 'my') {
                                    if (empty($this->allbookingoptionobjects)) {
                                        $bkngoptionids = array_intersect($mybookings,
                                                array($booking->id));
                                        $optids = \array_keys($bkngoptionids);
                                        foreach ($optids as $id) {
                                            $bookingoptionobjects[$id] = new \mod_booking\booking_option(
                                                    $booking->coursemodule, $id);
                                        }
                                        $booking->options = $bookingoptionobjects;
                                    }
                                }
                            }
                            $bookingdata = new \mod_booking\output\booking_bookinginstance($sort,
                                    $booking);
                            $output .= $renderer->render_bookings($bookingdata);
                        }
                    }
                }
            }
        }
        return $output;
    }
}