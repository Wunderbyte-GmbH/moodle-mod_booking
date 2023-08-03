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
 * Behat data generator for mod_booking.
 *
 * @package   mod_booking
 * @category  test
 * @copyright 2023 Andrii Semenets
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_booking_generator extends behat_generator_base {

    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'options' => [
                'singular' => 'option',
                'datagenerator' => 'option',
                'required' => ['booking', 'text', 'course', 'description'],
                'switchids' => ['booking' => 'bookingid', 'course' => 'courseid'],
            ],
            'pricecategories' => [
                'datagenerator' => 'pricecategory',
                'required' => ['ordernum', 'name', 'defaultvalue'],
            ],
            'campaigns' => [
                'datagenerator' => 'campaign',
                'required' => ['name', 'type', 'json', 'starttime', 'endtime', 'pricefactor', 'limitfactor'],
            ],
            'semesters' => [
                'datagenerator' => 'semester',
                'required' => ['identifier', 'name', 'startdate', 'enddate'],
            ],
        ];
    }

    /**
     * Get the booking CMID using an activity idnumber.
     *
     * @param string $idnumber
     * @return int The cmid
     */
    protected function get_booking_id(string $bookingname): int {
        global $DB;

        if (!$id = $DB->get_field('booking', 'id', array('name' => $bookingname))) {
            throw new Exception('The specified booking activity with name "' . $bookingname . '" does not exist');
        }
        return $id;
    }
}
