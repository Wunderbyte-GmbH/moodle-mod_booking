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
global $CFG;
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

const BOOKINGLINKPARAM_NONE = 0;
const BOOKINGLINKPARAM_BOOK = 1;
const BOOKINGLINKPARAM_USER = 2;
const BOOKINGLINKPARAM_ICAL = 3;

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
        AND u.suspended = 0
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
 *
 * @param $optionid
 * @param $user
 * @param $cm
 * @param $url
 * @throws coding_exception
 * @throws moodle_exception
 */
function booking_confirm_booking($optionid, $user, $cm, $url) {
    global $OUTPUT;
    echo $OUTPUT->header();

    $option = new booking_option($cm->id, $optionid, array(), 0, 0, false);

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
    $message .= "<p>" . format_text($option->booking->settings->bookingpolicy) . "<p>";
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
 * Helper function to render custom fields data of an option date session.
 * @param numeric $optiondateid the id of the option date for which the custom fields should be rendered
 * @return string the rendered HTML of the session's custom fields
 */
function get_rendered_customfields($optiondateid) {
    global $DB;
    $customfieldshtml = ''; // The rendered HTML.
    if ($customfields = $DB->get_records("booking_customfields", ["optiondateid" => $optiondateid])) {
        foreach ($customfields as $customfield) {
            $customfieldshtml .= '<p><i>' . $customfield->cfgname . ': </i>';
            $customfieldshtml .= $customfield->value . '</p>';
        }
    }
    return $customfieldshtml;
}

/**
 * Helper function to render the full description (including custom fields) of option events or optiondate events.
 * @param stdClass $option the option object
 * @param numeric $cmid the course module id
 * @param stdClass $optiondate the option date object (optional)
 * @return string The rendered HTML of the full description.
 */
function get_rendered_eventdescription($option, $cmid, $optiondate = false, $bookinglinkparam = BOOKINGLINKPARAM_NONE) {
    global $DB, $CFG;
    $fulldescription = '';

    // Create the description for a booking option date (session) event.
    if ($optiondate) {
        $timestart = userdate($optiondate->coursestarttime, get_string('strftimedatetime'));
        $timefinish = userdate($optiondate->courseendtime, get_string('strftimedatetime'));
        $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

        $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

        // Add rendered custom fields.
        $customfieldshtml = get_rendered_customfields($optiondate->id);
        if (!empty($customfieldshtml)) {
            $fulldescription .= $customfieldshtml;
        }
    } else {
        // Create the description for a booking option event without sessions.
        $timestart = userdate($option->coursestarttime, get_string('strftimedatetime'));
        $timefinish = userdate($option->courseendtime, get_string('strftimedatetime'));
        $fulldescription .= "<p><b>$timestart &ndash; $timefinish</b></p>";

        $fulldescription .= "<p>" . format_text($option->description, FORMAT_HTML) . "</p>";

        $customfields = $DB->get_records('booking_customfields', array('optionid' => $option->id));
        $customfieldcfg = \mod_booking\booking_option::get_customfield_settings();

        if ($customfields && !empty($customfieldcfg)) {
            foreach ($customfields as $field) {
                if (!empty($field->value)) {
                    $cfgvalue = $customfieldcfg[$field->cfgname]['value'];
                    if ($customfieldcfg[$field->cfgname]['type'] == 'multiselect') {
                        $tmpdata = implode(", ", explode("\n", $field->value));
                        $fulldescription .= "<p> <b>$cfgvalue: </b>$tmpdata</p>";
                    } else {
                        $fulldescription .= "<p> <b>$cfgvalue: </b>$field->value</p>";
                    }
                }
            }
        }
    }

    // Add location, institution and address.
    if (strlen($option->location) > 0) {
        $fulldescription .= '<p><i>' . get_string('location', 'booking') . '</i>: ' . $option->location . '</p>';
    }
    if (strlen($option->institution) > 0) {
        $fulldescription .= '<p><i>' . get_string('institution', 'booking') . '</i>: ' . $option->institution. '</p>';
    }
    if (strlen($option->address) > 0) {
        $fulldescription .= '<p><i>' . get_string('address', 'booking') . '</i>: ' . $option->address. '</p>';
    }

    // Attach the correct link.
    $linkurl = $CFG->wwwroot . "/mod/booking/view.php?id={$cmid}&optionid={$option->id}&action=showonlyone&whichview=showonlyone#goenrol";
    switch ($bookinglinkparam) {
        case BOOKINGLINKPARAM_BOOK:
            $fulldescription .= "<p>" . get_string("bookingoptioncalendarentry", 'booking', $linkurl) . "</p>";
            break;
        case BOOKINGLINKPARAM_USER:
            $fulldescription .= "<p>" . get_string("usercalendarentry", 'booking', $linkurl) . "</p>";
            break;
        case BOOKINGLINKPARAM_ICAL:
            $fulldescription .= "<br><p>" . get_string("linkgotobookingoption", 'booking', $linkurl) . "</p>";
            // Convert to plain text for ICAL.
            $fulldescription = rtrim(html_entity_decode(strip_tags(preg_replace( "/<br>|<\/p>/", "</p>\\n", $fulldescription))));
            break;
    }

    return $fulldescription;
}

/**
 * Helper function to delete custom fields belonging to an option date.
 * @param number $optiondateid id of the option date for which all custom fields will be deleted.
 */
function optiondate_deletecustomfields($optiondateid) {
    global $DB;
    // Delete all custom fields which belong to this optiondate.
    $DB->delete_records("booking_customfields", array('optiondateid' => $optiondateid));
}

/**
 * Helper function to duplicate custom fields belonging to an option date.
 * @param number $optiondateid id of the option date for which all custom fields will be duplicated.
 */
function optiondate_duplicatecustomfields($oldoptiondateid, $newoptiondateid) {
    global $DB;
    // Duplicate all custom fields which belong to this optiondate.
    $customfields = $DB->get_records("booking_customfields", array('optiondateid' => $oldoptiondateid));
    foreach ($customfields as $customfield) {
        $customfield->optiondateid = $newoptiondateid;
        $DB->insert_record("booking_customfields", $customfield);
    }
}

/**
 * Helper function to update calendar events after an option date (a session of a booking option) has been changed.
 * @param $optiondate stdClass optiondate object
 */
function optiondate_updateevent($optiondate, $cmid) {
    global $CFG, $DB;
    if (!$event = $DB->get_record('event', ['id' => $optiondate->eventid])) {
        return false;
    } else {
        $event->description = '';
        if ($option = $DB->get_record('booking_options', ['id' => $optiondate->optionid])) {
            $event->description = get_rendered_eventdescription($option, $cmid, $optiondate, BOOKINGLINKPARAM_BOOK);
            $event->timestart = $optiondate->coursestarttime;
            $event->timeduration = $optiondate->courseendtime - $optiondate->coursestarttime;
            $event->timesort = $optiondate->coursestarttime;
            $DB->update_record('event', $event);
        } else {
            return false;
        }
    }
}

/**
 * Get booking option status
 *
 * @param int $starttime
 * @param int $endtime
 * @return string
 * @throws coding_exception
 */
function booking_getoptionstatus($starttime = 0, $endtime = 0) {
    if ($starttime == 0 && $endtime == 0) {
        return '';
    } else if ($starttime < time() && $endtime > time()) {
        return get_string('active', 'booking');
    } else if ($endtime < time()) {
        return get_string('terminated', 'booking');
    } else if ($starttime > time()) {
        return get_string('notstarted', 'booking');
    }

    return "";
}
