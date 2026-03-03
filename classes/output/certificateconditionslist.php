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
 * @copyright 2026 Your Name <you@example.com>
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
        $contexts = [];

        foreach ($conditions as $cond) {
            // name is stored directly
            $cond->name = $cond->name ?? '';
            // decode components individually
            $filterobj = json_decode($cond->filterjson);
            $logicobj = json_decode($cond->logicjson);
            $actionobj = json_decode($cond->actionjson);
            $cond->filtername = $filterobj->filtername ?? '';
            $cond->logicname = $logicobj->logicname ?? '';
            $cond->actionname = $actionobj->actionname ?? '';
            $condcomponent = $filterobj->component ?? 'mod_booking';
            $logiccomponent = $logicobj->component ?? 'mod_booking';
            $actioncomponent = $actionobj->component ?? 'mod_booking';

            // Localize the names using dedicated certificate-condition keys.
            $localizedfilterkey = !empty($cond->filtername) ? 'filter_' . $cond->filtername : '';
            $localizedlogickey = !empty($cond->logicname) ? 'logic_' . $cond->logicname : '';
            $localizedactionkey = !empty($cond->actionname) ? 'action_' . $cond->actionname : '';
            $cond->localizedfiltername = !empty($localizedfilterkey) ?
                get_string($localizedfilterkey, $condcomponent) : '';
            $cond->localizedlogicname = !empty($localizedlogickey) ?
                get_string($localizedlogickey, $logiccomponent) : '';
            $cond->localizedactionname = !empty($localizedactionkey) ?
                get_string($localizedactionkey, $actioncomponent) : '';

            // Filter for conditions of this or other context.
            if ($cond->contextid == $contextid) {
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
