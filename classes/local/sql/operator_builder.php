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
 * Operator builder for SQL condition generation.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\sql;

use mod_booking\local\sql\operators\equals;
use mod_booking\local\sql\operators\not_equals;
use mod_booking\local\sql\operators\contains;

/**
 * Operator builder class to generate SQL snippets for different operators.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class operator_builder {
    /**
     * Get SQL snippet for a given operator.
     *
     * @param string $operator The operator (=, !=, <, >, ~, etc.)
     * @param string $dbtype Database type ('postgres' or 'mysql')
     * @param string $uservalue The user's profile field value
     * @param string $conditionvalue The value from the condition (from JSON)
     * @param string $tablealias Table alias for JSON fields
     * @param string $fieldkey JSON key for the field name
     * @param string $valuekey JSON key for the value
     * @return string SQL snippet
     */
    public static function get_operator_sql(
        string $operator,
        string $dbtype,
        string $uservalue,
        string $conditionvalue = '',
        string $tablealias = 'jt',
        string $fieldkey = 'profilefield',
        string $valuekey = 'value'
    ): string {

        switch ($operator) {
            case '=':
                $operatorclass = new equals();
                return $operatorclass->get_sql($dbtype, $uservalue, $conditionvalue, $tablealias, $fieldkey, $valuekey);

            case '!=':
                $operatorclass = new not_equals();
                return $operatorclass->get_sql($dbtype, $uservalue, $conditionvalue, $tablealias, $fieldkey, $valuekey);

            case '~':
                $operatorclass = new contains();
                return $operatorclass->get_sql($dbtype, $uservalue, $conditionvalue, $tablealias, $fieldkey, $valuekey);

            default:
                // Unsupported operator should not match anything.
                return 'FALSE';
        }
    }

    /**
     * Build a complete profile field check for PostgreSQL or MySQL.
     *
     * @param string $dbtype Database type ('postgres' or 'mysql')
     * @param object $user User object with profile fields
     * @param string $tablealias Table alias or object alias
     * @param string $fieldkey JSON key for profile field name
     * @param string $operatorkey JSON key for operator
     * @param string $valuekey JSON key for value
     * @return string SQL snippet
     */
    public static function build_profile_field_check(
        string $dbtype,
        object $user,
        string $tablealias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey
    ): string {

        if ($dbtype == 'postgres') {
            return self::build_postgres_check($user, $tablealias, $fieldkey, $operatorkey, $valuekey);
        } else {
            return self::build_mysql_check($user, $tablealias, $fieldkey, $operatorkey, $valuekey);
        }
    }

    /**
     * Build PostgreSQL check.
     *
     * @param object $user
     * @param string $objalias
     * @param string $fieldkey
     * @param string $operatorkey
     * @param string $valuekey
     * @return string
     */
    private static function build_postgres_check(
        object $user,
        string $objalias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey
    ): string {
        global $USER;

        $userid = (int)$USER->id;
        // Expression to fetch the user's custom field value as text.
        $userval = "COALESCE((SELECT uid.data FROM {user_info_data} uid " .
            "JOIN {user_info_field} uif ON uid.fieldid = uif.id " .
            "WHERE uid.userid = $userid AND uif.shortname = ($objalias->>'$fieldkey')::text LIMIT 1), '')";
        $condval = "($objalias->>'$valuekey')::text";

        // Helper snippets.
        $notempty = "$userval <> ''";
        $condnotempty = "$condval <> ''";
        $like = "LOWER($userval) LIKE '%' || LOWER($condval) || '%'";
        $notlike = "LOWER($userval) NOT LIKE '%' || LOWER($condval) || '%'";
        $inarray = "$userval = ANY (string_to_array($condval, ','))";
        $notinarray = "NOT ($inarray)";
        $containsany = "EXISTS (SELECT 1 FROM unnest(string_to_array($condval, ',')) AS item " .
            "WHERE $userval <> '' AND LOWER($userval) LIKE '%' || LOWER(item) || '%')";
        $containsnone = "NOT ($containsany)";

        return "(
            CASE ($objalias->>'$operatorkey')::text
                WHEN '=' THEN (($notempty AND $userval = $condval) OR ($userval = '' AND $condval = ''))
                WHEN '!=' THEN (($notempty AND $userval <> $condval) OR ($userval = '' AND $condnotempty))
                WHEN '<' THEN ($notempty AND $userval < $condval)
                WHEN '>' THEN ($notempty AND $userval > $condval)
                WHEN '~' THEN ($notempty AND $like)
                WHEN '!~' THEN ($notempty AND $notlike)
                WHEN '[]' THEN ($notempty AND $inarray)
                WHEN '[!]' THEN (($notempty AND $notinarray) OR ($userval = ''))
                WHEN '[~]' THEN ($notempty AND $containsany)
                WHEN '[!~]' THEN ($notempty AND $containsnone)
                WHEN '()' THEN ($userval = '')
                WHEN '(!)' THEN ($userval <> '')
                ELSE FALSE
            END
        )";
    }

    /**
     * Build MySQL check.
     *
     * @param object $user
     * @param string $tablealias
     * @param string $fieldkey
     * @param string $operatorkey
     * @param string $valuekey
     * @return string
     */
    private static function build_mysql_check(
        object $user,
        string $tablealias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey
    ): string {
        global $USER;

        $userid = (int)$USER->id;
        // Explicitly COLLATE JSON-extracted values to utf8mb4_unicode_ci to match subquery results.
        // This prevents collation mismatch errors when comparing JSON data with profile field data.
        $userval = "COALESCE((SELECT uid.data FROM {user_info_data} uid " .
            "JOIN {user_info_field} uif ON uid.fieldid = uif.id " .
            "WHERE uid.userid = $userid AND uif.shortname = $tablealias.$fieldkey COLLATE utf8mb4_unicode_ci LIMIT 1), '')";
        $condval = "$tablealias.$valuekey COLLATE utf8mb4_unicode_ci";

        $notempty = "$userval <> ''";
        $condnotempty = "$condval <> ''";
        $like = "LOWER($userval) LIKE CONCAT('%', LOWER($condval), '%')";
        $notlike = "LOWER($userval) NOT LIKE CONCAT('%', LOWER($condval), '%')";
        $inarray = "FIND_IN_SET($userval, $condval) > 0";
        $notinarray = "NOT ($inarray)";

        // Convert comma-separated condval into rows using JSON_TABLE for contains operations.
        $jsonarray = "CONCAT('[\"', REPLACE($condval, ',', '\",\"'), '\"]')";
        $containsany = "EXISTS (SELECT 1 FROM JSON_TABLE($jsonarray, '$[*]' COLUMNS (item TEXT PATH '$')) jtarr " .
            "WHERE $userval <> '' AND LOWER($userval) LIKE CONCAT('%', LOWER(jtarr.item COLLATE utf8mb4_unicode_ci), '%'))";
        $containsnone = "NOT ($containsany)";

        return "(
            CASE $tablealias.$operatorkey COLLATE utf8mb4_unicode_ci
                WHEN '=' THEN (($notempty AND $userval = $condval) OR ($userval = '' AND $condval = ''))
                WHEN '!=' THEN (($notempty AND $userval <> $condval) OR ($userval = '' AND $condnotempty))
                WHEN '<' THEN ($notempty AND $userval < $condval)
                WHEN '>' THEN ($notempty AND $userval > $condval)
                WHEN '~' THEN ($notempty AND $like)
                WHEN '!~' THEN ($notempty AND $notlike)
                WHEN '[]' THEN ($notempty AND $inarray)
                WHEN '[!]' THEN (($notempty AND $notinarray) OR ($userval = ''))
                WHEN '[~]' THEN ($notempty AND $containsany)
                WHEN '[!~]' THEN ($notempty AND $containsnone)
                WHEN '()' THEN ($userval = '')
                WHEN '(!)' THEN ($userval <> '')
                ELSE FALSE
            END
        )";
    }
}
