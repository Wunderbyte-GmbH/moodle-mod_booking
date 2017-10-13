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
 * @package    mod
 * @subpackage booking
 * @copyright  2015 onwards David Bogner  {@link http://www.edulabs.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;


class site_overview {

    /** @var number of entries to show */
    protected $perpage = 200;

    /** @var number
    /** @var courses user has access to with booking instances */
    protected $usercourses = array();

    /** @var array courses with booking instances; multidimensional array
     * [courseid] = array(bookingid1,bookingid2) array of bookingids as values of 2nd array */
    protected $courseswithbookings = array();

    /** @var array of instances of the module booking where $USER has access to, key: bookingid */
    protected $mybookinginstances = array();

    /** @var array of booking instances with subscribe other users prvilige key: bookingid */
    protected $bookingidsvisible = array();

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
        if(has_capability('moodle/site:config', \context_system::instance())){
            $sql =    "SELECT cm.instance, cm.id AS coursemodule, m.*, cw.section, cm.visible AS visible,
                    cm.groupmode, cm.groupingid, cm.course
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
                if(has_capability('mod/booking:subscribeusers', \context_module::instance($booking->coursemodule))){
                    $this->subscribeprivilegeinstances[$booking->id] = $booking;
                    $this->courseswithbookings[$booking->course][] = $booking->id;
                }
            }
        }
    }

    /**
     * Get all ids of booking instances that are visible to the user
     *
     * @return array of numbers or empty array
     */
    public function get_bookinginstances_visibletouser() {
        if(has_capability('moodle/site:config', \context_system::instance())){

        }
            if(empty($this->bookingidsvisible)) {

        }
        $bookinginstances = $this->subscribeprivilegeinstances;
        // TODO  course_get_
        if (!empty( $this->subscribeprivilegeinstances )) {
            foreach( $bookinginstances as $bookinginstance ) {
                $this->allbookings[$bookinginstance->id] = new \mod_booking\booking_option($bookinginstance->coursemodule,false);
            }
        }
        return $this->bookingidsvisible;
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
            $this->get_bookinginstances_visibletouser();
        }
        // TODO
        foreach($this->allbookings as $bookingid => $bookingoptionswithdata){
            $bookingoptionswithdata->get_all_users();
            foreach($bookingoptionswithdata as $optionid => $alluserofoption){
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
            $sorturl = new \moodle_url($url);
            $sorturl->param('sort','user');
            echo \html_writer::link($sorturl, get_string('sortbyuser', 'block_booking'),$attributeuser);
            echo \html_writer::span("  //  ");
            echo \html_writer::link($url,get_string('sortbycourse', 'block_booking'),$attributecourse);
            echo \html_writer::span("  //  ");
        }
        $sorturl->param('sort','my');
        echo \html_writer::link($sorturl,get_string('showmybookings', 'booking'),$attributemy);

        $this->get_bookinginstances_visibletouser();
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
                        $output .= \html_writer::tag('h2', $firstelement->course->fullname);
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