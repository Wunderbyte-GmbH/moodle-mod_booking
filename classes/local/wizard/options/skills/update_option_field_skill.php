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

use cache_helper;
use context_system;
use core_customfield\api;
use core_customfield\field_controller;
use mod_booking\customfield\booking_handler;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for mod_booking.update_option_field.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_option_field_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.update_option_field';

    /**
     * Booking option fields are a site-wide list: one field applies to every booking option on the
     * site, so the skill operates at the system context and is never scoped to a module.
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
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, [option_field_support::CAPABILITY]);
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
     * Human-readable preview of the change (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::update_option_field_descriptor($input);
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
            'description' => 'Change an existing booking option field: its name, shortname, category, default value, selectable '
                . 'values or whether it is required. Identify the field by its current shortname.',
            'is' => 'The field definition itself.',
            'not' => 'A value on one option (update_option).',
            'readonly' => $this->is_read_only(),
            'prompt_meta' => ['intent' => 'Change the definition of an existing custom booking option field.'],
            'properties' => [
                'currentshortname' => [
                    'type' => 'string',
                    'description' => 'Shortname of the field to change, as it is today, e.g. "sportlevel".',
                    'required' => true,
                ],
                'fieldid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the field to change, when it is known. Takes precedence over '
                        . 'currentshortname.',
                    'required' => false,
                ],
                'shortname' => [
                    'type' => 'string',
                    'description' => 'New shortname, when it should be renamed. Lowercase letters, digits and '
                        . 'underscore only.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New display name of the field.',
                    'required' => false,
                ],
                'categoryname' => [
                    'type' => 'string',
                    'description' => 'Name of the field category to move the field into. An unknown name creates it.',
                    'required' => false,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New description shown with the field.',
                    'required' => false,
                ],
                'options' => [
                    'type' => 'array',
                    'description' => 'New list of selectable values for a select field, e.g. ["beginner", "advanced"]. '
                        . 'Replaces the previous list.',
                    'required' => false,
                ],
                'defaultvalue' => [
                    'type' => 'string',
                    'description' => 'New default value for the field.',
                    'required' => false,
                ],
                'required' => [
                    'type' => 'boolean',
                    'description' => 'Whether a value must be given for every booking option.',
                    'required' => false,
                ],
                'uniquevalues' => [
                    'type' => 'boolean',
                    'description' => 'Whether the value must be unique across booking options.',
                    'required' => false,
                ],
                'configdata' => [
                    'type' => 'object',
                    'description' => 'Optional type-specific configuration, passed through to the field type.',
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
                'id' => 'mod_booking.update_option_field_request',
                'description' => 'User asks to change an existing custom field of the booking options.',
                'examples' => [
                    'Rename the booking option field sport to discipline',
                    'Make the option field room required',
                    'Add another value to the option field level',
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
        $errors = [];
        $lang = $this->get_output_language($input);

        $current = trim((string)($input['currentshortname'] ?? ''));
        $fieldid = (int)($input['fieldid'] ?? 0);
        if ($current === '' && $fieldid <= 0) {
            $errors[] = $this->localized_string('agent_booking_optionfield_target_required', null, $lang);
        }

        if (isset($input['shortname'])) {
            $new = trim((string)$input['shortname']);
            if ($new !== '' && !preg_match(option_field_support::SHORTNAME_PATTERN, $new)) {
                $errors[] = $this->localized_string('agent_booking_optionfield_shortname_invalid', null, $lang);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
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
        $lang = $this->get_output_language($input);

        if (!has_capability(option_field_support::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([[
                'code' => 'OPTION_FIELD_CAPABILITY_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_optionfield_capability_required', 'booking'),
            ]]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            return $this->invalid(option_field_validation::build_issues((array)($structure['errors'] ?? [])));
        }

        $record = $this->resolve_field($input);
        if ($record === null) {
            $target = trim((string)($input['currentshortname'] ?? (string)(int)($input['fieldid'] ?? 0)));
            return $this->invalid([[
                'code' => 'OPTION_FIELD_NOT_FOUND',
                'severity' => 'needs_clarification',
                'user_question' => $this->localized_string(
                    'agent_booking_optionfield_not_found_question',
                    $target,
                    $lang
                ),
                'remedy_options' => ['NAME_EXISTING_FIELD', 'CREATE_NEW_FIELD'],
            ]]);
        }

        // A rename runs through the same two rules as a new field.
        if (isset($input['shortname'])) {
            $new = trim((string)$input['shortname']);
            if ($new !== '' && $new !== (string)$record->shortname) {
                if ($issue = option_field_validation::check_shortname_free($new, $lang, (int)$record->id)) {
                    return $this->invalid([$issue]);
                }
            }
        }

        $prepared = $input;
        $prepared['fieldid'] = (int)$record->id;
        $prepared['currentshortname'] = (string)$record->shortname;
        $prepared['type'] = (string)$record->type;
        if (isset($input['options'])) {
            $prepared['options'] = option_field_support::normalize_options($input['options']);
        }

        return $this->pass($prepared);
    }

    /**
     * Resolve the field this call targets.
     *
     * @param array $input
     * @return \stdClass|null
     */
    private function resolve_field(array $input): ?\stdClass {
        $fieldid = (int)($input['fieldid'] ?? 0);
        if ($fieldid > 0) {
            return option_field_support::get_field_by_id($fieldid);
        }
        return option_field_support::get_field_by_shortname(trim((string)($input['currentshortname'] ?? '')));
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

        if (!has_capability(option_field_support::CAPABILITY, context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_optionfield_capability_required', 'booking'),
                'resultid' => null,
            ];
        }

        $record = $this->resolve_field($input);
        if ($record === null) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string(
                    'agent_booking_optionfield_not_found',
                    trim((string)($input['currentshortname'] ?? '')),
                    $outputlang
                ),
                'resultid' => null,
            ];
        }

        $shortname = (string)$record->shortname;
        if (isset($input['shortname']) && trim((string)$input['shortname']) !== '') {
            $shortname = trim((string)$input['shortname']);
        }

        // The write path repeats the rule that protects the site: preflight asks, execute refuses.
        if (
            $shortname !== (string)$record->shortname
            && option_field_validation::check_shortname_free($shortname, $outputlang, (int)$record->id) !== null
        ) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_optionfield_shortname_taken', $shortname, $outputlang),
                'resultid' => null,
            ];
        }

        $current = json_decode((string)($record->configdata ?? ''), true);
        if (!is_array($current)) {
            $current = [];
        }
        $configinput = $input;
        if (!array_key_exists('required', $input)) {
            $configinput['required'] = !empty($current['required']);
        }
        if (!array_key_exists('uniquevalues', $input)) {
            $configinput['uniquevalues'] = !empty($current['uniquevalues']);
        }
        $configdata = option_field_validation::build_configdata($configinput, $current);

        $handler = booking_handler::create();
        $field = field_controller::create((int)$record->id);

        $formdata = (object)[
            'id' => (int)$record->id,
            'name' => isset($input['name']) && trim((string)$input['name']) !== ''
                ? trim((string)$input['name'])
                : (string)$record->name,
            'shortname' => $shortname,
            'type' => (string)$record->type,
            'description_editor' => [
                'text' => array_key_exists('description', $input)
                    ? (string)$input['description']
                    : (string)($record->description ?? ''),
                'format' => FORMAT_HTML,
            ],
            'configdata' => $configdata,
        ];

        api::save_field_configuration($field, $formdata);

        if (isset($input['categoryname']) && trim((string)$input['categoryname']) !== '') {
            $category = option_field_validation::resolve_category($handler, trim((string)$input['categoryname']));
            if ((int)$category->get('id') !== (int)$record->categoryid) {
                api::move_field($field, (int)$category->get('id'));
            }
        }

        cache_helper::purge_by_definition('mod_booking', 'customfields');

        $usermessage = $this->localized_string('agent_booking_optionfield_updated', $shortname, $outputlang);
        $updated = option_field_support::get_field_by_id((int)$record->id);

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'resultid' => (int)$record->id,
            'usermessage' => $usermessage,
            'outputlang' => $outputlang,
            'field' => $updated !== null ? option_field_support::describe_field($updated) : [],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Field id: ' . (int)$record->id, 'Shortname: ' . $shortname]
            ),
        ];
    }
}
