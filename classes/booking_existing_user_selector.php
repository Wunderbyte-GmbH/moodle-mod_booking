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

namespace mod_booking;

use mod_booking\booking_user_selector_base;
use stdClass;

/**
 * User selector control for removing booked users
 *
 * @package mod_booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_existing_user_selector extends booking_user_selector_base {
    /**
     * $potentialusers
     *
     * @var mixed
     */
    public $potentialusers;

    /**
     * $options
     *
     * @var array
     */
    public $options;

    /**
     * Constructor
     *
     * @param string $name
     * @param array $options
     *
     */
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
        global $DB;

        // Only active enrolled or everybody on the frontpage.
        $fields = "SELECT " . $this->required_fields_sql("u");
        $countfields = 'SELECT COUNT(1)';
        [$searchcondition, $searchparams] = $this->search_sql($search, 'u');
        [$sort, $sortparams] = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!empty($this->potentialusers)) {
            $potentialuserids = array_keys($this->potentialusers);
            [$subscriberssql, $subscribeparams] = $DB->get_in_or_equal($potentialuserids, SQL_PARAMS_NAMED, "in_");
        } else {
            return [];
        }

        $option = new stdClass();
        $option->id = $this->options['optionid'];
        $option->bookingid = $this->options['bookingid'];

        if (
            booking_check_if_teacher($option) && !has_capability(
                'mod/booking:readallinstitutionusers',
                $this->options['accesscontext']
            )
        ) {
            $institution = $DB->get_record(
                'booking_options',
                ['id' => $this->options['optionid']]
            );

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

        $availableusers = $DB->get_records_sql(
            $fields . $sql . $order,
            array_merge($searchparams, $sortparams, $subscribeparams)
        );

        if (empty($availableusers)) {
            return [];
        }

        return [get_string("booked", 'booking') => $availableusers];
    }
}
