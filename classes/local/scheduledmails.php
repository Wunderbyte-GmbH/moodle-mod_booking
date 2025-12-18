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

namespace mod_booking\local;

/**
 * Class scheduledmails
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduledmails {
    /**
     * Returns SQL components ($fields, $from, $where) with DB-family aware JSON parsing.
     * @param int $contextid
     *
     * @return array [$fields, $from, $where, $params]
     */
    public static function get_sql($contextid = 1) {
        global $DB;

        $dbfamily = $DB->get_dbfamily();

        // DB-specific JSON extraction.
        if ($dbfamily === 'postgres') {
            $ruleidextract = "(ta.customdata::jsonb ->> 'ruleid')::int";
            $ruleid = "ta.customdata::jsonb ->> 'ruleid'";
            $bookingrulename = "br.rulejson::jsonb ->> 'name'";
            $userid = "(ta.customdata::jsonb ->> 'userid')::int";
            $messagesubject = "br.rulejson::jsonb -> 'actiondata' ->> 'subject'";
            $messagetext = "br.rulejson::jsonb -> 'actiondata' ->> 'template'";
            $name = "u.firstname || ' ' || u.lastname";
            $optionid = "ta.customdata::jsonb ->> 'optionid'";
            $cmid = "ta.customdata::jsonb ->> 'cmid'";
        } else { // MySQL
            $ruleidextract = "JSON_UNQUOTE(JSON_EXTRACT(ta.customdata, '$.ruleid'))";
            $ruleid = "CAST(JSON_UNQUOTE(JSON_EXTRACT(ta.customdata, '$.ruleid')) AS UNSIGNED)";
            $bookingrulename = "JSON_UNQUOTE(JSON_EXTRACT(br.rulejson, '$.name'))";
            $userid = "JSON_UNQUOTE(JSON_EXTRACT(ta.customdata, '$.userid'))";
            $messagesubject = "JSON_UNQUOTE(JSON_EXTRACT(br.rulejson, '$.actiondata.subject'))";
            $messagetext = "JSON_UNQUOTE(JSON_EXTRACT(br.rulejson, '$.actiondata.template'))";
            $name = "CONCAT(u.firstname, ' ', u.lastname)";
            $optionid = "JSON_UNQUOTE(JSON_EXTRACT(ta.customdata, '$.optionid'))";
            $cmid = "JSON_UNQUOTE(JSON_EXTRACT(ta.customdata, '$.cmid'))";
        }

        $fields = "
            s1.*
        ";

        $from = " (SELECT
                    ta.id AS id,
                    ta.classname,
                    ta.nextruntime,
                    br.id AS ruleid,
                    $name AS name,
                    $bookingrulename AS rulename,
                    $messagesubject AS subject,
                    $messagetext AS message,
                    $optionid AS optionid,
                    $cmid AS cmid,
                    br.contextid
                 FROM
            {task_adhoc} ta
            JOIN {booking_rules} br
               ON br.id = $ruleidextract
            JOIN {user} u
               ON u.id = $userid
            WHERE ta.customdata LIKE '{%'      -- ensure JSON-like
                  AND ta.customdata LIKE '%\"ruleid\"%'   -- ensure ruleid exists
               ) as s1
        ";

        $where = "1 = 1";

        return [$fields, $from, $where, []];
    }
}
