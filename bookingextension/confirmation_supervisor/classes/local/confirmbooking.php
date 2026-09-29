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
 * Class confirmation_supervisor.
 *
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_confirmation_supervisor\local;

use context_system;
use mod_booking\local\interfaces\bookingextension\confirmbooking_interface;
use context_module;
use mod_booking\singleton_service;

/**
 * Class to confirmbookings
 */
class confirmbooking implements confirmbooking_interface {
    use supervisor_relation_trait;

    /**
     * We use this property to decide about the restrictions.
     * When true, the supervisor can see & confirm all answers regardless of demand order settings.
     * @var bool $supervisorteam
     */
    public $supervisorteam = false;

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

        // Admin & all persons who have alwayscanapprove capability can confirm answers regardless of any other conditions.
        if (has_capability('mod/booking:alwayscanapprove', context_system::instance())) {
            $approved = true;
            $message = '';
            $reload = false;
            return [$approved, $message, $reload]; // Can approve regardless of any other conditions.
        }

        $approved = false;
        $message = get_string('notallowedtoconfirm', 'bookingextension_confirmation_supervisor');
        $reload = false;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        // A supervisor may hold only the restricted "book my team" capability instead of the
        // unrestricted "book for others". The supervisor / deputy relation is verified below,
        // so bookmyteam is sufficient to reach that check. Same gate as in subscribeusers.php.
        if (
            !has_capability('mod/booking:bookforothers', $context)
            && !has_capability('mod/booking:bookmyteam', $context)
        ) {
            return [$approved, $message, $reload]; // Can not approve.
        }

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $bookinganswer = ($bookinganswers->get_usersonwaitinglist())[$userid] ?? null;
        if (empty($bookinganswer)) {
            $message = 'Nothing to confirm';
            return [$approved, $message, $reload]; // Can not approve.
        }
        // Get current number of confirmation.
        $confirmationcount = (!empty($bookinganswer->json)) ?
            (json_decode($bookinganswer->json))->confirmationcount ?? 0 : 0;
        // Get list of users ids of approvers who currently confirmed.
        $confirmationby = (!empty($bookinganswer->json)) ?
            (json_decode($bookinganswer->json))->confirmwaitinglist_modifieduserid ?? [] : [];
        // Get User ID of the last approver.
        $lastconfirmationby = count($confirmationby) ? end($confirmationby) : 0;

        $bosettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (!property_exists($bosettings->jsonobject, 'confirmationsupervisorenabled')) {
            return [$approved, $message, $reload]; // Can not approve.
        }

        $confirmationsupervisorenabled = (int) $bosettings->jsonobject->confirmationsupervisorenabled;

