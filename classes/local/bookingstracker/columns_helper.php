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
 * Maps the per-instance column settings (responsesfields, reportfields) to Bookings tracker columns.
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use context_module;
use mod_booking\singleton_service;

/**
 * Maps the per-instance column settings (responsesfields, reportfields) to Bookings tracker columns.
 *
 * The same settings that define the columns of "Manage responses" (report.php) are applied
 * to the Bookings tracker (report2.php): responsesfields for the displayed tables,
 * reportfields for the table download.
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class columns_helper {
    /**
     * Returns the tracker display columns ([columnkey => header]) configured
     * in the instance setting responsesfields ("Manage responses - Page").
     * Returns an empty array if no instance can be resolved or nothing is configured,
     * so callers can fall back to their default columns.
     *
     * Fields without tracker support (rating, places, userpic, indexnumber,
     * certificate, allusercertificates, price) are silently skipped.
     *
     * @param int $cmid
     * @return array
     */
    public static function display_columns(int $cmid): array {
        if (empty($cmid)) {
            return [];
        }
        $settings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $configured = self::explode_setting($settings->responsesfields ?? '');
        if (empty($configured)) {
            return [];
        }

        $profilefields = self::get_configured_profilefields($cmid);

        $columns = [];
        foreach ($configured as $value) {
            switch ($value) {
                case 'fullname':
                    // The tracker tables use separate firstname and lastname columns.
                    $columns['firstname'] = get_string('firstname', 'core');
                    $columns['lastname'] = get_string('lastname', 'core');
                    break;
                case 'email':
                    $columns['email'] = get_string('email', 'core');
                    break;
                case 'status':
                    $columns['status'] = get_string('presence', 'mod_booking');
                    break;
                case 'notes':
                    $columns['notes'] = get_string('notes', 'mod_booking');
                    break;
                case 'timecreated':
                    $columns['timecreated'] = get_string('bookingdate', 'mod_booking');
                    break;
                case 'institution':
                    $columns['institution'] = empty($settings->lblinstitution)
                        ? get_string('institution', 'booking') : $settings->lblinstitution;
                    break;
                case 'city':
                    $columns['city'] = get_string('city');
                    break;
                case 'department':
                    $columns['department'] = get_string('department');
                    break;
                case 'completed':
                    $columns['completed'] = get_string('completed', 'mod_booking');
                    break;
                case 'completeddate':
                    $columns['completeddate'] = get_string('completeddate', 'mod_booking');
                    break;
                case 'numrec':
                    if (!empty($settings->numgenerator)) {
                        $columns['numrec'] = get_string('numrec', 'mod_booking');
                    }
                    break;
                case 'waitinglist':
                    $columns['waitinglist'] = get_string('searchwaitinglist', 'mod_booking');
                    break;
                default:
                    if (isset($profilefields[$value])) {
                        $columns['cust' . strtolower($value)] = format_string($profilefields[$value]->name);
                    }
                    break;
            }
        }
        return $columns;
    }

    /**
     * Returns the tracker download columns ([columnkey => header]) configured
     * in the instance setting reportfields ("Manage responses - Download").
     * Returns an empty array if no instance can be resolved or nothing is configured,
     * so callers can fall back to the display columns.
     *
     * Fields without tracker support (groups, price, certificate) are silently skipped.
     *
     * @param int $cmid
     * @return array
     */
    public static function download_columns(int $cmid): array {
        global $DB;

        if (empty($cmid)) {
            return [];
        }
        $settings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $configured = self::explode_setting($settings->reportfields ?? '');
        if (empty($configured)) {
            return [];
        }

        $profilefields = self::get_configured_profilefields($cmid);
        $context = context_module::instance($cmid);

        $columns = [];
        foreach ($configured as $value) {
            switch ($value) {
                case 'optionid':
                    $columns['optionid'] = get_string('optionid', 'booking');
                    break;
                case 'booking':
                    // The booking option name is available as column 'text' in the tracker.
                    $columns['text'] = get_string('bookingoptionname', 'booking');
                    break;
                case 'location':
                    $columns['location'] = get_string('location', 'booking');
                    break;
                case 'coursestarttime':
                    $columns['coursestarttime'] = get_string('coursestarttime', 'booking');
                    break;
                case 'courseendtime':
                    $columns['courseendtime'] = get_string('courseendtime', 'booking');
                    break;
                case 'numrec':
                    if (!empty($settings->numgenerator)) {
                        $columns['numrec'] = get_string('numrec', 'booking');
                    }
                    break;
                case 'userid':
                    $columns['userid'] = get_string('userid', 'booking');
                    break;
                case 'username':
                    $columns['username'] = get_string('username');
                    break;
                case 'firstname':
                    $columns['firstname'] = get_string('firstname');
                    break;
                case 'lastname':
                    $columns['lastname'] = get_string('lastname');
                    break;
                case 'email':
                    $columns['email'] = get_string('email');
                    break;
                case 'city':
                    $columns['city'] = get_string('city');
                    break;
                case 'department':
                    $columns['department'] = get_string('department');
                    break;
                case 'institution':
                    if (has_capability('moodle/site:viewuseridentity', $context)) {
                        $columns['institution'] = get_string('institution', 'booking');
                    }
                    break;
                case 'idnumber':
                    if (
                        $DB->count_records_select('user', ' idnumber <> \'\'') > 0
                        && has_capability('moodle/site:viewuseridentity', $context)
                    ) {
                        $columns['idnumber'] = get_string('idnumber');
                    }
                    break;
                case 'completed':
                    $columns['completed'] = get_string('completed', 'booking');
                    break;
                case 'waitinglist':
                    $columns['waitinglist'] = get_string('waitinglist', 'booking');
                    break;
                case 'status':
                    $columns['status'] = get_string('presence', 'mod_booking');
                    break;
                case 'notes':
                    $columns['notes'] = get_string('notes', 'mod_booking');
                    break;
                case 'timecreated':
                    $columns['timecreated'] = get_string('timecreated', 'mod_booking');
                    break;
                case 'completeddate':
                    $columns['completeddate'] = get_string('completeddate', 'mod_booking');
                    break;
                default:
                    if (isset($profilefields[$value])) {
                        $columns['cust' . strtolower($value)] = format_string($profilefields[$value]->name);
                    }
                    break;
            }
        }
        return $columns;
    }

    /**
     * Returns SQL subselects (and their params) for the custom user profile fields configured
     * in responsesfields or reportfields, aliased "cust<shortname>" each.
     * The union of both settings is used because the same cached SQL serves
     * the displayed table and its download.
     *
     * @param int $cmid
     * @param string $useridalias SQL expression for the user id the subselects correlate on
     * @return array [string $extraselect, array $params]
     */
    public static function profilefield_sql(int $cmid, string $useridalias = 'ba.userid'): array {
        global $DB;

        $extraselect = '';
        $params = [];
        $i = 0;
        foreach (self::get_configured_profilefields($cmid) as $profilefield) {
            $alias = 'cust' . strtolower($profilefield->shortname);
            $extraselect .= ", (SELECT " . $DB->sql_concat('uif.datatype', "'|'", 'uid.data') . "
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uid.userid = $useridalias
                AND uif.shortname = :custpf{$i}) AS {$alias}";
            $params['custpf' . $i] = $profilefield->shortname;
            $i++;
        }
        return [$extraselect, $params];
    }

    /**
     * Resolves the values configured in responsesfields and reportfields against
     * the existing custom user profile fields, keyed by shortname.
     *
     * @param int $cmid
     * @return array
     */
    private static function get_configured_profilefields(int $cmid): array {
        global $DB;

        if (empty($cmid)) {
            return [];
        }
        $settings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $configured = array_unique(array_merge(
            self::explode_setting($settings->responsesfields ?? ''),
            self::explode_setting($settings->reportfields ?? '')
        ));
        if (empty($configured)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($configured, SQL_PARAMS_NAMED);
        return $DB->get_records_select(
            'user_info_field',
            'shortname ' . $insql,
            $inparams,
            'id',
            'shortname, id, name'
        );
    }

    /**
     * Splits a comma-separated setting into a clean array of values.
     *
     * @param string $setting
     * @return array
     */
    private static function explode_setting(string $setting): array {
        return array_values(array_filter(array_map('trim', explode(',', $setting))));
    }
}
