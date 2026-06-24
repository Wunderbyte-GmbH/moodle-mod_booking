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

use cache_helper;
use core_text;
use mod_booking\booking_rules\rules_info;
use mod_booking\output\scheduledmails as scheduledmails_output;
use mod_booking\table\scheduledmails_table;
use stdClass;

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
        } else { // MySQL.
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
                    br.isactive,
                    br.contextid,
                    ta.customdata
                 FROM
            {task_adhoc} ta
            JOIN {booking_rules} br
               ON br.id = $ruleidextract
            JOIN {user} u
               ON u.id = $userid
            WHERE ta.customdata LIKE '{%'      -- ensure JSON-like
                  AND ta.customdata LIKE '%\"ruleid\"%'   -- ensure ruleid exists
                  ORDER BY ta.id ASC
               ) as s1
        ";

        // Only list scheduled mails whose generating rule lives in the requested context, so a
        // booking instance shows its own mails and the system context (contextid 1, "global")
        // shows only site-wide rules' mails -- not every rule's mails across the whole site.
        $where = "s1.contextid = :scheduledmailscontextid";
        $params = ['scheduledmailscontextid' => $contextid];

        return [$fields, $from, $where, $params];
    }

    /**
     * Checks if a scheduled mail task still applies according to the rule logic.
     *
     * @param stdClass $values
     * @return bool
     */
    public static function is_task_still_valid(stdClass $values): bool {
        if (empty($values->id) || empty($values->ruleid) || empty($values->customdata)) {
            return false;
        }

        $taskdata = json_decode((string)$values->customdata);
        if (empty($taskdata) || empty($taskdata->optionid) || empty($taskdata->userid)) {
            return false;
        }

        $ruleinstance = (object)[
            'id' => (int)$values->ruleid,
            'rulename' => $taskdata->rulename ?? '',
            'isactive' => (int)($values->isactive ?? 0),
            'rulejson' => $taskdata->rulejson ?? '',
            'contextid' => (int)($values->contextid ?? 0),
        ];

        if (empty($ruleinstance->rulename) || empty($ruleinstance->rulejson)) {
            return false;
        }

        // Vergleiche Rule-JSON immer als Objekt, um DB-Unterschiede zu vermeiden.
        if (!empty($taskdata->rulejson)) {
            $taskrulejson = json_decode((string)$taskdata->rulejson);
            $currentrulejson = json_decode((string)$ruleinstance->rulejson);
            if (empty($taskrulejson) || empty($currentrulejson)) {
                return false;
            }
            // Wenn die Rule unterschiedlich ist, ist der Task ungültig.
            if ($taskrulejson != $currentrulejson) {
                return false;
            }
        }

        $rule = rules_info::get_rule($ruleinstance->rulename);
        if (empty($rule)) {
            return false;
        }

        try {
            $rule->set_ruledata($ruleinstance);
            return (bool)$rule->check_if_rule_still_applies(
                (int)$taskdata->optionid,
                (int)$taskdata->userid,
                (int)($values->nextruntime ?? 0),
                (int)($taskdata->optiondateid ?? 0)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cleanup invalid scheduled mails for one context by using the rendered table rows.
     *
     * @param int $contextid
     * @param int $pagesize
     * @return array
     */
    public static function cleanup_invalid_tasks_in_context(int $contextid, int $pagesize = 100000): array {
        $scheduledmails = new scheduledmails_output($contextid);
        $table = $scheduledmails->return_table();

        return self::cleanup_invalid_tasks_from_table($table, $pagesize);
    }

    /**
     * Cleanup invalid scheduled mails by inspecting formatted table rows.
     *
     * @param scheduledmails_table $table
     * @param int $pagesize
     * @return array
     */
    public static function cleanup_invalid_tasks_from_table(scheduledmails_table $table, int $pagesize = 100000): array {
        global $DB;

        $deleted = 0;
        $checked = 0;
        $notasksfound = 0;
        $nostatusfound = 0;
        $no = core_text::strtolower(get_string('no'));

        // Render enough rows to inspect all visible records on current filter set.
        $table->printtable($pagesize, true);

        foreach ($table->formatedrows as $key => $row) {
            $checked++;

            $status = core_text::strtolower(trim(strip_tags((string)($row['status'] ?? ''))));
            if ($status === '') {
                $nostatusfound++;
                continue;
            }

            if ($status !== $no) {
                continue;
            }

            $taskid = (int)$key;
            if ($taskid < 1) {
                $notasksfound++;
                continue;
            }

            $DB->delete_records('task_adhoc', ['id' => $taskid]);
            $deleted++;
        }

        cache_helper::purge_by_definition('mod_booking', 'scheduledmailscache');
        cache_helper::purge_by_event('setbackscheduledmailscache');

        return [
            'checked' => $checked,
            'deleted' => $deleted,
            'nostatusfound' => $nostatusfound,
            'notasksfound' => $notasksfound,
        ];
    }
}
