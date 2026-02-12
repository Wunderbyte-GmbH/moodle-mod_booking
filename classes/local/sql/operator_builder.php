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
use mod_booking\singleton_service;

/**
 * Operator builder class to generate SQL snippets for different operators.
 *
 * SECURITY NOTES:
 * - User profile values are passed via Moodle's parameter system ($params array) using named parameters.
 *   Each value is added to $params with a generated unique key (e.g., 'profilevalue', 'profilevalue_1', etc.)
 *   and referenced by :paramname placeholder in SQL. Moodle's database abstraction layer handles proper
 *   escaping/binding for both PostgreSQL and MySQL.
 * - Use generate_unique_param_name() static method to create unique parameter names in your code.
 * - Function parameters like $fieldkey, $operatorkey, $valuekey should only be used with
 *   system-defined keys (not user input). These are JSON object keys embedded in SQL code,
 *   and should never come from untrusted sources.
 * - Table/object aliases ($tablealias) should be passed as system-defined constants only.
 * - All functions accept a &$params reference array to collect parameterized values.
 *
 * USAGE:
 *   $params = [];
 *   $sql = operator_builder::build_profile_field_check('mysql', $user, 'jt', 'fieldkey', 'operator', 'value', $params);
 *   // Now $params contains: ['profilevalue' => '...', 'profilevalue_1' => '...', ...]
 *   $records = $DB->get_records_sql($sql, $params);
 *
 * GENERATING UNIQUE PARAMS IN YOUR CODE:
 *   $paramname = operator_builder::generate_unique_param_name($params, 'myvalue');
 *   $params[$paramname] = 'some_value';
 *   // Later in SQL: WHERE field = :myvalue or WHERE field = :myvalue_1, etc.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class operator_builder {
    /**
     * Generate a unique parameter name for SQL queries.
     * Checks the $params array for existing keys and increments a counter if needed.
     *
     * @param array $params The parameters array to check against
     * @param string $basename Base name for the parameter (e.g., 'profilevalue')
     * @return string A unique parameter name that doesn't exist in $params
     */
    public static function generate_unique_param_name(array $params, string $basename = 'param'): string {
        // Check if the base name is already used as a key.
        if (!isset($params[$basename])) {
            return $basename;
        }

        // If base name is taken, append counters until we find a free name.
        $counter = 1;
        while (isset($params[$basename . '_' . $counter])) {
            $counter++;
        }

        return $basename . '_' . $counter;
    }

    /**
     * Build a CASE statement that maps shortnames from the JSON field to user profile values.
     * Uses Moodle's parameter system ($params) for secure value handling with named placeholders.
     *
     * @param string $dbtype Database type ('postgres' or 'mysql')
     * @param object $user User object with $user->profile array
     * @param string $tablealias Table/object alias in SQL
     * @param string $fieldkey JSON key containing the profile field shortname
     * @param array $params Reference array to collect query parameters (keys added: profilevalue, profilevalue_1, etc.)
     * @return string SQL CASE statement with named parameter placeholders (:profilevalue, :profilevalue_1, etc.)
     */
    private static function build_shortname_case(
        string $dbtype,
        object $user,
        string $tablealias,
        string $fieldkey,
        array &$params
    ): string {
        // If user has no profile data, return empty string.
        if (empty($user->profile) || !is_array($user->profile)) {
            if ($dbtype == 'postgres') {
                return "''::text";
            } else {
                return "''";
            }
        }

        // Build CASE statement for each profile field shortname.
        $caseclauses = [];

        foreach ($user->profile as $shortname => $value) {
            // Generate unique parameter name and add to params array.
            $paramname = self::generate_unique_param_name($params, 'profilevalue');
            $params[$paramname] = (string)$value;
            $placeholder = ':' . $paramname;

            // Escape shortname for SQL by doubling single quotes.
            $escapedshortname = str_replace("'", "''", (string)$shortname);

            if ($dbtype == 'postgres') {
                // PostgreSQL: WHEN accepts string literal, THEN gets parameter placeholder.
                $caseclauses[] = "WHEN '$escapedshortname' THEN $placeholder ::text";
            } else {
                // MySQL: same pattern.
                $caseclauses[] = "WHEN '$escapedshortname' THEN $placeholder";
            }
        }

        // Build the full CASE statement.
        if ($dbtype == 'postgres') {
            $casestart = "CASE ($tablealias->>'$fieldkey')::text";
        } else {
            $casestart = "CASE JSON_UNQUOTE(JSON_EXTRACT($tablealias, CONCAT('$.', '$fieldkey')))";
        }

        $casebody = implode(" ", $caseclauses);

        if ($dbtype == 'postgres') {
            return "$casestart $casebody ELSE ''::text END";
        } else {
            return "$casestart $casebody ELSE '' END";
        }
    }

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
     * @param array $params Reference array to collect query parameters
     * @return string SQL snippet
     */
    public static function build_profile_field_check(
        string $dbtype,
        object $user,
        string $tablealias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey,
        array &$params
    ): string {

        if ($dbtype == 'postgres') {
            return self::build_postgres_check($user, $tablealias, $fieldkey, $operatorkey, $valuekey, $params);
        } else {
            return self::build_mysql_check($user, $tablealias, $fieldkey, $operatorkey, $valuekey, $params);
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
     * @param array $params Reference array to collect query parameters
     * @return string
     */
    private static function build_postgres_check(
        object $user,
        string $objalias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey,
        array &$params
    ): string {
        $condval = "($objalias->>'$valuekey')::text";

        return "(
            CASE ($objalias->>'$operatorkey')::text
                WHEN '=' THEN ((" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " = $condval) OR (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " = '' AND $condval = ''))
                WHEN '!=' THEN ((" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> $condval) OR (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " = '' AND $condval <> ''))
                WHEN '<' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " < $condval)
                WHEN '>' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " > $condval)
                WHEN '~' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND LOWER(" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    ") LIKE '%' || LOWER($condval) || '%')
                WHEN '!~' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND LOWER(" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    ") NOT LIKE '%' || LOWER($condval) || '%')
                WHEN '[]' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " = ANY (string_to_array($condval, ',')))
                WHEN '[!]' THEN ((" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND NOT (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " = ANY (string_to_array($condval, ',')))) OR (" .
                    self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) . " = ''))
                WHEN '[~]' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND EXISTS (SELECT 1 FROM unnest(string_to_array($condval, ',')) AS item " .
                    "WHERE " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND LOWER(" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    ") LIKE '%' || LOWER(item) || '%'))
                WHEN '[!~]' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND NOT (EXISTS (SELECT 1 FROM unnest(string_to_array($condval, ',')) AS item " .
                    "WHERE " . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    " <> '' AND LOWER(" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) .
                    ") LIKE '%' || LOWER(item) || '%')))
                WHEN '()' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) . " = '')
                WHEN '(!)' THEN (" . self::build_shortname_case('postgres', $user, $objalias, $fieldkey, $params) . " <> '')
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
     * @param array $params Reference array to collect query parameters
     * @return string
     */
    private static function build_mysql_check(
        object $user,
        string $tablealias,
        string $fieldkey,
        string $operatorkey,
        string $valuekey,
        array &$params
    ): string {
        $condval = "JSON_UNQUOTE(JSON_EXTRACT($tablealias, CONCAT('$.', '$valuekey')))";

        return "(
            CASE JSON_UNQUOTE(JSON_EXTRACT($tablealias, CONCAT('$.', '$operatorkey')))
                WHEN '=' THEN ((TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND " . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " = $condval) OR (" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " = '' AND $condval = ''))
                WHEN '!=' THEN ((TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND " . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " <> $condval) OR (" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " = '' AND $condval <> ''))
                WHEN '<' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND CAST(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " AS SIGNED) < CAST($condval AS SIGNED))
                WHEN '>' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND CAST(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " AS SIGNED) > CAST($condval AS SIGNED))
                WHEN '~' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND LOWER(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") LIKE CONCAT('%', LOWER($condval), '%'))
                WHEN '!~' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND LOWER(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") NOT LIKE CONCAT('%', LOWER($condval), '%'))
                WHEN '[]' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND FIND_IN_SET(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ", $condval) > 0)
                WHEN '[!]' THEN ((TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND NOT (FIND_IN_SET(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ", $condval) > 0)) OR (" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    " = ''))
                WHEN '[~]' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND EXISTS (SELECT 1 FROM JSON_TABLE(CONCAT('[\"', REPLACE($condval, ',', '\",\"'), '\"]'),
                    '$[*]' COLUMNS (item TEXT PATH '$')) jtarr WHERE " .
                    self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) . " <> '' AND LOWER(" .
                    self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") LIKE CONCAT('%', LOWER(jtarr.item), '%')))
                WHEN '[!~]' THEN (TRIM(" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") <> '' AND NOT (EXISTS (SELECT 1 FROM JSON_TABLE(CONCAT('[\"', REPLACE($condval, ',', '\",\"'), '\"]'),
                    '$[*]' COLUMNS (item TEXT PATH '$')) jtarr WHERE " .
                    self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) . " <> '' AND LOWER(" .
                    self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) .
                    ") LIKE CONCAT('%', LOWER(jtarr.item), '%'))))
                WHEN '()' THEN (" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) . " = '')
                WHEN '(!)' THEN (" . self::build_shortname_case('mysql', $user, $tablealias, $fieldkey, $params) . " <> '')
                ELSE FALSE
            END
        )";
    }
}
