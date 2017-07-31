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

require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');
require_once($CFG->libdir . '/tcpdf/tcpdf.php');


/**
 * Standard base class for mod_booking
 * Module was originally programmed for 1.9 but further adjustments should be made with new Moodle 2.X coding style using this base class.
 *
 * @package mod_booking
 * @copyright 2013 David Bogner {@link http://www.edulabs.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking {

    /** @var number id booking id  */
    public $id = 0;

    /**
     *
     * @var \context the context of the course module for this booking instance (or just the course if we are
     */
    protected $context = null;

    /** @var \stdClass the course this booking instance belongs to */
    public $course = null;

    /** @var \stdClass the course module for this assign instance */
    public $cm = null;

    /** @var array of user objects who have capability to book. object contains only id */
    public $canbookusers = array();

    /** @var array users who are members of the current users group */
    public $groupmembers = array();

    /** @var booking booking object from booking instance settings */
    public $booking;

    /**
     * @var array $alloptions option objects indexed by optionid
     */
    protected $alloptions = array();

    /**
     * @var array of ids
     */
    protected $optionids = array();

    /**
     *
     * @var number of bookings a user has made
     */
    protected $userbookings = null;

    /**
     * Constructor for the booking class
     *
     * @param mixed $context context|null the course module context (or the course context if the coursemodule has not been created yet)
     * @param mixed $coursemodule the current course module if it was already loaded - otherwise this class will load one from the context as required
     * @param mixed $course the current course if it was already loaded - otherwise this class will load one from the context as required
     */
    public function __construct($cmid) {
        global $DB;
        $this->cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $this->course = $DB->get_record('course', array('id' => $this->cm->course),
                'id, fullname, shortname, groupmode, groupmodeforce, visible', MUST_EXIST);
        $this->id = $this->cm->instance;
        $this->context = \context_module::instance($cmid);
        $this->booking = $DB->get_record("booking", array("id" => $this->id));
        // if the course has groups and I do not have the capability to see all groups, show only users of my groups
        if ($this->course->groupmode !== 0 &&
                 !has_capability('moodle/site:accessallgroups', $this->context)) {
            $this->groupmembers = $this::booking_get_groupmembers($this->course->id);
        }
    }

    /**
     *
     * @return \context
     */
    public function get_context() {
        return $this->context;
    }

    public function apply_tags() {
        $tags = new \booking_tags($this->cm);
        $this->booking = $tags->booking_replace($this->booking);
    }

    /**
     *
     */
    public function get_url_params() {
        $bu = new \booking_utils();
        $params = $bu->generate_params($this->booking);
        $this->booking->pollurl = $bu->get_body($this->booking, 'pollurl', $params);
        $this->booking->pollurlteachers = $bu->get_body($this->booking, 'pollurlteachers', $params);
    }

    /**
     * get all the user ids who are allowed to book capability mod/booking:choose available in $htis->canbookusers
     */
    public function get_canbook_userids() {
        // TODO check if course has guest access if not get all enrolled users and check with has_capability if user has right to book
        // $this->canbookusers = get_users_by_capability($this->context, 'mod/booking:choose', 'u.id', 'u.lastname ASC, u.firstname ASC', '', '', '',
        // '', true, true);
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
            $groupids = array_keys($usergroups);
            list($insql, $inparams) = $DB->get_in_or_equal($groupids);
            $groupmembers = $DB->get_records_sql(
                    "SELECT u.id
                    FROM {user} u, {groups_members} gm
                    WHERE u.id = gm.userid AND gm.groupid $insql
                    ORDER BY lastname ASC", $inparams);
        }
        return $groupmembers;
    }

    /**
     * Get all booking options as an array of objects indexed by optionid
     *
     * @return array of booking options records
     */
    public function get_all_options() {
        global $DB;
        if (empty($this->alloptions)) {
            $this->alloptions = $DB->get_records('booking_options', array('bookingid' => $this->id));
            if (!empty($this->optionids)) {
                $this->optionids = array_keys($this->alloptions);
            }
        }
        return $this->alloptions;
    }

    /**
     * Get all booking option ids as an array of numbers
     *
     * @return array of ids
     */
    public function get_all_optionids() {
        global $DB;
        if (!empty($this->optionids)) {
            return $this->optionids;
        }
        $this->optionids = $DB->get_fieldset_select('booking_options', 'id', "bookingid = {$this->booking->id}");
        return $this->optionids;
    }

    /**
     * Display a message about the maximum nubmer of bookings this user is allowed to make
     *
     * @param \stdClass $user
     * @return string
     */
    public function show_maxperuser($user) {
        global $USER;

        $warning = '';

        if (!empty($this->booking->banusernames)) {
            $disabledusernames = explode(',', $this->booking->banusernames);

            foreach ($disabledusernames as $value) {
                if (strpos($USER->username, trim($value)) !== false) {
                    $warning = \html_writer::tag('p', get_string('banusernameswarning', 'mod_booking'));
                }
            }
        }

        if (!$this->booking->maxperuser) {
            return $warning; // No per-user limits.
        }

        $outdata = new \stdClass();
        $outdata->limit = $this->booking->maxperuser;
        $outdata->count = $this->get_user_booking_count($user);

        $warning .= \html_writer::tag('div', get_string('maxperuserwarning', 'mod_booking', $outdata), array ('class' => 'alert alert-warning'));
        return $warning;
    }

    /**
     * Determins the number of bookings that a single user has already made in all booking options
     *
     * @param \stdClass $user
     * @return number of bookings made by user
     */
    public function get_user_booking_count($user) {
        global $DB;
        if (!empty($this->userbookings)) {
            return $this->userbookings;
        }
        return $this->userbookings = $DB->count_records('booking_answers',
                array('bookingid' => $this->id, 'userid' => $user->id));
    }
}