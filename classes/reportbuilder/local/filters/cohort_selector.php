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

use lang_string;
use MoodleQuickForm;
use core_reportbuilder\local\filters\base;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\entities\user;
use context_system;
use stdClass;

/**
 * Cohort selector filter for booking reportbuilder.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_selector extends base {
    /**
     * Add form elements for this filter.
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $cohorts = cohort_get_all_cohorts(0, 0)['cohorts'];
        $options = [];
        foreach ($cohorts as $cohort) {
            $options[$cohort->id] = format_string($cohort->name, true, [
                'context' => $cohort->contextid,
                'escape' => false,
            ]);
        }
        $mform->addElement('select', $this->name, new lang_string('condition:cohort', 'mod_booking'), $options);
        $mform->setType($this->name, PARAM_INT);
        $mform->setDefault($this->name, 0);
    }

    /**
     * Return filter SQL.
     *
     * @param array $values
     * @return array [$sql, [...$params]]
     */
    public function get_sql_filter(array $values): array {
        $cohortid = (int) ($values[$this->name] ?? 0);
        if ($cohortid <= 0) {
            return ['', []];
        }
        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();
        $paramname = database::generate_param_name();
        $sql = "{$fieldsql} = :{$paramname}";
        $params[$paramname] = $cohortid;
        return [$sql, $params];
    }

    /**
     * Return sample filter values.
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            $this->name => 1,
        ];
    }
}
