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
 * Adhoc task to execute bulk operations on booking options.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\form\option_form_bulk;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Adhoc task to execute bulk operations on booking options.
 *
 * Applying the changes of the bulk operations form to a large number of
 * booking options takes too long for a web request, so the form submission
 * only queues this task and the actual work is done here.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execute_bulkoperations_adhoc extends \core\task\adhoc_task {
    /**
     * Get the task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskexecutebulkoperationsadhoc', 'mod_booking');
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @throws \coding_exception
     * @throws \dml_exception
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        /* Decode as associative array so that nested structures (multiselects,
        customfields etc.) are arrays again, just like moodleform::get_data() returned them. */
        $taskdata = json_decode($this->get_custom_data_as_string(), true);

        if (empty($taskdata['optionids']) || empty($taskdata['formdata'])) {
            mtrace('mod_booking\task\execute_bulkoperations_adhoc: no data provided, nothing to do.');
            return;
        }

        $optionids = array_map('intval', $taskdata['optionids']);
        mtrace('mod_booking\task\execute_bulkoperations_adhoc: updating ' . count($optionids) . ' booking option(s).');

        $errors = 0;
        foreach ($optionids as $optionid) {
            // Fresh copy for each option, as save_options mutates the data object.
            $data = (object) $taskdata['formdata'];
            try {
                option_form_bulk::save_options($data, [$optionid]);
                mtrace("... booking option $optionid updated.");
            } catch (\Throwable $e) {
                /* We do not rethrow: a retry of the whole task would re-apply the changes to the
                already updated options and might trigger duplicate update notifications. */
                $errors++;
                mtrace("... ERROR updating booking option $optionid: " . $e->getMessage());
            }
        }

        mtrace(
            'mod_booking\task\execute_bulkoperations_adhoc: finished, '
            . (count($optionids) - $errors) . ' updated, ' . $errors . ' failed.'
        );
    }
}
