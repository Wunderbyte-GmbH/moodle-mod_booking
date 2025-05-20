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
 * Adhoc Task to send a mail by a rule at a certain time.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use mod_booking\booking_option;
use mod_booking\local\respondapi\entities\person;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use Exception;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle adhoc Task to send a mail by a rule at a certain time.
 */
class respondapi_import_person_adhoc extends \core\task\adhoc_task {
    /**
     * Get task name.
     *
     * @return \lang_string|string
     * @throws \coding_exception
     */
    public function get_name() {
        return get_string('marmara:importpersonadhoc', 'mod_booking');
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
        $taskdata = $this->get_custom_data();

        if ($taskdata != null) {
            mtrace($this->get_name() . ' executed.');

            try {
                // Chekc if all required keys exits in $taskdata.
                $requiredkeys = [
                    'provider',
                    'addkeywords',
                    'removekeywords',
                    'optionid',
                    'userid',
                    'source',
                ];
                foreach ($requiredkeys as $key) {
                    if (!property_exists($taskdata, $key)) {
                        throw new Exception("Excepted key ({$key}) not found in task data.");
                    }
                }

                // Preparing data to send to API.
                $provider = $taskdata->provider;
                $provider = new $provider();
                $user = singleton_service::get_instance_of_user($taskdata->userid);
                $person = new person($user->firstname, $user->lastname, $user->email);
                $personid = $provider->sync_person(
                    $taskdata->source,
                    $person->to_array(),
                    $taskdata->addkeywords,
                    $taskdata->removekeywords
                );
                // If $personid is an integer, the operation was successful.
                // Otherwise, the sync failed and the user should be queued for retry.
                if (!is_int($personid)) {
                    return new Exception(" Unexpected response from sync_keyword: {$personid}");
                }

                mtrace($this->get_name() . ": Task done successfully.");
            } catch (\Throwable $e) {
                mtrace($this->get_name() . ": ERROR - " . $e->getMessage());
                throw $e;
            }
        } else {
            mtrace($this->get_name() . ': ERROR - missing taskdata.');
            throw new \coding_exception(
                $this->get_name() . ': ERROR - missing taskdata.'
            );
        }
    }
}
