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
use mod_booking\option\fields\multiplebookings;
use mod_booking\singleton_service;
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
            [$enrolledsql, $params] = get_enrolled_sql($this->accesscontext, null, null, true);
        }

        // When "book again" (multiplebookings) is enabled on the option, already-booked users may be
        // re-subscribed, so they must not be excluded outright from the potential list below.
        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $bookagainenabled = ((int)($settings->jsonobject->multiplebookings ?? multiplebookings::MODE_DISABLED))
            !== multiplebookings::MODE_DISABLED;

        if (
            booking_check_if_teacher($this->optionid) && !has_capability(
                'mod/booking:readallinstitutionusers',
                $this->options['accesscontext']
            )
        ) {
            $institution = $DB->get_record(
                'booking_options',
                ['id' => $this->optionid]
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

        if ($bookagainenabled) {
            // Only exclude users in a pending state (waiting list / reserved / notify-me). Currently
            // booked users stay in the list so a trainer can re-subscribe them; they are then filtered
            // by the book-again timing gate in PHP after the query (see below).
            $pendingstatuses = [
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
                MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
            ];
            [$pendingsql, $pendingparams] = $DB->get_in_or_equal($pendingstatuses, SQL_PARAMS_NAMED, 'pending');
            $notbookedsql = "AND u.id NOT IN (
                SELECT ba.userid
                FROM {booking_answers} ba
                WHERE ba.optionid = {$this->optionid}
                AND ba.waitinglist $pendingsql
            )";
            $searchparams = array_merge($searchparams, $pendingparams);
        } else {
            $notbookedsql = "AND u.id NOT IN (
                SELECT ba.userid
                FROM {booking_answers} ba
                WHERE ba.optionid = {$this->optionid}
                AND waitinglist <> :statusparamdeleted
            )";
            $searchparams['statusparamdeleted'] = MOD_BOOKING_STATUSPARAM_DELETED;
        }

        $sql = " FROM {user} u
        WHERE $searchcondition
        AND u.suspended = 0
        AND u.deleted = 0
        $enrolledsqlpart
        $groupsql
        $notbookedsql";

        // Agents who are not unrestricted (eg. supervisors with only "bookmyteam") only see their own team.
        global $USER;
        $teamuserids = \mod_booking\local\bookingworkflow\bookforothers::get_bookable_target_ids(
            $this->optionid,
            $USER->id
        );
        if ($teamuserids !== null) {
            if (empty($teamuserids)) {
                return [];
            }
            [$teamsql, $teamparams] = $DB->get_in_or_equal($teamuserids, SQL_PARAMS_NAMED, 'team');
            $sql .= " AND u.id $teamsql";
            $searchparams = array_merge($searchparams, $teamparams);
        }

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

        if ($bookagainenabled) {
            // Respect the book-again timing gate: an already-booked user is only offered for
            // re-subscription when they are currently eligible to book again (same rule the user's
            // own re-booking follows). Eligible ones are flagged for the "(is already booked)" label.
            $usersonlist = singleton_service::get_instance_of_booking_answers($settings)->get_usersonlist();
            foreach ($availableusers as $uid => $user) {
                if (
                    isset($usersonlist[$uid])
                    && (int)$usersonlist[$uid]->waitinglist === MOD_BOOKING_STATUSPARAM_BOOKED
                ) {
                    if (!multiplebookings::book_again_due($this->optionid, $usersonlist[$uid])) {
                        unset($availableusers[$uid]);
                        continue;
                    }
                    $user->bookingalreadybooked = true;
                }
            }
            if (empty($availableusers)) {
                return [];
            }
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

    /**
     * Output one user row, appending an "(is already booked)" hint for re-subscribable users.
     *
     * @param stdClass $user
     * @return string
     */
    public function output_user($user) {
        $out = parent::output_user($user);
        if (!empty($user->bookingalreadybooked)) {
            $out .= get_string('subscribealreadybooked', 'mod_booking');
        }
        return $out;
    }
}
