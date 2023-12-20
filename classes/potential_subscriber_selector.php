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

use mod_booking\subscriber_selector_base;

/**
 * A user selector control for potential subscribers to the selected booking
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class potential_subscriber_selector extends subscriber_selector_base {

    /**
     * If set to true EVERYONE in this course is force subscribed to this booking
     *
     * @var bool
     */
    protected $forcesubscribed = false;

    /**
     * Can be used to store existing subscribers so that they can be removed from the potential subscribers list
     *
     * @var array
     */
    protected $existingsubscribers = [];

    /**
     * Constructor method
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed = true;
        }
    }

    /**
     * Returns an arary of options for this control
     *
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed === true) {
            $options['forcesubscribed'] = 1;
        }
        return $options;
    }

    /**
     * Finds all potential users (teachers). Potential subscribers are all, who are not already subscribed.
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $whereconditions = [];

        list($wherecondition, $params) = $this->search_sql($search, 'u');
        if ($wherecondition) {
            $whereconditions[] = $wherecondition;
        }

        $whereconditions[] = 'u.suspended = 0';

        if (!$this->forcesubscribed) {
            $existingids = [];
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    $existingids[$user->id] = 1;
                }
            }
            if ($existingids) {
                list($usertest, $userparams) = $DB->get_in_or_equal(array_keys($existingids),
                        SQL_PARAMS_NAMED, 'existing', false);
                $whereconditions[] = 'u.id ' . $usertest;
                $params = array_merge($params, $userparams);
            }
        }

        if ($whereconditions) {
            $wherecondition = 'WHERE ' . implode(' AND ', $whereconditions);
        }

        $fields = 'SELECT ' . $this->required_fields_sql('u');
        $countfields = 'SELECT COUNT(u.id)';

        $sql = " FROM {user} u
        $wherecondition";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        // Check to see if there are too many to show sensibly.
        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        // If not, show them.
        $availableusers = $DB->get_records_sql($fields . $sql . $order,
                array_merge($params, $sortparams));

        if (empty($availableusers)) {
            return [];
        }

        if ($this->forcesubscribed) {
            return [get_string("existingsubscribers", 'booking') => $availableusers];
        } else {
            return [get_string("potentialsubscribers", 'booking') => $availableusers];
        }
    }

    /**
     * Sets the existing subscribers
     *
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this booking as force subscribed or not
     *
     * @param bool $setting
     *
     * @return void
     *
     */
    public function set_force_subscribed($setting = true) {
        $this->forcesubscribed = true;
    }
}
