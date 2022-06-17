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
use mod_booking\event\bookingoptiondate_created;
use mod_booking\singleton_service;

global $CFG;
require_once($CFG->dirroot . '/user/selector/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Abstract class used by booking subscriber selection controls
 *
 * @package mod_booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_user_selector_base extends user_selector_base {

    /**
     * The id of the booking this selector is being used for.
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
 * @package mod_booking
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
                 userdate($option->option->coursestarttime, get_string('strftimedatetime', 'langconfig')) . " - " .
                 userdate($option->option->courseendtime, get_string('strftimedatetime', 'langconfig'));
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
 * @param int $optionid
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
 * @param int $optionid
 * @param int $cmid the course module id
 * @param int $descriptionparam
 * @param bool $forbookeduser
 * @return string The rendered HTML of the full description.
 */
function get_rendered_eventdescription(int $optionid, int $cmid,
    int $descriptionparam = DESCRIPTION_WEBSITE, bool $forbookeduser = false): string {

    global $PAGE;

    // We have the following differences:
    // - Rendered live on the website (eg wihin a modal) -> use button.
    // - Rendered in calendar event -> use link.php? link.
    // - Rendered in ical file for mail -> use link.php? link.

    $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

    $data = new \mod_booking\output\bookingoption_description($booking, $optionid, null, $descriptionparam, true, $forbookeduser);
    $output = $PAGE->get_renderer('mod_booking');

    if ($descriptionparam == DESCRIPTION_ICAL) {
        // If this is for ical.
        return $output->render_bookingoption_description_ical($data);
    } else if ($descriptionparam == DESCRIPTION_MAIL) {
        // If this is used for a mail - placeholder {bookingdetails}.
        return $output->render_bookingoption_description_mail($data);
    } else if ($descriptionparam == DESCRIPTION_CALENDAR) {
        // If this is used for an event.
        return $output->render_bookingoption_description_event($data);
    }

    return $output->render_bookingoption_description($data);

}

/**
 * Helper function to delete custom fields belonging to an option date.
 * @param int $optiondateid id of the option date for which all custom fields will be deleted.
 */
function optiondate_deletecustomfields($optiondateid) {
    global $DB;
    // Delete all custom fields which belong to this optiondate.
    $DB->delete_records("booking_customfields", array('optiondateid' => $optiondateid));
}

/**
 * Helper function to duplicate custom fields belonging to an option date.
 * @param int $optiondateid id of the option date for which all custom fields will be duplicated.
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
 * Helper function to update user calendar events after an option or optiondate (a session of a booking option) has been changed.
 * @param stdClass $option
 * @param stdClass $optiondate
 * @param int $cmid
 */
function option_optiondate_update_event(stdClass $option, stdClass $optiondate = null, int $cmid) {
    global $DB, $USER;

    // We either do this for option or optiondate
    // different way to retrieve the right events.
    if ($optiondate) {
        // Check if we have already associated userevents.
        if (!isset($optiondate->eventid) || (!$event = $DB->get_record('event', ['id' => $optiondate->eventid]))) {

            // If we don't find the event here, we might still be just switching to multisession.
            // Let's create the event anew.
            $bocreatedevent = bookingoptiondate_created::create(array('context' => context_module::instance($cmid),
                'objectid' => $optiondate->id, 'userid' => $USER->id, 'other' => ['optionid' => $option->id]));
            $bocreatedevent->trigger();

            // We have to return false if we have switched from multisession to create the right events.
            return false;
        } else {

            // Get all the userevents.
            $sql = "SELECT e.* FROM {booking_userevents} ue
              JOIN {event} e
              ON ue.eventid = e.id
              WHERE ue.optiondateid = :optiondateid";

            $allevents = $DB->get_records_sql($sql, ['optiondateid' => $optiondate->id]);

            // Use the optiondate as data object.
            $data = $optiondate;

            if ($event = $DB->get_record('event', ['id' => $optiondate->eventid])) {
                if ($allevents && count($allevents) > 0) {
                    if ($event && isset($event->description)) {
                        $allevents[] = $event;
                    }
                } else {
                    $allevents = [$event];
                }
            }
        }
    } else {
        // Get all the userevents.
        $sql = "SELECT e.* FROM {booking_userevents} ue
                    JOIN {event} e
                    ON ue.eventid = e.id
                    WHERE ue.optionid = :optionid";

        $allevents = $DB->get_records_sql($sql, ['optionid' => $option->id]);

        // Use the option as data object.
        $data = $option;

        if ($event = $DB->get_record('event', ['id' => $option->calendarid])) {
            if ($allevents && count($allevents) > 0) {
                if ($event && isset($event->description)) {
                    $allevents[] = $event;
                }
            } else {
                $allevents = [$event];
            }
        }
    }

    // We use $data here for $option and $optiondate, the necessary keys are the same.
    foreach ($allevents as $eventrecord) {
        if ($eventrecord->eventtype == 'user') {
            $eventrecord->description = get_rendered_eventdescription($option->id, $cmid, DESCRIPTION_CALENDAR, true);
        } else {
            $eventrecord->description = get_rendered_eventdescription($option->id, $cmid, DESCRIPTION_CALENDAR, false);
        }
        $eventrecord->name = $option->text;
        $eventrecord->timestart = $data->coursestarttime;
        $eventrecord->timeduration = $data->courseendtime - $data->coursestarttime;
        $eventrecord->timesort = $data->coursestarttime;
        if (!$DB->update_record('event', $eventrecord)) {
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
