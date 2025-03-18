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
 * Adhoc Task to check booking answers and possibly delete them.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\local\checkanswers\checkanswers;

/**
 * Class to handle adhoc Task to check booking answers of booking instances.
 * Answers might be deleted via this task.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_answers extends \core\task\adhoc_task {
    /**
     * Data for sending mail
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('taskcheckanswers', 'mod_booking');
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

        $data = $this->get_custom_data();
        if (!isset($data->optionid)) {
            return;
        }

        if (
            // For safety, we check for both settings.
            get_config('booking', 'unenroluserswithoutaccessareyousure')
            && get_config('booking', 'unenroluserswithoutaccess')
        ) {
            checkanswers::process_booking_option(
                $data->optionid,
                $data->check,
                $data->action,
                $data->userid
            );
        }
        return;
    }
}
