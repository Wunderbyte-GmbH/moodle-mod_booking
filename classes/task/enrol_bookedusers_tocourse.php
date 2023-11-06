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

namespace mod_booking\task;

use mod_booking\elective;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

class enrol_bookedusers_tocourse extends \core\task\scheduled_task {

    /**
     * @return string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('task_enrol_bookedusers_tocourse', 'mod_booking');
    }

    /**
     * Enrol users if course has started and this function has not yet been executed.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function execute() {

        global $DB;

        // Get all booking options with associated Moodle courses that have enrolmentstatus 0 and coursestartdate in the past.
        $select = "enrolmentstatus < 1 AND coursestarttime < :now";
        $now = time();
        $boids = $DB->get_records_select_menu('booking_options', $select, ['now' => $now], '', 'id, bookingid');

        foreach ($boids as $optionid => $bookingid) {

            if ($bookingid) {
                $cm = get_coursemodule_from_instance('booking', $bookingid);
            } else {
                mtrace("WARNING: Failed to get booking instance from option id: $optionid");
            }

            // Bugfix for #185.
            if (empty($cm->id)) {
                mtrace('WARNING: bookingid could not be matched with an existing course module.');
                continue;
            }

            $boption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);

            $booking = $boption->booking;
            // phpcs:ignore
            // $iselective = $booking->settings->iselective; TODO: delete this?
            $enforceorder = !empty($booking->settings->enforceorder) || !empty($booking->settings->enforceteacherorder) ? 1 : 0;

            // Get all booked users of the relevant booking options.
            $bookedusers = $boption->get_all_users_booked();

            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($booking->cmid);

            // Enrol all users to the course.
            foreach ($bookedusers as $bookeduser) {

                if ($bookingsettings->iselective
                    && $enforceorder == 1) {
                    if (!elective::check_if_allowed_to_inscribe($boption, $bookeduser->userid)) {
                        continue;
                    }
                }

                $boption->enrol_user($bookeduser->userid);

                // TODO: check if enrolment successful... (enrol_user needs to return bool).

                if (!empty($boption->option->courseid)) {
                    mtrace("The user with the {$bookeduser->userid} has been enrolled to the course {$boption->option->courseid}.");
                }

                // We update enrolement status of this option only if it's not an elective.
                if (empty($bookingsettings->iselective)) {
                    list($insql, $params) = $DB->get_in_or_equal([$optionid]);
                    $DB->set_field_select('booking_options', 'enrolmentstatus', '1', 'id ' . $insql, $params);
                }

            }
        }
    }
}
