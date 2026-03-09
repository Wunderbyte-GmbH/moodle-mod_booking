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
 * Contains operator (~) for SQL conditions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\sql\operators;

/**
 * Contains operator class (~).
 */
class contains implements base_operator {
    /**
     * Get SQL snippet for contains operator.
     *
     * @param string $dbtype Database type ('postgres' or 'mysql')
     * @param string $uservalue User's profile field value
     * @param string $conditionvalue Value from the condition
     * @param string $tablealias Table alias for JSON fields
     * @param string $fieldkey JSON key for field name
     * @param string $valuekey JSON key for value
     * @return string SQL snippet
     */
    public function get_sql(
        string $dbtype,
        string $uservalue,
        string $conditionvalue,
        string $tablealias,
        string $fieldkey,
        string $valuekey
    ): string {
        if ($dbtype == 'postgres') {
            return $this->get_sql_postgres($tablealias, $fieldkey, $valuekey);
        }
        return $this->get_sql_mysql($tablealias, $fieldkey, $valuekey);
    }

    /**
     * PostgreSQL snippet: user field must be non-empty and contain the value (case-insensitive).
     * @param string $objalias
     * @param string $fieldkey
     * @param string $valuekey
     *
     * @return string
     *
     */
    public function get_sql_postgres(
        string $objalias,
        string $fieldkey,
        string $valuekey
    ): string {
        global $USER;

        return "(
            WITH userval AS (
                SELECT uid.data AS data
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uid.userid = " . (int)$USER->id . "
                AND uif.shortname = ($objalias->>'$fieldkey')::text
                LIMIT 1
            )
            SELECT (
                COALESCE((SELECT data FROM userval), '') <> ''
                AND LOWER(COALESCE((SELECT data FROM userval), '')) LIKE '%' || LOWER(($objalias->>'$valuekey')::text) || '%'
            )
        )";
    }

    /**
     * MySQL snippet: user field must be non-empty and contain the value (case-insensitive).
     * @param string $tablealias
     * @param string $fieldkey
     * @param string $valuekey
     *
     * @return string
     *
     */
    public function get_sql_mysql(
        string $tablealias,
        string $fieldkey,
        string $valuekey
    ): string {
        global $USER;

        return "(
            WITH userval AS (
                SELECT uid.data AS data
                FROM {user_info_data} uid
                JOIN {user_info_field} uif ON uid.fieldid = uif.id
                WHERE uid.userid = " . (int)$USER->id . "
                AND uif.shortname = $tablealias.$fieldkey
                LIMIT 1
            )
            SELECT (
                COALESCE((SELECT data FROM userval), '') <> ''
                AND LOWER(COALESCE((SELECT data FROM userval), '')) LIKE CONCAT('%', LOWER($tablealias.$valuekey), '%')
            )
        )";
    }
}
