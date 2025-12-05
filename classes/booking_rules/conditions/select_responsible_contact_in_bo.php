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

namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule_condition;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Condition how to identify concerned users by matching booking option field and user profile field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_responsible_contact_in_bo implements booking_rule_condition {
    /** @var string $rulename */
    public $conditionname = 'select_responsible_contact_in_bo';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectresponsiblecontactinbo';

    /** @var string $rulejson a json string for a booking rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain booking rule type.
     * @param string $bookingruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool {
        // This condition can currently be combined with any rule.
        return true;
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
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement(
            'static',
            'select_responsible_contact_in_bo',
            '',
            get_string('conditionselectresponsiblecontactinbo_desc', 'mod_booking')
        );
    }

    /**
     * Get the name of the rule.
     *
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionnamestringid, 'mod_booking') : $this->conditionname;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data): void {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruleconditiontype = $this->conditionname;
    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optinid & cmid.
     * SQL is a bit complex because responsible contact field can be empty, contain 1 userid or mulitple userids comma separated.
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void {
        global $DB;

        $useridfilter = '';
        if (!empty($params['userid'])) {
            $params['userid2'] = $params['userid'];
            $useridfilter = " AND rc.userid = :userid2";
        }

        $usesoptiondate = (strpos($sql->select, 'optiondate') !== false);
        $dbfamily = $DB->get_dbfamily();

        switch ($dbfamily) {
            case 'postgres':
                $splitfrom = " JOIN LATERAL (
                    SELECT trim(x) AS userid
                    FROM regexp_split_to_table(bo.responsiblecontact, E',') AS x
                ) rc ON rc.userid <> ''";
                $unique = $usesoptiondate
                    ? $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "rc.userid")
                    : $DB->sql_concat("bo.id", "'-'", "rc.userid");
                break;

            case 'mysql':
            case 'mariadb':
                $maxsplit = 20;
                $numbers = [];
                for ($i = 1; $i <= $maxsplit; $i++) {
                    $numbers[] = "SELECT $i AS n";
                }
                $numbersunion = implode(" UNION ALL ", $numbers);

                // Moodle 4.5 minimal DB liitaions: MySQL suuports JOIN LATERAL since 8.0
                // But MariaDB does not support it in 10.6 - so it is necessary to load t_booking_options again.
                $splitfrom = " JOIN (
                    SELECT bo2.id AS optionid,
                        TRIM(
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(bo2.responsiblecontact, ',', n.n),
                                ',', -1
                            )
                        ) AS userid
                    FROM {booking_options} bo2
                    JOIN ( $numbersunion ) AS n
                    WHERE bo2.responsiblecontact IS NOT NULL
                    AND bo2.responsiblecontact <> ''
                    AND n.n <= 1 + (LENGTH(bo2.responsiblecontact) - LENGTH(REPLACE(bo2.responsiblecontact, ',', '')))
                ) rc ON rc.userid <> ''";
                $unique = $usesoptiondate
                    ? $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "rc.userid")
                    : $DB->sql_concat("bo.id", "'-'", "rc.userid");
                break;

            default:
                throw new \moodle_exception('Unsupported database type for splitting responsiblecontact.');
        }

        // Prepend uniqueid and append userid.
        $sql->select = "$unique AS uniqueid, " . $sql->select;
        $sql->select .= ", rc.userid AS userid";

        // Append the split join.
        $sql->from .= " " . $splitfrom;

        // Apply optional filter.
        $sql->where .= $useridfilter;
    }
}
