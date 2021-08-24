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

global $CFG;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

/**
 * Class webservice_import
 * Import controller for webservice imports
 *
 * @package mod_booking\classes\utils
 */
class webservice_import {

    /**
     * In the constructor of this class, we don't know which booking instance will be used.
     * We have to interprete the data first.
     */
    public function __construct() {

    }

    /**
     * This function verifies if the given data should be merged with an existing booking option.
     * If so, we merge.
     * If not, we create a new booking option.
     * @param $data
     */
    public function process_data($data) {

        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        // First, we remap data to make sure we can use it in update & creation.
        $this->remap_data($data);

        // Now we get the booking instance.
        if (!$bookingid = $this->return_booking_id($data)) {
            throw new \moodle_exception('nobookingid', 'mod_booking', null, null,
                    'We need a booking instance for webservice upload.');
        }

        if ($bookingoption = $this->check_if_update_option($data)) {
            // Do something.
        } else {
            // Create new booking option.
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $context = \context_module::instance($cm->id);

            $bookingoptionid = booking_update_options($data, $context);
        }

        return array('status' => 1);
    }

    /**
     * Verify if we have enough overlapping with an existing booking option so we can update.
     * Returns 0 if we have to create a new option, else bookingoptionid.
     * @param $data
     * @return booking_option|null
     */
    private function check_if_update_option($data): ?booking_option {

        global $DB;
        // Array of Keys to check:
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
        }

        // If the ismultisession marker is 0 or 1, we create a new booking option. Therefore, we have to create a booking instance first.
        // Analyze data to know where to retrieve the right booking instance.

        // $bookingid = $this->return_booking_id($data);

        return null;
    }

    /**
     * There are several ways to find out to which booking instance we should add this booking option.
     * @param $data
     * @return int|null
     */
    private function return_booking_id(&$data): ?int {

        global $DB;

        if (isset($data->bookingid)) {
            // If we have received a bookingid, we just take this value.
            return $data->bookingid;
        } else if (isset($data->courseid)) {
            // If there is a moodle courseid, we just get the booking instances in this course.
            // There can only be one visible booking instance in every course.
            if (!isset($data->bookingslotnumber)) {
                $data->bookingslotnumber = 0;
            }
            $bookinginstances = get_coursemodules_in_course('booking', $data->courseid);

            $bookinginstances = array_filter($bookinginstances, function($x) { return $x->visible == 1; });

            if (count($bookinginstances) != 1) {
                throw new \moodle_exception('wrongnumberofinstances', 'mod_booking', null, null,
                        'There should be only one visible booking activity in the course.');
            }

            $bookinginstance = reset($bookinginstances);

            $bookingid = $bookinginstance->instance;
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
        }

        $data->bookingid = $bookingid;

        return $bookingid;
    }

    /**
     * Remapping changes the name of keys and transforms dates.
     * @param $data
     */
    private function remap_data(&$data) {
        self::change_property($data, 'name', 'text');
        $data->coursestarttime = strtotime($data->coursestarttime);
        $data->courseendtime = strtotime($data->courseendtime);
        $data->bookingclosingtime = strtotime($data->bookingclosingtime);
    }

    /**
     * Helper function to change propertyname in object.
     * @param $data
     * @param $oldname
     * @param $newname
     */
    private static function change_property(&$data, $oldname, $newname) {
        if (isset($data->{$oldname})) {
            $data->{$newname} = $data->{$oldname};
            unset($data->{$oldname});
        }
    }
}