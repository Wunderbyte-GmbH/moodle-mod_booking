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

use mod_booking\local\wizard\engine\skill_risk_class;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\local\wizard\engine\observation_time;
use mod_booking\singleton_service;

/**
 * Task definition for booking.get_option_details.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_option_details_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.get_option_details';

    /** Default fields returned on the first detail lookup. */
    private const DEFAULT_STANDARD_FIELDS = [
        'title',
        'teachers',
        'sessions',
        'price',
        'currency',
    ];

    /** All supported standard fields for targeted follow-up lookups. */
    private const SUPPORTED_STANDARD_FIELDS = [
        'title',
        'description',
        'price',
        'currency',
        'teachers',
        'sessions',
        'imageurl',
        'canceluntil',
        'coursestarttime',
        'courseendtime',
        'costcenter',
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
        $schema = [
            'version' => 1,
            'description' => 'Get detailed information for one or more booking options via booking option APIs.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Show me the full details of the Spring Workshop',
                'What is the price and teacher for the yoga course?',
                'Give me all the information about option 1422',
                'How many seats are left in the cooking class?',
                'Tell me everything about the First Aid Course',
            ],
            'properties' => [
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Single booking option id to inspect.',
                    'required' => false,
                ],
                'optionids' => [
                    'type' => 'array',
                    'description' => 'Optional list of booking option ids for batch details. Keep short.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Option title/query to resolve when optionid is unknown.',
                    'required' => false,
                ],
                'includesessions' => [
                    'type' => 'boolean',
                    'description' => 'Whether session details should be included (default true).',
                    'required' => false,
                ],
                'requested_fields' => [
                    'type' => 'array',
                    'description' => 'Optional targeted standard fields (e.g. description, price, teachers). '
                        . 'If omitted, returns a compact default set and capability hints.',
                    'required' => false,
                ],
                'include_customfields' => [
                    'type' => 'boolean',
                    'description' => 'Include custom field values in the response (default false).',
                    'required' => false,
                ],
                'customfield_keys' => [
                    'type' => 'array',
                    'description' => 'Optional custom field shortnames to return. Only used when include_customfields=true.',
                    'required' => false,
                ],
                'maxitems' => [
                    'type' => 'integer',
                    'description' => 'Safety limit for batch lookups (default 3, max 5).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];

        $schema['prompt_meta'] = [
            'input_fields_for_prompt' => ['optionquery (or optionid / optionids)'],
            'anchor_fields' => ['optionquery', 'optionid'],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.get_option_details_request',
                'description' => 'User asks for specific details of an already identified booking option.',
                'examples' => [
                    'Who is the trainer for "Event 1"?',
                    'Show details for option 73',
                    'Which sessions does Option X have?',
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
                'id' => 'mod_booking.get_option_details',
                'triggers' => [
                    'trainer', 'teacher',
                    'option details', 'option detail', 'option sessions',
                ],
                'guidance' => [
                    '- Use booking.get_option_details when the user asks for specific fields of an option',
                    '  (e.g. teachers, sessions, times, image, price context).',
                    '- Prefer optionid when already known; otherwise resolve via optionquery first.',
                    '- First call can be compact to learn available detail fields; follow-up calls can target',
                    '  requested_fields and optional customfield_keys for precise details.',
                    '- Keep batch usage small and intentional (max a few options).',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $hasoptionid = !empty((int)($input['optionid'] ?? 0));
        $hasoptionids = !empty($input['optionids']) && is_array($input['optionids']);
        $hasquery = trim((string)($input['optionquery'] ?? '')) !== '';

        if (!$hasoptionid && !$hasoptionids && !$hasquery) {
            $errors[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
        }

        if (isset($input['optionids']) && !is_array($input['optionids'])) {
            $errors[] = 'optionids must be an array.';
        }

        if (isset($input['requested_fields']) && !is_array($input['requested_fields'])) {
            $errors[] = 'requested_fields must be an array.';
        }

        if (isset($input['customfield_keys']) && !is_array($input['customfield_keys'])) {
            $errors[] = 'customfield_keys must be an array.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    // Preflight is intentionally NOT overridden: base_skill::run_preflight() already maps
    // check_structure() onto the DTO-free pass/invalid result (structure is validated
    // independently of context scope; this skill resolves its context eagerly in execute()).

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $contextid  Moodle contextid (module or system context).
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        // Resolve cmid for module-context lookups; stays 0 for system context (contextid=1).
        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        $outputlang = $this->get_output_language($input);
        $includesessions = !array_key_exists('includesessions', $input) || !empty($input['includesessions']);
        $includecustomfields = !empty($input['include_customfields']);
        $maxitems = isset($input['maxitems']) ? max(1, min(5, (int)$input['maxitems'])) : 3;
        $requestedfields = $this->normalize_requested_fields((array)($input['requested_fields'] ?? []));
        $customfieldkeys = $this->normalize_customfield_keys((array)($input['customfield_keys'] ?? []));

        if (empty($requestedfields)) {
            $requestedfields = self::DEFAULT_STANDARD_FIELDS;
        }

        $resolvedids = $this->resolve_target_option_ids($input, $cmid, $userid, $maxitems);
        if (empty($resolvedids)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang),
                'resultid' => null,
                'optiondetails' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Resolved ids: none']),
            ];
        }

        $details = [];
        $availablecustomfields = [];
        foreach ($resolvedids as $optionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings((int)$optionid);
            if (!$settings) {
                continue;
            }

            $info = $settings->return_booking_option_information(null, $includesessions);
            if (!is_array($info)) {
                continue;
            }

            $capability = $this->build_option_capability_snapshot($settings);
            foreach ((array)($capability['available_customfields'] ?? []) as $cf) {
                if (!is_array($cf)) {
                    continue;
                }
                $key = trim((string)($cf['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                if (!isset($availablecustomfields[$key])) {
                    $availablecustomfields[$key] = $cf;
                }
            }

            $selectedstandard = $this->select_standard_fields($info, $requestedfields, $includesessions);
            $selectedcustomfields = $this->select_custom_fields($settings, $includecustomfields, $customfieldkeys);

            $details[] = [
                'optionid' => (int)($info['itemid'] ?? $optionid),
                'title' => (string)($info['title'] ?? ''),
                'requested_fields' => $requestedfields,
                'standard_fields' => $selectedstandard,
                'customfields' => $selectedcustomfields,
                'capabilities' => $capability,
            ];
        }

        if (empty($details)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang),
                'resultid' => null,
                'optiondetails' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Resolved ids: none with data']),
            ];
        }

        $count = count($details);
        $firstid = (int)($details[0]['optionid'] ?? 0);
        // Entity mentions always carry real moodle_url links for the synchronizer:
        // "Title (link)" per option instead of bare titles.
        $labels = [];
        foreach (array_slice($details, 0, 3) as $d) {
            $title = trim((string)($d['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $detailoptionid = (int)($d['optionid'] ?? 0);
            $labels[] = ($cmid > 0 && $detailoptionid > 0)
                ? $title . ' (' . booking_skill_support::build_option_link_for_output($cmid, $detailoptionid) . ')'
                : $title;
        }
        $detailmessage = 'Found details for ' . $count . ' booking option(s)';
        if (!empty($labels)) {
            $detailmessage .= ': ' . implode(', ', $labels);
        }
        $detailmessage .= '.';

        $detailcapabilities = [
            'supported_standard_fields' => self::SUPPORTED_STANDARD_FIELDS,
            'default_standard_fields' => self::DEFAULT_STANDARD_FIELDS,
            'available_customfields' => array_values($availablecustomfields),
        ];

        return [
            'status' => 'executed',
            'detail' => $detailmessage,
            'usermessage' => $detailmessage,
            'observation_full' => $this->build_observation_full($detailmessage, $details, $detailcapabilities),
            'resultid' => $firstid > 0 ? $firstid : null,
            'previewoptionids' => array_values(array_map(
                static fn(array $d): int => (int)($d['optionid'] ?? 0),
                $details
            )),
            'optiondetails' => $details,
            'detail_capabilities' => $detailcapabilities,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved ids: ' . implode(', ', $resolvedids),
                    'Details returned: ' . $count,
                    'Requested fields: ' . implode(', ', $requestedfields),
                    'Custom fields included: ' . ($includecustomfields ? 'yes' : 'no'),
                ]
            ),
        ];
    }

    /**
     * Build verbose observation payload for follow-up reasoning steps.
     *
     * @param string $detailmessage
     * @param array $details
     * @param array $detailcapabilities
     * @return string
     */
    private function build_observation_full(string $detailmessage, array $details, array $detailcapabilities): string {
        $payload = [
            'optiondetails' => $details,
            'detail_capabilities' => $detailcapabilities,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return $detailmessage;
        }

        return $detailmessage . "\n\nDetailed option payload (JSON):\n" . $json;
    }

    /**
     * Normalize requested standard fields.
     *
     * @param array $fields
     * @return array<int,string>
     */
    private function normalize_requested_fields(array $fields): array {
        $normalized = [];
        foreach ($fields as $field) {
            $key = strtolower(trim((string)$field));
            if ($key === '') {
                continue;
            }
            if ($key === 'all_standard') {
                return self::SUPPORTED_STANDARD_FIELDS;
            }
            if (in_array($key, self::SUPPORTED_STANDARD_FIELDS, true)) {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize custom field key filters.
     *
     * @param array $keys
     * @return array<int,string>
     */
    private function normalize_customfield_keys(array $keys): array {
        $normalized = [];
        foreach ($keys as $key) {
            $shortname = trim((string)$key);
            if ($shortname !== '') {
                $normalized[] = $shortname;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Select requested standard fields from booking option information.
     *
     * @param array $info
     * @param array $requestedfields
     * @param bool $includesessions
     * @return array<string,mixed>
     */
    private function select_standard_fields(array $info, array $requestedfields, bool $includesessions): array {
        $selected = [];
        foreach ($requestedfields as $field) {
            switch ($field) {
                case 'title':
                    $selected['title'] = (string)($info['title'] ?? '');
                    break;
                case 'description':
                    $selected['description'] = (string)($info['description'] ?? '');
                    break;
                case 'price':
                    $selected['price'] = $info['price'] ?? null;
                    break;
                case 'currency':
                    $selected['currency'] = (string)($info['currency'] ?? '');
                    break;
                case 'teachers':
                    $selected['teachers'] = (array)($info['teachers'] ?? []);
                    break;
                case 'sessions':
                    $selected['sessions'] = $includesessions ? (array)($info['sessions'] ?? []) : [];
                    break;
                case 'imageurl':
                    $selected['imageurl'] = (string)($info['imageurl'] ?? '');
                    break;
                case 'canceluntil':
                    // Render LLM-readable, timezone-adjusted (sessions are already formatted upstream).
                    $selected['canceluntil'] = observation_time::format((int)($info['canceluntil'] ?? 0));
                    break;
                case 'coursestarttime':
                    $selected['coursestarttime'] = observation_time::format((int)($info['coursestarttime'] ?? 0));
                    break;
                case 'courseendtime':
                    $selected['courseendtime'] = observation_time::format((int)($info['courseendtime'] ?? 0));
                    break;
                case 'costcenter':
                    $selected['costcenter'] = (string)($info['costcenter'] ?? '');
                    break;
            }
        }

        return $selected;
    }

    /**
     * Select custom field values from singleton-loaded option settings.
     *
     * @param object $settings
     * @param bool $includecustomfields
     * @param array $customfieldkeys
     * @return array<string,mixed>
     */
    private function select_custom_fields(object $settings, bool $includecustomfields, array $customfieldkeys): array {
        if (!$includecustomfields) {
            return [];
        }

        // Use customfieldsfortemplates for processed/readable values (resolves select option labels etc.).
        $templates = (array)($settings->customfieldsfortemplates ?? []);

        // Build case-insensitive lookup map: lowercase_key => [key, value].
        $lookup = [];
        foreach ($templates as $shortname => $field) {
            if (!is_array($field)) {
                continue;
            }
            $val = $field['value'] ?? '';
            $lookup[strtolower((string)$shortname)] = [
                'key' => (string)$shortname,
                'value' => $val,
            ];
        }

        if (empty($customfieldkeys)) {
            // Return all processed values.
            $selected = [];
            foreach ($lookup as $entry) {
                $selected[$entry['key']] = $entry['value'];
            }
            return $selected;
        }

        $selected = [];
        foreach ($customfieldkeys as $key) {
            $lkey = strtolower(trim((string)$key));
            if (isset($lookup[$lkey])) {
                $entry = $lookup[$lkey];
                $selected[$entry['key']] = $entry['value'];
            }
        }

        return $selected;
    }

    /**
     * Build compact capability metadata for follow-up detail queries.
     *
     * @param object $settings
     * @return array<string,mixed>
     */
    private function build_option_capability_snapshot(object $settings): array {
        $availablecustomfields = [];
        foreach ((array)($settings->customfieldsfortemplates ?? []) as $shortname => $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['key'] ?? $shortname));
            if ($key === '') {
                continue;
            }
            $availablecustomfields[] = [
                'key' => $key,
                'label' => trim((string)($field['label'] ?? $key)),
                'type' => trim((string)($field['type'] ?? 'mixed')),
            ];
        }

        return [
            'supported_standard_fields' => self::SUPPORTED_STANDARD_FIELDS,
            'available_customfields' => $availablecustomfields,
        ];
    }

    /**
     * Resolve target option ids from input.
     *
     * Supports two execution contexts:
     *   - Module context (cmid > 0): lookups are scoped to the booking instance
     *     identified by cmid. This is the current default behaviour.
     *   - System context (cmid = 0): direct optionid/optionids inputs work as-is
     *     (singleton_service::get_instance_of_booking_option_settings() is global).
     *     For optionquery a cross-instance title lookup is performed as a fallback.
     *
     * @param array $input
     * @param int   $cmid     Resolved cmid (0 when contextid is system/1 or unknown).
     * @param int   $userid
     * @param int   $maxitems
     * @return array<int,int>
     */
    private function resolve_target_option_ids(array $input, int $cmid, int $userid, int $maxitems): array {
        $ids = [];

        // Direct id inputs work in all contexts because option settings are loaded globally.
        $optionid = (int)($input['optionid'] ?? 0);
        if ($optionid > 0) {
            $ids[] = $optionid;
        }

        $optionids = is_array($input['optionids'] ?? null) ? (array)$input['optionids'] : [];
        foreach ($optionids as $id) {
            $intid = (int)$id;
            if ($intid > 0) {
                $ids[] = $intid;
            }
        }

        $query = trim((string)($input['optionquery'] ?? ''));
        if ($query !== '') {
            if ($cmid > 0) {
                // Module context: scope the lookup to this booking instance.
                if (booking_skill_support::is_last_option_reference($query)) {
                    $previewids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
                    foreach ($previewids as $id) {
                        $intid = (int)$id;
                        if ($intid > 0) {
                            $ids[] = $intid;
                        }
                    }
                } else {
                    $resolved = booking_skill_support::resolve_single_option($cmid, $query, '');
                    if (($resolved['status'] ?? '') === 'ok') {
                        $rid = (int)($resolved['optionid'] ?? 0);
                        if ($rid > 0) {
                            $ids[] = $rid;
                        }
                    }
                }
            } else {
                // System context (cmid=0): perform a cross-instance title lookup.
                // Only exact numeric ids are accepted without a cmid scope check;
                // title searches query all booking_options globally.
                $systemids = $this->resolve_option_ids_for_system_context($query, $maxitems);
                foreach ($systemids as $id) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        // Capability gate (audit CAP-02): only surface options whose hosting booking activity is
        // visible to the actor. Closes the cross-instance / site-wide leak where a direct optionid or a
        // system-context title/numeric search could otherwise return options in an instance the actor
        // cannot access (teachers, sessions, price, custom fields).
        $ids = array_values(array_filter(
            $ids,
            fn(int $id): bool => $this->actor_can_view_option($id, $userid)
        ));

        return array_slice($ids, 0, $maxitems);
    }

    /**
     * Resolve option ids from a text query when no module context is available.
     *
     * Used exclusively for system-context (cmid=0) requests where the agent
     * operates across all booking instances. Performs a case-insensitive title
     * match directly against the booking_options table.
     *
     * This method intentionally avoids the cmid-scoped bookingoptions_wbtable path
     * so that future system-wide agent support can be added without changing the
     * existing module-context resolution logic.
     *
     * @param string $query   Option title or numeric id string.
     * @param int    $limit   Maximum number of ids to return.
     * @return array<int,int>
     */
    private function resolve_option_ids_for_system_context(string $query, int $limit): array {
        global $DB;

        $ids = [];

        // Accept a plain numeric string as a direct option id without scope restriction.
        if (preg_match('/^\d+$/', $query)) {
            $id = (int)$query;
            if ($id > 0 && $DB->record_exists('booking_options', ['id' => $id])) {
                $ids[] = $id;
            }
            return $ids;
        }

        // Case-insensitive title search across all booking_options. The row cap goes through
        // get_records_sql()'s $limitnum argument, not a SQL "LIMIT" clause, so it stays portable
        // (raw LIMIT/OFFSET is not cross-database; Moodle's limit params abstract the dialect).
        $sql = 'SELECT id FROM {booking_options} WHERE ' . $DB->sql_like('text', ':query', false);
        $records = $DB->get_records_sql($sql, [
            'query' => '%' . $DB->sql_like_escape($query) . '%',
        ], 0, max(1, $limit));

        foreach ($records as $record) {
            $intid = (int)($record->id ?? 0);
            if ($intid > 0) {
                $ids[] = $intid;
            }
        }

        return $ids;
    }

    /**
     * Whether the acting user may view the booking option's hosting activity.
     *
     * Resolves the option to its course module and checks the activity is visible to the actor
     * (enrolment / visibility / availability via uservisible). Fail-closed on any resolution error.
     * Closes audit CAP-02: cross-instance / site-wide option-detail disclosure.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    private function actor_can_view_option(int $optionid, int $userid): bool {
        if ($optionid <= 0) {
            return false;
        }

        try {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $cmid = (int)($settings->cmid ?? 0);
            if (!$settings || $cmid <= 0) {
                return false;
            }

            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return false;
            }

            $cminfo = get_fast_modinfo((int)$cm->course, $userid)->get_cm($cmid);
            return (bool)$cminfo->uservisible;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
