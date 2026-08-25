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

namespace mod_booking\reportbuilder\local\filters;

use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;
use mod_booking\local\competencies\competencies_handler;

/**
 * Competency selector filter for booking reportbuilder.
 *
 * Booking options store their competencies as a comma-separated list of
 * competency ids (booking_options.competencies). This filter offers a select
 * of all competencies currently used by booking options and matches a single
 * id inside the stored list.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency_selector extends select {
    /**
     * Return the options for the select: all competencies used by booking options.
     *
     * @return array competencyid => shortname
     */
    protected function get_select_options(): array {
        global $DB;
        static $options = null;

        if ($options !== null) {
            return $options;
        }

        $options = [];
        $lists = $DB->get_fieldset_sql(
            "SELECT DISTINCT competencies
               FROM {booking_options}
              WHERE competencies IS NOT NULL AND competencies <> ''"
        );
        $competencyids = [];
        foreach ($lists as $list) {
            foreach (explode(',', $list) as $competencyid) {
                $competencyid = (int)trim($competencyid);
                if ($competencyid) {
                    $competencyids[$competencyid] = true;
                }
            }
        }
        foreach (array_keys($competencyids) as $competencyid) {
            $shortname = competencies_handler::get_competency_shortname_by_id($competencyid);
            if ($shortname !== '') {
                $options[$competencyid] = $shortname;
            }
        }
        \core_collator::asort($options);

        return $options;
    }

    /**
     * Return filter SQL matching the selected id inside the comma-separated list.
     *
     * @param array $values
     * @return array [$sql, [...$params]]
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANY_VALUE);
        $competencyid = (int) ($values["{$this->name}_value"] ?? 0);

        if ($operator === self::ANY_VALUE || !array_key_exists($competencyid, $this->get_select_options())) {
            return ['', []];
        }

        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        // Wrap the list in leading/trailing commas so ids match only on delimiter boundaries.
        $wrappedfield = $DB->sql_concat("','", $fieldsql, "','");
        $paramname = database::generate_param_name();
        $params[$paramname] = "%,{$competencyid},%";
        $likesql = $DB->sql_like($wrappedfield, ":{$paramname}");

        switch ($operator) {
            case self::EQUAL_TO:
                return [$likesql, $params];
            case self::NOT_EQUAL_TO:
                $notlikesql = $DB->sql_like($wrappedfield, ":{$paramname}", true, true, true);
                return ["({$fieldsql} IS NULL OR {$notlikesql})", $params];
            default:
                return ['', []];
        }
    }

    /**
     * Return sample filter values.
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            "{$this->name}_operator" => self::EQUAL_TO,
            "{$this->name}_value" => 1,
        ];
    }
}
