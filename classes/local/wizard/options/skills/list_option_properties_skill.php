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
use mod_booking\local\wizard\engine\module_targeted_skill;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.list_option_properties.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_option_properties_skill extends booking_skill_base implements skill_trigger_provider_interface {
    // Generic activity-instance targeting: the engine resolves the operating booking instance
    // (ambient course first, then site-wide) from cmid/activityquery and asks when ambiguous,
    // so the lookup also works from non-module entry points (dashboard, MCP system context).
    use module_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.list_option_properties';

    /**
     * The module type whose instances this skill targets.
     *
     * @return string
     */
    public function get_target_modname(): string {
        return 'booking';
    }

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, \mod_booking\local\wizard\engine\skill_risk_class::R0);
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
            // Reverted to the proven wording (#2423): the longer "field list … not documentation" window of the
            // wave-8 rewrite pulled LOP-1/2/3 to wizard.explain_docs in two consecutive Nachläufe (2026-09-17),
            // while this short description reached the skill in runs 8 and 10. LOP-4 stays open (see the ledger).
            'description' => 'List booking option properties derived from create/update task schemas.',
            // W32 LOP-4 (L41 call 79626, L43 thread 13168 call 82972): the first pick was list_option_fields, whose
            // card names "fields ... with its type". The IS line is card-only (not embedded, 1c02ed0c02); it states
            // what this skill returns (name, label, type, description - see the guidance) and the built-in/custom
            // boundary, in its own words, without moving the description that #2423 keeps for LOP-1..3.
            'is' => 'Reference of the built-in booking option fields (not administrator-defined ones): name, label, type and '
                . 'description of each.',
            'not' => 'Custom option fields an administrator defined (list_option_fields); the written documentation '
                . '(wizard.explain_docs).',
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
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course-module id of the booking activity, when it is known — e.g. from a '
                        . 'candidate list that names "cmid <id>" or from a link.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'The booking activity the user named as the target, if any. If the user names a '
                        . 'booking activity in THIS message OR an earlier one — e.g. answering a "which booking '
                        . 'activity?" question with a name like "selflearning" — you MUST put that exact name here '
                        . 'verbatim, so the request is executed in that activity. Leave empty ONLY when the user named '
                        . 'no specific activity (then the activity in scope is used, and the system asks which one if '
                        . 'several exist). Never guess or invent a name. This is the booking activity, NEVER a course — '
                        . 'do NOT use courseid or coursequery for this task.',
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
                // W32 LOP-4 (L41 call 79626 miss, L43 thread 13168 call 82972 detour - same first pick list_option_fields
                // under the old and the frozen selector prompt): "when creating or updating" narrowed this WHEN line
                // to a create/update context, while the neighbour's WHEN names "which booking option fields exist".
                // The WHEN line is card-only (anchors = description + example_utterances,
                // embeddings_catalog_builder_service.php:62), so discovery for LOP-1..3 is unchanged. <= 180.
                'description' => 'The user wants the built-in fields of booking options listed or described - all of them, '
                    . 'or those used when creating or updating one - not custom fields.',
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
