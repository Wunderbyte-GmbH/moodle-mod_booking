<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class confirmation_trainer.
 *
 * @package     bookingextension_confirmation_trainer
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_confirmation_trainer\local;

use local_taskflow\local\supervisor\supervisor;
use mod_booking\bo_availability\conditions\confirmation;
use mod_booking\local\interfaces\bookingextension\confirmbooking_interface;
use context_module;
use mod_booking\singleton_service;

/**
 * Class to confirmbookings
 */
class confirmbooking implements confirmbooking_interface {
    /**
     * A subplugin can implement it's own way to add ways to allow supervisors to approve requests on waitinglist.
     * If the first value in the aray is true, this means that the test was successful.
     *
     * @param int $optionid
     * @param int $approverid
     * @param int $userid
     *
     * @return array // Returns [false, 'Reason why you are not allowed to book', false] // where last value is reload.
     *
     */
    public static function has_capability_to_confirm_booking(int $optionid, int $approverid, int $userid): array {

        $approved = false;
        $message = get_string('notallowedtoconfirm', 'bookingextension_confirmation_trainer');
        $reload = false;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        if (has_capability('mod/booking:bookforothers', $context)) {
            $approved = true;
            $message = '';
        }

        return [$approved, $message, $reload];
    }

    /**
     * Function to return the name of the workflow.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string('workflowname', 'bookingextension_confirmation_trainer');
    }

    /**
     * Function to return a detailed description of the workflow.
     *
     * @return string
     *
     */
    public function get_description(): string {
        return get_string('workflowdescription', 'bookingextension_confirmation_trainer');
    }

    /**
     * This returns the sql corresponding to the right settings.
     *
     * @return string
     *
     */
    public function return_where_sql(): string {
        $sql = '';

        global $DB;

        $driver = $DB->get_dbfamily();

        switch ($driver) {
            case 'postgres':
                $sql = $this->return_where_sql_postgres();
                break;
            case 'mysql':
                $sql = $this->return_where_sql_mysql();
                break;
            default: // Fallback.
                throw new \moodle_exception('Unsupported DB driver: ' . $driver);
        }

        return $sql;
    }

    /**
     * This returns the sql corresponding to the right settings.
     *
     * @return string
     *
     */
    public function return_where_sql_postgres(): string {

        // The logic needs to be like this:

        Depending on the chosen setting in the column json,
        we either verify that the current user is a supervisor
        or the user is a HR
        and we need first confirmation
        or we need second confirmation

        Actually, i guess supervisors should see the need for confirmation from HR and HR from supervisor
        so probably that should not even be an issue.

        So we just need to make sure the user is allowed to see the settings.
        The supervisor confirmation goes on hr, supervisorfield and deputy field.
        The trainer approval goes on being trainer for a given booking option.
        (might also need to check the context capabilities on all booking instances, when we think of it);




        return " bo.json::jsonb ->> 'waitforconfirmation' = '1' ";
    }

    /**
     * This returns the sql corresponding to the right settings.
     *
     * @return string
     *
     */
    public function return_where_sql_mysql(): string {
        return " JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.waitforconfirmation')) = '1' ";
    }
}
