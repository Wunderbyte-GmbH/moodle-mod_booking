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
 * Defines message providers (types of messages being sent)
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\booking;
use mod_booking\singleton_service;

/**
 * To create booking specific behat scearios.
 */
class behat_booking extends behat_base {

    /**
     * Create booking option in booking instance
     * @Given /^I create booking option "(?P<optionname_string>(?:[^"]|\\")*)" in "(?P<instancename_string>(?:[^"]|\\")*)"$/
     * @param string $optionname
     * @param string $instancename
     * @return void
     */
    public function i_create_booking_option($optionname, $instancename) {

        $cm = $this->get_cm_by_booking_name($instancename);

        $booking = singleton_service::get_instance_of_booking_by_cmid($cm->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optionname;
        $record->courseid = $cm->course;
        $record->description = 'Test description';

        $datagenerator = \testing_util::get_data_generator();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $datagenerator->get_plugin_generator('mod_booking');
        $bookingoption1 = $plugingenerator->create_option($record);
    }

    /**
     * Follow a certain link
     * @Given /^I open the link "(?P<linkurl_string>(?:[^"]|\\")*)"$/
     * @param string $linkurl
     * @return void
     */
    public function i_open_the_link($linkurl) {
        $this->getSession()->visit($linkurl);
    }

    /**
     * Get a booking by booking instance name.
     *
     * @param string $name booking instance name.
     * @return stdClass the corresponding DB row.
     */
    protected function get_booking_by_name(string $name): stdClass {
        global $DB;
        return $DB->get_record('booking', ['name' => $name], '*', MUST_EXIST);
    }

    /**
     * Get a booking coursemodule object from the name.
     *
     * @param string $name name.
     * @return stdClass cm from get_coursemodule_from_instance.
     */
    protected function get_cm_by_booking_name(string $name): stdClass {
        $booking = $this->get_booking_by_name($name);
        return get_coursemodule_from_instance('booking', $booking->id, $booking->course);
    }

}
