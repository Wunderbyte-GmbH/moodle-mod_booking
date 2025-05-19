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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
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
                'required' => ['booking', 'text', 'description'],
                'switchids' => ['booking' => 'bookingid', 'course' => 'courseid', 'semester' => 'semesterid'],
            ],
            'answers' => [
                'singular' => 'answer',
                'datagenerator' => 'answer',
                'required' => ['booking', 'option', 'user'],
                'switchids' => ['booking' => 'bookingid', 'option' => 'optionid', 'user' => 'userid'],
            ],
            'pricecategories' => [
                'datagenerator' => 'pricecategory',
                'required' => ['ordernum', 'identifier', 'name', 'defaultvalue'],
            ],
            'prices' => [
                'singular' => 'price',
                'datagenerator' => 'price',
                'required' => ['itemname', 'area', 'pricecategoryidentifier', 'price', 'currency'],
            ],
            'campaigns' => [
                'datagenerator' => 'campaign',
                'required' => ['name', 'type', 'json', 'starttime', 'endtime', 'pricefactor', 'limitfactor'],
            ],
            'subbookings' => [
                'datagenerator' => 'subbooking',
                'required' => ['name', 'type', 'option', 'block', 'json'],
                'switchids' => ['option' => 'optionid'],
            ],
            'semesters' => [
                'datagenerator' => 'semester',
                'required' => ['identifier', 'name', 'startdate', 'enddate'],
            ],
            'rules' => [
                'datagenerator' => 'rule',
                'required' => [
                        'conditionname', 'contextid',
                        'name', 'actionname', 'actiondata',
                        'rulename', 'ruledata',
                ],
                'switchids' => ['booking' => 'bookingid'],
            ],
            'user purchases' => [
                'singular' => 'user purchase',
                'datagenerator' => 'user_purchase',
                'required' => ['booking', 'option', 'user'],
                'switchids' => ['booking' => 'bookingid', 'option' => 'optionid', 'user' => 'userid'],
            ],
            'actions' => [
                'singular' => 'action',
                'datagenerator' => 'action',
                'required' => ['option', 'action_type', 'boactionname', 'boactionjson'],
                'switchids' => ['option' => 'optionid'],
            ],
        ];
    }

    /**
     * Get the booking CMID using an activity idnumber.
     *
     * @param string $bookingname
     * @return int The cmid
     */
    protected function get_booking_id(string $bookingname): int {
        global $DB;

        if (!$id = $DB->get_field('booking', 'id', ['name' => $bookingname])) {
            throw new Exception('The specified booking activity with name "' . $bookingname . '" does not exist');
        }
        return $id;
    }

    /**
     * Get the semesterID using an identifier.
     *
     * @param string $identifier
     * @return int The semester id
     */
    protected function get_semester_id(string $identifier): int {
        global $DB;

        if (!$id = $DB->get_field('booking_semesters', 'id', ['identifier' => $identifier])) {
            throw new Exception('The specified booking semester with name "' . $identifier . '" does not exist');
        }
        return $id;
    }

    /**
     * Get the optionID using an identifier.
     *
     * @param string $identifier
     * @return int The option id
     */
    protected function get_option_id(string $identifier): int {
        global $DB;

        if (!$id = $DB->get_field('booking_options', 'id', ['text' => $identifier])) {
            throw new Exception('The specified booking option with name text "' . $identifier . '" does not exist');
        }
        return $id;
    }
}
