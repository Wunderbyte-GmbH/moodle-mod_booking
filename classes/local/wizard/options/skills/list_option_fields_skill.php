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

namespace mod_booking\local\wizard\options\skills;

use context_system;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for mod_booking.list_option_fields.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_option_fields_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.list_option_fields';

    /**
     * Booking option fields are a site-wide list, not activity-scoped, so this skill operates at the
     * system context and needs no booking-activity target. A field affects every booking option on the
     * site, so it is never read per module context.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, \mod_booking\local\wizard\engine\skill_risk_class::R0, [option_field_support::CAPABILITY]);
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
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector/constructor window.
            'description' => 'Show the booking option fields (custom fields for booking options) of this site: all of them, or one '
                . 'selected by shortname, with its type, category, configuration and how many options hold a value.',
            'is' => 'Custom fields an administrator defined for booking options.',
            'not' => 'The built-in properties of a booking option (list_option_properties).',
            'readonly' => $this->is_read_only(),
            'prompt_meta' => ['intent' => 'Show the custom booking option fields defined on this site.'],
            'properties' => [
                'shortname' => [
                    'type' => 'string',
                    'description' => 'Shortname of a single field to show, e.g. "sport". Leave empty to list all fields.',
                    'required' => false,
                ],
                'fieldid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of a single field to show, when it is known. Takes precedence over shortname.',
                    'required' => false,
                ],
                'includeusage' => [
                    'type' => 'boolean',
                    'description' => 'Whether to count the booking options that hold a value for each field (default true).',
                    'required' => false,
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user question for language detection and phrasing.',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.list_option_fields_request',
                'description' => 'User asks which booking option fields (custom fields for booking options) exist, '
                    . 'or asks for the configuration of one of them.',
                'examples' => [
                    'Which booking option fields exist?',
                    'Show me the custom fields for booking options',
                    'What is configured for the option field sport?',
                    'List the booking option fields and their types',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        // Reading needs no input at all: without a selector the whole list is returned.
        return [
            'valid' => true,
            'errors' => [],
        ];
    }

    /**
     * Deep preflight validation and normalized input preparation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if (!has_capability(option_field_support::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([[
                'code' => 'OPTION_FIELD_CAPABILITY_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_optionfield_capability_required', 'booking'),
            ]]);
        }

        $prepared = $input;
        $prepared['shortname'] = trim((string)($input['shortname'] ?? ''));
        $prepared['fieldid'] = (int)($input['fieldid'] ?? 0);
        $prepared['includeusage'] = !isset($input['includeusage']) || !empty($input['includeusage']);

        return $this->pass($prepared);
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $outputlang = $this->get_output_language($input);
        $shortname = trim((string)($input['shortname'] ?? ''));
        $fieldid = (int)($input['fieldid'] ?? 0);
        $withusage = !isset($input['includeusage']) || !empty($input['includeusage']);

        if ($fieldid > 0 || $shortname !== '') {
            $record = $fieldid > 0
                ? option_field_support::get_field_by_id($fieldid)
                : option_field_support::get_field_by_shortname($shortname);

            if ($record === null) {
                $usermessage = $this->localized_string(
                    'agent_booking_optionfield_not_found',
                    $shortname !== '' ? $shortname : (string)$fieldid,
                    $outputlang
                );
                return [
                    'status' => 'executed',
                    'detail' => $usermessage,
                    'resultid' => null,
                    'usermessage' => $usermessage,
                    'fields' => [],
                    'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Fields returned: 0']),
                ];
            }

            $descriptor = option_field_support::describe_field($record, $withusage);
            $usermessage = $this->localized_string(
                'agent_booking_optionfield_list_one',
                $descriptor['shortname'],
                $outputlang
            );

            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'resultid' => $descriptor['id'],
                'usermessage' => $usermessage,
                'fields' => [$descriptor],
                'installedtypes' => option_field_support::get_installed_types(),
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    ['Fields returned: 1', 'Shortname: ' . $descriptor['shortname']]
                ),
            ];
        }

        $fields = [];
        foreach (option_field_support::get_field_records() as $record) {
            $fields[] = option_field_support::describe_field($record, $withusage);
        }

        $usermessage = $this->localized_string(
            'agent_booking_optionfield_list_found',
            count($fields),
            $outputlang
        );

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'resultid' => null,
            'usermessage' => $usermessage,
            'fields' => $fields,
            'installedtypes' => option_field_support::get_installed_types(),
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Fields returned: ' . count($fields)]
            ),
        ];
    }
}
