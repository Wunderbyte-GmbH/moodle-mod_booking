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
use mod_booking\bo_availability\conditions\customform;
use mod_booking\booking_option;
use mod_booking\local\certificate_conditions\certificate_conditions;
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
     * Option-specific fields (certificate, allusercertificates) are only added
     * if an optionid is given, as their visibility depends on the booking option.
     *
     * @param int $cmid
     * @param int $optionid
     * @return array
     */
    public static function display_columns(int $cmid, int $optionid = 0): array {
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
                    $columns['timecreated'] = get_string('timecreated', 'mod_booking');
                    break;
                case 'timebooked':
                    $columns['timebooked'] = get_string('timebooked', 'mod_booking');
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
                case 'waitinglist':
                    $columns['waitinglist'] = get_string('searchwaitinglist', 'mod_booking');
                    break;
                case 'rating':
                    // Like on report.php, only if the instance uses ratings.
                    // The tracker shows the aggregated rating (read-only).
                    // Only in option-level scopes: their SQL provides the aggregate (extra_fields_sql).
                    if (!empty($optionid) && !empty($settings->assessed)) {
                        $columns['rating'] = get_string('rating', 'core_rating');
                    }
                    break;
                case 'places':
                    // Only in option-level scopes: their SQL selects ba.places.
                    if (!empty($optionid)) {
                        $columns['places'] = get_string('places', 'mod_booking');
                    }
                    break;
                case 'userpic':
                    $columns['userpic'] = get_string('userpic');
                    break;
                case 'price':
                    // This is only possible, if local_shopping_cart is installed.
                    // Only in option-level scopes: their SQL provides the join (extra_fields_sql).
                    if (!empty($optionid) && class_exists('local_shopping_cart\shopping_cart')) {
                        $columns['price'] = get_string('price', 'mod_booking');
                        $columns['currency'] = get_string('currency', 'local_shopping_cart');
                    }
                    break;
                case 'certificate':
                    if (!empty($optionid) && self::show_certificate_columns($optionid)) {
                        $columns['certificate'] = get_string('certificatecolheader', 'mod_booking');
                    }
                    break;
                case 'allusercertificates':
                    if (!empty($optionid) && self::show_certificate_columns($optionid)) {
                        $columns['allusercertificates'] = get_string('allusercertificates', 'mod_booking');
                    }
                    break;
            }
        }

        // Like on report.php, the custom user profile fields come after all standard
        // columns, ordered by field id.
        foreach ($profilefields as $shortname => $profilefield) {
            if (in_array($shortname, $configured)) {
                $columns['cust' . strtolower($shortname)] = format_string($profilefield->name);
            }
        }

        // The certificate columns are shown after the custom user profile fields.
        $certcolumns = [];
        foreach (['certificate', 'allusercertificates'] as $key) {
            if (isset($columns[$key])) {
                $certcolumns[$key] = $columns[$key];
                unset($columns[$key]);
            }
        }
        $columns = array_merge($columns, $certcolumns);

        // Like on report.php, userpic is moved to the front.
        $front = [];
        foreach (['userpic'] as $key) {
            if (isset($columns[$key])) {
                $front[$key] = $columns[$key];
                unset($columns[$key]);
            }
        }
        return array_merge($front, $columns);
    }

    /**
     * Returns the tracker download columns ([columnkey => header]) configured
     * in the instance setting reportfields ("Manage responses - Download").
     * Returns an empty array if no instance can be resolved or nothing is configured,
     * so callers can fall back to the display columns.
     *
     * @param int $cmid
     * @param int $optionid
     * @return array
     */
    public static function download_columns(int $cmid, int $optionid = 0): array {
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
                case 'groups':
                    $columns['groups'] = get_string('group');
                    break;
                case 'price':
                    // This is only possible, if local_shopping_cart is installed.
                    // Only in option-level scopes: their SQL provides the join (extra_fields_sql).
                    if (!empty($optionid) && class_exists('local_shopping_cart\shopping_cart')) {
                        $columns['price'] = get_string('price', 'mod_booking');
                        $columns['currency'] = get_string('currency', 'local_shopping_cart');
                    }
                    break;
                case 'certificate':
                    // Like in booking::get_manage_responses_fields, the download always
                    // includes the certificate column when it is configured.
                    $columns['certificate'] = get_string('certificate', 'mod_booking');
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
                case 'timebooked':
                    $columns['timebooked'] = get_string('timebooked', 'mod_booking');
                    break;
                case 'completeddate':
                    $columns['completeddate'] = get_string('completeddate', 'mod_booking');
                    break;
            }
        }

        // Like on report.php, the custom user profile fields come after all standard
        // columns, ordered by field id.
        foreach ($profilefields as $shortname => $profilefield) {
            if (in_array($shortname, $configured)) {
                $columns['cust' . strtolower($shortname)] = format_string($profilefield->name);
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
     * Checks if the option has a customform with an enrolusersaction ("enrol multiple users") element,
     * i.e. if enrollinks can exist for this option.
     *
     * @param int $optionid
     * @return bool
     */
    public static function has_enrolusersaction(int $optionid): bool {
        if (empty($optionid)) {
            return false;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        // Depending on the stored availability json, return_formelements returns an array or a stdClass.
        $formelements = (array)customform::return_formelements($settings);
        foreach ($formelements as $formelement) {
            if (($formelement->formtype ?? '') === 'enrolusersaction') {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the columns ([columnkey => header]) for the fields of the customform
     * availability condition of the given booking option, like on report.php:
     * one column "formfield_<counter>" per form element, labelled with the field label.
     *
     * If $includeenrollink is true (display tables), an "enrollink" column is added
     * as soon as one of the form elements is an enrolusersaction field.
     *
     * @param int $optionid
     * @param bool $includeenrollink
     * @return array
     */
    public static function customform_columns(int $optionid, bool $includeenrollink = false): array {
        if (empty($optionid)) {
            return [];
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $formelements = customform::return_formelements($settings);

        $columns = [];
        foreach ($formelements as $counter => $formelement) {
            if (
                $includeenrollink
                && ($formelement->formtype ?? '') === 'enrolusersaction'
                && !isset($columns['enrollink'])
            ) {
                $columns['enrollink'] = get_string('enrollink', 'booking');
            }
            $label = !empty($formelement->label) ? $formelement->label : 'label_' . $counter;
            $columns['formfield_' . $counter] = format_string($label);
        }
        return $columns;
    }

    /**
     * Returns the slot columns ([columnkey => header]) for slotbooking options,
     * like on report.php: only if the option has slotbooking enabled (slot_enabled
     * in the option json). The moveslot action column needs mod/booking:updatebooking.
     *
     * @param int $optionid
     * @return array
     */
    public static function slot_columns(int $optionid): array {
        if (
            empty($optionid)
            || !booking_option::get_value_of_json_by_key($optionid, 'slot_enabled')
        ) {
            return [];
        }

        $columns = [
            'slotstarttime' => get_string('starttime', 'mod_booking'),
            'slotendtime' => get_string('endtime', 'mod_booking'),
            'slotnumslots' => get_string('slot_report_numslots', 'mod_booking'),
            'slotteachers' => get_string('slot_report_teachers', 'mod_booking'),
            'slotprice' => get_string('slot_report_price', 'mod_booking'),
        ];

        $cmid = singleton_service::get_instance_of_booking_option_settings($optionid)->cmid ?? 0;
        if (
            !empty($cmid)
            && has_capability('mod/booking:updatebooking', context_module::instance($cmid))
        ) {
            $columns['moveslot'] = get_string('slot_move_action', 'mod_booking');
        }

        return $columns;
    }

    /**
     * Returns additional SQL (selects, joins and their params) needed by columns
     * configured in responsesfields/reportfields: the price/currency of the latest
     * shopping cart purchase and the aggregated rating.
     * The union of both settings is used because the same cached SQL serves
     * the displayed table and its download.
     *
     * The returned select part starts with a comma (or is empty), the join part
     * can be appended after the joins of the calling scope (references "ba").
     *
     * @param int $cmid
     * @param int $optionid
     * @return array [string $extraselect, string $extrajoin, array $params]
     */
    public static function extra_fields_sql(int $cmid, int $optionid): array {
        $extraselect = '';
        $extrajoin = '';
        $params = [];

        if (empty($cmid) || empty($optionid)) {
            return [$extraselect, $extrajoin, $params];
        }

        $settings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $configured = array_unique(array_merge(
            self::explode_setting($settings->responsesfields ?? ''),
            self::explode_setting($settings->reportfields ?? '')
        ));

        if (
            in_array('price', $configured)
            && class_exists('local_shopping_cart\shopping_cart')
        ) {
            $extraselect .= ", schp.price, schp.currency";
            $extrajoin .= "
                LEFT JOIN (
                    SELECT sch.itemid, sch.price, sch.userid, sch.currency
                    FROM {local_shopping_cart_history} sch
                    JOIN (
                        SELECT userid, MAX(timecreated) AS timecreated
                        FROM {local_shopping_cart_history}
                        WHERE itemid = :schitemid1
                        AND componentname = 'mod_booking'
                        AND paymentstatus = 2
                        GROUP BY userid
                    ) schlatest
                    ON sch.userid = schlatest.userid AND sch.timecreated = schlatest.timecreated
                    WHERE sch.itemid = :schitemid2
                    AND sch.componentname = 'mod_booking'
                    AND sch.paymentstatus = 2
                ) schp ON schp.itemid = ba.optionid AND schp.userid = ba.userid ";
            $params['schitemid1'] = $optionid;
            $params['schitemid2'] = $optionid;
        }

        if (
            in_array('rating', $configured)
            && !empty($settings->assessed)
        ) {
            // Aggregation methods, see RATING_AGGREGATE_* in rating/lib.php.
            $aggregates = [1 => 'AVG', 2 => 'COUNT', 3 => 'MAX', 4 => 'MIN', 5 => 'SUM'];
            $aggregate = $aggregates[(int)$settings->assessed] ?? 'AVG';
            $extraselect .= ", (
                SELECT {$aggregate}(r.rating)
                FROM {rating} r
                WHERE r.component = 'mod_booking'
                AND r.ratingarea = 'bookingoption'
                AND r.contextid = :ratingcontextid
                AND r.itemid = ba.id
            ) AS rating";
            $params['ratingcontextid'] = context_module::instance($cmid)->id;
        }

        return [$extraselect, $extrajoin, $params];
    }

    /**
     * Whether the certificate columns are shown for the given booking option,
     * using the same checks as report.php: the option has a certificate configured,
     * is targeted by a certificate condition, or already has issued certificates.
     *
     * @param int $optionid
     * @return bool
     */
    public static function show_certificate_columns(int $optionid): bool {
        global $DB;

        if (!empty(booking_option::get_value_of_json_by_key($optionid, 'certificate'))) {
            return true;
        }
        if (certificate_conditions::option_is_targeted_by_condition($optionid)) {
            return true;
        }
        if (class_exists('tool_certificate\certificate')) {
            switch ($DB->get_dbfamily()) {
                case 'postgres':
                    $existssql = "
                        SELECT 1
                          FROM {tool_certificate_issues} tci
                         WHERE (tci.data::jsonb ->> 'bookingoptionid') ~ '^[0-9]+$'
                           AND (tci.data::jsonb ->> 'bookingoptionid')::int = :optionid
                    ";
                    return $DB->record_exists_sql($existssql, ['optionid' => $optionid]);
                case 'mysql':
                    $existssql = "
                        SELECT 1
                          FROM {tool_certificate_issues} tci
                         WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(tci.data, '$.bookingoptionid')) AS UNSIGNED) = :optionid
                    ";
                    return $DB->record_exists_sql($existssql, ['optionid' => $optionid]);
            }
        }
        return false;
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
