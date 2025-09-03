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

use context_course;
use local_taskflow\local\supervisor\supervisor;
use mod_booking\bo_availability\conditions\confirmation;
use mod_booking\local\interfaces\bookingextension\confirmbooking_interface;
use context_module;
use context_system;
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
        global $USER, $DB;
        $approved = false;
        $message = get_string('notallowedtoconfirm', 'bookingextension_confirmation_trainer');
        $reload = false;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);

        // TODO: MDL-0 Since supervisor and HR have the same capability, we need to check
        // if we really need something to prevent the user from confirming the booking answer
        // when the user is a supervisor or HR.
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
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql(array &$params): string {
        $sql = '';

        global $DB;

        $driver = $DB->get_dbfamily();

        switch ($driver) {
            case 'postgres':
                $sql = $this->return_where_sql_postgres($params);
                break;
            case 'mysql':
                $sql = $this->return_where_sql_mysql($params);
                break;
            default: // Fallback.
                throw new \moodle_exception('Unsupported DB driver: ' . $driver);
        }

        return $sql;
    }

    /**
     * This returns the sql corresponding to the right settings.
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql_postgres(array &$params): string {

        // The logic needs to be like this.

        return " ( bo.json::jsonb ->> 'waitforconfirmation' = '1'
                    AND bo.json::jsonb ->> 'confirmationtrainerenabled' = '1' ) ";
    }

    /**
     * This returns the sql corresponding to the right settings.
     * When adding params, make sure the don't interfer.
     * Prefix them with bec (bookingextension_confirmation), eg bectuserid or becsuserid.
     * @param array $params
     *
     * @return string
     *
     */
    public function return_where_sql_mysql(array &$params): string {
        return " ( JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.waitforconfirmation')) = '1'
                AND JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.confirmationtrainerenabled')) = '1' )";
    }

    /**
     * Returns the number of required confirmations based on the booking option settings.
     *
     * @param int $optionid
     * @return int Number of confirmations needed (e.g., 1 or 2)
     */
    public static function get_required_confirmation_count(int $optionid): int {
        return 1;
    }
}
