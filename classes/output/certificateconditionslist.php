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
 * This file contains the definition for the renderable classes for certificate
 * conditions list
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use coding_exception;
use mod_booking\local\certificate_conditions\conditions\combination;
use mod_booking\local\certificate_conditions\conditions_info;
use mod_booking\singleton_service;
use Throwable;
use moodle_url;
use renderer_base;
use renderable;
use templatable;

/**
 * Renderable for certificate conditions list
 *
 * @package   mod_booking
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificateconditionslist implements renderable, templatable {
    /** @var array $conditions */
    public $conditions = [];

    /** @var array $conditionsothercontext */
    public $conditionsothercontext = [];

    /** @var int $contextid */
    public $contextid = 1;

    /** @var bool $enableaddbutton */
    public $enableaddbutton = true;

    /**
     * Constructor takes the conditions to render and saves them as array.
     * @param array $conditions
     * @param int $contextid
     * @param bool $enableaddbutton
     * @throws coding_exception
     */
    public function __construct(array $conditions, int $contextid, bool $enableaddbutton = true) {
        global $DB;
        $contexts = [];

        // Relations between combinations and their building blocks (one query for the whole list).
        $byid = [];
        foreach ($conditions as $cond) {
            $byid[(int)$cond->id] = $cond;
        }
        $usedin = [];
        $items = $DB->get_records(
            'booking_cert_cond_item',
            ['component' => 'mod_booking', 'area' => combination::ITEM_AREA],
            '',
            'id,conditionid,itemid'
        );
        foreach ($items as $item) {
            $usedin[(int)$item->itemid][(int)$item->conditionid] = (int)$item->conditionid;
        }

        foreach ($conditions as $cond) {
            $cond->name = $cond->name ?? '';
            $filterobj = json_decode($cond->filterjson);
            $logicobj = json_decode($cond->logicjson);
            $actionobj = json_decode($cond->actionjson);
            $cond->filtername = $filterobj->filtername ?? '';
            // Keep template compatibility: expose logicname, but source it from conditionname first.
            $cond->logicname = $logicobj->conditionname ?? ($logicobj->logicname ?? '');
            $cond->actionname = $actionobj->actionname ?? '';
            $condcomponent = $filterobj->component ?? 'mod_booking';
            $logiccomponent = $logicobj->component ?? 'mod_booking';
            $actioncomponent = $actionobj->component ?? 'mod_booking';

            // Localize the names using dedicated certificate-condition keys.
            $localizedfilterkey = !empty($cond->filtername) ? 'filter_' . $cond->filtername : '';
            $localizedlogickey = !empty($cond->logicname) ? 'condition_' . $cond->logicname : '';
            $localizedactionkey = !empty($cond->actionname) ? 'action_' . $cond->actionname : '';
            $cond->localizedfiltername = !empty($localizedfilterkey) ?
                get_string($localizedfilterkey, $condcomponent) : '';
            $cond->localizedlogicname = !empty($localizedlogickey) ?
                get_string($localizedlogickey, $logiccomponent) : '';
            $cond->localizedactionname = !empty($localizedactionkey) ?
                get_string($localizedactionkey, $actioncomponent) : get_string('certificateaction_none', 'mod_booking');

            // Short summary of the configured condition, e.g. the groups of a combination.
            $cond->logicdescription = '';
            $conditionhandler = !empty($cond->logicname) ? conditions_info::get_condition($cond->logicname) : null;
            if ($conditionhandler) {
                try {
                    $cond->logicdescription = $conditionhandler->get_description($cond);
                } catch (Throwable $e) {
                    debugging('Could not build description of certificate condition ' . $cond->id . ': ' . $e->getMessage());
                }
            }

            // Filter for conditions of this or other context.
            if ($cond->contextid == $contextid) {
                $this->add_relations($cond, $byid, $usedin[(int)$cond->id] ?? []);
                $this->conditions[] = (array)$cond;
            } else if (
                $contextid == 1
                && !isset($contexts[$cond->contextid])
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
                    AND ctx.contextlevel = :contextlevel
                    ";
                $params = [
                    'contextid' => $cond->contextid,
                    'contextlevel' => CONTEXT_MODULE,
                ];
                $conddata = $DB->get_record_sql($sql, $params);
                if (empty($conddata->instanceid)) {
                    continue;
                }
                $url = new moodle_url('/mod/booking/edit_certificateconditions.php', ['cmid' => $conddata->instanceid]);
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($conddata->instanceid);

                $cond->courseid = $conddata->courseid;
                $cond->coursename = format_string($conddata->coursename);
                $cond->linktoconditionsininstance = $url->out();
                $cond->bookingname = format_string($bookingsettings->name);

                $contexts[$cond->contextid] = 1;
                $this->conditionsothercontext[] = (array)$cond;
            }
            // Make sure, conditions from the same course appear next to each other in the list.
            if (!empty($this->conditionsothercontext)) {
                usort($this->conditionsothercontext, function ($a, $b) {
                    return strcmp($a['coursename'], $b['coursename']);
                });
            }
        }
        $this->contextid = $contextid;
        $this->enableaddbutton = $enableaddbutton;
    }

    /**
     * Add the building block / combination information of a condition for the template.
     *
     * @param object $cond condition record (name, logicname, actionname already resolved)
     * @param array $byid all condition records keyed by id
     * @param array $usedinids ids of the combinations using this condition
     * @return void
     */
    private function add_relations(object $cond, array $byid, array $usedinids): void {
        $cond->iscombination = ($cond->logicname === 'combination');
        $cond->hasaction = !empty($cond->actionname);

        $cond->usedin = [];
        foreach ($usedinids as $combinationid) {
            if (isset($byid[$combinationid])) {
                $cond->usedin[] = [
                    'id' => $combinationid,
                    'name' => format_string($byid[$combinationid]->name ?? ''),
                    'contextid' => (int)$byid[$combinationid]->contextid,
                ];
            }
        }
        // Building block: a condition without own action. A condition with an action that a combination uses as
        // well stays a condition and is marked as "also used as building block".
        $cond->isbuildingblock = !$cond->iscombination && !$cond->hasaction;
        $cond->isalsobuildingblock = !$cond->iscombination && $cond->hasaction && !empty($cond->usedin);
        $cond->isunused = !$cond->iscombination && !$cond->hasaction && empty($cond->usedin);
        $cond->hasusedin = !empty($cond->usedin);

        $cond->groups = [];
        $cond->hascontextmismatch = false;
        if ($cond->iscombination) {
            $combination = new combination();
            $combination->set_logicdata($cond);
            $operatorstrings = [
                combination::OP_AND => get_string('condition_combination_and', 'mod_booking'),
                combination::OP_OR => get_string('condition_combination_or', 'mod_booking'),
            ];
            foreach ($combination->get_groups() as $group) {
                $leaves = [];
                foreach ($group['conditionids'] as $leafid) {
                    $leaf = $byid[$leafid] ?? null;
                    $leafjson = $leaf ? json_decode((string)$leaf->logicjson) : null;
                    $mismatch = $leaf && (int)$leaf->contextid !== (int)$cond->contextid;
                    $cond->hascontextmismatch = $cond->hascontextmismatch || $mismatch || !$leaf;
                    $leaves[] = [
                        'id' => (int)$leafid,
                        'name' => $leaf ? format_string($leaf->name ?? '') : '#' . $leafid,
                        'requiredcount' => max(1, (int)($leafjson->requiredcount ?? 1)),
                        'contextid' => $leaf ? (int)$leaf->contextid : 0,
                        'contextmismatch' => $mismatch || !$leaf,
                        'last' => false,
                    ];
                }
                if (!empty($leaves)) {
                    $leaves[count($leaves) - 1]['last'] = true;
                }
                $cond->groups[] = [
                    'operator' => $operatorstrings[$group['operator']],
                    'leaves' => $leaves,
                    'last' => false,
                ];
            }
            if (!empty($cond->groups)) {
                $cond->groups[count($cond->groups) - 1]['last'] = true;
            }
            $cond->topoperator = $operatorstrings[$combination->operator];
            $cond->uniqueoptions = $combination->uniqueoptions;
            // The chips replace the text description.
            $cond->logicdescription = '';
        }
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        $returnarray = [
            'conditions' => $this->conditions,
            'conditionsothercontext' => $this->conditionsothercontext,
            'contextid' => $this->contextid,
            'enableaddbutton' => $this->enableaddbutton,
        ];
        if ($this->contextid == 1) {
            $returnarray['displayothercontexts'] = 1;
        }
        return $returnarray;
    }
}
