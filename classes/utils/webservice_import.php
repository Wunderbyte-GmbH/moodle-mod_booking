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
 * Import booking options via webservice.
 *
 * @package mod_booking
 * @copyright 2021 Georg Maisser <georg.maisser@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\utils;

use coding_exception;
use mod_booking\booking;
use stdClass;
use mod_booking\booking_option;
use mod_booking\customfield\booking_handler;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class webservice_import
 * Import controller for webservice imports
 */
class webservice_import {


    /** @var stdClass the course module for this assign instance */
    public $cm = null;

    /**
     * In the constructor of this class, we don't know which booking instance will be used.
     * We have to interpret the data first.
     */
    public function __construct() {

    }

    /**
     * This function verifies if the given data should be merged with an existing booking option.
     * If so, we merge.
     * If not, we create a new booking option.
     * @param $data
     * @return int[]
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function process_data($data) {

        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        // PRO feature: A license key is needed to use the Import controller web service.
        if (!wb_payment::pro_version_is_activated()) {
            throw new \moodle_exception('missinglicensekey', 'mod_booking', null, null,
                'You need an activated PRO version in order to use the import controller web service.');
        }

        // Now we get the booking instance.
        if (!$bookingid = $this->return_booking_id($data)) {
            throw new \moodle_exception('nobookingid', 'mod_booking', null, null,
                    'We need a booking instance for webservice upload.');
        }

        $data->bookingid = $bookingid;
        $this->cm = get_coursemodule_from_instance('booking', $bookingid);

        if (!isset($data->bookingcmid)) {
            $data->bookingcmid = $this->cm->id;
        }

        // This will set the bookingoption to update (if there is one, else it will be null).
        $bookingoption = $this->check_if_update_option($data);

        // First, we remap data to make sure we can use it in update & creation.
        $this->remap_data($data, $bookingoption);

        if ($bookingoption == null) {
            // Create new booking option.
            $context = \context_module::instance($this->cm->id);
        } else {
            $context = $bookingoption->booking->context;
        }

        $bookingoptionid = booking_update_options($data, $context);

        // Now that we have the option id, also from new users, we can add the teacher.

        $this->add_teacher_to_bookingoption($bookingoptionid, $data);

        $this->add_customfields_to_bookingoption($bookingoptionid, $data);

        return array('status' => 1);
    }

    /**
     * Function to update option. It is used to add teacher, to inscribe users or to add multisession date.
     * @param $data
     * @param $bookingoption
     */
    public function update_option(&$data, $bookingoption) {

    }

    /**
     * Verify if we have enough overlapping with an existing booking option so we can update.
     * Returns 0 if we have to create a new option, else bookingoptionid.
     * @param $data
     * @return booking_option|null
     */
    private function check_if_update_option(&$data): ?booking_option {

        global $DB;
        // Array of keys to check.
        $keystocheck = [];

        // If we have an actual and valid moodle bookingoptionid, we can return the corresponding booking option right away.
        if ($data->bookingoptionid) {

            $sql = "SELECT cm.id
                    FROM {booking_options} bo
                    INNER JOIN {course_modules} cm
                    ON cm.instance=bo.bookingid
                    INNER JOIN {modules} m
                    ON cm.module=m.id
                    WHERE bo.id=:bookingoptionid
                    AND m.name=:modulename";
            $bookingcmid = $DB->get_field_sql($sql, array('bookingoptionid' => $data->bookingoptionid, 'modulename' => 'booking'));

            return new booking_option($bookingcmid, $data->bookingoptionid);
        } else {
            // The identifier is unique in every instance, therefore we can find the id by id.
            $sql = "SELECT cm.id as cmid, bo.id as boid
                    FROM {course_modules} cm
                    INNER JOIN {booking_options} bo
                    ON cm.instance = bo.bookingid
                    INNER JOIN {modules} m
                    ON cm.module=m.id
                    WHERE cm.instance=:bookingid
                    AND m.name=:modulename
                    AND bo.identifier=:identifier";
            if ($result = $DB->get_record_sql($sql, array(
                    'bookingid' => $data->bookingid,
                    'modulename' => 'booking',
                    'identifier' => $data->identifier))) {
                return new booking_option($result->cmid, $result->boid);
            }
        }

        // If the mergeparam marker is 0 or 1, we create a new booking option.
        // Therefore, we have to create a booking instance first.
        // Analyze data to know where to retrieve the right booking instance.

        return null;
    }

