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

namespace mod_booking\local\wbagent\booking\tasks;

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\booking\support\booking_mutation_validation;

/**
 * Task definition for booking.update_option.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_option_task extends base_booking_task {
    /** Task name constant. */
    public const TASK_NAME = 'booking.update_option';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update an existing booking option in the current booking instance.',
            'readonly' => $this->is_read_only(),
            'properties' => array_merge([
                'text' => [
                    'type' => 'string',
                    'description' => 'Title of the booking option (not the long description).',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'ID of the booking option to update. If omitted, provide optionquery.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Text query to resolve the target option by title/description/location.',
                    'required' => false,
                ],
                'optionwhen' => [
                    'type' => 'string',
                    'description' => 'Optional temporal hint for disambiguation (e.g. "next monday").',
                    'required' => false,
                ],
            ], option_schema_definition::common_properties()),
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.mutation_flow',
                'triggers' => [
                    'create option', 'update option', 'new option', 'change option',
                    'erstelle', 'anlegen', 'aktualisiere', 'update', 'setze', 'andern', 'ändern',
                ],
                'guidance' => [
                    '- In command input mapping: "text" means option title, "description" means long body text.',
                    '- If user names an existing option, use that text directly as optionquery.',
                    '- If user provides a title fragment (e.g. "contains Hannah Arendt"), still use optionquery directly.',
                    '- For hide/show requests, map to visibility/invisible:',
                    '  invisible|hidden -> invisible=1, visible -> invisible=0, direct-link-only -> invisible=2.',
                    '- Do not ask for optionid first unless optionquery resolution is ambiguous.',
                    '- For mutating requests, combine lookup and action in one command,',
                    '  e.g. booking.update_option with optionquery/teacherquery/coursequery.',
                    '- For mutating requests, do not ask for permission to run internal lookup steps.',
                    '- Do not output standalone search tasks as final action for mutating intent.',
                    '- For date additions on existing options, use optiondates with optiondatesmode=append '
                        . '(or omit optiondatesmode; append is default).',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        global $DB;

        $errors = [];
        $ambiguities = [];

        if (empty($input['optionid'])) {
            if (empty($input['optionquery'])) {
                $ambiguities[] = 'Which booking option should be updated? Provide optionid or optionquery.';
            } else if (!booking_task_support::is_last_option_reference((string)$input['optionquery'])) {
                $result = booking_task_support::resolve_single_option(
                    $cmid,
                    (string)$input['optionquery'],
                    (string)($input['optionwhen'] ?? '')
                );
                if ($result['status'] === 'error') {
                    $errors[] = (string)$result['message'];
                } else if ($result['status'] === 'ambiguity') {
                    $ambiguities[] = (string)$result['message'];
                }
            }
        } else {
            $cm = get_coursemodule_from_id('booking', $cmid);
            if (
                !$cm
                || !$DB->record_exists('booking_options', [
                    'id' => (int)$input['optionid'],
                    'bookingid' => $cm->instance,
                ])
            ) {
                $errors[] = 'Booking option with id ' . (int)$input['optionid']
                    . ' does not exist in this booking instance.';
            }
        }

        $common = booking_mutation_validation::validate_common($input, $cmid, self::TASK_NAME);
        $errors = array_merge($errors, $common['errors']);
        $ambiguities = array_merge($ambiguities, $common['ambiguities']);

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Verify that relevant fields were persisted as requested.
     *
     * @param array<string,mixed> $input
     * @param object $settings
     * @return array<int,string>
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }
}
