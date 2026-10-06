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
use core_customfield\category_controller;
use core_customfield\field_controller;
use mod_booking\customfield\booking_handler;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for mod_booking.create_option_field.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_field_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.create_option_field';

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
     * Human-readable preview of the new field (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::create_option_field_descriptor($input);
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
            'description' => 'Create a new booking option field: a custom field that every booking option on this site can then be '
                . 'given a value for, such as a room, a level or a distance marker.',
            'is' => 'The field definition itself.',
            'not' => 'A value on one option (update_option); a price category (add_price_category).',
            'readonly' => $this->is_read_only(),
            'prompt_meta' => ['intent' => 'Create a custom booking option field definition for the whole site.'],
            'properties' => [
                'shortname' => [
                    'type' => 'string',
                    'description' => 'Unique shortname of the field, lowercase letters, digits and underscore only, '
                        . 'e.g. "sportlevel". This is the name rules, filters and shortcodes use.',
                    'required' => true,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Display name of the field, e.g. "Sport level".',
                    'required' => true,
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Field type, one of the customfield types installed on this site, e.g. text, '
                        . 'textarea, select, checkbox, date, number. Ask when the user did not say which type.',
                    'required' => true,
                ],
                'categoryname' => [
                    'type' => 'string',
                    'description' => 'Name of the field category the field belongs to. An unknown name creates that '
                        . 'category; leave empty to use the first existing one.',
                    'required' => false,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional description shown with the field.',
                    'required' => false,
                ],
                'options' => [
                    'type' => 'array',
                    'description' => 'The selectable values, required for a select field, e.g. ["beginner", "advanced"].',
                    'required' => false,
                ],
                'defaultvalue' => [
                    'type' => 'string',
                    'description' => 'Optional default value for the field.',
                    'required' => false,
                ],
                'required' => [
                    'type' => 'boolean',
                    'description' => 'Whether a value must be given for every booking option (default false).',
                    'required' => false,
                ],
                'uniquevalues' => [
                    'type' => 'boolean',
                    'description' => 'Whether the value must be unique across booking options (default false).',
                    'required' => false,
                ],
                'configdata' => [
                    'type' => 'object',
                    'description' => 'Optional type-specific configuration, passed through to the field type, e.g. '
                        . '{"displaysize": 30} for a text field.',
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
                'id' => 'mod_booking.create_option_field_request',
                'description' => 'User asks for a new custom field for booking options.',
                'examples' => [
                    'Create a booking option field for the room',
                    'I need a new option field of type select with the values near and far',
                    'Add a custom field to the booking options',
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

        $shortname = trim((string)($input['shortname'] ?? ''));
        if ($shortname === '') {
            $errors[] = $this->localized_string('agent_booking_optionfield_shortname_required', null, $lang);
        } else if (!preg_match(option_field_support::SHORTNAME_PATTERN, $shortname)) {
            $errors[] = $this->localized_string('agent_booking_optionfield_shortname_invalid', null, $lang);
        }

        if (trim((string)($input['name'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_booking_optionfield_name_required', null, $lang);
        }

        if (trim((string)($input['type'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_booking_optionfield_type_required', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Deep preflight validation and normalized input preparation.
     *
     * Every recoverable problem ends as a clarification question, so nothing is written and the user
     * is asked instead of shown an error.
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

        $shortname = trim((string)$input['shortname']);
        $type = trim((string)$input['type']);

        if ($issue = option_field_validation::check_type($type, $lang)) {
            return $this->invalid([$issue]);
        }
        if ($issue = option_field_validation::check_shortname_free($shortname, $lang)) {
            return $this->invalid([$issue]);
        }

        $options = option_field_support::normalize_options($input['options'] ?? []);
        if ($type === 'select' && empty($options)) {
            return $this->invalid([[
                'code' => 'OPTION_FIELD_SELECT_OPTIONS_REQUIRED',
                'severity' => 'needs_clarification',
                'user_question' => $this->localized_string(
                    'agent_booking_optionfield_select_options_required',
                    $shortname,
                    $lang
                ),
                'remedy_options' => ['PROVIDE_SELECT_OPTIONS', 'USE_DIFFERENT_TYPE'],
            ]]);
        }

        $types = option_field_support::get_installed_types();
        $prepared = $input;
        $prepared['shortname'] = $shortname;
        $prepared['name'] = trim((string)$input['name']);
        $prepared['type'] = $type;
        $prepared['typename'] = (string)($types[$type] ?? $type);
        $prepared['categoryname'] = trim((string)($input['categoryname'] ?? ''));
        $prepared['description'] = trim((string)($input['description'] ?? ''));
        $prepared['options'] = $options;
        $prepared['required'] = !empty($input['required']);
        $prepared['uniquevalues'] = !empty($input['uniquevalues']);
        $prepared['defaultvalue'] = isset($input['defaultvalue']) ? (string)$input['defaultvalue'] : '';

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

        if (!has_capability(option_field_support::CAPABILITY, context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_optionfield_capability_required', 'booking'),
                'resultid' => null,
            ];
        }

        $shortname = trim((string)($input['shortname'] ?? ''));
        $type = trim((string)($input['type'] ?? ''));

        // The write path repeats the two rules that protect the site: a field must not shadow a
        // booking option property, and a shortname is used once. Preflight asks, execute refuses.
        if (booking_handler::is_reserved_shortname($shortname) || option_field_support::get_field_by_shortname($shortname)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_optionfield_shortname_taken', $shortname, $outputlang),
                'resultid' => null,
            ];
        }
        if (!option_field_support::type_is_installed($type)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_optionfield_type_unknown', $type, $outputlang),
                'resultid' => null,
            ];
        }

        $handler = booking_handler::create();
        $category = option_field_validation::resolve_category($handler, trim((string)($input['categoryname'] ?? '')));

        $field = field_controller::create(0, (object)['type' => $type], $category);
        $configdata = option_field_validation::build_configdata($input);

        api::save_field_configuration($field, (object)[
            'name' => trim((string)($input['name'] ?? '')),
            'shortname' => $shortname,
            'type' => $type,
            // Store an empty (not null) description: mod_booking renders strlen($field->description).
            'description_editor' => [
                'text' => (string)($input['description'] ?? ''),
                'format' => FORMAT_HTML,
            ],
            'configdata' => $configdata,
        ]);

        cache_helper::purge_by_definition('mod_booking', 'customfields');

        $fieldid = (int)$field->get('id');
        $usermessage = $this->localized_string('agent_booking_optionfield_created', $shortname, $outputlang);

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'resultid' => $fieldid,
            'usermessage' => $usermessage,
            'outputlang' => $outputlang,
            'field' => option_field_support::describe_field(
                option_field_support::get_field_by_id($fieldid) ?? (object)[
                    'id' => $fieldid,
                    'name' => trim((string)($input['name'] ?? '')),
                    'shortname' => $shortname,
                    'type' => $type,
                    'description' => '',
                    'configdata' => json_encode($configdata),
                    'sortorder' => 0,
                    'categoryid' => (int)$category->get('id'),
                    'categoryname' => (string)$category->get('name'),
                ],
                false
            ),
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Field id: ' . $fieldid, 'Type: ' . $type]
            ),
        ];
    }
}
