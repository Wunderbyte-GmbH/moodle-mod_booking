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
namespace mod_booking\task;


class send_confirmation_mails extends \core\task\adhoc_task {

    /**
     * Data for sending mail
     *
     * @var \stdClass
     */
    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    public function execute() {
        global $CFG, $DB;
        $taskdata = $this->get_custom_data();

        if ($taskdata != null) {
            if (!email_to_user($taskdata->userto, $taskdata->userfrom, $taskdata->subject,
                    $taskdata->messagetext, $taskdata->messagehtml, $taskdata->attachment,
                    $taskdata->attachname)) {
                throw new \coding_exception('Confirmation email was not sent');
            } else {
                $search = str_replace($CFG->tempdir . '/', '', $taskdata->attachment);
                if ($DB->count_records_select('task_adhoc', "customdata LIKE '%$search%'") == 1) {
                    unlink($taskdata->attachment);
                }
            }
        } else {
            throw new \coding_exception(
                    'Confirmation email was not sent due to lack of custom message data');
        }
    }
}
