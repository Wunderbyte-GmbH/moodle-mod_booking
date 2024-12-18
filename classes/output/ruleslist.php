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
use mod_booking\singleton_service;
use moodle_url;
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

    /** @var array $rules */
    public $rulesothercontext = [];

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
        $contexts = [];

        foreach ($rules as $rule) {
            $ruleobj = json_decode($rule->rulejson);
            $rule->name = $ruleobj->name;
            $rule->actionname = $ruleobj->actionname;
            $rule->conditionname = $ruleobj->conditionname;
            // Localize the names.
            $rule->localizedrulename = get_string(str_replace("_", "", $rule->rulename), 'mod_booking');
            $rule->localizedactionname = get_string(str_replace("_", "", $ruleobj->actionname), 'mod_booking');
            $rule->localizedconditionname = get_string(str_replace("_", "", $ruleobj->conditionname), 'mod_booking');

            // Filter for rules of this or other context.
            if ($rule->contextid == $contextid) {
                $this->rules[] = (array)$rule;
            } else if (
                $contextid == 1
                && !isset($contexts[$rule->contextid])
            ) {
                global $DB;
                $sql = "
                    SELECT
                        ctx.instanceid AS instanceid,
                        c.id AS courseid,
                        c.fullname AS coursename
                    FROM {context} ctx
                    JOIN {course_modules} cm ON cm.id = ctx.instanceid
                    JOIN {course} c ON cm.course = c.id
                    WHERE ctx.id = :contextid
                    AND ctx.contextlevel = 70
                    ";
                $params = ['contextid' => $rule->contextid];
                $ruledata = $DB->get_record_sql($sql, $params);
                $url = new moodle_url('/mod/booking/edit_rules.php', ['cmid' => $ruledata->instanceid]);
                $booking = singleton_service::get_instance_of_booking_by_cmid($ruledata->instanceid);

                $rule->courseid = $ruledata->courseid;
                $rule->coursename = $ruledata->coursename;
                $rule->linktorulesininstance = $url->out();
                $rule->bookingname = $booking->settings->name;

                $contexts[$rule->contextid] = 1;
                $this->rulesothercontext[] = (array)$rule;
            }
            // Make sure, rules from the same course appear next to each other in the list.
            usort($this->rulesothercontext, function ($a, $b) {
                return strcmp($a['coursename'], $b['coursename']);
            });
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
        $returnarray = [
                'rules' => $this->rules,
                'rulesothercontext' => $this->rulesothercontext,
                'contextid' => $this->contextid,
                'enableaddbutton' => $this->enableaddbutton,
        ];
        if ($this->contextid == 1) {
            $returnarray['displayothercontexts'] = 1;
        }
        return $returnarray;
    }
}
