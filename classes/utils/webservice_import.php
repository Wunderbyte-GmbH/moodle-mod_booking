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

use mod_booking\booking;
use stdClass;
use mod_booking\booking_option;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class webservice_import
 * Import controller for webservice imports
 *
 * @package mod_booking\classes\utils
 */
class webservice_import {

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

        // Now we get the booking instance.
        if (!$bookingid = $this->return_booking_id($data)) {
            throw new \moodle_exception('nobookingid', 'mod_booking', null, null,
                    'We need a booking instance for webservice upload.');
        }

        $data->bookingid = $bookingid;

        if (!isset($data->bookingcmid)) {
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $data->bookingcmid = $cm->id;
        }

        // This will set the bookingoption to update (if there is one, else it will be null).
        $bookingoption = $this->check_if_update_option($data);

        // First, we remap data to make sure we can use it in update & creation.
        $this->remap_data($data, $bookingoption);

        if ($bookingoption == null) {
            // Create new booking option.
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $context = \context_module::instance($cm->id);
        } else {
            $context = $bookingoption->booking->context;
        }

        $bookingoptionid = booking_update_options($data, $context);

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
            $bookingid = $DB->get_field_sql($sql, array('bookingoptionid' => $data->bookingoptionid, 'modulename' => 'booking'));

            return new booking_option($bookingid, $data->bookingoptionid);
        } else if ($data->mergeparam == 2 || $data->mergeparam == 3) {
            // If there is the multisession marker 2 or 3, we might return a booking option.
            // First, we look at the last bookingoption created. Is it in the same instance?
            $sql = "SELECT MAX(id) FROM {booking_options}";
            if ($lastbookingoptionid = $DB->get_field_sql($sql)) {
                if (($bookingoption = new booking_option($data->bookingcmid, $lastbookingoptionid))
                        && ($bookingoption->option->text == $data->name) // Text is called 'name' before remap.
                        && ($bookingoption->option->description == $data->description)) {
                    return $bookingoption;
                }
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
        } else if (isset($data->courseid)) {
            // If there is a moodle courseid, we just get the booking instances in this course.
            // There can only be one visible booking instance in every course.
            $bookinginstances = get_coursemodules_in_course('booking', $data->courseid);

            $bookinginstances = array_filter($bookinginstances, function($x) {
                return $x->visible == 1;
            });

            if (count($bookinginstances) != 1) {
                throw new \moodle_exception('wrongnumberofinstances', 'mod_booking', null, null,
                        'There should be only one visible booking activity in the course.');
            }

            $bookinginstance = reset($bookinginstances);

            $bookingid = $bookinginstance->instance;
        } else if (isset($data->courseidnumber)) {
            // If we can identify the course via courseidnumber, we do so.
            $data->courseid = $DB->get_field('course', 'id', array('idnumber' => $data->courseidnumber));
            $bookingid = $this->return_booking_id($data);
        } else if (isset($data->courseshortname)) {
            // If we can identify the course via course shortname, we do so.
            $data->courseid = $DB->get_field('course', 'id', array('shortname' => $data->courseshortname));
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
            } else if ($data->mergeparam == 1 || $data->mergeparam == 2) {

                $data->startendtimeknown = 1;
                // TODO: also check if it gets added to calendar correctly.

                $data->coursestarttime = strtotime($data->coursestarttime);
                $data->courseendtime = strtotime($data->courseendtime);

                $sessionkey = self::return_next_sessionkey($data, $bookingoption);

                $startkey = 'ms' . $sessionkey . 'starttime';
                $endkey = 'ms' . $sessionkey. 'endtime';

                if ($data->mergeparam == 1) {
                    // We don't change, but just add multisession, because here there is only one.
                    $data->{$startkey} = $data->coursestarttime;
                    $data->{$endkey} = $data->courseendtime;
                } else {
                    self::change_property($data, 'coursestarttime', $startkey);
                    self::change_property($data, 'courseendtime', $endkey);
                }

            } else if ($data->mergeparam == 3) {

                //TODO: Merge teachers to booking option.
            }
        }

        // Set booking closing time.
        $data->bookingclosingtime = strtotime($data->bookingclosingtime);
    }

    /**
     * Function to return the key for the next session, by counting existing ones.
     * If no bookingoption as of yet, we return 1.
     * @param object $data
     * @param booking_option|null $bookingoption
     * @return int
     */
    function return_next_sessionkey(object $data, booking_option $bookingoption = null) {
        if ($bookingoption) {
            return count($bookingoption->sessions) + 1;
        } else {
            return 1;
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
}
