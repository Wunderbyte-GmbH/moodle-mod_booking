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
                'switchids' => [
                    'booking' => 'bookingid',
                    'course' => 'courseid',
                    'semester' => 'semesterid',
                    // Optional 'entity' column: a local_entities entity name; the resolved id lands
                    // in the option-level entity form field, so the created option gets the entity
                    // relation and location exactly like a form submission.
                    'entity' => 'local_entities_entityid_0',
                    // Availability conditions that reference other records by id: the columns take
                    // names (option text, usernames, cohort idnumbers) and land in the form fields.
                    'previouslybookedoption' => 'bo_cond_previouslybooked_optionid',
                    'selectusers' => 'bo_cond_selectusers_userids',
                    'cohorts' => 'bo_cond_enrolledincohorts_cohortids',
                ],
            ],
            'templates' => [
                'singular' => 'template',
                'datagenerator' => 'template',
                'required' => ['booking', 'templatename'],
                'switchids' => ['booking' => 'bookingid', 'course' => 'courseid'],
            ],
            'bookingimages' => [
                'singular' => 'bookingimage',
                'datagenerator' => 'bookingimage',
                'required' => ['filepath', 'filename', 'booking'],
                'switchids' => ['booking' => 'bookingid'],
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
                'singular' => 'rule',
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

        // Support explicit disambiguation for duplicate booking names:
        // "Booking name::COURSESHORTNAME", e.g. "My booking::C2".
        if (strpos($bookingname, '::') !== false) {
            [$name, $courseshortname] = array_map('trim', explode('::', $bookingname, 2));
            $courseid = $DB->get_field('course', 'id', ['shortname' => $courseshortname]);
            if (!$courseid) {
                throw new Exception('The specified course shortname "' . $courseshortname . '" does not exist');
            } else {
                $id = $DB->get_field('booking', 'id', ['name' => $name, 'course' => $courseid]);
                if (!$id) {
                    throw new Exception('The specified booking activity with name "'
                        . $name . '" and course shortname "' . $courseshortname . '" does not exist');
                }
                return $id;
            }
        } else {
            if (!$id = $DB->get_field('booking', 'id', ['name' => $bookingname])) {
                throw new Exception('The specified booking activity with name "' . $bookingname . '" does not exist');
            }
            return $id;
        }
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

    /**
     * Resolve the option referenced by the 'previouslybookedoption' column to its id.
     *
     * @param string $optiontext
     * @return int
     */
    protected function get_previouslybookedoption_id(string $optiontext): int {
        return $this->get_option_id($optiontext);
    }

    /**
     * Resolve the comma separated usernames of the 'selectusers' column to user ids.
     *
     * @param string $usernames
     * @return int[]
     */
    protected function get_selectusers_id(string $usernames): array {
        global $DB;
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $usernames))) as $username) {
            if (!$id = $DB->get_field('user', 'id', ['username' => $username])) {
                throw new Exception('The specified user with username "' . $username . '" does not exist');
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /**
     * Resolve the comma separated cohort idnumbers of the 'cohorts' column to cohort ids.
     *
     * @param string $idnumbers
     * @return int[]
     */
    protected function get_cohorts_id(string $idnumbers): array {
        global $DB;
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $idnumbers))) as $idnumber) {
            if (!$id = $DB->get_field('cohort', 'id', ['idnumber' => $idnumber])) {
                throw new Exception('The specified cohort with idnumber "' . $idnumber . '" does not exist');
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /**
     * Get the id of a local_entities entity by its name (for the optional 'entity' option column).
     *
     * @param string $name the entity name
     * @return int The entity id
     */
    protected function get_entity_id(string $name): int {
        global $DB;

        if (!$id = $DB->get_field('local_entities', 'id', ['name' => $name])) {
            throw new Exception('The specified entity with name "' . $name . '" does not exist (is local_entities installed?)');
        }
        return $id;
    }
}
