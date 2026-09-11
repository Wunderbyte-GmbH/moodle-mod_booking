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

/**
 * Filter that compares a custom user profile field value to the current user's ID.
 *
 * Designed for profile fields like "supervisor" that store a Moodle user ID
 * as text. When the operator "Current user" is selected, the filter resolves
 * to `{fieldsql} = $USER->id` at query time, so each schedule-recipient
 * sees only rows where the profile field matches their own user ID.
 *
 * The field SQL passed to this filter must resolve to the profile field's
 * stored value (typically a join on {user_info_data}).
 *
 * @package    mod_booking
 * @copyright  Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_field_current_user extends base {
    /** @var int No filter applied. */
    public const ANYVALUE = 0;

    /** @var int Value equals the current user's ID. */
    public const CURRENT_USER = 1;

    /** @var int Value equals a manually entered string. */
    public const IS_EQUAL_TO = 2;

    /**
     * Return available operators.
     *
     * @return lang_string[]
     */
    private function get_operators(): array {
        $operators = [
            self::ANYVALUE => new lang_string('filterisanyvalue', 'core_reportbuilder'),
            self::CURRENT_USER => new lang_string('condition:profilefieldcurrentuser', 'mod_booking'),
            self::IS_EQUAL_TO => new lang_string('filterisequalto', 'core_reportbuilder'),
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

        // Free-text value input (only visible when operator is IS_EQUAL_TO).
        $valuelabel = get_string('filterfieldvalue', 'core_reportbuilder', $this->get_header());
        $mform->addElement('text', "{$this->name}_value", $valuelabel);
        $mform->setType("{$this->name}_value", PARAM_RAW);
        $mform->hideIf("{$this->name}_value", "{$this->name}_operator", 'neq', self::IS_EQUAL_TO);
    }

    /**
     * Return filter SQL.
     *
     * @param array $values
     * @return array [$sql, [...$params]]
     */
    public function get_sql_filter(array $values): array {
        global $USER;

        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANYVALUE);

        switch ($operator) {
            case self::CURRENT_USER:
                $paramname = database::generate_param_name();
                $sql = "{$fieldsql} = :{$paramname}";
                $params[$paramname] = (string) $USER->id;
                break;

            case self::IS_EQUAL_TO:
                $value = $values["{$this->name}_value"] ?? '';
                if ($value === '') {
                    return ['', []];
                }
                $paramname = database::generate_param_name();
                $sql = "{$fieldsql} = :{$paramname}";
                $params[$paramname] = $value;
                break;

            default:
                return ['', []];
        }

        return [$sql, $params];
    }
}