    /**
     * There are several ways to find out to which booking instance we should add this booking option.
     * @param $data
     * @return int|null
     */
    private function return_booking_id(&$data): ?int {

        global $DB;

        if (isset($data->bookingcmid)) {
            // If we have received a bookingid, we just take this value.
            if (!$bookingid = $DB->get_field('course_modules', 'instance', array('id' => $data->bookingcmid))) {
                $bookingid = 0;
            }
        } else if (isset($data->bookingidnumber)) {

            $sql = "SELECT cm.instance
                    FROM {booking} b
                    INNER JOIN {course_modules} cm
                    ON cm.instance=b.id
                    INNER JOIN {modules} m
                    ON cm.module=m.id
                    WHERE m.name=:modulename
                    AND cm.idnumber=:idnumber";

            if (!$bookingid = $DB->get_field_sql($sql, array('idnumber' => $data->bookingidnumber, 'modulename' => 'booking'))) {
                return 0;
            }
        } else if (isset($data->targetcourseid)) {
            // If there is a moodle target courseid, we just get the booking instances in this course.
            // There can only be one visible booking instance in every course.
            $bookinginstances = get_coursemodules_in_course('booking', $data->targetcourseid);

            $bookinginstances = array_filter($bookinginstances, function($x) {
                return ($x->visible) == 1 && ($x->deletioninprogress == 0);
            });

            if (count($bookinginstances) != 1) {
                throw new \moodle_exception('wrongnumberofinstances', 'mod_booking', null, null,
                        'There should be only one visible booking activity in the course.');
            }

            $bookinginstance = reset($bookinginstances);

            $bookingid = $bookinginstance->instance;
        } else if (isset($data->courseidnumber)) {
            // If we can identify the course via courseidnumber, we do so.
            $data->targetcourseid = $DB->get_field('course', 'id', array('idnumber' => $data->courseidnumber));
            $bookingid = $this->return_booking_id($data);
        } else if (isset($data->courseshortname)) {
            // If we can identify the course via course shortname, we do so.
            $data->targetcourseid = $DB->get_field('course', 'id', array('shortname' => $data->courseshortname));
            $bookingid = $this->return_booking_id($data);
        }

        $data->bookingid = $bookingid;

        return $bookingid;
    }

    /**
     * Remapping changes the name of keys and transforms dates.
     * @param $data
     * @throws \moodle_exception
     */
    private function remap_data(&$data, $bookingoption) {

        global $DB;

        self::change_property($data, 'name', 'text');

        // Throw an error if coursestarttime is provided without courseendtime.
        if (!empty($data->coursestarttime) && empty($data->courseendtime)) {
            throw new \moodle_exception('startendtimeerror', 'mod_booking', null, null,
                'You provided coursestarttime but courseendtime is missing.');
        }

        // Throw an error if courseendtime is provided without coursestarttime.
        if (!empty($data->courseendtime) && empty($data->coursestarttime)) {
            throw new \moodle_exception('startendtimeerror', 'mod_booking', null, null,
                'You provided courseendtime but coursestarttime is missing.');
        }

        if ($bookingoption) {
            $data->optionid = $bookingoption->option->id;
        }

        // For mergeparams 1 and 2 both start time and end time need to be provided.
        if (($data->mergeparam == 1 || $data->mergeparam == 2)
            && empty($data->coursestarttime)
            && empty($data->courseendtime)) {
            throw new \moodle_exception('startendtimeerror', 'mod_booking', null, null,
                'For mergeparams 1 and 2 you need to provide start and end time.');
        }

        // We need to set startendtimeknown to 1 if both are provided.
        if (!empty($data->coursestarttime) && !empty($data->courseendtime)) {

            // Throw an error if courseendtime is not after course start time.
            if ($data->courseendtime <= $data->coursestarttime) {
                throw new \moodle_exception('startendtimeerror', 'mod_booking', null, null,
                    'Course end time needs to be AFTER course start time (not before or equal).');
            }

            // Check if it's no multisession.
            if (empty($data->mergeparam)) {
                $data->startendtimeknown = 1;
                $data->coursestarttime = strtotime($data->coursestarttime);
                $data->courseendtime = strtotime($data->courseendtime);
            } else if ($data->mergeparam == 1 || $data->mergeparam == 2 || $data->mergeparam == 3) {

                $data->startendtimeknown = 1;

                $data->coursestarttime = strtotime($data->coursestarttime);
                $data->courseendtime = strtotime($data->courseendtime);

                $createnewdates = true;
                foreach ($bookingoption->settings->sessions as $session) {
                    $data->stillexistingdates[$session->id] = "$session->coursestarttime - $session->courseendtime";

                    if ("$data->coursestarttime - $data->courseendtime" == "$session->coursestarttime - $session->courseendtime") {
                        $createnewdates = false;
                    }
                }
                if ($createnewdates) {
                    $data->newoptiondates[] = "$data->coursestarttime - $data->courseendtime";
                }

            }
        }
        // We need the limitanswers key if there are maxanswers.
        if (isset($data->maxanswers)) {
            $data->limitanswers = 1;
            // To make sure overbooking is not null, we set it here.
            if (!isset($data->maxoverbooking)) {
                $data->maxoverbooking = 0;
            }
        }

        // Make sure minanswers is not null.
        if (!isset($data->minanswers)) {
            $data->minanswers = 0;
        }

        // Set booking closing time.
        if (!empty($data->bookingclosingtime)) {
            $data->restrictanswerperiodclosing = 1;
            $data->bookingclosingtime = strtotime($data->bookingclosingtime);
        }

        // Set booking opening time.
        if (!empty($data->bookingopeningtime)) {
            $data->restrictanswerperiodopening = 1;
            $data->bookingopeningtime = strtotime($data->bookingopeningtime);
        }

        if (!empty($data->responsiblecontact)) {

            if (!$user = $DB->get_record('user', array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1,
            'email' => $data->responsiblecontact), 'id', IGNORE_MULTIPLE)) {

                throw new \moodle_exception('responsiblecontactnotsubscribed', 'mod_booking', null, null,
                'The contact with email ' . $data->responsiblecontact .
                ' does not exist in the target database.');
            } else {
                $data->responsiblecontact = $user->id;
            }

        }

