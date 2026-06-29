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

use mod_booking\local\wizard\booking\booking_skill_support;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;

/**
 * Task definition for booking.list_option_properties.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_option_properties_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.list_option_properties';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, \bookingextension_agent\local\wizard\dto\skill_risk_class::R0);
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
            'description' => 'List booking option properties derived from create/update task schemas.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user question for language detection and phrasing.',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), create, update, or shared.',
                    'required' => false,
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
                'id' => 'mod_booking.list_option_properties_request',
                'description' => 'User asks for a list of option properties or field definitions '
                    . 'when creating or updating a booking option.',
                'examples' => [
                    'What properties can an option have?',
                    'List fields for creating an option',
                    'Which fields does an option have?',
                    'Which fields can I set when I create a new booking option?',
                    'Which fields can I set when creating a new booking option?',
                    'What fields are available for a booking option?',
                ],
            ],
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
                'id' => 'mod_booking.list_option_properties',
                'triggers' => [
                    'list properties', 'option properties', 'which fields', 'option fields',
                    'fields of option', 'which fields', 'fields can i set',
                    'available fields', 'option fields create',
                ],
                'guidance' => [
                    '- Use booking.list_option_properties when the user asks about available option fields.',
                    '- This task MUST be called when the user asks which fields or properties can be set'
                        . ' for a booking option (creation or update scope).',
                    '- Return a concise structured list of property name, label, type and description.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * Unknown scope values are normalised to 'all' instead of hard-blocking,
     * because the LLM may invent plausible-sounding but unlisted values
     * (e.g. "readonly", "info"). Silently falling back is safer than a
     * VALIDATION_ERROR that surfaces as an agent-level error response.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        // No hard errors: scope is always normalised by normalize_scope().
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Normalise the scope parameter.
     *
     * Accepts 'all', 'create', 'update', 'shared'. Any other value (including
     * LLM-generated variants like 'readonly' or 'info') falls back to 'all'.
     *
     * @param array $input
     * @return string
     */
    private function normalize_scope(array $input): string {
        $scope = strtolower(trim((string)($input['scope'] ?? '')));
        $allowed = ['all', 'create', 'update', 'shared'];
        return in_array($scope, $allowed, true) ? $scope : 'all';
    }

    /**
     * Explicit preflight for readonly task — validates structure and passes input unchanged.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($guard = $this->require_booking_instance_scope($cmid)) {
            return $guard;
        }
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        return $this->pass($input);
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
        if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
            return $scoperesult;
        }
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);

        // Read the sibling skills' schemas directly (same plugin) instead of reaching into the
        // engine's skill registry — this keeps the skill free of engine-internal services.
        $createschema = (new create_option_skill())->get_schema();
        $updateschema = (new update_option_skill())->get_schema();
        $createproperties = (array)($createschema['properties'] ?? []);
        $updateproperties = (array)($updateschema['properties'] ?? []);

        $scope = $this->normalize_scope($input);
        $keys = array_values(array_unique(array_merge(array_keys($createproperties), array_keys($updateproperties))));
        sort($keys);

        $properties = [];
        foreach ($keys as $key) {
            $increate = array_key_exists($key, $createproperties);
            $inupdate = array_key_exists($key, $updateproperties);

            if ($scope === 'create' && !$increate) {
                continue;
            }
            if ($scope === 'update' && !$inupdate) {
                continue;
            }
            if ($scope === 'shared' && !($increate && $inupdate)) {
                continue;
            }

            $source = $createproperties[$key] ?? $updateproperties[$key] ?? [];
            $properties[] = [
                'name' => (string)$key,
                'label' => booking_skill_support::get_localized_property_label_for_output((string)$key),
                'type' => (string)($source['type'] ?? 'mixed'),
                'description' => (string)($source['description'] ?? ''),
                'increate' => $increate,
                'inupdate' => $inupdate,
                'requiredoncreate' => (bool)($createproperties[$key]['required'] ?? false),
                'requiredonupdate' => (bool)($updateproperties[$key]['required'] ?? false),
            ];
        }

        $usermessage = $this->localized_string(
            'agent_booking_list_option_properties_found',
            count($properties),
            $outputlang
        );

        $debugextra = [
            'Properties returned: ' . count($properties),
            'Top property: ' . ($properties[0]['name'] ?? ''),
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'resultid' => null,
            'usermessage' => $usermessage,
            'properties' => $properties,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                $debugextra
            ),
        ];
    }
}
