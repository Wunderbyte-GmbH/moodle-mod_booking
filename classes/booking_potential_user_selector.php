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

use context_module;
use mod_booking\booking_user_selector_base;
use stdClass;

/**
 * Сlass used by гser selector for booking other users
 *
 * @package mod_booking
 * @copyright 2013 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_potential_user_selector extends booking_user_selector_base {
    /** @var array $options */
    public $options;

    /**
     * Constructor
     *
     * @param string $name
     * @param array $options
     *
     */
    public function __construct($name, $options) {

        $this->options = $options;
        parent::__construct($name, $options);
    }

    /**
     * Find users.
     *
     * @param string $search
     *
     * @return array
     *
     */
    public function find_users($search) {
        global $DB;

        $bookanyone = get_user_preferences('bookanyone', false);

        $onlygroupmembers = false;
        if (
            groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS &&
                !has_capability(
                    'moodle/site:accessallgroups',
                    \context_course::instance($this->course->id)
                )
        ) {
            $onlygroupmembers = true;
        }

        $fields = "SELECT " . $this->required_fields_sql("u");

        $countfields = 'SELECT COUNT(1)';
        [$searchcondition, $searchparams] = $this->search_sql($search, 'u');
        $groupsql = '';
        if ($onlygroupmembers) {
            [$groupsql, $groupparams] = \mod_booking\booking::booking_get_groupmembers_sql($this->course->id);
            [$enrolledsql, $eparams] = get_enrolled_sql($this->options['accesscontext'], null, null, true);
            $groupsql = " AND u.id IN (" . $groupsql . ")";
            $params = array_merge($eparams, $groupparams);
        } else {
            [$enrolledsql, $params] = get_enrolled_sql($this->options['accesscontext'], null, null, true);
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

        // If true, anyone can be booked - even users not enrolled.
        // To allow this, bookanyone has to be given.
        if (
            $bookanyone
            && has_capability('mod/booking:bookanyone', context_module::instance($this->cm->id))
        ) {
            $enrolledsqlpart = '';
        } else {
            $enrolledsqlpart = "AND u.id IN (
                SELECT esql.id
                FROM ($enrolledsql) AS esql
                WHERE esql.id > 1
            )";
        }

        $sql = " FROM {user} u
        WHERE $searchcondition
        AND u.suspended = 0
        AND u.deleted = 0
        $enrolledsqlpart
        $groupsql
        AND u.id NOT IN (
            SELECT ba.userid
            FROM {booking_answers} ba
            WHERE ba.optionid = {$this->options['optionid']}
            AND waitinglist <> :statusparamdeleted
        )";

        $searchparams['statusparamdeleted'] = MOD_BOOKING_STATUSPARAM_DELETED;

        [$sort, $sortparams] = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql(
                $countfields . $sql,
                array_merge($searchparams, $params)
            );
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        $availableusers = $DB->get_records_sql(
            $fields . $sql . $order,
            array_merge($searchparams, $params, $sortparams)
        );

        if (empty($availableusers)) {
            return [];
        }

        if ($bookanyone) {
            if ($search) {
                $groupname = get_string('usersmatching', 'mod_booking');
            } else {
                $groupname = get_string('allmoodleusers', 'mod_booking');
            }
        } else {
            if ($search) {
                $groupname = get_string('usersmatching', 'mod_booking');
            } else {
                $groupname = get_string('enrolledusers', 'mod_booking');
            }
        }

        return [$groupname => $availableusers];
    }
}