        if (self::is_hr($approverid)) {
            // We need to check value of confirmationsupervisorenabled. HR should see 2, 3, 4, 5.
            // For type 2 (HR → supervisor), show only if confirmationcount = 0 in booking answer record.
            // For type 4 (supervisor → HR), show only if confirmationcount = 1 in booking answer record.
            if (!in_array($confirmationsupervisorenabled, [2, 3, 4, 5])) {
                // Cannot approve, because based on confirmationsupervisorenabled settings,
                // HR is not allowed to confirm the answers of this booking option.
                return [$approved, $message, $reload];
            }

            if ($confirmationsupervisorenabled === 4 && $confirmationcount == 0) {
                // Cannot approve, because the booking answer is not confirmed by supervisor yet and
                // HR must wait for confirmartion of supervisor.
                $message = get_string('needsconfirmationofsupervisor', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [2, 3]) && $confirmationcount >= 1)
                ||
                (in_array($confirmationsupervisorenabled, [4]) && $confirmationcount >= 2)
            ) {
                // Cannot approve, because HR confirmed the booking answer previously.
                $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [5]) && $confirmationcount >= 1)
            ) {
                // Check who has confirmed the ansewr, HR or supervisor, to return meaningful message.
                if ($lastconfirmationby == $USER->id) {
                    // Cannot approve, because HR confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                } else {
                    // Cannot approve, because Supervisor confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmedbysupervisor', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                }
            }
        } else {
            // Check if user user is supervisor (or deputy of supervisor) for the related user of the booking
            // answer record, then check if allowed to confirm, and check if his/her right in confirmation order.
            if (
                !self::is_supervisor($approverid, $userid)
                && !self::is_deputy($approverid, $userid)
            ) {
                // Canot approve because the user id of supervisor is not defined in the user profile
                // of the user who owns the booking answer.
                return [$approved, $message, $reload]; // Can not approve.
            }
            // We need to check value of confirmationsupervisorenabled. HR should see 1, 2, 4, 5.
            // For type 2 (HR → supervisor), show only if confirmationcount = 1 in booking answer record.
            // For type 4 (supervisor → HR), show only if confirmationcount = 0 in booking answer record.
            if (!in_array($confirmationsupervisorenabled, [1, 2, 4, 5])) {
                // Cannot approve, because based on confirmationsupervisorenabled settings,
                // Supervisor is not allowed to confirm the answers of this booking option.
                return [$approved, $message, $reload];
            }

            if ($confirmationsupervisorenabled === 2 && $confirmationcount == 0) {
                // Cannot approve, because supervisor confirmed the booking answer previously.
                $message = get_string('needsconfirmationofhr', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload]; // Can not approve.
            }

            if (
                (in_array($confirmationsupervisorenabled, [1, 4]) && $confirmationcount >= 1)
                ||
                (in_array($confirmationsupervisorenabled, [2]) && $confirmationcount >= 2)
            ) {
                // Cannot approve, because HR confirmed the booking answer previously.
                $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                return [$approved, $message, $reload];
            }

            if (
                (in_array($confirmationsupervisorenabled, [5]) && $confirmationcount >= 1)
            ) {
                // Check who has confirmed the ansewr, HR or supervisor, to return meaningful message.
                if ($lastconfirmationby == $USER->id) {
                    // Cannot approve, because Supervisor confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmed', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                } else {
                    // Cannot approve, because HR confirmed the booking answer previously.
                    $message = get_string('alreadyconfirmedbyhr', 'bookingextension_confirmation_supervisor');
                    return [$approved, $message, $reload];
                }
            }
        }

        $approved = true;
        $message = '';
        return [$approved, $message, $reload]; // Can approve.
    }

    /**
     * Function to return the name of the workflow.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string('workflowname', 'bookingextension_confirmation_supervisor');
    }

    /**
     * Function to return a detailed description of the workflow.
     *
     * @return string
     *
     */
    public function get_description(): string {
        return get_string('workflowdescription', 'bookingextension_confirmation_supervisor');
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

        global $USER, $DB;

        // Get the short name of the selected custom field for the supervisor field, then fetch the field ID from the database.
        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $supervisorfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $supervisorfieldshortname], IGNORE_MISSING);

        // Get the short name of the selected custom field for the deputy field, then fetch the field ID from the database.
        $deputyfieldshortname = get_config('bookingextension_confirmation_supervisor', 'deputy');
        $deputyfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $deputyfieldshortname], IGNORE_MISSING);

        // Get the list of HRs from config and explode it into an array.
        $hrids = explode(
            ',',
            get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        );
        $ishr = in_array($USER->id, $hrids);

        // Core JSON confirmation field.
        $waitforconfirmation = "(bo.json::jsonb ->> 'waitforconfirmation')::int IN (1,2)";

        if (has_capability('mod/booking:seealllisttoapprove', context_system::instance())) {
            // Admin & all persons who have seealllisttoapprove capability can see all answers.
            return "$waitforconfirmation";
        } else if ($ishr) {
            // HR should see 2, 3, 4, 5.
            $conflevel = "(bo.json::jsonb ->> 'confirmationsupervisorenabled')::int IN (2,3,4,5)";

            return "$waitforconfirmation AND $conflevel";
        } else {
            // Supervisors should see 1, 2, 4, 5 — but only if they're linked via profile field.
            $conflevel = "(bo.json::jsonb ->> 'confirmationsupervisorenabled')::int IN (1,2,4,5)";
            // Run a SQL query to extract an array of IDs that a supervisor or their deputies can see.
            // Then use the get_in_or_equal function to prepare this part of the query with parameters,
            // and include it in the main query.
            $sql = "
                SELECT userid
                FROM {user_info_data} uid
                WHERE uid.fieldid = :becsupervisorfieldid
                AND
                (
                    uid.data = :becssupervisorid
                    OR
                    uid.data IN (
                                    SELECT sup.userid::VARCHAR
                                    FROM {user_info_data} sup
                                    WHERE sup.fieldid = :becdeputyfieldid
                                    AND
                                    (
                                        sup.data = :becsdeputyid1
                                        OR
                                        :becsdeputyid2 = ANY (string_to_array(sup.data, ','))
                                    )
                                )
                )
            ";
            // Params.
            $params0['becssupervisorid'] = $USER->id;
            $params0['becsdeputyid1'] = $USER->id;
            $params0['becsdeputyid2'] = $USER->id;
            $params0['becsupervisorfieldid'] = $supervisorfieldid;
            $params0['becdeputyfieldid'] = $deputyfieldid;
            foreach ($params0 as $k => $v) {
                $params[$k] = $v;
            }

            // If $supervisorteam is true, the supervisor can see all answers regardless of confirmation enablement,
            // but only if they are linked via the profile field.
            if ($this->supervisorteam) {
                return "ba.userid IN ($sql)";
            }

            return "$waitforconfirmation AND $conflevel AND ba.userid IN ($sql)";
        }
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
        global $USER, $DB;

        // Get the short name of the selected custom field for the supervisor field, then fetch the field ID.
        $supervisorfieldshortname = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $supervisorfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $supervisorfieldshortname], IGNORE_MISSING);

        // Get the short name of the selected custom field for the deputy field, then fetch the field ID.
        $deputyfieldshortname = get_config('bookingextension_confirmation_supervisor', 'deputy');
        $deputyfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $deputyfieldshortname], IGNORE_MISSING);

        // Get the list of HRs from config and explode it into an array.
        $hrids = explode(',', get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers'));
        $ishr = in_array($USER->id, $hrids);

        // Core JSON confirmation field.
        $waitforconfirmation = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.waitforconfirmation')) AS UNSIGNED) > 0";

        if (has_capability('mod/booking:seealllisttoapprove', context_system::instance())) {
            // Admin & all persons who have seealllisttoapprove capability can see all answers.
            return "$waitforconfirmation";
        } else if ($ishr) {
            // HR should see 2, 3, 4, 5.
            $conflevel = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.confirmationsupervisorenabled')) AS UNSIGNED) IN (2,3,4,5)";
            return "$waitforconfirmation AND $conflevel";
        } else {
            // Supervisors should see 1, 2, 4, 5 — but only if they're linked via profile field.
            $conflevel = "CAST(JSON_UNQUOTE(JSON_EXTRACT(bo.json, '$.confirmationsupervisorenabled')) AS UNSIGNED) IN (1,2,4,5)";

            // Run a SQL query to extract an array of IDs that a supervisor or their deputies can see.
            // Then use the get_in_or_equal function to prepare this part of the query with parameters,
            // and include it in the main query.
            $sql = "
                SELECT userid
                FROM {user_info_data} uid
                WHERE uid.fieldid = :becsupervisorfieldid
                AND (
                    uid.data = :becssupervisorid
                    OR uid.data IN (
                        SELECT sup.userid
                        FROM {user_info_data} sup
                        WHERE sup.fieldid = :becdeputyfieldid
                            AND (
                                sup.data = :becsdeputyid1
                                OR FIND_IN_SET(:becsdeputyid2, sup.data)
                            )
                    )
                )
            ";
            $params0 = [
                'becssupervisorid' => $USER->id,
                'becsdeputyid1' => $USER->id,
                'becsdeputyid2' => $USER->id,
                'becsupervisorfieldid' => $supervisorfieldid,
                'becdeputyfieldid' => $deputyfieldid,
            ];

            foreach ($params0 as $k => $v) {
                $params[$k] = $v;
            }

            // If $supervisorteam is true, supervisor can see all answers regardless of confirmation enablement,
            // but only if they are linked via profile field.
            if ($this->supervisorteam) {
                return "ba.userid IN ($sql)";
            }

            return "$waitforconfirmation AND $conflevel AND ba.userid IN ($sql)";
        }
    }

    /**
     * Returns the number of required confirmations based on the booking option settings.
     *
     * @param int $optionid
     * @return int Number of confirmations needed (e.g., 1 or 2)
     */
    public static function get_required_confirmation_count(int $optionid): int {
        $bosettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (property_exists($bosettings->jsonobject, 'confirmationsupervisorenabled')) {
            $confirmationsupervisorenabled = (int) $bosettings->jsonobject->confirmationsupervisorenabled;
            switch ($confirmationsupervisorenabled) {
                case 1:
                case 3:
                case 5:
                    return 1;
                case 2:
                case 4:
                    return 2;
                default:
                    return 0;
            }
        }

        return 0;  // When the option 'no confirmation needed' is selected in booking option settings.
    }
}
