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
 * Booking-domain task provider.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\agent\booking;

use context_module;
use mod_booking\agent\interfaces\agent_task_provider;
use mod_booking\booking_option;
use mod_booking\singleton_service;

/**
 * Implements agent_task_provider for mod_booking.
 *
 * Supported tasks:
 *  - booking.create_option
 *  - booking.update_option
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_task_provider implements agent_task_provider {

    /** Task name for creating a new booking option. */
    const TASK_CREATE_OPTION = 'booking.create_option';

    /** Task name for updating an existing booking option. */
    const TASK_UPDATE_OPTION = 'booking.update_option';

    /**
     * Return the task names this provider handles.
     *
     * @return string[]
     */
    public function get_task_names(): array {
        return [
            self::TASK_CREATE_OPTION,
            self::TASK_UPDATE_OPTION,
        ];
    }

    /**
     * Return the JSON schema for the given task name.
     *
     * @param string $taskname
     * @return array
     */
    public function get_task_schema(string $taskname): array {
        $common = [
            'text'        => ['type' => 'string', 'description' => 'Full description of the booking option.', 'required' => false],
            'location'    => ['type' => 'string', 'description' => 'Location / venue.', 'required' => false],
            'address'     => ['type' => 'string', 'description' => 'Address of the venue.', 'required' => false],
            'maxanswers'  => ['type' => 'integer', 'description' => 'Maximum number of participants.', 'required' => false],
            'maxoverbooking' => ['type' => 'integer', 'description' => 'Waiting-list size.', 'required' => false],
            'coursestarttime' => [
                'type' => 'string',
                'description' => 'Start date/time in ISO 8601 (e.g. 2025-06-01T09:00:00). Converted to UNIX timestamp.',
                'required' => false,
            ],
            'courseendtime' => [
                'type' => 'string',
                'description' => 'End date/time in ISO 8601.',
                'required' => false,
            ],
            'teacheremail' => [
                'type'        => 'string',
                'description' => 'E-mail address of the teacher. Used to resolve teacherid.',
                'required'    => false,
            ],
        ];

        if ($taskname === self::TASK_CREATE_OPTION) {
            return [
                'version'     => 1,
                'description' => 'Create a new booking option inside the current booking instance.',
                'properties'  => array_merge([
                    'text' => ['type' => 'string', 'description' => 'Title of the new booking option.', 'required' => true],
                ], $common),
            ];
        }

        if ($taskname === self::TASK_UPDATE_OPTION) {
            return [
                'version'     => 1,
                'description' => 'Update an existing booking option in the current booking instance.',
                'properties'  => array_merge([
                    'optionid' => [
                        'type'        => 'integer',
                        'description' => 'ID of the booking option to update. Must be resolved from the option list.',
                        'required'    => true,
                    ],
                ], $common),
            ];
        }

        return [];
    }

    /**
     * Validate task input.
     *
     * @param string $taskname
     * @param array  $input
     * @param int    $cmid
     * @return array
     */
    public function validate(string $taskname, array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];

        if ($taskname === self::TASK_CREATE_OPTION) {
            if (empty($input['text'])) {
                $errors[] = 'Field "text" (option title) is required for create_option.';
            }
        } else if ($taskname === self::TASK_UPDATE_OPTION) {
            if (empty($input['optionid'])) {
                $ambiguities[] = 'Which booking option should be updated? Please provide the option ID or name.';
            } else {
                // Verify the option belongs to this booking instance.
                global $DB;
                $cm = get_coursemodule_from_id('booking', $cmid);
                if (!$cm || !$DB->record_exists('booking_options', [
                    'id' => (int)$input['optionid'],
                    'bookingid' => $cm->instance,
                ])) {
                    $errors[] = 'Booking option with id ' . (int)$input['optionid'] .
                                ' does not exist in this booking instance.';
                }
            }
        } else {
            $errors[] = 'Unknown task: ' . $taskname;
        }

        if (isset($input['coursestarttime'])) {
            if (!self::parse_datetime($input['coursestarttime'])) {
                $errors[] = 'Field "coursestarttime" must be a valid ISO 8601 date-time string.';
            }
        }
        if (isset($input['courseendtime'])) {
            if (!self::parse_datetime($input['courseendtime'])) {
                $errors[] = 'Field "courseendtime" must be a valid ISO 8601 date-time string.';
            }
        }

        return [
            'valid'       => empty($errors) && empty($ambiguities),
            'errors'      => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Execute a validated command.
     *
     * @param string $taskname
     * @param array  $input
     * @param int    $cmid
     * @param int    $userid
     * @return array
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return ['status' => 'error', 'detail' => 'Invalid course module.', 'resultid' => null];
        }

        $context = context_module::instance($cmid);

        // Build the data object for booking_option::update().
        $data = new \stdClass();
        $data->bookingid = (int)$cm->instance;
        $data->cmid = $cmid;
        $data->importing = true; // Triggers array-mode processing in ::update().

        // Map common fields.
        $textfields = ['text', 'location', 'address', 'description'];
        foreach ($textfields as $field) {
            if (isset($input[$field])) {
                $data->$field = clean_param($input[$field], PARAM_TEXT);
            }
        }

        $intfields = ['maxanswers', 'maxoverbooking'];
        foreach ($intfields as $field) {
            if (isset($input[$field])) {
                $data->$field = (int)$input[$field];
            }
        }

        foreach (['coursestarttime', 'courseendtime'] as $field) {
            if (isset($input[$field])) {
                $ts = self::parse_datetime($input[$field]);
                if ($ts !== false) {
                    $data->$field = $ts;
                }
            }
        }

        // Resolve teacher email → userid.
        if (!empty($input['teacheremail'])) {
            $teacher = $DB->get_record('user', ['email' => $input['teacheremail'], 'deleted' => 0]);
            if ($teacher) {
                $data->teachersforoption = [$teacher->id];
            }
        }

        if ($taskname === self::TASK_CREATE_OPTION) {
            $data->id = 0;
            if (empty($data->text)) {
                return ['status' => 'error', 'detail' => 'Option title is required.', 'resultid' => null];
            }
        } else if ($taskname === self::TASK_UPDATE_OPTION) {
            $data->id = (int)$input['optionid'];
        }

        try {
            $newoptionid = booking_option::update($data, $context);
            return [
                'status'   => 'executed',
                'detail'   => 'Booking option ' . ($taskname === self::TASK_CREATE_OPTION ? 'created' : 'updated') .
                              ' (id=' . (int)$newoptionid . ').',
                'resultid' => (int)$newoptionid,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null];
        }
    }

    /**
     * Parse an ISO 8601 date-time string to a UNIX timestamp.
     *
     * @param  string $value
     * @return int|false  UNIX timestamp or false on failure.
     */
    private static function parse_datetime(string $value): int|false {
        $ts = strtotime($value);
        return ($ts !== false && $ts > 0) ? $ts : false;
    }
}
