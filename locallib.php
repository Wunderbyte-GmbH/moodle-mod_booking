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

defined('MOODLE_INTERNAL') || die();

use mod_booking\booking_option;
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Abstract class used by booking subscriber selection controls
 *
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_user_selector_base extends user_selector_base {

    /**
     * The id of the booking this selector is being used for
     *
     * @var int
     */
    protected $bookingid = null;

    /**
     * The id of the current option
     *
     * @var int
     */
    protected $optionid = null;

    /**
     * The potential users array
     *
     * @var array
     */
    public $potentialusers = null;

    /**
     *
     * @var array of userids
     */
    public $bookedvisibleusers = null;

    /**
     *
     * @var stdClass
     */
    public $course;
    /**
     *
     * @var cm_info
     */
    public $cm;

    /**
     * Constructor method
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $this->maxusersperpage = 50;
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
        if (isset($options['course'])) {
            $this->course = $options['course'];
        }
        if (isset($options['cm'])) {
            $this->cm = $options['cm'];
        }
    }

    protected function get_options() {
        $options = parent::get_options();
        $options['file'] = 'mod/booking/locallib.php';
        $options['bookingid'] = $this->bookingid;
        $options['potentialusers'] = $this->potentialusers;
        $options['optionid'] = $this->optionid;
        $options['cm'] = $this->cm;
        $options['course'] = $this->course;
        // Add our custom options to the $options array.
        return $options;
    }

    /**
     * Sets the existing subscribers
     *
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

    public $options;

    public function __construct($name, $options) {
        $this->options = $options;
        parent::__construct($name, $options);
    }

    public function find_users($search) {
        global $DB, $USER;

        $onlygroupmembers = false;
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS and
                !has_capability('moodle/site:accessallgroups',
                        \context_course::instance($this->course->id))) {
            $onlygroupmembers = true;
        }

        $fields = "SELECT " . $this->required_fields_sql("u");

        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');
        $groupsql = '';
        if ($onlygroupmembers) {
            list($groupsql, $groupparams) = \mod_booking\booking::booking_get_groupmembers_sql($this->course->id);
            list($esql, $eparams) = get_enrolled_sql($this->options['accesscontext'], null, null, true);
            $groupsql = " AND u.id IN (" . $groupsql.")";
            $params = array_merge($eparams, $groupparams);
        } else {
            list($esql, $params) = get_enrolled_sql($this->options['accesscontext'], null, null, true);
        }

        $option = new stdClass();
        $option->id = $this->options['optionid'];
        $option->bookingid = $this->options['bookingid'];

        if (booking_check_if_teacher($option) && !has_capability(
                'mod/booking:readallinstitutionusers', $this->options['accesscontext'])) {

            $institution = $DB->get_record('booking_options',
                    array('id' => $this->options['optionid']));

            $searchparams['onlyinstitution'] = $institution->institution;
            $searchcondition .= ' AND u.institution LIKE :onlyinstitution';
        }

        $sql = " FROM {user} u
        WHERE $searchcondition
        AND u.id IN (SELECT nnn.id FROM ($esql) AS nnn WHERE nnn.id > 1)
        $groupsql
        AND u.id NOT IN (SELECT ba.userid FROM {booking_answers} ba WHERE ba.optionid = {$this->options['optionid']})";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql,
                    array_merge($searchparams, $params));
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($searchparams, $params, $sortparams));

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
 *
 * @package mod-booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_existing_user_selector extends booking_user_selector_base {

    public $potentialusers;

    public $options;

    public function __construct($name, $options) {
        $this->potentialusers = $options['potentialusers'];
        $this->options = $options;

        parent::__construct($name, $options);
    }

    /**
     * Finds all booked users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB, $USER;

        // Only active enrolled or everybody on the frontpage.
        $fields = "SELECT " . $this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        list($searchcondition, $searchparams) = $this->search_sql($search, 'u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!empty($this->potentialusers)) {
            $potentialuserids = array_keys ($this->potentialusers);
            list($subscriberssql, $subscribeparams) = $DB->get_in_or_equal($potentialuserids, SQL_PARAMS_NAMED, "in_");
        } else {
            return array();
        }

        $option = new stdClass();
        $option->id = $this->options['optionid'];
        $option->bookingid = $this->options['bookingid'];

        if (booking_check_if_teacher($option) && !has_capability(
                'mod/booking:readallinstitutionusers', $this->options['accesscontext'])) {

            $institution = $DB->get_record('booking_options',
                    array('id' => $this->options['optionid']));

            $searchparams['onlyinstitution'] = $institution->institution;
            $searchcondition .= ' AND u.institution LIKE :onlyinstitution';
        }

        $sql = " FROM {user} u
                        WHERE u.id $subscriberssql
                        AND $searchcondition
                        ";

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, array_merge($subscribeparams, $searchparams));
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($searchparams, $sortparams, $subscribeparams));

        if (empty($availableusers)) {
            return array();
        }

        return array(get_string("booked", 'booking') => $availableusers);
    }
}

/**
 * Outputs a confirm button on a separate page to confirm a booking.
 */
function booking_confirm_booking($optionid, $user, $cm, $url) {
    global $OUTPUT;
    echo $OUTPUT->header();

    $option = new \mod_booking\booking_option($cm->id, $optionid, array(), 0, 0, false);

    $optionidarray['answer'] = $optionid;
    $optionidarray['confirm'] = 1;
    $optionidarray['sesskey'] = $user->sesskey;
    $optionidarray['id'] = $cm->id;
    $requestedcourse = "<br />" . $option->option->text;
    if ($option->option->coursestarttime != 0) {
        $requestedcourse .= "<br />" .
                 userdate($option->option->coursestarttime, get_string('strftimedatetime')) . " - " .
                 userdate($option->option->courseendtime, get_string('strftimedatetime'));
    }
    $message = "<h2>" . get_string('confirmbookingoffollowing', 'booking') . "</h2>" .
             $requestedcourse;
    $message .= "<p><b>" . get_string('agreetobookingpolicy', 'booking') . ":</b></p>";
    $message .= "<p>" . $option->booking->settings->bookingpolicy . "<p>";
    echo $OUTPUT->confirm($message, new moodle_url('/mod/booking/view.php', $optionidarray), $url);
    echo $OUTPUT->footer();
}

/**
 * Update start and enddate in booking_option when dates are set or deleted
 * @param number $optionid
 */
function booking_updatestartenddate($optionid) {
    global $DB;

    $result = $DB->get_record_sql(
            'SELECT MIN(coursestarttime) AS coursestarttime, MAX(courseendtime) AS courseendtime
             FROM {booking_optiondates}
             WHERE optionid = ?',
            array($optionid));

    $save = new stdClass();
    $save->id = $optionid;

    if (is_null($result->coursestarttime)) {
        $save->coursestarttime = 0;
        $save->courseendtime = 0;
    } else {
        $save->coursestarttime = $result->coursestarttime;
        $save->courseendtime = $result->courseendtime;
    }

    $DB->update_record("booking_options", $save);
}

/**
 * Get booking option status
 */
function booking_getoptionstatus($starttime = 0, $endtime = 0) {
    if ($starttime == 0 && $endtime == 0) {
        return get_string('active', 'booking');
    } else if ($starttime > time() && $endtime < time()) {
        return get_string('active', 'booking');
    } else if ($endtime > time()) {
        return get_string('terminated', 'booking');
    } else if ($starttime < time()) {
        return get_string('notstarted', 'booking');
    }

    return "";
}
