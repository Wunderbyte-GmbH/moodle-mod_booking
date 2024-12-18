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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2022 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use coding_exception;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruleslist implements renderable, templatable {

    /** @var array $rules */
    public $rules = [];

    /** @var int $contextid */
    public $contextid = 1;

    /** @var bool $enableaddbutton */
    public $enableaddbutton = true;

    /**
     * Constructor takes the rules to render and saves them as array.
     * @param array $rules
     * @param int $contextid
     * @param bool $enableaddbutton
     * @return void
     * @throws coding_exception
     */
    public function __construct(array $rules, int $contextid, bool $enableaddbutton = true) {

        foreach ($rules as $rule) {
            $ruleobj = json_decode($rule->rulejson);
            $rule->name = $ruleobj->name;
            $rule->actionname = $ruleobj->actionname;
            $rule->conditionname = $ruleobj->conditionname;
            // Localize the names.
            $rule->localizedrulename = get_string(str_replace("_", "", $rule->rulename), 'mod_booking');
            $rule->localizedactionname = get_string(str_replace("_", "", $ruleobj->actionname), 'mod_booking');
            $rule->localizedconditionname = get_string(str_replace("_", "", $ruleobj->conditionname), 'mod_booking');

            $this->rules[] = (array)$rule;
        }
        $this->contextid = $contextid;
        $this->enableaddbutton = $enableaddbutton;
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {
        return [
                'rules' => $this->rules,
                'contextid' => $this->contextid,
                'enableaddbutton' => $this->enableaddbutton,
        ];
    }
}
