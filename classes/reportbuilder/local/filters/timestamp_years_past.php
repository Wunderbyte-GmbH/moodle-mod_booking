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

use core\clock;
use core\di;
use lang_string;
use MoodleQuickForm;
use core_reportbuilder\local\filters\base;
use core_reportbuilder\local\helpers\database;

/**
 * Filter that checks whether a timestamp is within the past X years.
 *
 * @package    mod_booking
 * @copyright  2026 wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timestamp_years_past extends base {
    /** @var int No filter applied. */
    public const ANYVALUE = 0;

    /** @var int Timestamp is within the last X years (inclusive). */
    public const WITHIN_LAST_YEARS = 1;

    /**
     * Return available operators.
     *
     * @return lang_string[]
     */
    private function get_operators(): array {
        $operators = [
            self::ANYVALUE => new lang_string('filterisanyvalue', 'core_reportbuilder'),
            self::WITHIN_LAST_YEARS => new lang_string('condition:withinpastxyears', 'mod_booking'),
        ];

        return $this->filter->restrict_limited_operators($operators);
    }

    /**
     * Add form elements for this filter.
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        $operatorlabel = get_string('filterfieldoperator', 'core_reportbuilder', $this->get_header());
        $mform->addElement('select', "{$this->name}_operator", $operatorlabel, $this->get_operators())
            ->setHiddenLabel(true);

        $mform->setType("{$this->name}_operator", PARAM_INT);
        $mform->setDefault("{$this->name}_operator", self::ANYVALUE);

        $valuelabel = get_string('filterfieldvalue', 'core_reportbuilder', $this->get_header());
        $mform->addElement('text', "{$this->name}_value", $valuelabel, ['size' => 3]);
        $mform->setType("{$this->name}_value", PARAM_INT);
        $mform->setDefault("{$this->name}_value", 1);
        $mform->hideIf("{$this->name}_value", "{$this->name}_operator", 'neq', self::WITHIN_LAST_YEARS);
    }

    /**
     * Return filter SQL.
     *
     * @param array $values
     * @return array
     */
    public function get_sql_filter(array $values): array {
        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANYVALUE);
        if ($operator !== self::WITHIN_LAST_YEARS) {
            return ['', []];
        }

        $years = (int) ($values["{$this->name}_value"] ?? 0);
        if ($years <= 0) {
            return ['', []];
        }

        $now = di::get(clock::class)->now();
        $cutoffyear = ((int) $now->format('Y')) - $years;
        $cutoff = $now->setDate($cutoffyear, 1, 1)->setTime(0, 0, 0)->getTimestamp();
        $current = di::get(clock::class)->time();

        [$paramcutoff, $paramnow] = database::generate_param_names(2);

        $sql = "COALESCE({$fieldsql}, 0) BETWEEN :{$paramcutoff} AND :{$paramnow}";
        $params[$paramcutoff] = $cutoff;
        $params[$paramnow] = $current;

        return [$sql, $params];
    }

    /**
     * Return sample filter values.
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            "{$this->name}_operator" => self::WITHIN_LAST_YEARS,
            "{$this->name}_value" => 5,
        ];
    }
}