        if (!empty($data->boav_enrolledincourse)) {

            $items = explode(',', $data->boav_enrolledincourse);

            list($inorequal, $params) = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
            $sql = "SELECT id
                    FROM {course}
                    WHERE shortname $inorequal";
            $courses = $DB->get_records_sql($sql, $params);

            $data->bo_cond_enrolledincourse_courseids = array_keys($courses);
            $data->restrictwithenrolledincourse = 1;
            unset($data->boav_enrolledincourse);
        }

        if (!empty($data->enroltocourseshortname)) {

            if ($courseid = $DB->get_field('course', 'id', ['shortname' => $data->enroltocourseshortname])) {
                $data->courseid = $courseid;
                unset($data->enroltocourseshortname);
            }

        }
    }

    /**
     * Helper function to change propertyname in object.
     * @param object $data
     * @param string $oldname
     * @param string $newname
     */
    private static function change_property(object &$data, string $oldname, string $newname) {
        if (isset($data->{$oldname})) {
            $data->{$newname} = $data->{$oldname};
            unset($data->{$oldname});
        }
    }

    /**
     *
     * @param mixed $optionid
     * @param mixed $data
     * @return void
     * @throws moodle_exception
     * @throws coding_exception
     */
    private function add_customfields_to_bookingoption($optionid, $data) {
        if (!empty($data->recommendedin)) {

            $handler = booking_handler::create();
            $handler->field_save($optionid, 'recommendedin', $data->recommendedin);
        }
    }

    /**
     * Add the teacher information to the booking option.
     * @param mixed $optionid
     * @param mixed $data
     * @return void
     * @throws moodle_exception
     * @throws coding_exception
     */
    private function add_teacher_to_bookingoption($optionid, $data) {
        global $DB;

        // If no teacher e-mail is provided, we don't do anything.
        if (empty($data->teacheremail) || !strpos($data->teacheremail, "@")) {
            return;
        }

        // If a teacher e-mail is provided, but the teacher can't be found in the DB, we throw an error.
        if (!$userids = $DB->get_fieldset_select('user', 'id', 'email=:email', array('email' => $data->teacheremail))) {
            throw new \moodle_exception('teachernotsubscribed', 'mod_booking', null, null,
                'The teacher with email ' . $data->teacheremail .
                ' does not exist in the target database.');
        }

        if (count($userids) != 1) {
            throw new \moodle_exception('nomatchingteacheremail', 'mod_booking', null, null,
                    'Teacher email is not there or not unique');
        }
        $userid = reset($userids);

        // Try to subscribe teacher to booking option and throw an error if not successful.
        if (!subscribe_teacher_to_booking_option($userid, $optionid, $this->cm->id)) {
            throw new \moodle_exception('teachernotsubscribed', 'mod_booking', null, null,
                'The teacher with e-mail ' . $data->teacheremail .
                ' could not be subscribed to the option with optionid ' . $optionid);
        }
    }
}
