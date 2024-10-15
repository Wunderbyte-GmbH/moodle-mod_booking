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
 * Activity custom completion for the booking activity.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\completion;

use Exception;
use core_completion\activity_custom_completion;
use mod_booking\singleton_service;

/**
 * Activity custom completion subclass for the booking activity.
 *
 * Class for defining mod_booking's custom completion rules and fetching the completion statuses
 * of the custom completion rules for a given booking instance and a user.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {

    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        if (!$booking = singleton_service::get_instance_of_booking_by_cmid($this->cm->id)) {
            throw new Exception("Can't find booking {$this->cm->id}");
        }

        // Feedback only supports completionsubmit as a custom rule.
        $status = $DB->count_records('booking_answers',
            ['bookingid' => $booking->id, 'userid' => $this->userid, 'completed' => '1']);

        return $booking->settings->enablecompletion <= $status ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionoptioncompleted'];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $completionoptioncompleted = $this->cm->customdata['customcompletionrules']['completionoptioncompleted'] ?? 0;
        return ['completionoptioncompleted' => get_string(
            'completionoptioncompletedcminfo',
            'booking',
            $completionoptioncompleted
        )];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionoptioncompleted'];
    }
}
