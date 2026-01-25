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
 * Base operator interface for SQL condition operators.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\sql\operators;

/**
 * Base operator interface.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface base_operator {
    /**
     * Get SQL snippet for this operator.
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
    ): string;

    /**
     * Get PostgreSQL specific SQL snippet.
     *
     * @param string $objalias Object alias in PostgreSQL
     * @param string $fieldkey JSON key for field name
     * @param string $valuekey JSON key for value
     * @return string SQL snippet
     */
    public function get_sql_postgres(
        string $objalias,
        string $fieldkey,
        string $valuekey
    ): string;

    /**
     * Get MySQL specific SQL snippet.
     *
     * @param string $tablealias Table alias
     * @param string $fieldkey Field key
     * @param string $valuekey Value key
     * @return string SQL snippet
     */
    public function get_sql_mysql(
        string $tablealias,
        string $fieldkey,
        string $valuekey
    ): string;
}
