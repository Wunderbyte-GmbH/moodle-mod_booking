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
 * Semesters settings
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use context_system;
use mod_booking\task\recalculate_prices;
use moodle_url;
use stdClass;

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

global $OUTPUT, $USER;

$context = context_system::instance();

$cmid = required_param('id', PARAM_INT);
$submit = optional_param('submit', false, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$pageurl = new \moodle_url('/mod/booking/recalculateprices.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('recalculateprices', 'mod_booking')
);

$data = new stdClass();
$data->cmid = $cmid;
$url = new \moodle_url('/mod/booking/view.php', ['id' => $cmid]);
$data->back = $url->out(false);
$url = new \moodle_url('/mod/booking/recalculateprices.php', ['id' => $cmid, 'submit' => true]);
$data->continue = $url->out(false);
$data->alertmsg = get_string('alertrecalculate', 'mod_booking');

if ($submit) {
    $price = new price('option');
    $currency = get_config('booking', 'globalcurrency');
    $formulastring = get_config('booking', 'defaultpriceformula');
    if (empty($price->pricecategories)) {
        $data->alertmsg = get_string('nopricecategoriesyet', 'mod_booking');
        $data->alert = 1;
    } else if (empty($formulastring)) {
        $url = new moodle_url("/admin/category.php", ['category' => 'modbookingfolder'], 'admin-defaultpriceformula');
        $a = new stdClass();
        $a->url = $url->out(false);
        $data->alertmsg = get_string('nopriceformulaset', 'mod_booking', $a);
        $data->alert = 1;
    } else {

        if (!$DB->record_exists('task_adhoc', ['classname' => '\mod_booking\task\recalculate_prices'])) {
            $task = new recalculate_prices();
            $taskdata = [
                'cmid' => $cmid,
            ];
            $task->set_custom_data($taskdata);
            $task->set_userid($USER->id);

            // Now queue the task or reschedule it if it already exists (with matching data).
            \core\task\manager::reschedule_or_queue_adhoc_task($task);

            $msg = get_string('successfulcalculation', 'mod_booking');
        } else {
            $msg = get_string('bocondoptionhasstarted', 'mod_booking');
        }

        redirect($data->back, $msg, 5);
    }
}

// Page output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculateprices', 'mod_booking'));
echo $OUTPUT->render_from_template('mod_booking/recalculateprices', $data);
echo $OUTPUT->footer();
