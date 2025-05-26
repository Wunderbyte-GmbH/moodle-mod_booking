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
 * Condition to identify the user who triggered an event or the user who was affected by an event.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule_condition;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Condition to identify the user who triggered an event (userid of event).
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_user_shopping_cart implements booking_rule_condition {
    /** @var string $conditionname */
    public $conditionname = 'select_user_shopping_cart';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectusershoppingcart';

    /** @var int $userid the user who triggered an event */
    public $userid = 0;

    /** @var int $relateduserid the user affected by an event */
    public $relateduserid = 0;

    /** @var string $rulejson a json string for a booking rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain booking rule type.
     * @param string $bookingruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool {
        // This rule cannot be combined with the "days before" rule as it has no event.
        global $DB;

        // This condition needs support for json access to db.
        // If this is not the case, we return false.
        $dbfamily = $DB->get_dbfamily();

        $supporteddbs = [
            'postgres',
            'mysql',
        ];

        if (!in_array($dbfamily, $supporteddbs)) {
            return false;
        }

        // JSON_TABLE is only available in MariaDB 10.6+ and MySQL 8.0+.
        if (
            $dbfamily == 'mysql'
            && !db_is_at_least_mariadb_106_or_mysql_8()
        ) {
            return false;
        }

        if ($bookingruletype == 'rule_daysbefore') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule condition record from DB
     */
    public function set_conditiondata(stdClass $record) {
        $this->set_conditiondata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_conditiondata_from_json(string $json) {
        $this->rulejson = $json;
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        $mform->addElement(
            'static',
            'condition_select_user_shopping_cart',
            '',
            get_string('conditionselectusershoppingcart_desc', 'mod_booking')
        );
    }

    /**
     * Get the name of the condition.
     *
     * @param bool $localized
     * @return string the name of the condition
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionnamestringid, 'mod_booking') : $this->conditionname;
    }

    /**
     * Saves the JSON for the condition into the $data object.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data): void {

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruleconditiontype = $this->conditionname;

        $jsonobject = json_decode($record->rulejson);
    }

    /**
     * Execute the condition.
     *
     * @param stdClass $sql
     * @param array $params
     * @param bool $testmode
     * @param int $nextruntime
     */
    public function execute(
        stdClass &$sql,
        array &$params,
        $testmode = false,
        $nextruntime = 0
    ): void {

        global $DB;

        $newparams = [
            'paymentstatus' => 2, // LOCAL_SHOPPING_CART_PAYMENT_SUCCESS.
            'componentname' => 'mod_booking',
            'area' => 'option',
        ];

        $params = array_merge($params, $newparams);

        $dbfamily = $DB->get_dbfamily();

        switch ($dbfamily) {
            case 'postgres':
                // If select contains optiondateid, we need to include it in uniqueid.
                if (strpos($sql->select, 'optiondateid') !== false) {
                    $concat = $DB->sql_concat(
                        "bo.id",
                        "'-'",
                        "bod.id", // Include optiondateid in uniqueid.
                        "'-'",
                        " (payments_info.payment_data->>'id') ",
                        "'-'",
                        " (payments_info.payment_data->>'timestamp') "
                    );
                } else {
                    $concat = $DB->sql_concat(
                        "bo.id",
                        "'-'",
                        " (payments_info.payment_data->>'id') ",
                        "'-'",
                        " (payments_info.payment_data->>'timestamp') "
                    );
                }

                $sql->select = "$concat as uniquid,
                                bo.id optionid,
                                cm.id cmid,
                                sch.userid,
                                (payments_info.payment_data->>'timestamp')::int AS datefield,
                                (payments_info.payment_data->>'paid')::numeric AS paid,
                                (payments_info.payment_data->>'price')::numeric AS price,
                                (payments_info.payment_data->>'id')::int AS payment_id
                                ";
                $sql->from .= " RIGHT JOIN {local_shopping_cart_history} sch
                                ON sch.itemid = bo.id AND sch.componentname =:componentname AND sch.area=:area,
                                LATERAL (
                                    SELECT jsonb_array_elements(sch.json::jsonb->'installments'->'payments') AS payment_data
                                ) AS payments_info"; // We want to join only the chosen user.
                                // We only want those payments that are.

                // When we are in testmode, we fetch all the records which are before a certain moment.
                $sql->where = " (payments_info.payment_data->>'paid')::numeric = 0
                                AND sch.installments > 0
                                AND sch.paymentstatus = :paymentstatus
                                AND sch.json IS NOT NULL
                                AND sch.json <> ''";

                // If we are in testmode, we check for a very specific payment from one user.
                if ($testmode) {
                    $sql->where .= " AND sch.userid = :userid ";

                    // And we know exactly when the payment was due.
                    $nextruntime = $nextruntime + $params['numberofdays'] * 86400;

                    $sql->where .= " AND (payments_info.payment_data->>'timestamp')::int = :nextruntime ";
                    $params['nextruntime'] = $nextruntime;
                } else {
                    // If we are not in testmode, we want to get all future payments.
                    $sql->where .= " AND (payments_info.payment_data->>'timestamp')::int
                                        >= ( :nowparam + (86400 * :numberofdays ))";
                }
                break;
            case 'mysql':
                $sql->select = "
                    CONCAT('', bo.id, '-', "
                    // If select contains optiondateid, we need to include it in uniqueid.
                    . strpos($sql->select, 'optiondateid') !== false ? "bod.id, '-', " : ""
                    . "JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.id')),
                    '-', JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.timestamp'))) AS uniquid,
                    bo.id optionid,
                    cm.id cmid,
                    sch.userid,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.timestamp')) AS UNSIGNED) AS datefield,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.paid')) AS DECIMAL(10, 2)) AS paid,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.price')) AS DECIMAL(10, 2)) AS price,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.id')) AS UNSIGNED) AS payment_id
                    ";
                $sql->from .= " RIGHT JOIN {local_shopping_cart_history} sch
                    ON sch.itemid = bo.id AND sch.componentname = :componentname AND sch.area = :area
                    JOIN JSON_TABLE(
                        sch.json,
                        '$.installments.payments[*]' COLUMNS (
                            payment_data JSON PATH '$'
                        )
                    ) AS payments_info";
                $sql->where = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.paid')) AS DECIMAL(10, 2)) = 0
                    AND sch.installments > 0
                    AND sch.paymentstatus = :paymentstatus
                    AND sch.json IS NOT NULL
                    AND sch.json <> ''";

                if ($testmode) {
                    $sql->where .= " AND sch.userid = :userid ";

                    // And we know exactly when the payment was due.
                    $nextruntime = $nextruntime + $params['numberofdays'] * 86400;

                    $sql->where .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.timestamp')) AS UNSIGNED)
                                        = :nextruntime ";
                    $params['nextruntime'] = $nextruntime;
                } else {
                    // If we are not in testmode, we want to get all future payments.
                    $sql->where .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payments_info.payment_data, '$.timestamp')) AS UNSIGNED)
                                        >= ( :nowparam + (86400 * :numberofdays ))";
                }

                break;
        }
    }
}
