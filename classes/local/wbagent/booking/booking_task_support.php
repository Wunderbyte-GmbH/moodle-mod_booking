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
 * Booking task support service.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent\booking;

use core_component;
use context_module;
use context_system;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\booking\tasks\add_price_category_task;
use mod_booking\local\wbagent\booking\tasks\base_booking_task;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\list_actions_task;
use mod_booking\local\wbagent\booking\tasks\list_option_properties_task;
use mod_booking\local\wbagent\booking\tasks\option_schema_definition;
use mod_booking\local\wbagent\booking\tasks\search_courses_task;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\booking\tasks\search_users_task;
use mod_booking\local\wbagent\booking\tasks\bulk_update_options_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\external\search_courses;
use mod_booking\external\search_users;
use mod_booking\option\fields_info;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;

/**
 * Domain support for booking-related AI tasks.
 */
class booking_task_support {
    /** @var array<string, task_interface>|null */
    private ?array $taskinstancescache = null;

    /**
     * Return the task names this provider handles.
     *
     * @return string[]
     */
    public function get_task_names(): array {
        $names = array_keys($this->get_task_instances());

        sort($names);
        return $names;
    }

    /**
     * Return context-specific prompt packs for this domain provider.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_task_instances() as $task) {
            if (!$task instanceof task_interface || !method_exists($task, 'get_contextual_prompt_packs')) {
                continue;
            }

            $taskpacks = (array)$task->get_contextual_prompt_packs();
            foreach ($taskpacks as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Return the JSON schema for the given task name.
     *
     * @param string $taskname
     * @return array
     */
    public function get_task_schema(string $taskname): array {
        if (!$this->has_task_name($taskname)) {
            return [];
        }

        $task = $this->get_task_instances()[$taskname] ?? null;
        if ($task && $this->task_has_own_schema($task)) {
            return (array)$task->get_schema();
        }

        $common = option_schema_definition::common_properties();

        if ($taskname === create_option_task::TASK_NAME) {
            return [
                'version'     => 1,
                'description' => 'Create a new booking option inside the current booking instance.',
                'properties'  => array_merge([
                    'text' => ['type' => 'string', 'description' => 'Title of the new booking option.', 'required' => true],
                ], $common),
            ];
        }

        if ($taskname === bulk_update_options_task::TASK_NAME) {
            return [
                'version'     => 1,
                'description' => 'Update multiple booking options at once. All provided fields are applied to every '
                    . 'matched option. Requires optionids, optionquery, or apply_to_all=true to select targets.',
                'properties'  => array_merge([
                    'optionids' => [
                        'type'        => 'array',
                        'description' => 'Array of specific option IDs to update.',
                        'required'    => false,
                    ],
                    'optionquery' => [
                        'type'        => 'string',
                        'description' => 'Search query to select multiple options to update '
                            . '(e.g. "yoga" selects all yoga options).',
                        'required'    => false,
                    ],
                    'apply_to_all' => [
                        'type'        => 'boolean',
                        'description' => 'Set to true to update ALL options in this booking instance. '
                            . 'Must be set when neither optionids nor optionquery is provided.',
                        'required'    => false,
                    ],
                ], $common),
            ];
        }

        if ($taskname === search_options_task::TASK_NAME) {
            return [
                'version' => 1,
                'description' => 'Search booking options via the existing booking table fulltext/filter pipeline.',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional search text (title/description/location), e.g. "next monday". '
                            . 'If omitted, returns a short list of options in this booking instance.',
                        'required' => false,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of candidates to return (default 10).',
                        'required' => false,
                    ],
                    'when' => [
                        'type' => 'string',
                        'description' => 'Optional temporal hint (e.g. "next monday").',
                        'required' => false,
                    ],
                ],
            ];
        }

        if ($taskname === search_users_task::TASK_NAME) {
            return [
                'version' => 1,
                'description' => 'Search users via mod_booking external search_users functionality.',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search text for first name, last name, email or user id.',
                        'required' => true,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of users to return (default 10).',
                        'required' => false,
                    ],
                ],
            ];
        }

        if ($taskname === search_courses_task::TASK_NAME) {
            return [
                'version' => 1,
                'description' => 'Search courses via mod_booking external search_courses functionality.',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search text for course full name, short name or id.',
                        'required' => true,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of courses to return (default 10).',
                        'required' => false,
                    ],
                ],
            ];
        }

        if ($taskname === list_option_properties_task::TASK_NAME) {
            return [
                'version' => 1,
                'description' => 'List booking option properties derived from create/update task schemas.',
                'properties' => [
                    'scope' => [
                        'type' => 'string',
                        'description' => 'Filter scope: all (default), create, update, or shared.',
                        'required' => false,
                    ],
                ],
            ];
        }

        if ($taskname === list_actions_task::TASK_NAME) {
            return [
                'version' => 1,
                'description' => 'List supported booking AI actions/tasks derived from registered task schemas.',
                'properties' => [
                    'scope' => [
                        'type' => 'string',
                        'description' => 'Filter scope: all (default), readonly, or mutating.',
                        'required' => false,
                    ],
                ],
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
        $ismutationtask = $this->is_mutating_task_name($taskname);

        if ($taskname === create_option_task::TASK_NAME) {
            if (empty($input['text'])) {
                $errors[] = 'Field "text" (option title) is required for create_option.';
            } else {
                $duplicatecheck = self::find_existing_options_by_exact_title($cmid, (string)$input['text']);
                if (($duplicatecheck['status'] ?? '') === 'single') {
                    $ambiguities[] = get_string(
                        'agent_booking_create_option_exists_single',
                        'booking',
                        (int)$duplicatecheck['optionid']
                    );
                } else if (($duplicatecheck['status'] ?? '') === 'multiple') {
                    $ambiguities[] = get_string(
                        'agent_booking_create_option_exists_multiple',
                        'booking',
                        (string)($duplicatecheck['candidates'] ?? '')
                    );
                }
            }
        } else if ($taskname === update_option_task::TASK_NAME) {
            if (empty($input['optionid'])) {
                if (empty($input['optionquery'])) {
                    $ambiguities[] = 'Which booking option should be updated? Provide optionid or optionquery.';
                } else if (!self::is_last_option_reference((string)$input['optionquery'])) {
                    $result = self::resolve_single_option(
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
                // Verify the option belongs to this booking instance.
                global $DB;
                $cm = get_coursemodule_from_id('booking', $cmid);
                if (
                    !$cm ||
                    !$DB->record_exists('booking_options', [
                        'id' => (int)$input['optionid'],
                        'bookingid' => $cm->instance,
                    ])
                ) {
                    $errors[] = 'Booking option with id ' . (int)$input['optionid'] .
                                ' does not exist in this booking instance.';
                }
            }
        } else if ($taskname === bulk_update_options_task::TASK_NAME) {
            $hasids = !empty($input['optionids']) && is_array($input['optionids'])
                && count($input['optionids']) > 0;
            $hasquery = !empty($input['optionquery']) && is_string($input['optionquery'])
                && trim((string)$input['optionquery']) !== '';
            $applytoall = !empty($input['apply_to_all']);

            if (!$hasids && !$hasquery && !$applytoall) {
                $errors[] = 'Provide optionids (array), optionquery (string), or set apply_to_all=true '
                    . 'to specify which options should be updated.';
            }

            if ($hasids) {
                global $DB;
                $cm = get_coursemodule_from_id('booking', $cmid);
                if ($cm) {
                    foreach ($input['optionids'] as $optid) {
                        if (
                            !$DB->record_exists('booking_options', [
                                'id' => (int)$optid,
                                'bookingid' => (int)$cm->instance,
                            ])
                        ) {
                            $errors[] = 'Option id ' . (int)$optid
                                . ' does not exist in this booking instance.';
                        }
                    }
                }
            }

            if (!empty($input['bookusersquery'])) {
                $errors[] = 'Field "bookusersquery" is not supported for booking.bulk_update_options. '
                    . 'Use booking.update_option for per-option user booking.';
            }
        } else if ($taskname === search_options_task::TASK_NAME) {
            if (isset($input['query']) && !is_string($input['query'])) {
                $errors[] = 'Field "query" must be a string when provided for search_options.';
            }
        } else if ($taskname === search_users_task::TASK_NAME) {
            if (empty($input['query']) || !is_string($input['query'])) {
                $errors[] = 'Field "query" is required for search_users.';
            }
        } else if ($taskname === search_courses_task::TASK_NAME) {
            if (empty($input['query']) || !is_string($input['query'])) {
                $errors[] = 'Field "query" is required for search_courses.';
            }
        } else if ($taskname === list_option_properties_task::TASK_NAME) {
            $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
            $allowed = ['all', 'create', 'update', 'shared'];
            if (!in_array($scope, $allowed, true)) {
                $errors[] = 'Field "scope" must be one of: all, create, update, shared.';
            }
        } else if ($taskname === list_actions_task::TASK_NAME) {
            $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
            $allowed = ['all', 'readonly', 'mutating'];
            if (!in_array($scope, $allowed, true)) {
                $errors[] = 'Field "scope" must be one of: all, readonly, mutating.';
            }
        } else {
            $errors[] = 'Unknown task: ' . $taskname;
        }

        if ($ismutationtask) {
            try {
                $context = context_module::instance($cmid);
                $permissioncheck = self::validate_update_field_permissions($input, (int)$context->id);
                if (($permissioncheck['status'] ?? '') !== 'ok') {
                    $errors[] = (string)($permissioncheck['message']
                        ?? get_string('agent_booking_update_permission_denied_generic', 'booking'));
                }
            } catch (\Throwable $e) {
                $errors[] = get_string('agent_booking_update_permission_check_failed', 'booking');
            }

            $pricevalidation = self::validate_prices_input($input);
            $errors = array_merge($errors, $pricevalidation['errors']);
            $ambiguities = array_merge($ambiguities, $pricevalidation['ambiguities']);
        }

        if (
            $ismutationtask
            && empty($input['teacheremail'])
            && !empty($input['teacherquery'])
        ) {
            $userresult = self::resolve_single_user((string)$input['teacherquery']);
            if ($userresult['status'] === 'error') {
                $errors[] = (string)$userresult['message'];
            } else if ($userresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$userresult['message'];
            }
        }

        if (
            $ismutationtask
            && !empty($input['coursequery'])
        ) {
            $courseresult = self::resolve_single_course((string)$input['coursequery']);
            if ($courseresult['status'] === 'error') {
                $errors[] = (string)$courseresult['message'];
            } else if ($courseresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$courseresult['message'];
            }
        }

        if ($ismutationtask && (array_key_exists('invisible', $input) || array_key_exists('visibility', $input))) {
            $normalizedvisibility = self::normalize_visibility_input($input);
            if (!empty($normalizedvisibility['error'])) {
                $errors[] = (string)$normalizedvisibility['error'];
            }
        }

        if ($ismutationtask && array_key_exists('optiondatesmode', $input)) {
            $mode = strtolower(trim((string)$input['optiondatesmode']));
            if (!in_array($mode, ['append', 'replace'], true)) {
                $errors[] = 'Field "optiondatesmode" must be either "append" or "replace".';
            }
        }

        if (
            $ismutationtask
            && !empty($input['enrolledincoursequery'])
        ) {
            $restrictioncourses = self::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
            if (!empty($restrictioncourses['errors'])) {
                foreach ($restrictioncourses['errors'] as $error) {
                    $errors[] = $error;
                }
            }
            if (!empty($restrictioncourses['ambiguities'])) {
                foreach ($restrictioncourses['ambiguities'] as $ambiguity) {
                    $ambiguities[] = $ambiguity;
                }
            }

            $operator = strtoupper(trim((string)($input['enrolledincourseoperator'] ?? 'OR')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "enrolledincourseoperator" must be either "OR" or "AND".';
            }
        }

        if (
            $ismutationtask
            && array_key_exists('enrolledincourseenabled', $input)
            && empty($input['enrolledincourseenabled'])
            && !empty($input['enrolledincoursequery'])
        ) {
            $errors[] = 'Cannot provide enrolledincoursequery when enrolledincourseenabled is false.';
        }

        $parsedoptiondates = self::extract_optiondates($input);

        if (isset($input['coursestarttime']) && !isset($input['optiondates'])) {
            if (!self::parse_datetime($input['coursestarttime'])) {
                $errors[] = 'Field "coursestarttime" must be a valid ISO 8601 date-time string or Unix timestamp.';
            }
        }
        if (isset($input['courseendtime']) && !isset($input['optiondates'])) {
            if (!self::parse_datetime($input['courseendtime'])) {
                $errors[] = 'Field "courseendtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
            }
        }
        if (isset($input['bookingopeningtime']) && !self::parse_datetime($input['bookingopeningtime'])) {
            $errors[] = 'Field "bookingopeningtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }
        if (isset($input['bookingclosingtime']) && !self::parse_datetime($input['bookingclosingtime'])) {
            $errors[] = 'Field "bookingclosingtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }

        if (isset($input['optiondates']) && empty($parsedoptiondates)) {
            $errors[] = 'Field "optiondates" must contain at least one valid date range.';
        }

        if ($taskname === create_option_task::TASK_NAME) {
            foreach ($parsedoptiondates as $idx => $date) {
                $startts = $date['coursestarttime'];
                $endts = $date['courseendtime'];
                $label = count($parsedoptiondates) > 1 ? 'Date range #' . ($idx + 1) . ': ' : '';

                if ($startts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . 'The provided start time appears to be in the past. '
                        . 'Please confirm the intended date/time.';
                }
                if ($endts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . 'The provided end time appears to be in the past. '
                        . 'Please confirm the intended date/time.';
                }
                if ($endts <= $startts) {
                    $errors[] = $label . '"courseendtime" must be later than "coursestarttime".';
                }
            }
        }

        if (
            $ismutationtask
            && !empty($input['enrolledincohortquery'])
        ) {
            $cohortresult = self::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
            foreach ($cohortresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($cohortresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['enrolledincohortoperator'] ?? 'OR')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "enrolledincohortoperator" must be either "OR" or "AND".';
            }
        }

        if (
            $ismutationtask
            && array_key_exists('enrolledincohortenabled', $input)
            && empty($input['enrolledincohortenabled'])
            && !empty($input['enrolledincohortquery'])
        ) {
            $errors[] = 'Cannot provide enrolledincohortquery when enrolledincohortenabled is false.';
        }

        if (
            $ismutationtask
            && !empty($input['hascompetencyquery'])
        ) {
            $competencyresult = self::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
            foreach ($competencyresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($competencyresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['hascompetencyoperator'] ?? 'AND')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "hascompetencyoperator" must be either "OR" or "AND".';
            }
        }

        if (
            $ismutationtask
            && array_key_exists('hascompetencyenabled', $input)
            && empty($input['hascompetencyenabled'])
            && !empty($input['hascompetencyquery'])
        ) {
            $errors[] = 'Cannot provide hascompetencyquery when hascompetencyenabled is false.';
        }

        if (
            $ismutationtask
            && !empty($input['previouslybookedquery'])
        ) {
            $prev = self::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
            if ($prev['status'] === 'error') {
                $errors[] = (string)$prev['message'];
            } else if ($prev['status'] === 'ambiguity') {
                $ambiguities[] = (string)$prev['message'];
            }
        }

        if (
            $ismutationtask
            && array_key_exists('previouslybookedenabled', $input)
            && empty($input['previouslybookedenabled'])
            && !empty($input['previouslybookedquery'])
        ) {
            $errors[] = 'Cannot provide previouslybookedquery when previouslybookedenabled is false.';
        }

        if (
            $ismutationtask
            && !empty($input['selectusersquery'])
        ) {
            $users = self::resolve_users_for_restriction((string)$input['selectusersquery']);
            foreach ($users['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($users['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
        }

        if (
            $ismutationtask
            && array_key_exists('selectusersenabled', $input)
            && empty($input['selectusersenabled'])
            && !empty($input['selectusersquery'])
        ) {
            $errors[] = 'Cannot provide selectusersquery when selectusersenabled is false.';
        }

        if (
            ($taskname === create_option_task::TASK_NAME || $taskname === update_option_task::TASK_NAME)
            && !empty($input['bookusersquery'])
        ) {
            $users = self::resolve_users_for_booking((string)$input['bookusersquery']);
            foreach ($users['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($users['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
        }

        if ($taskname === update_option_task::TASK_NAME && !empty($input['bookusersquery'])) {
            $forbidden = self::detect_forbidden_fields_for_bookusers_update($input);
            if (!empty($forbidden)) {
                $errors[] = 'When using "bookusersquery" in booking.update_option, '
                    . 'no option updates are allowed in the same command. '
                    . 'Remove these fields: ' . implode(', ', $forbidden) . '.';
            }
        }

        if (isset($input['bookuserstimebooked']) && !self::parse_datetime($input['bookuserstimebooked'])) {
            $errors[] = 'Field "bookuserstimebooked" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }

        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            if (!in_array($mode, ['block', 'warn'], true)) {
                $errors[] = 'Field "nooverlappingmode" must be "block" or "warn".';
            }
        }

        if (!empty($input['userprofilestandardfield']) || !empty($input['userprofilestandardoperator'])) {
            if (empty($input['userprofilestandardfield']) || empty($input['userprofilestandardoperator'])) {
                $errors[] = 'For standard profile condition, provide userprofilestandardfield and userprofilestandardoperator.';
            }
        }

        if (!empty($input['userprofilecustomfield']) || !empty($input['userprofilecustomoperator'])) {
            if (empty($input['userprofilecustomfield']) || empty($input['userprofilecustomoperator'])) {
                $errors[] = 'For custom profile condition, provide userprofilecustomfield and userprofilecustomoperator.';
            }
        }

        foreach (
            [
                'enrolledincourseoverrideoperator',
                'enrolledincohortoverrideoperator',
                'hascompetencyoverrideoperator',
                'selectusersoverrideoperator',
                'userprofilestandardoverrideoperator',
                'userprofilecustomoverrideoperator',
            ] as $overrideoperatorfield
        ) {
            if (isset($input[$overrideoperatorfield])) {
                $op = strtoupper(trim((string)$input[$overrideoperatorfield]));
                if (!in_array($op, ['OR', 'AND'], true)) {
                    $errors[] = 'Field "' . $overrideoperatorfield . '" must be "OR" or "AND".';
                }
            }
        }

        if (isset($input['userprofilecustomfield2']) && empty($input['userprofilecustomoperator2'])) {
            $errors[] = 'Field "userprofilecustomoperator2" is required when "userprofilecustomfield2" is provided.';
        }

        if (isset($input['duration']) && (int)$input['duration'] <= 0) {
            $errors[] = 'Field "duration" must be a positive integer (seconds).';
        }

        if (
            $ismutationtask
            && array_key_exists('customformenabled', $input)
            && empty($input['customformenabled'])
            && (!empty($input['customformjson']) || !empty($input['customformelements']))
        ) {
            $errors[] = 'Cannot provide custom form content when customformenabled is false.';
        }

        if (array_key_exists('customformelements', $input)) {
            if (!is_array($input['customformelements'])) {
                $errors[] = 'Field "customformelements" must be an array.';
            } else {
                $validation = self::validate_customform_elements($input['customformelements']);
                foreach ($validation['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }

        return [
            'valid'       => empty($errors) && empty($ambiguities),
            'errors'      => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Return instantiated booking task classes keyed by task name.
     *
     * @return array<string, task_interface>
     */
    private function get_task_instances(): array {
        if ($this->taskinstancescache !== null) {
            return $this->taskinstancescache;
        }

        $instances = [];
        $taskclasses = core_component::get_component_classes_in_namespace('mod_booking', 'local\\wbagent\\booking\\tasks');
        foreach (array_keys($taskclasses) as $classname) {
            try {
                $reflection = new \ReflectionClass($classname);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $task = $reflection->newInstance();
                if (!$task instanceof task_interface) {
                    continue;
                }

                $instances[$task->get_name()] = $task;
            } catch (\Throwable $e) {
                continue;
            }
        }

        $this->taskinstancescache = $instances;
        return $instances;
    }

    /**
     * True when the given task name is registered by a task class.
     *
     * @param string $taskname
     * @return bool
     */
    private function has_task_name(string $taskname): bool {
        return array_key_exists($taskname, $this->get_task_instances());
    }

    /**
     * True when task is mutating according to task class read-only flag.
     *
     * @param string $taskname
     * @return bool
     */
    private function is_mutating_task_name(string $taskname): bool {
        $task = $this->get_task_instances()[$taskname] ?? null;
        if (!$task) {
            return false;
        }
        return !$task->is_read_only();
    }

    /**
     * True when a task overrides get_schema() in its own class.
     *
     * @param task_interface $task
     * @return bool
     */
    private function task_has_own_schema(task_interface $task): bool {
        try {
            $method = new \ReflectionMethod($task, 'get_schema');
            return $method->getDeclaringClass()->getName() !== base_booking_task::class;
        } catch (\Throwable $e) {
            return false;
        }
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

        if ($taskname === search_options_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $when = trim((string)($input['when'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : ($query === '' ? 50 : 10);

            $rows = self::search_option_candidates($cmid, $query, $limit, $when);
            if (empty($rows)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching booking options found.',
                    'resultid' => null,
                ];
            }

            $items = [];
            $structuredoptions = [];
            foreach ($rows as $row) {
                $optionid = (int)($row['optionid'] ?? 0);
                $name = (string)($row['text'] ?? '');
                $link = self::build_option_link($cmid, $optionid);
                $items[] = self::format_option_label($cmid, $optionid, $name);
                $structuredoptions[] = [
                    'id' => $optionid,
                    'name' => $name,
                    'link' => $link,
                ];
            }

            return [
                'status' => 'executed',
                'detail' => 'Found ' . count($structuredoptions) . ' option(s).',
                'resultid' => (int)($rows[0]['optionid'] ?? 0),
                'previewoptionids' => array_values(array_map(
                    static fn(array $row): int => (int)($row['optionid'] ?? 0),
                    $rows
                )),
                'options' => $structuredoptions,
            ];
        }

        if ($taskname === search_users_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

            if ($query === '') {
                return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
            }

            $users = self::search_user_candidates($query, $limit);
            if (empty($users)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching users found.',
                    'resultid' => null,
                ];
            }

            $ids = array_map(fn($row) => (int)($row['userid'] ?? 0), $users);
            return [
                'status' => 'executed',
                'detail' => 'Found users: ' . implode(', ', $ids) . '. ' . json_encode($users),
                'resultid' => (int)($users[0]['userid'] ?? 0),
            ];
        }

        if ($taskname === search_courses_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

            if ($query === '') {
                return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
            }

            $courses = self::search_course_candidates($query, $limit);
            if (empty($courses)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching courses found.',
                    'resultid' => null,
                ];
            }

            $ids = array_map(fn($row) => (int)($row['courseid'] ?? 0), $courses);
            return [
                'status' => 'executed',
                'detail' => 'Found courses: ' . implode(', ', $ids) . '. ' . json_encode($courses),
                'resultid' => (int)($courses[0]['courseid'] ?? 0),
            ];
        }

        if ($taskname === list_option_properties_task::TASK_NAME) {
            $createschema = $this->get_task_schema(create_option_task::TASK_NAME);
            $updateschema = $this->get_task_schema(update_option_task::TASK_NAME);
            $createproperties = (array)($createschema['properties'] ?? []);
            $updateproperties = (array)($updateschema['properties'] ?? []);

            $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
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
                    'label' => self::get_localized_property_label((string)$key),
                    'type' => (string)($source['type'] ?? 'mixed'),
                    'description' => (string)($source['description'] ?? ''),
                    'increate' => $increate,
                    'inupdate' => $inupdate,
                    'requiredoncreate' => (bool)($createproperties[$key]['required'] ?? false),
                    'requiredonupdate' => (bool)($updateproperties[$key]['required'] ?? false),
                ];
            }

            return [
                'status' => 'executed',
                'detail' => '',
                'resultid' => null,
                'properties' => $properties,
            ];
        }

        if ($taskname === list_actions_task::TASK_NAME) {
            $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
            $actions = [];
            $registry = task_registry::make_default();
            foreach ($registry->get_task_names() as $name) {
                if ($scope === 'readonly' && !$registry->is_read_only_task($name)) {
                    continue;
                }
                if ($scope === 'mutating' && $registry->is_read_only_task($name)) {
                    continue;
                }

                $task = $registry->get_task($name);
                if (!$task) {
                    continue;
                }

                $schema = $task->get_schema();
                $actions[] = [
                    'task' => $name,
                    'label' => self::get_localized_action_label($name),
                    'description' => (string)($schema['description'] ?? ''),
                    'readonly' => $task->is_read_only(),
                ];
            }

            return [
                'status' => 'executed',
                'detail' => '',
                'resultid' => null,
                'actions' => $actions,
            ];
        }

        $context = context_module::instance($cmid);

        // Build the data object for booking_option::update().
        $data = new \stdClass();
        $data->bookingid = (int)$cm->instance;
        $data->cmid = $cmid;
        $data->importing = true; // Triggers array-mode processing in ::update().
        $bookusersforoption = [];
        $bookusersmeta = [
            'completed' => false,
            'updateexisting' => false,
            'timebooked' => null,
        ];

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

        if (array_key_exists('selflearningcourse', $input)) {
            $data->selflearningcourse = !empty($input['selflearningcourse']) ? 1 : 0;
        }
        if (isset($input['duration'])) {
            $data->duration = (int)$input['duration'];
        }
        if (array_key_exists('disablecancel', $input)) {
            $data->disablecancel = !empty($input['disablecancel']) ? 1 : 0;
        }

        $normalizedvisibility = self::normalize_visibility_input($input);
        if (isset($normalizedvisibility['value'])) {
            $data->invisible = (int)$normalizedvisibility['value'];
        }

        if (isset($input['bookingopeningtime'])) {
            $opening = self::parse_datetime($input['bookingopeningtime']);
            if ($opening !== false) {
                $data->bookingopeningtime = $opening;
            }
        }
        if (isset($input['bookingclosingtime'])) {
            $closing = self::parse_datetime($input['bookingclosingtime']);
            if ($closing !== false) {
                $data->bookingclosingtime = $closing;
            }
        }

        $parsedoptiondates = self::extract_optiondates($input);

        $normalizedprices = self::normalize_prices_input($input['prices'] ?? null);
        if (!empty($normalizedprices)) {
            $data->useprice = 1;
            foreach ($normalizedprices as $identifier => $value) {
                $data->{$identifier} = $value;
            }
        }

        // Resolve teacher input using the importer-compatible teacheremail field.
        if (!empty($input['teacheremail'])) {
            $data->teacheremail = trim((string)$input['teacheremail']);
        } else if (!empty($input['teacherquery'])) {
            $teacherresult = self::resolve_single_user((string)$input['teacherquery']);
            if ($teacherresult['status'] !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)$teacherresult['message'],
                    'resultid' => null,
                ];
            }
            if (empty($teacherresult['email'])) {
                return [
                    'status' => 'error',
                    'detail' => 'Resolved teacher has no e-mail address. Please provide teacheremail directly.',
                    'resultid' => null,
                ];
            }
            $data->teacheremail = (string)$teacherresult['email'];
        }

        // Resolve connected Moodle course by query and map to importer-compatible field.
        if (!empty($input['coursequery'])) {
            $courseresult = self::resolve_single_course((string)$input['coursequery']);
            if ($courseresult['status'] !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)$courseresult['message'],
                    'resultid' => null,
                ];
            }
            $data->courseid = (int)$courseresult['courseid'];
            $data->enroltocourseshortname = (string)$courseresult['shortname'];
            $data->chooseorcreatecourse = 1;
        }

        // Restrict booking to users enrolled in specific courses (availability condition).
        if (array_key_exists('enrolledincourseenabled', $input) && empty($input['enrolledincourseenabled'])) {
            $data->bo_cond_enrolledincourse_restrict = 0;
        }
        if (!empty($input['enrolledincoursequery'])) {
            $restrictioncourses = self::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
            if (!empty($restrictioncourses['errors'])) {
                return [
                    'status' => 'error',
                    'detail' => implode(' ', $restrictioncourses['errors']),
                    'resultid' => null,
                ];
            }
            if (!empty($restrictioncourses['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => implode(' ', $restrictioncourses['ambiguities']),
                    'resultid' => null,
                ];
            }

            if (empty($restrictioncourses['courseids'])) {
                return [
                    'status' => 'error',
                    'detail' => 'No valid course found for enrolledincoursequery.',
                    'resultid' => null,
                ];
            }

            $data->bo_cond_enrolledincourse_restrict = 1;
            $data->bo_cond_enrolledincourse_courseids = $restrictioncourses['courseids'];
            $operator = strtoupper(trim((string)($input['enrolledincourseoperator'] ?? 'OR')));
            $data->bo_cond_enrolledincourse_courseids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'OR';
        }
        if (array_key_exists('enrolledincoursesqlfilter', $input)) {
            $data->bo_cond_enrolledincourse_sqlfiltercheck = !empty($input['enrolledincoursesqlfilter']) ? 1 : 0;
        }
        if (!empty($input['enrolledincourseoverride'])) {
            $data->bo_cond_enrolledincourse_overrideconditioncheckbox = 1;
            if (isset($input['enrolledincourseoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['enrolledincourseoverrideoperator']));
                $data->bo_cond_enrolledincourse_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            $overrideids = $input['enrolledincourseoverrideconditionids'] ?? [];
            if (!empty($overrideids) && is_array($overrideids)) {
                $data->bo_cond_enrolledincourse_overridecondition = array_map('intval', $overrideids);
            }
        } else if (array_key_exists('enrolledincourseoverride', $input) && empty($input['enrolledincourseoverride'])) {
            $data->bo_cond_enrolledincourse_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('enrolledincohortenabled', $input) && empty($input['enrolledincohortenabled'])) {
            $data->bo_cond_enrolledincohorts_restrict = 0;
        }
        if (!empty($input['enrolledincohortquery'])) {
            $cohorts = self::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
            if (!empty($cohorts['errors']) || !empty($cohorts['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($cohorts['errors'], $cohorts['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_enrolledincohorts_restrict = 1;
            $data->bo_cond_enrolledincohorts_cohortids = $cohorts['cohortids'];
            $operator = strtoupper(trim((string)($input['enrolledincohortoperator'] ?? 'OR')));
            $data->bo_cond_enrolledincohorts_cohortids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'OR';
        }
        if (array_key_exists('enrolledincohort_sqlfilter', $input)) {
            $data->bo_cond_enrolledincohorts_sqlfiltercheck = !empty($input['enrolledincohort_sqlfilter']) ? 1 : 0;
        }
        if (!empty($input['enrolledincohortoverride'])) {
            $data->bo_cond_enrolledincohorts_overrideconditioncheckbox = 1;
            if (isset($input['enrolledincohortoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['enrolledincohortoverrideoperator']));
                $data->bo_cond_enrolledincohorts_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            $overrideids = $input['enrolledincohortoverrideconditionids'] ?? [];
            if (!empty($overrideids) && is_array($overrideids)) {
                $data->bo_cond_enrolledincohorts_overridecondition = array_map('intval', $overrideids);
            }
        } else if (array_key_exists('enrolledincohortoverride', $input) && empty($input['enrolledincohortoverride'])) {
            $data->bo_cond_enrolledincohorts_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('hascompetencyenabled', $input) && empty($input['hascompetencyenabled'])) {
            $data->bo_cond_hascompetency_restrict = 0;
        }
        if (!empty($input['hascompetencyquery'])) {
            $competencies = self::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
            if (!empty($competencies['errors']) || !empty($competencies['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($competencies['errors'], $competencies['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_hascompetency_restrict = 1;
            $data->bo_cond_hascompetency_competencyids = $competencies['competencyids'];
            $operator = strtoupper(trim((string)($input['hascompetencyoperator'] ?? 'AND')));
            $data->bo_cond_hascompetency_competencyids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'AND';
        }
        if (!empty($input['hascompetencyoverride'])) {
            $data->bo_cond_hascompetency_overrideconditioncheckbox = 1;
            if (isset($input['hascompetencyoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['hascompetencyoverrideoperator']));
                $data->bo_cond_hascompetency_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (!empty($input['hascompetencyoverrideconditionids']) && is_array($input['hascompetencyoverrideconditionids'])) {
                $data->bo_cond_hascompetency_overridecondition = array_map('intval', $input['hascompetencyoverrideconditionids']);
            }
        } else if (array_key_exists('hascompetencyoverride', $input) && empty($input['hascompetencyoverride'])) {
            $data->bo_cond_hascompetency_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('previouslybookedenabled', $input) && empty($input['previouslybookedenabled'])) {
            $data->bo_cond_previouslybooked_restrict = 0;
        }
        if (!empty($input['previouslybookedquery'])) {
            $prev = self::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
            if (($prev['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($prev['message'] ?? 'Could not resolve previouslybookedquery.'),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_previouslybooked_restrict = 1;
            $data->bo_cond_previouslybooked_optionid = (int)$prev['optionid'];
            if (!empty($input['previouslybookedrequirecompletion'])) {
                $data->bo_cond_previouslybooked_requirecompletion = 1;
            }
        }

        if (array_key_exists('selectusersenabled', $input) && empty($input['selectusersenabled'])) {
            $data->bo_cond_selectusers_restrict = 0;
        }
        if (!empty($input['selectusersquery'])) {
            $users = self::resolve_users_for_restriction((string)$input['selectusersquery']);
            if (!empty($users['errors']) || !empty($users['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($users['errors'], $users['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_selectusers_restrict = 1;
            $data->bo_cond_selectusers_userids = $users['userids'];
        }

        if (!empty($input['bookusersquery'])) {
            $usersforbooking = self::resolve_users_for_booking((string)$input['bookusersquery']);
            if (!empty($usersforbooking['errors']) || !empty($usersforbooking['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($usersforbooking['errors'], $usersforbooking['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $bookusersforoption = $usersforbooking['userids'];
            $bookusersmeta['updateexisting'] = !empty($input['bookusersupdateexisting']);
            $bookusersmeta['completed'] = !empty($input['bookuserscompleted']);
            if (isset($input['bookuserstimebooked'])) {
                $timebooked = self::parse_datetime($input['bookuserstimebooked']);
                if ($timebooked !== false) {
                    $bookusersmeta['timebooked'] = $timebooked;
                }
            }
        }
        if (!empty($input['selectusersoverride'])) {
            $data->bo_cond_selectusers_overrideconditioncheckbox = 1;
            if (isset($input['selectusersoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['selectusersoverrideoperator']));
                $data->bo_cond_selectusers_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (!empty($input['selectusersoverrideconditionids']) && is_array($input['selectusersoverrideconditionids'])) {
                $data->bo_cond_selectusers_overridecondition = array_map('intval', $input['selectusersoverrideconditionids']);
            }
        } else if (array_key_exists('selectusersoverride', $input) && empty($input['selectusersoverride'])) {
            $data->bo_cond_selectusers_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('nooverlappingenabled', $input) && empty($input['nooverlappingenabled'])) {
            $data->bo_cond_nooverlapping_restrict = 0;
        }
        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            $data->bo_cond_nooverlapping_restrict = 1;
            $data->bo_cond_nooverlapping_handling =
                ($mode === 'warn')
                    ? MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN
                    : MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        }

        if (array_key_exists('allowedtobookininstance', $input)) {
            $restrict = !empty($input['allowedtobookininstance']) ? 1 : 0;
            $data->bo_cond_allowedtobookininstance_restrict = $restrict;
            if ($restrict === 1) {
                $data->bo_cond_allowedtobookininstance_capabilitynotneeded =
                    array_key_exists('allowedtobookininstancecapabilitynotneeded', $input)
                        ? (!empty($input['allowedtobookininstancecapabilitynotneeded']) ? 1 : 0)
                        : 1;
            }
        }

        if (array_key_exists('userprofilestandardenabled', $input) && empty($input['userprofilestandardenabled'])) {
            $data->bo_cond_userprofilefield_1_default_restrict = 0;
        }
        if (!empty($input['userprofilestandardfield']) && !empty($input['userprofilestandardoperator'])) {
            $data->bo_cond_userprofilefield_1_default_restrict = 1;
            $data->bo_cond_userprofilefield_field = trim((string)$input['userprofilestandardfield']);
            $data->bo_cond_userprofilefield_operator = trim((string)$input['userprofilestandardoperator']);
            $data->bo_cond_userprofilefield_value = (string)($input['userprofilestandardvalue'] ?? '');
        }
        if (!empty($input['userprofilestandardoverride'])) {
            $data->bo_cond_userprofilefield_overrideconditioncheckbox = 1;
            if (isset($input['userprofilestandardoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['userprofilestandardoverrideoperator']));
                $data->bo_cond_userprofilefield_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (
                !empty($input['userprofilestandardoverrideconditionids'])
                && is_array($input['userprofilestandardoverrideconditionids'])
            ) {
                $data->bo_cond_userprofilefield_overridecondition =
                    array_map('intval', $input['userprofilestandardoverrideconditionids']);
            }
        } else if (array_key_exists('userprofilestandardoverride', $input) && empty($input['userprofilestandardoverride'])) {
            $data->bo_cond_userprofilefield_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('userprofilecustomenabled', $input) && empty($input['userprofilecustomenabled'])) {
            $data->bo_cond_userprofilefield_2_custom_restrict = 0;
        }
        if (!empty($input['userprofilecustomfield']) && !empty($input['userprofilecustomoperator'])) {
            $data->bo_cond_userprofilefield_2_custom_restrict = 1;
            $data->bo_cond_customuserprofilefield_field = trim((string)$input['userprofilecustomfield']);
            $data->bo_cond_customuserprofilefield_operator = trim((string)$input['userprofilecustomoperator']);
            $data->bo_cond_customuserprofilefield_value = (string)($input['userprofilecustomvalue'] ?? '');
        }
        if (array_key_exists('userprofilecustomconnectsecondfield', $input)) {
            $connectsecond = !empty($input['userprofilecustomconnectsecondfield']) ? 1 : 0;
            $data->bo_cond_customuserprofilefield_connectsecondfield = $connectsecond;
        }
        if (isset($input['userprofilecustomfield2'])) {
            $data->bo_cond_customuserprofilefield_field2 = trim((string)$input['userprofilecustomfield2']);
        }
        if (isset($input['userprofilecustomoperator2'])) {
            $data->bo_cond_customuserprofilefield_operator2 = trim((string)$input['userprofilecustomoperator2']);
        }
        if (isset($input['userprofilecustomvalue2'])) {
            $data->bo_cond_customuserprofilefield_value2 = (string)$input['userprofilecustomvalue2'];
        }
        if (array_key_exists('userprofilecustomsqlfilter', $input)) {
            $data->bo_cond_customuserprofilefield_sqlfiltercheck = !empty($input['userprofilecustomsqlfilter']) ? 1 : 0;
        }
        if (!empty($input['userprofilecustomoverride'])) {
            $data->bo_cond_customuserprofilefield_overrideconditioncheckbox = 1;
            if (isset($input['userprofilecustomoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['userprofilecustomoverrideoperator']));
                $data->bo_cond_customuserprofilefield_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (
                !empty($input['userprofilecustomoverrideconditionids'])
                && is_array($input['userprofilecustomoverrideconditionids'])
            ) {
                $data->bo_cond_customuserprofilefield_overridecondition =
                    array_map('intval', $input['userprofilecustomoverrideconditionids']);
            }
        } else if (array_key_exists('userprofilecustomoverride', $input) && empty($input['userprofilecustomoverride'])) {
            $data->bo_cond_customuserprofilefield_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('customformenabled', $input) && empty($input['customformenabled'])) {
            $data->bo_cond_customform_restrict = 0;
        }

        if (array_key_exists('customformelements', $input) && is_array($input['customformelements'])) {
            $elements = self::normalize_customform_elements($input['customformelements']);
            if (!empty($elements)) {
                $data->bo_cond_customform_restrict = 1;
                $index = 1;
                foreach ($elements as $element) {
                    $data->{'bo_cond_customform_select_1_' . $index} = (string)$element['formtype'];
                    $data->{'bo_cond_customform_label_1_' . $index} = (string)($element['label'] ?? '');
                    $data->{'bo_cond_customform_value_1_' . $index} = (string)($element['value'] ?? '');
                    $data->{'bo_cond_customform_notempty_1_' . $index} = !empty($element['required']) ? 1 : 0;
                    $data->{'bo_cond_customform_enroluserstowaitinglist' . $index} =
                        !empty($element['enroluserstowaitinglist']) ? 1 : 0;
                    $index++;
                }
                if (array_key_exists('customformdeleteinfoscheckboxadmin', $input)) {
                    $data->bo_cond_customform_deleteinfoscheckboxadmin =
                        !empty($input['customformdeleteinfoscheckboxadmin']) ? 1 : 0;
                }
            } else if (array_key_exists('customformenabled', $input) && !empty($input['customformenabled'])) {
                // Explicitly enabled with empty elements: clear condition content.
                $data->bo_cond_customform_restrict = 0;
            }
        }

        if (!empty($input['customformjson']) && is_array($input['customformjson'])) {
            $customformjson = $input['customformjson'];
            if (!empty($customformjson['formsarray']) && is_array($customformjson['formsarray'])) {
                $data->bo_cond_customform_restrict = 1;
                foreach ($customformjson['formsarray'] as $formcounter => $formrows) {
                    if (!is_array($formrows)) {
                        continue;
                    }
                    foreach ($formrows as $counter => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $fc = (int)$formcounter;
                        $ix = (int)$counter;
                        $data->{'bo_cond_customform_select_' . $fc . '_' . $ix} = (string)($row['formtype'] ?? '0');
                        $data->{'bo_cond_customform_label_' . $fc . '_' . $ix} = (string)($row['label'] ?? '');
                        $data->{'bo_cond_customform_value_' . $fc . '_' . $ix} = (string)($row['value'] ?? '');
                        $data->{'bo_cond_customform_notempty_' . $fc . '_' . $ix} = !empty($row['notempty']) ? 1 : 0;
                        $data->{'bo_cond_customform_enroluserstowaitinglist' . $ix} =
                            !empty($row['enroluserstowaitinglist']) ? 1 : 0;
                    }
                }
                $data->bo_cond_customform_deleteinfoscheckboxadmin =
                    !empty($customformjson['deleteinfoscheckboxadmin']) ? 1 : 0;
            }
        }

        if (
            $taskname === create_option_task::TASK_NAME
            || $taskname === update_option_task::TASK_NAME
            || $taskname === bulk_update_options_task::TASK_NAME
        ) {
            $permissioncheck = self::validate_update_field_permissions($input, (int)$context->id);
            if (($permissioncheck['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($permissioncheck['message']
                        ?? get_string('agent_booking_update_permission_denied_generic', 'booking')),
                    'resultid' => null,
                ];
            }
        }

        if ($taskname === create_option_task::TASK_NAME) {
            $data->id = 0;
            if (empty($data->text)) {
                return ['status' => 'error', 'detail' => 'Option title is required.', 'resultid' => null];
            }
        } else if ($taskname === update_option_task::TASK_NAME) {
            if (!empty($input['optionid'])) {
                $data->id = (int)$input['optionid'];
            } else if (self::is_last_option_reference((string)($input['optionquery'] ?? ''))) {
                $lastoptionid = self::resolve_last_option_for_user($cmid, $userid);
                if (!$lastoptionid) {
                    return [
                        'status' => 'error',
                        'detail' => 'No previously worked-on option found in this booking context. '
                            . 'Please provide optionid or a specific optionquery.',
                        'resultid' => null,
                    ];
                }
                $data->id = $lastoptionid;
            } else {
                $result = self::resolve_single_option(
                    $cmid,
                    (string)($input['optionquery'] ?? ''),
                    (string)($input['optionwhen'] ?? '')
                );
                if ($result['status'] !== 'ok') {
                    return [
                        'status' => 'error',
                        'detail' => (string)$result['message'],
                        'resultid' => null,
                    ];
                }
                $data->id = (int)$result['optionid'];
            }

            // Default update behavior for optiondates is append so existing sessions are not lost.
            if (!empty($parsedoptiondates)) {
                $datesmode = strtolower(trim((string)($input['optiondatesmode'] ?? 'append')));
                if ($datesmode === 'append') {
                    $parsedoptiondates = self::merge_existing_optiondates_with_new((int)$data->id, $parsedoptiondates);
                }
            }
        } else if ($taskname === bulk_update_options_task::TASK_NAME) {
            // Resolve all target option IDs and apply the update to each one.
            $optionids = self::resolve_bulk_option_ids($cmid, $input);
            if (empty($optionids)) {
                return [
                    'status' => 'error',
                    'detail' => 'No matching booking options found to update.',
                    'resultid' => null,
                ];
            }

            $updated = [];
            $failed  = [];
            foreach ($optionids as $optionid) {
                $itemdata = clone $data;
                $itemdata->id = (int)$optionid;
                try {
                    booking_option::update($itemdata, $context);
                    $updated[] = (int)$optionid;
                    self::remember_last_option_for_user($userid, $cmid, (int)$optionid, (int)$cm->instance);
                } catch (\Throwable $e) {
                    $failed[] = (int)$optionid . ' (' . $e->getMessage() . ')';
                }
            }

            if (!empty($failed)) {
                return [
                    'status'   => 'error',
                    'detail'   => 'Updated: ' . implode(', ', $updated) . '. Failed: ' . implode('; ', $failed),
                    'resultid' => !empty($updated) ? $updated[0] : null,
                ];
            }

            return [
                'status'           => 'executed',
                'detail'           => 'Updated ' . count($updated) . ' booking option(s): '
                    . implode(', ', $updated) . '.',
                'resultid'         => !empty($updated) ? $updated[0] : null,
                'previewoptionids' => $updated,
            ];
        }

        if (!empty($parsedoptiondates)) {
            self::apply_optiondates_to_update_data($data, $parsedoptiondates);
        }

        try {
            $newoptionid = booking_option::update($data, $context);

            if (
                $taskname === create_option_task::TASK_NAME
                || $taskname === update_option_task::TASK_NAME
            ) {
                self::remember_last_option_for_user($userid, $cmid, (int)$newoptionid, (int)$cm->instance);
            }

            $detail = 'Booking option ' . ($taskname === create_option_task::TASK_NAME ? 'created' : 'updated')
                . ' (id=' . (int)$newoptionid . ', link=' . self::build_option_link($cmid, (int)$newoptionid) . ').';

            $verificationwarnings = self::verify_persisted_option_state_for_task(
                $taskname,
                $input,
                (int)$newoptionid
            );
            if (!empty($verificationwarnings)) {
                $detail .= ' Verification warnings: ' . implode(' ', $verificationwarnings);
            }

            if (!empty($bookusersforoption)) {
                $bookusersresult = self::book_users_via_bookit((int)$newoptionid, $bookusersforoption, $bookusersmeta);
                if (!empty($bookusersresult['errors'])) {
                    return [
                        'status' => 'error',
                        'detail' => $detail . ' User booking failed: ' . implode(' ', $bookusersresult['errors']),
                        'resultid' => (int)$newoptionid,
                    ];
                }

                if (!empty($bookusersresult['bookeduserids'])) {
                    $detail .= ' Booked users: ' . implode(', ', $bookusersresult['bookeduserids']) . '.';
                }
            }

            return [
                'status'   => 'executed',
                'detail'   => $detail,
                'resultid' => (int)$newoptionid,
                'warnings' => $verificationwarnings,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null];
        }
    }

    /**
     * Run task-specific post-apply verification against persisted option settings.
     *
     * @param string $taskname
     * @param array<string,mixed> $input
     * @param int $optionid
     * @return array<int,string>
     */
    private static function verify_persisted_option_state_for_task(string $taskname, array $input, int $optionid): array {
        if ($optionid <= 0) {
            return [];
        }

        if ($taskname !== create_option_task::TASK_NAME && $taskname !== update_option_task::TASK_NAME) {
            return [];
        }

        try {
            $support = new self();
            $task = $support->get_task_instances()[$taskname] ?? null;
            if (!$task instanceof base_booking_task) {
                return [];
            }

            singleton_service::destroy_booking_option_singleton($optionid);
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            return array_values(array_filter(
                $task->verify_persisted_option_state($input, $settings),
                static fn($item): bool => is_string($item) && trim($item) !== ''
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve the list of option IDs targeted by a bulk_update_options command.
     *
     * Priority: explicit optionids array → optionquery search → apply_to_all (all in instance).
     *
     * @param int $cmid
     * @param array<string,mixed> $input
     * @return int[]
     */
    private static function resolve_bulk_option_ids(int $cmid, array $input): array {
        global $DB;

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return [];
        }

        if (!empty($input['optionids']) && is_array($input['optionids'])) {
            return array_values(array_filter(
                array_map('intval', $input['optionids']),
                static function (int $id) use ($DB, $cm): bool {
                    return $id > 0 && $DB->record_exists(
                        'booking_options',
                        ['id' => $id, 'bookingid' => (int)$cm->instance]
                    );
                }
            ));
        }

        if (!empty($input['optionquery']) && is_string($input['optionquery'])) {
            $rows = self::search_option_candidates($cmid, trim((string)$input['optionquery']), 500, '');
            return array_values(array_map(fn(array $row): int => (int)($row['optionid'] ?? 0), $rows));
        }

        if (!empty($input['apply_to_all'])) {
            $records = $DB->get_records('booking_options', ['bookingid' => (int)$cm->instance], '', 'id');
            return array_values(array_map('intval', array_keys($records)));
        }

        return [];
    }

    /**
     * Validate whether current user can update requested field groups.
     *
     * @param array<string,mixed> $input
     * @param int $contextid
     * @return array{status:string,message?:string}
     */
    private static function validate_update_field_permissions(array $input, int $contextid): array {
        $required = self::requested_update_field_groups($input);
        if (empty($required)) {
            return ['status' => 'ok'];
        }

        $available = fields_info::get_available_field_class_ids($contextid);
        $availablelookup = array_fill_keys($available, true);

        $blockedlabels = [];
        foreach ($required as $entry) {
            $fieldid = (int)$entry['fieldid'];
            if (!isset($availablelookup[$fieldid])) {
                $blockedlabels[] = (string)$entry['label'];
            }
        }

        $blockedlabels = array_values(array_unique($blockedlabels));
        if (empty($blockedlabels)) {
            return ['status' => 'ok'];
        }

        return [
            'status' => 'error',
            'message' => get_string(
                'agent_booking_update_permission_denied_groups',
                'booking',
                implode(', ', $blockedlabels)
            ),
        ];
    }

    /**
     * Map requested update input keys to option field groups.
     *
     * @param array<string,mixed> $input
     * @return array<int,array{fieldid:int,label:string}>
     */
    private static function requested_update_field_groups(array $input): array {
        $groups = [];

        $register = static function (int $fieldid, string $label) use (&$groups): void {
            $groups[] = ['fieldid' => $fieldid, 'label' => $label];
        };

        if (self::has_any_input_key($input, ['text'])) {
            $register(MOD_BOOKING_OPTION_FIELD_TEXT, get_string('text', 'booking'));
        }
        if (self::has_any_input_key($input, ['description'])) {
            $register(MOD_BOOKING_OPTION_FIELD_DESCRIPTION, get_string('description', 'booking'));
        }
        if (self::has_any_input_key($input, ['location'])) {
            $register(MOD_BOOKING_OPTION_FIELD_LOCATION, get_string('location', 'booking'));
        }
        if (self::has_any_input_key($input, ['address'])) {
            $register(MOD_BOOKING_OPTION_FIELD_ADDRESS, get_string('address', 'booking'));
        }

        if (self::has_any_input_key($input, ['maxanswers'])) {
            $register(MOD_BOOKING_OPTION_FIELD_MAXANSWERS, get_string('maxanswers', 'booking'));
        }
        if (self::has_any_input_key($input, ['maxoverbooking'])) {
            $register(
                MOD_BOOKING_OPTION_FIELD_MAXOVERBOOKING,
                get_string('maxoverbooking', 'booking')
            );
        }

        if (self::has_any_input_key($input, ['coursequery'])) {
            $register(MOD_BOOKING_OPTION_FIELD_COURSEID, get_string('associatedcourse', 'booking'));
        }

        if (self::has_any_input_key($input, ['teacherquery', 'teacheremail'])) {
            $register(MOD_BOOKING_OPTION_FIELD_TEACHERS, get_string('teachers', 'booking'));
        }

        if (self::has_any_input_key($input, ['prices'])) {
            $register(MOD_BOOKING_OPTION_FIELD_PRICE, get_string('price', 'booking'));
        }

        if (self::has_any_input_key($input, ['optiondates', 'coursestarttime', 'courseendtime', 'daystonotify'])) {
            $register(MOD_BOOKING_OPTION_FIELD_OPTIONDATES, get_string('optiondates', 'booking'));
        }

        if (self::has_any_input_key($input, ['bookingopeningtime'])) {
            $register(
                MOD_BOOKING_OPTION_FIELD_BOOKINGOPENINGTIME,
                get_string('bookingopeningtime', 'booking')
            );
        }
        if (self::has_any_input_key($input, ['bookingclosingtime'])) {
            $register(
                MOD_BOOKING_OPTION_FIELD_BOOKINGCLOSINGTIME,
                get_string('bookingclosingtime', 'booking')
            );
        }

        if (self::has_any_input_key($input, ['selflearningcourse', 'duration'])) {
            $register(MOD_BOOKING_OPTION_FIELD_DURATION, get_string('duration', 'booking'));
        }
        if (self::has_any_input_key($input, ['disablecancel'])) {
            $register(MOD_BOOKING_OPTION_FIELD_DISABLECANCEL, get_string('disablecancel', 'booking'));
        }

        if (self::has_any_input_key($input, ['invisible', 'visibility'])) {
            $register(MOD_BOOKING_OPTION_FIELD_INVISIBLE, get_string('optionvisibility', 'mod_booking'));
        }

        if (
            self::has_any_input_key(
                $input,
                ['bookusersquery', 'bookuserscompleted', 'bookusersupdateexisting', 'bookuserstimebooked']
            )
        ) {
            $register(MOD_BOOKING_OPTION_FIELD_BOOKUSERS, get_string('bookusers', 'booking'));
        }

        if (self::has_any_input_key($input, ['customfieldvalues'])) {
            $register(
                MOD_BOOKING_OPTION_FIELD_COSTUMFIELDS,
                get_string('customfields', 'booking')
            );
        }

        $availabilitykeys = [
            'enrolledincoursequery',
            'enrolledincourseoperator',
            'enrolledincoursesqlfilter',
            'enrolledincourseenabled',
            'enrolledincourseoverride',
            'enrolledincourseoverrideoperator',
            'enrolledincourseoverrideconditionids',
            'enrolledincohortquery',
            'enrolledincohortoperator',
            'enrolledincohort_sqlfilter',
            'enrolledincohortenabled',
            'enrolledincohortoverride',
            'enrolledincohortoverrideoperator',
            'enrolledincohortoverrideconditionids',
            'hascompetencyquery',
            'hascompetencyoperator',
            'hascompetencyenabled',
            'hascompetencyoverride',
            'hascompetencyoverrideoperator',
            'hascompetencyoverrideconditionids',
            'previouslybookedquery',
            'previouslybookedenabled',
            'previouslybookedrequirecompletion',
            'selectusersquery',
            'selectusersenabled',
            'selectusersoverride',
            'selectusersoverrideoperator',
            'selectusersoverrideconditionids',
            'nooverlappingmode',
            'nooverlappingenabled',
            'allowedtobookininstance',
            'allowedtobookininstancecapabilitynotneeded',
            'userprofilestandardenabled',
            'userprofilestandardfield',
            'userprofilestandardoperator',
            'userprofilestandardvalue',
            'userprofilestandardoverride',
            'userprofilestandardoverrideoperator',
            'userprofilestandardoverrideconditionids',
            'userprofilecustomenabled',
            'userprofilecustomfield',
            'userprofilecustomoperator',
            'userprofilecustomvalue',
            'userprofilecustomconnectsecondfield',
            'userprofilecustomfield2',
            'userprofilecustomoperator2',
            'userprofilecustomvalue2',
            'userprofilecustomsqlfilter',
            'userprofilecustomoverride',
            'userprofilecustomoverrideoperator',
            'userprofilecustomoverrideconditionids',
            'customformenabled',
            'customformelements',
            'customformjson',
            'customformdeleteinfoscheckboxadmin',
        ];

        if (self::has_any_input_key($input, $availabilitykeys)) {
            $register(
                MOD_BOOKING_OPTION_FIELD_AVAILABILITY,
                get_string('availabilityconditions', 'booking')
            );
        }

        return $groups;
    }

    /**
     * True if any key from list is present in input.
     *
     * @param array<string,mixed> $input
     * @param array<int,string> $keys
     * @return bool
     */
    private static function has_any_input_key(array $input, array $keys): bool {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a date-time value to a UNIX timestamp.
     *
     * Accepts either ISO 8601 strings or Unix timestamps.
     *
     * @param  mixed $value
     * @return int|false  UNIX timestamp or false on failure.
     */
    private static function parse_datetime(mixed $value): int|false {
        if (is_int($value)) {
            return $value > 0 ? $value : false;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            $ts = (int)$value;
            return $ts > 0 ? $ts : false;
        }

        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $timezonename = (string)(get_config('core', 'timezone') ?? '');
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            $tz = new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }

        try {
            $dt = new \DateTime($value, $tz);
            $ts = $dt->getTimestamp();
            return $ts > 0 ? $ts : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Extract date ranges from input for option date processing.
     *
     * Supports either:
     * - optiondates: [{coursestarttime, courseendtime, daystonotify?, optiondateid?}, ...]
     * - legacy single fields: coursestarttime + courseendtime
     *
     * @param array $input
     * @return array<int, array<string, int>>
     */
    private static function extract_optiondates(array $input): array {
        $result = [];

        if (!empty($input['optiondates']) && is_array($input['optiondates'])) {
            foreach ($input['optiondates'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $startts = self::parse_datetime($item['coursestarttime'] ?? null);
                $endts = self::parse_datetime($item['courseendtime'] ?? null);
                if ($startts === false || $endts === false) {
                    continue;
                }
                $result[] = [
                    'optiondateid' => (int)($item['optiondateid'] ?? 0),
                    'coursestarttime' => $startts,
                    'courseendtime' => $endts,
                    'daystonotify' => (int)($item['daystonotify'] ?? 0),
                ];
            }

            usort($result, fn($a, $b) => $a['coursestarttime'] <=> $b['coursestarttime']);
            return $result;
        }

        $startts = self::parse_datetime($input['coursestarttime'] ?? null);
        $endts = self::parse_datetime($input['courseendtime'] ?? null);
        if ($startts !== false && $endts !== false) {
            $result[] = [
                'optiondateid' => 0,
                'coursestarttime' => $startts,
                'courseendtime' => $endts,
                'daystonotify' => 0,
            ];
        }

        return $result;
    }

    /**
     * Search option candidates using the existing booking table pipeline.
     *
     * @param int $cmid
     * @param string $query
     * @param int $limit
     * @param string $when
     * @return array<int, array<string, mixed>>
     */
    private static function search_option_candidates(
        int $cmid,
        string $query,
        int $limit = 10,
        string $when = ''
    ): array {
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        $optionsfields = explode(',', (string)($bookingsettings->optionsfields ?? ''));
        if (!in_array('booknow', $optionsfields)) {
            $optionsfields[] = 'booknow';
        }

        $range = self::extract_time_window_from_text($when !== '' ? $when : $query);

        $fetchrows = static function (string $searchtext, int $pagesize) use ($booking, $cmid, $optionsfields): array {
            $table = new bookingoptions_wbtable("cmid_{$cmid} aioptionsearch");
            view::apply_standard_params_for_bookingtable(
                $table,
                $optionsfields,
                true,
                true,
                true,
                false,
                true,
                MOD_BOOKING_VIEW_PARAM_LIST,
                $cmid
            );

            $wherearray = ['bookingid' => (int)$booking->id];
            [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
                0,
                0,
                '',
                null,
                $booking->context,
                [],
                $wherearray,
                null,
                [MOD_BOOKING_STATUSPARAM_BOOKED],
                '',
                '',
                $table
            );
            $table->set_filter_sql($fields, $from, $where, $filter, $params);

            if ($searchtext !== '') {
                $table->apply_filter('', $searchtext);
                if ($searchtext !== '') {
                    $table->apply_searchtext($searchtext);
                }
            }

            $table->printtable($pagesize, true);
            return (array)($table->rawdata ?? []);
        };

        $rows = $fetchrows(trim($query), max(1, $limit));
        if (empty($rows) && $range !== null) {
            $rows = $fetchrows('', max(50, $limit * 5));
        }

        $normalized = [];
        foreach ($rows as $row) {
            $start = isset($row->coursestarttime) ? (int)$row->coursestarttime : 0;
            $end = isset($row->courseendtime) ? (int)$row->courseendtime : 0;

            if ($range !== null) {
                $overlaps = ($start <= $range['end']) && (($end === 0) || ($end >= $range['start']));
                if (!$overlaps) {
                    continue;
                }
            }

            $normalized[] = [
                'optionid' => (int)($row->id ?? 0),
                'text' => (string)($row->text ?? ''),
                'titleprefix' => (string)($row->titleprefix ?? ''),
                'location' => (string)($row->location ?? ''),
                'coursestarttime' => $start,
                'courseendtime' => $end,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            $ats = (int)($a['coursestarttime'] ?? 0);
            $bts = (int)($b['coursestarttime'] ?? 0);
            return $ats <=> $bts;
        });

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Public wrapper for Wunderbyte-table based option search used by external preview rendering.
     *
     * @param int $cmid
     * @param string $query
     * @param int $limit
     * @param string $when
     * @return array<int, array<string, mixed>>
     */
    public static function search_option_candidates_for_preview(
        int $cmid,
        string $query,
        int $limit = 10,
        string $when = ''
    ): array {
        return self::search_option_candidates($cmid, $query, $limit, $when);
    }

    /**
     * Resolve a single option id by query and optional temporal hint.
     *
     * @param int $cmid
     * @param string $optionquery
     * @param string $when
     * @return array<string, mixed>
     */
    private static function resolve_single_option(int $cmid, string $optionquery, string $when = ''): array {
        $query = trim($optionquery);
        if ($query === '') {
            return ['status' => 'ambiguity', 'message' => 'Please provide optionquery to identify the option.'];
        }

        $rows = self::search_option_candidates($cmid, $query, 5, $when);
        if (empty($rows)) {
            return [
                'status' => 'error',
                'message' => 'No option matched optionquery "' . $query . '".',
            ];
        }

        if (count($rows) > 1) {
            $candidates = [];
            foreach ($rows as $row) {
                $candidates[] = self::format_option_label(
                    $cmid,
                    (int)$row['optionid'],
                    (string)$row['text']
                );
            }
            return [
                'status' => 'ambiguity',
                'message' => 'Multiple options matched: ' . implode(', ', $candidates)
                    . '. Please provide optionid.',
            ];
        }

        return [
            'status' => 'ok',
            'optionid' => (int)$rows[0]['optionid'],
        ];
    }

    /**
     * Find existing options with exactly matching title (case-insensitive).
     *
     * @param int $cmid
     * @param string $title
     * @return array{status:string,optionid?:int,candidates?:string}
     */
    private static function find_existing_options_by_exact_title(int $cmid, string $title): array {
        $title = trim($title);
        if ($title === '') {
            return ['status' => 'none'];
        }

        $rows = self::search_option_candidates($cmid, $title, 20);
        if (empty($rows)) {
            return ['status' => 'none'];
        }

        $matches = [];
        foreach ($rows as $row) {
            $candidate = trim((string)($row['text'] ?? ''));
            if (strtolower($candidate) === strtolower($title)) {
                $matches[] = $row;
            }
        }

        if (empty($matches)) {
            return ['status' => 'none'];
        }

        if (count($matches) === 1) {
            return ['status' => 'single', 'optionid' => (int)$matches[0]['optionid']];
        }

        $candidates = [];
        foreach ($matches as $row) {
            $candidates[] = self::format_option_label(
                $cmid,
                (int)$row['optionid'],
                (string)$row['text']
            );
        }

        return ['status' => 'multiple', 'candidates' => implode(', ', $candidates)];
    }

    /**
     * Search users through the existing external search_users implementation.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function search_user_candidates(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $result = search_users::execute($query);
        } catch (\Throwable $e) {
            return [];
        }

        $list = $result['list'] ?? [];
        $normalized = [];
        foreach ($list as $user) {
            $normalized[] = [
                'userid' => (int)($user->id ?? $user['id'] ?? 0),
                'firstname' => (string)($user->firstname ?? $user['firstname'] ?? ''),
                'lastname' => (string)($user->lastname ?? $user['lastname'] ?? ''),
                'email' => (string)($user->email ?? $user['email'] ?? ''),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Search courses through the existing external search_courses implementation.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function search_course_candidates(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $result = search_courses::execute($query);
        } catch (\Throwable $e) {
            return [];
        }

        $list = $result['list'] ?? [];
        $normalized = [];
        foreach ($list as $course) {
            $courseid = (int)($course->id ?? $course['id'] ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $normalized[] = [
                'courseid' => $courseid,
                'fullname' => (string)($course->fullname ?? $course['fullname'] ?? ''),
                'shortname' => (string)($course->shortname ?? $course['shortname'] ?? ''),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Resolve a single user id by query.
     *
     * @param string $query
     * @return array<string, mixed>
     */
    private static function resolve_single_user(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [
                'status' => 'ambiguity',
                'message' => get_string('agent_booking_resolve_user_query_required', 'booking'),
            ];
        }

        $users = self::search_user_candidates($query, 5);
        if (empty($users)) {
            return [
                'status' => 'error',
                'message' => get_string('agent_booking_resolve_user_no_match', 'booking', $query),
            ];
        }

        if (count($users) > 1) {
            $candidates = [];
            foreach ($users as $user) {
                $fullname = trim((string)$user['firstname'] . ' ' . (string)$user['lastname']);
                $candidates[] = (int)$user['userid'] . ' (' . $fullname . ', ' . (string)$user['email'] . ')';
            }
            return [
                'status' => 'ambiguity',
                'message' => get_string(
                    'agent_booking_resolve_user_ambiguous',
                    'booking',
                    implode(', ', $candidates)
                ),
            ];
        }

        return [
            'status' => 'ok',
            'userid' => (int)$users[0]['userid'],
            'email' => (string)$users[0]['email'],
        ];
    }

    /**
     * Resolve a single course by query.
     *
     * @param string $query
     * @return array<string, mixed>
     */
    private static function resolve_single_course(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return ['status' => 'ambiguity', 'message' => 'Please provide coursequery to identify the course.'];
        }

        $courses = self::search_course_candidates($query, 5);
        if (empty($courses)) {
            return [
                'status' => 'error',
                'message' => 'No course matched coursequery "' . $query . '".',
            ];
        }

        if (count($courses) > 1) {
            $candidates = [];
            foreach ($courses as $course) {
                $candidates[] = (int)$course['courseid'] . ' ('
                    . (string)$course['fullname'] . ', ' . (string)$course['shortname'] . ')';
            }
            return [
                'status' => 'ambiguity',
                'message' => 'Multiple courses matched: ' . implode(', ', $candidates)
                    . '. Please provide a more specific coursequery.',
            ];
        }

        return [
            'status' => 'ok',
            'courseid' => (int)$courses[0]['courseid'],
            'shortname' => (string)$courses[0]['shortname'],
            'fullname' => (string)$courses[0]['fullname'],
        ];
    }

    /**
     * Resolve one or many course queries for enrolled-in-course restrictions.
     *
     * @param string $rawquery single query or comma-separated list
     * @return array{
     *   courseids: array<int,int>,
     *   shortnames: array<int,string>,
     *   errors: array<int,string>,
     *   ambiguities: array<int,string>
     * }
     */
    private static function resolve_courses_for_restriction(string $rawquery): array {
        $parts = array_values(array_filter(array_map('trim', explode(',', $rawquery)), static fn(string $p): bool => $p !== ''));
        if (empty($parts)) {
            return [
                'courseids' => [],
                'shortnames' => [],
                'errors' => ['Please provide enrolledincoursequery to identify course(s).'],
                'ambiguities' => [],
            ];
        }

        $courseids = [];
        $shortnames = [];
        $errors = [];
        $ambiguities = [];

        foreach ($parts as $part) {
            $resolved = self::resolve_single_course($part);
            if (($resolved['status'] ?? '') === 'ok') {
                $courseid = (int)($resolved['courseid'] ?? 0);
                if ($courseid > 0) {
                    $courseids[] = $courseid;
                }
                $shortname = trim((string)($resolved['shortname'] ?? ''));
                if ($shortname !== '') {
                    $shortnames[] = $shortname;
                } else {
                    $errors[] = 'Resolved course "' . $part . '" has no shortname.';
                }
            } else if (($resolved['status'] ?? '') === 'ambiguity') {
                $ambiguities[] = (string)($resolved['message'] ?? 'Ambiguous course query: ' . $part);
            } else {
                $errors[] = (string)($resolved['message'] ?? ('No course matched: ' . $part));
            }
        }

        $courseids = array_values(array_unique($courseids));
        $shortnames = array_values(array_unique($shortnames));

        return [
            'courseids' => $courseids,
            'shortnames' => $shortnames,
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Split a comma-separated query string.
     *
     * @param string $raw
     * @return array<int, string>
     */
    private static function split_query_list(string $raw): array {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $p): bool => $p !== ''));
    }

    /**
     * Resolve cohort queries to cohort ids.
     *
     * @param string $rawquery
     * @return array{cohortids: array<int,int>, errors: array<int,string>, ambiguities: array<int,string>}
     */
    private static function resolve_cohorts_for_restriction(string $rawquery): array {
        global $DB;

        $parts = self::split_query_list($rawquery);
        if (empty($parts)) {
            return ['cohortids' => [], 'errors' => ['Please provide enrolledincohortquery.'], 'ambiguities' => []];
        }

        $ids = [];
        $errors = [];
        $ambiguities = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part)) {
                $record = $DB->get_record('cohort', ['id' => (int)$part], 'id, name, idnumber');
                if ($record) {
                    $ids[] = (int)$record->id;
                    continue;
                }
            }

            $matches = $DB->get_records_select(
                'cohort',
                $DB->sql_like('name', ':name', false) . ' OR ' . $DB->sql_like('idnumber', ':idnumber', false),
                ['name' => '%' . $part . '%', 'idnumber' => '%' . $part . '%'],
                'id ASC',
                'id, name, idnumber'
            );

            if (empty($matches)) {
                $errors[] = 'No cohort matched query "' . $part . '".';
                continue;
            }
            if (count($matches) > 1) {
                $cands = [];
                foreach ($matches as $m) {
                    $cands[] = (int)$m->id . ' (' . (string)$m->name . ', ' . (string)$m->idnumber . ')';
                }
                $ambiguities[] = 'Multiple cohorts matched "' . $part . '": ' . implode(', ', $cands) . '.';
                continue;
            }

            $ids[] = (int)reset($matches)->id;
        }

        return ['cohortids' => array_values(array_unique($ids)), 'errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve competency queries to competency ids.
     *
     * @param string $rawquery
     * @return array{competencyids: array<int,int>, errors: array<int,string>, ambiguities: array<int,string>}
     */
    private static function resolve_competencies_for_restriction(string $rawquery): array {
        global $DB;

        $parts = self::split_query_list($rawquery);
        if (empty($parts)) {
            return ['competencyids' => [], 'errors' => ['Please provide hascompetencyquery.'], 'ambiguities' => []];
        }

        $ids = [];
        $errors = [];
        $ambiguities = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part)) {
                $record = $DB->get_record('competency', ['id' => (int)$part], 'id, shortname');
                if ($record) {
                    $ids[] = (int)$record->id;
                    continue;
                }
            }

            $matches = $DB->get_records_select(
                'competency',
                $DB->sql_like('shortname', ':shortname', false) . ' OR ' . $DB->sql_like('idnumber', ':idnumber', false),
                ['shortname' => '%' . $part . '%', 'idnumber' => '%' . $part . '%'],
                'id ASC',
                'id, shortname, idnumber'
            );

            if (empty($matches)) {
                $errors[] = 'No competency matched query "' . $part . '".';
                continue;
            }
            if (count($matches) > 1) {
                $cands = [];
                foreach ($matches as $m) {
                    $cands[] = (int)$m->id . ' (' . (string)$m->shortname . ')';
                }
                $ambiguities[] = 'Multiple competencies matched "' . $part . '": ' . implode(', ', $cands) . '.';
                continue;
            }

            $ids[] = (int)reset($matches)->id;
        }

        return ['competencyids' => array_values(array_unique($ids)), 'errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve user query list to explicit user ids.
     *
     * @param string $rawquery
     * @return array{userids: array<int,int>, errors: array<int,string>, ambiguities: array<int,string>}
     */
    private static function resolve_users_for_restriction(string $rawquery): array {
        $parts = self::split_query_list($rawquery);
        if (empty($parts)) {
            return ['userids' => [], 'errors' => ['Please provide selectusersquery.'], 'ambiguities' => []];
        }

        $ids = [];
        $errors = [];
        $ambiguities = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part)) {
                $ids[] = (int)$part;
                continue;
            }

            $resolved = self::resolve_single_user($part);
            if (($resolved['status'] ?? '') === 'ok') {
                $ids[] = (int)$resolved['userid'];
            } else if (($resolved['status'] ?? '') === 'ambiguity') {
                $ambiguities[] = (string)$resolved['message'];
            } else {
                $errors[] = (string)$resolved['message'];
            }
        }

        return ['userids' => array_values(array_unique($ids)), 'errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve user query list to bookable users (ids + emails).
     *
     * @param string $rawquery
     * @return array{userids: array<int,int>, emails: array<int,string>, errors: array<int,string>, ambiguities: array<int,string>}
     */
    private static function resolve_users_for_booking(string $rawquery): array {
        $parts = self::split_query_list($rawquery);
        if (empty($parts)) {
            return ['userids' => [], 'emails' => [], 'errors' => ['Please provide bookusersquery.'], 'ambiguities' => []];
        }

        $userids = [];
        $emails = [];
        $errors = [];
        $ambiguities = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part)) {
                $userid = (int)$part;
                $user = singleton_service::get_instance_of_user($userid);
                if (empty($user) || empty($user->id) || empty($user->email)) {
                    $errors[] = 'User id "' . $part . '" could not be resolved to a valid user with e-mail.';
                    continue;
                }
                $userids[] = (int)$user->id;
                $emails[] = (string)$user->email;
                continue;
            }

            $resolved = self::resolve_single_user($part);
            if (($resolved['status'] ?? '') === 'ok') {
                $userids[] = (int)$resolved['userid'];
                $email = trim((string)($resolved['email'] ?? ''));
                if ($email === '') {
                    $errors[] = 'User matched "' . $part . '" but has no e-mail address.';
                    continue;
                }
                $emails[] = $email;
            } else if (($resolved['status'] ?? '') === 'ambiguity') {
                $ambiguities[] = (string)$resolved['message'];
            } else {
                $errors[] = (string)$resolved['message'];
            }
        }

        return [
            'userids' => array_values(array_unique($userids)),
            'emails' => array_values(array_unique($emails)),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Book users for an option through the standard booking_bookit flow.
     *
     * This enforces all existing booking rules and condition checks.
     *
     * @param int $optionid
     * @param array<int,int> $userids
     * @param array{completed: bool, updateexisting: bool, timebooked: int|null} $meta
     * @return array{bookeduserids: array<int,int>, errors: array<int,string>}
     */
    private static function book_users_via_bookit(int $optionid, array $userids, array $meta): array {
        $bookeduserids = [];
        $errors = [];

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->id)) {
            return [
                'bookeduserids' => [],
                'errors' => ['Could not resolve booking option settings for optionid ' . $optionid . '.'],
            ];
        }

        foreach ($userids as $targetuserid) {
            $targetuserid = (int)$targetuserid;
            if ($targetuserid <= 0) {
                continue;
            }

            // Explicit pre-check requested by product requirement: only hard blockers.
            $results = bo_info::get_condition_results((int)$settings->id, $targetuserid, true);
            if (!empty($results)) {
                $blockersummary = self::summarize_condition_blockers($results);
                $followup = self::blocking_followup_question($results);
                $errors[] = 'User ' . $targetuserid . ' cannot be booked due to blocking conditions '
                    . ': ' . $blockersummary . ' ' . $followup;
                continue;
            }

            $first = booking_bookit::bookit('option', (int)$settings->id, $targetuserid);
            $response = $first;

            // Confirmation-based flows may require a second call.
            if ((int)($first['status'] ?? 0) !== 1) {
                $second = booking_bookit::bookit('option', (int)$settings->id, $targetuserid);
                $response = $second;
            }

            if ((int)($response['status'] ?? 0) !== 1) {
                $message = (string)($response['message'] ?? 'notallowedtobook');
                $latestblockers = bo_info::get_condition_results((int)$settings->id, $targetuserid, true);
                if (!empty($latestblockers)) {
                    $blockersummary = self::summarize_condition_blockers($latestblockers);
                    $followup = self::blocking_followup_question($latestblockers);
                    $errors[] = 'User ' . $targetuserid . ' could not be booked: ' . $message . '. '
                        . 'Blocking conditions: ' . $blockersummary . ' ' . $followup;
                } else {
                    $errors[] = 'User ' . $targetuserid . ' could not be booked: ' . $message . '.';
                }
                continue;
            }

            $bookeduserids[] = $targetuserid;

            if (!empty($meta['completed'])) {
                try {
                    $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, (int)$settings->id);
                    $timebooked = (int)($meta['timebooked'] ?? time());
                    $updateexisting = !empty($meta['updateexisting']);
                    $bookingoption->toggle_user_completion($targetuserid, $timebooked, $updateexisting);
                } catch (\Throwable $e) {
                    $errors[] = 'User ' . $targetuserid . ' booked, but completion toggle failed: ' . $e->getMessage();
                }
            }
        }

        return [
            'bookeduserids' => array_values(array_unique($bookeduserids)),
            'errors' => $errors,
        ];
    }

    /**
     * True if task is read-only and should be executed without confirmation.
     *
     * @param string $taskname
     * @return bool
     */
    public static function is_read_only_task(string $taskname): bool {
        return in_array($taskname, [
            search_options_task::TASK_NAME,
            search_users_task::TASK_NAME,
            search_courses_task::TASK_NAME,
            list_option_properties_task::TASK_NAME,
            list_actions_task::TASK_NAME,
        ], true);
    }

    /**
     * Return a localized label for a schema property.
     *
     * @param string $propertyname
     * @return string
     */
    private static function get_localized_property_label(string $propertyname): string {
        $propertyname = trim($propertyname);
        if ($propertyname === '') {
            return '';
        }

        $exactmap = [
            'text' => ['text', 'booking'],
            'description' => ['description', 'booking'],
            'location' => ['location', 'booking'],
            'address' => ['address', 'booking'],
            'maxanswers' => ['maxanswers', 'booking'],
            'maxoverbooking' => ['maxoverbooking', 'booking'],
            'optiondates' => ['optiondates', 'booking'],
            'coursestarttime' => ['coursestarttime', 'booking'],
            'courseendtime' => ['courseendtime', 'booking'],
            'bookingopeningtime' => ['bookingopeningtime', 'booking'],
            'bookingclosingtime' => ['bookingclosingtime', 'booking'],
            'disablecancel' => ['disablecancel', 'booking'],
            'invisible' => ['optionvisibility', 'mod_booking'],
            'visibility' => ['optionvisibility', 'mod_booking'],
            'duration' => ['duration', 'booking'],
            'coursequery' => ['associatedcourse', 'booking'],
            'prices' => ['price', 'booking'],
            'bookusersquery' => ['bookusers', 'booking'],
            'optionid' => ['ai_property_optionid', 'mod_booking'],
            'optionquery' => ['ai_property_optionquery', 'mod_booking'],
            'optionwhen' => ['ai_property_optionwhen', 'mod_booking'],
            'teacherquery' => ['ai_property_teacherquery', 'mod_booking'],
            'teacheremail' => ['ai_property_teacheremail', 'mod_booking'],
            'selflearningcourse' => ['ai_property_selflearningcourse', 'mod_booking'],
            'bookuserscompleted' => ['ai_property_bookuserscompleted', 'mod_booking'],
            'bookuserstimebooked' => ['ai_property_bookuserstimebooked', 'mod_booking'],
            'bookusersupdateexisting' => ['ai_property_bookusersupdateexisting', 'mod_booking'],
            'customformjson' => ['ai_property_customformjson', 'mod_booking'],
            'customformelements' => ['ai_property_customformelements', 'mod_booking'],
            'customformdeleteinfoscheckboxadmin' => ['ai_property_customformdeleteinfoscheckboxadmin', 'mod_booking'],
        ];

        if (isset($exactmap[$propertyname])) {
            [$key, $component] = $exactmap[$propertyname];
            return get_string($key, $component);
        }

        $prefixmap = [
            'allowedtobookininstance' => 'bocondallowedtobookininstance',
            'enrolledincourse' => 'bocondenrolledincourse',
            'enrolledincohort' => 'bocondenrolledincohorts',
            'hascompetency' => 'bocondhascompetency',
            'previouslybooked' => 'bocondpreviouslybooked',
            'selectusers' => 'ai_property_selectusers',
            'nooverlapping' => 'bocondnooverlapping',
            'userprofilestandard' => 'ai_property_userprofilestandard',
            'userprofilecustom' => 'ai_property_userprofilecustom',
            'customform' => 'ai_property_customform',
        ];

        foreach ($prefixmap as $prefix => $stringkey) {
            if (!str_starts_with($propertyname, $prefix)) {
                continue;
            }

            $base = get_string($stringkey, str_starts_with($stringkey, 'ai_') ? 'mod_booking' : 'booking');
            $suffix = substr($propertyname, strlen($prefix));
            $suffixlabel = self::get_localized_property_suffix_label($suffix);
            return $suffixlabel === '' ? $base : $base . ' - ' . $suffixlabel;
        }

        return $propertyname;
    }

    /**
     * Return a localized label for a property suffix.
     *
     * @param string $suffix
     * @return string
     */
    private static function get_localized_property_suffix_label(string $suffix): string {
        $normalized = ltrim($suffix, '_');
        if ($normalized === '') {
            return '';
        }

        $map = [
            'enabled' => 'ai_property_suffix_enabled',
            'query' => 'ai_property_suffix_query',
            'operator' => 'ai_property_suffix_operator',
            'sqlfilter' => 'ai_property_suffix_sqlfilter',
            'override' => 'ai_property_suffix_override',
            'overrideoperator' => 'ai_property_suffix_overrideoperator',
            'overrideconditionids' => 'ai_property_suffix_overrideconditionids',
            'capabilitynotneeded' => 'ai_property_suffix_capabilitynotneeded',
            'requirecompletion' => 'ai_property_suffix_requirecompletion',
            'mode' => 'ai_property_suffix_mode',
            'field' => 'ai_property_suffix_field',
            'field2' => 'ai_property_suffix_field2',
            'value' => 'ai_property_suffix_value',
            'value2' => 'ai_property_suffix_value2',
            'operator2' => 'ai_property_suffix_operator2',
            'connectsecondfield' => 'ai_property_suffix_connectsecondfield',
            'json' => 'ai_property_suffix_json',
            'elements' => 'ai_property_suffix_elements',
            'deleteinfoscheckboxadmin' => 'ai_property_suffix_deleteinfoscheckboxadmin',
        ];

        if (isset($map[$normalized])) {
            return get_string($map[$normalized], 'mod_booking');
        }

        return $normalized;
    }

    /**
     * Return a localized label for a supported action.
     *
     * @param string $taskname
     * @return string
     */
    private static function get_localized_action_label(string $taskname): string {
        $map = [
            create_option_task::TASK_NAME => 'ai_action_create_option',
            update_option_task::TASK_NAME => 'ai_action_update_option',
            search_options_task::TASK_NAME => 'ai_action_search_options',
            search_users_task::TASK_NAME => 'ai_action_search_users',
            search_courses_task::TASK_NAME => 'ai_action_search_courses',
            add_price_category_task::TASK_NAME => 'ai_action_add_price_category',
            list_option_properties_task::TASK_NAME => 'ai_action_list_option_properties',
            list_actions_task::TASK_NAME => 'ai_action_list_actions',
        ];

        if (isset($map[$taskname])) {
            return get_string($map[$taskname], 'mod_booking');
        }

        return $taskname;
    }

    /**
     * Build a readable blocker summary from bo_info condition results.
     *
     * @param array $results
     * @return string
     */
    private static function summarize_condition_blockers(array $results): string {
        if (empty($results)) {
            return 'unknown blocking condition';
        }

        $parts = [];
        foreach ($results as $result) {
            $classname = (string)($result['classname'] ?? 'condition');
            $classparts = explode('\\', $classname);
            $shortname = strtolower((string)end($classparts));
            $description = trim(strip_tags((string)($result['description'] ?? '')));

            if ($description !== '') {
                $parts[] = $shortname . ': ' . $description;
            } else {
                $parts[] = $shortname;
            }
        }

        $parts = array_values(array_unique($parts));
        return implode(' | ', $parts);
    }

    /**
     * Build targeted follow-up question depending on blocking condition types.
     *
     * @param array $results
     * @return string
     */
    private static function blocking_followup_question(array $results): string {
        $needscustomform = false;
        $needsbookingpolicy = false;

        foreach ($results as $result) {
            $classname = strtolower((string)($result['classname'] ?? ''));
            if (str_contains($classname, 'customform')) {
                $needscustomform = true;
            }
            if (str_contains($classname, 'bookingpolicy')) {
                $needsbookingpolicy = true;
            }
        }

        if ($needscustomform && $needsbookingpolicy) {
            return get_string('agent_booking_blocker_followup_customform_bookingpolicy', 'booking');
        }
        if ($needscustomform) {
            return get_string('agent_booking_blocker_followup_customform', 'booking');
        }
        if ($needsbookingpolicy) {
            return get_string('agent_booking_blocker_followup_bookingpolicy', 'booking');
        }

        return get_string('agent_booking_blocker_followup_generic', 'booking');
    }

    /**
     * Validate custom form elements payload from AI input.
     *
     * @param array $elements
     * @return array{errors: array<int,string>}
     */
    private static function validate_customform_elements(array $elements): array {
        $errors = [];
        $allowed = [
            'advcheckbox',
            'static',
            'shorttext',
            'select',
            'url',
            'mail',
            'deleteinfoscheckboxuser',
            'enrolusersaction',
        ];

        if (count($elements) > 50) {
            $errors[] = 'Field "customformelements" supports at most 50 elements.';
            return ['errors' => $errors];
        }

        foreach ($elements as $idx => $element) {
            $n = $idx + 1;
            if (!is_array($element)) {
                $errors[] = 'customformelements[' . $n . '] must be an object.';
                continue;
            }

            $formtype = trim((string)($element['formtype'] ?? ''));
            if ($formtype === '' || !in_array($formtype, $allowed, true)) {
                $errors[] = 'customformelements[' . $n . '].formtype must be one of: '
                    . implode(', ', $allowed) . '.';
                continue;
            }

            if ($formtype !== 'deleteinfoscheckboxuser') {
                $label = trim((string)($element['label'] ?? ''));
                if ($label === '') {
                    $errors[] = 'customformelements[' . $n . '].label is required for formtype "' . $formtype . '".';
                }
            }
        }

        return ['errors' => $errors];
    }

    /**
     * Normalize custom form elements payload for execute mapping.
     *
     * @param array $elements
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_customform_elements(array $elements): array {
        $allowed = [
            'advcheckbox',
            'static',
            'shorttext',
            'select',
            'url',
            'mail',
            'deleteinfoscheckboxuser',
            'enrolusersaction',
        ];

        $normalized = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $formtype = trim((string)($element['formtype'] ?? ''));
            if ($formtype === '' || !in_array($formtype, $allowed, true)) {
                continue;
            }

            $normalized[] = [
                'formtype' => $formtype,
                'label' => (string)($element['label'] ?? ''),
                'value' => (string)($element['value'] ?? ''),
                'required' => !empty($element['required']) ? 1 : 0,
                'enroluserstowaitinglist' => !empty($element['enroluserstowaitinglist']) ? 1 : 0,
            ];

            if (count($normalized) >= 50) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Detect forbidden fields when update_option is used only for booking users.
     *
     * @param array $input
     * @return array<int,string>
     */
    private static function detect_forbidden_fields_for_bookusers_update(array $input): array {
        $allowed = [
            'optionid',
            'optionquery',
            'optionwhen',
            'bookusersquery',
            'bookuserscompleted',
            'bookusersupdateexisting',
            'bookuserstimebooked',
        ];

        $forbidden = [];
        foreach (array_keys($input) as $field) {
            if (!in_array((string)$field, $allowed, true)) {
                $forbidden[] = (string)$field;
            }
        }

        sort($forbidden);
        return $forbidden;
    }

    /**
     * Extract a day-range from natural-language hints like "next monday".
     *
     * @param string $text
     * @return array<string, int>|null
     */
    private static function extract_time_window_from_text(string $text): ?array {
        $text = trim(strtolower($text));
        if ($text === '') {
            return null;
        }

        $timezonename = (string)(get_config('core', 'timezone') ?? '');
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            $tz = new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }

        $now = new \DateTimeImmutable('now', $tz);

        if (preg_match('/\b(today|tomorrow)\b/i', $text, $m)) {
            $day = $m[1] === 'tomorrow' ? $now->modify('+1 day') : $now;
            $start = $day->setTime(0, 0, 0)->getTimestamp();
            $end = $day->setTime(23, 59, 59)->getTimestamp();
            return ['start' => $start, 'end' => $end];
        }

        if (preg_match('/\b(next|this)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text, $m)) {
            $phrase = strtolower($m[1] . ' ' . $m[2]);
            $day = $now->modify($phrase);
            $start = $day->setTime(0, 0, 0)->getTimestamp();
            $end = $day->setTime(23, 59, 59)->getTimestamp();
            return ['start' => $start, 'end' => $end];
        }

        return null;
    }

    /**
     * Validate prices payload and category existence.
     *
     * @param array $input
     * @return array{errors: array, ambiguities: array}
     */
    private static function validate_prices_input(array $input): array {
        $errors = [];
        $ambiguities = [];

        if (!array_key_exists('prices', $input) || $input['prices'] === null) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $prices = self::normalize_prices_input($input['prices']);
        if ($prices === null) {
            $errors[] = 'Field "prices" must be an object map like {"default": 10, "student": 20}.';
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        if (empty($prices)) {
            $errors[] = 'Field "prices" must contain at least one category => value pair.';
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $categories = self::get_price_categories_by_identifier();
        $unknown = [];
        foreach ($prices as $identifier => $value) {
            if (!isset($categories[strtolower($identifier)]) || (int)$categories[strtolower($identifier)]->disabled === 1) {
                $unknown[] = $identifier;
            }
            if (!is_numeric($value)) {
                $errors[] = 'Price for category "' . $identifier . '" must be numeric.';
                continue;
            }
            if ((float)$value < 0) {
                $errors[] = 'Price for category "' . $identifier . '" must be non-negative.';
            }
        }

        if (!empty($unknown)) {
            $existinglist = self::format_price_categories_for_message($categories);
            $ambiguities[] = 'Unknown price category/categories: ' . implode(', ', $unknown)
                . '. Existing categories are: ' . $existinglist . '.';
        }

        return ['errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Normalize prices payload to identifier => float map.
     *
     * @param mixed $prices
     * @return array<string, float>|null
     */
    private static function normalize_prices_input($prices): ?array {
        if ($prices === null) {
            return [];
        }

        if (!is_array($prices)) {
            return null;
        }

        $normalized = [];
        foreach ($prices as $identifier => $value) {
            if (!is_string($identifier) || trim($identifier) === '') {
                return null;
            }
            if (!is_numeric($value)) {
                return null;
            }
            $key = trim($identifier);
            $normalized[$key] = (float)$value;
        }

        return $normalized;
    }

    /**
     * Merge existing sessions with new sessions for append-style updates.
     *
     * @param int $optionid
     * @param array<int, array<string, int>> $newdates
     * @return array<int, array<string, int>>
     */
    private static function merge_existing_optiondates_with_new(int $optionid, array $newdates): array {
        $merged = [];

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $existing = (array)($settings->sessions ?? []);
        foreach ($existing as $session) {
            $start = (int)($session->coursestarttime ?? 0);
            $end = (int)($session->courseendtime ?? 0);
            if ($start <= 0 || $end <= 0) {
                continue;
            }

            $key = $start . '-' . $end;
            $merged[$key] = [
                'optiondateid' => (int)($session->id ?? 0),
                'coursestarttime' => $start,
                'courseendtime' => $end,
                'daystonotify' => (int)($session->daystonotify ?? 0),
            ];
        }

        foreach ($newdates as $date) {
            $start = (int)($date['coursestarttime'] ?? 0);
            $end = (int)($date['courseendtime'] ?? 0);
            if ($start <= 0 || $end <= 0) {
                continue;
            }

            $key = $start . '-' . $end;
            if (isset($merged[$key])) {
                // Keep existing record id to avoid creating duplicates for the same time range.
                continue;
            }
            $merged[$key] = [
                'optiondateid' => (int)($date['optiondateid'] ?? 0),
                'coursestarttime' => $start,
                'courseendtime' => $end,
                'daystonotify' => (int)($date['daystonotify'] ?? 0),
            ];
        }

        $result = array_values($merged);
        usort(
            $result,
            static function (array $a, array $b): int {
                return ((int)$a['coursestarttime']) <=> ((int)$b['coursestarttime']);
            }
        );
        return $result;
    }

    /**
     * Apply normalized optiondates to booking_option::update() form payload.
     *
     * @param \stdClass $data
     * @param array<int, array<string, int>> $optiondates
     * @return void
     */
    private static function apply_optiondates_to_update_data(\stdClass $data, array $optiondates): void {
        $data->datescounter = count($optiondates);
        $index = 1;
        foreach ($optiondates as $date) {
            $data->{'optiondateid_' . $index} = (int)($date['optiondateid'] ?? 0);
            $data->{'coursestarttime_' . $index} = (int)$date['coursestarttime'];
            $data->{'courseendtime_' . $index} = (int)$date['courseendtime'];
            $data->{'daystonotify_' . $index} = (int)($date['daystonotify'] ?? 0);
            $index++;
        }
    }

    /**
     * Normalize visibility input to booking option visibility constants.
     *
     * Supported sources:
     * - invisible: 0|1|2 (int/string) or bool
     * - visibility: visible|invisible|directlink (plus common aliases)
     *
     * @param array<string,mixed> $input
     * @return array{value?:int,error?:string}
     */
    private static function normalize_visibility_input(array $input): array {
        $frominvisible = null;
        $fromvisibility = null;

        if (array_key_exists('invisible', $input)) {
            $raw = $input['invisible'];
            if (is_bool($raw)) {
                $frominvisible = $raw ? MOD_BOOKING_OPTION_INVISIBLE : MOD_BOOKING_OPTION_VISIBLE;
            } else if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)))) {
                $value = (int)$raw;
                $allowedvisibility = [
                    MOD_BOOKING_OPTION_VISIBLE,
                    MOD_BOOKING_OPTION_INVISIBLE,
                    MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                ];
                if (!in_array($value, $allowedvisibility, true)) {
                    return [
                        'error' => 'Field "invisible" must be one of: 0 (visible), 1 (invisible), '
                            . '2 (visible via direct link).',
                    ];
                }
                $frominvisible = $value;
            } else if (is_string($raw)) {
                $normalized = strtolower(trim($raw));
                $map = [
                    'visible' => MOD_BOOKING_OPTION_VISIBLE,
                    'public' => MOD_BOOKING_OPTION_VISIBLE,
                    'invisible' => MOD_BOOKING_OPTION_INVISIBLE,
                    'hidden' => MOD_BOOKING_OPTION_INVISIBLE,
                    'directlink' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                    'visiblewithlink' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                    'visible_with_link' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                    'linkonly' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                ];
                if (!isset($map[$normalized])) {
                    return ['error' => 'Field "invisible" string value must be one of: visible, invisible, directlink.'];
                }
                $frominvisible = $map[$normalized];
            } else {
                return ['error' => 'Field "invisible" must be an integer, boolean, or visibility string.'];
            }
        }

        if (array_key_exists('visibility', $input)) {
            if (!is_string($input['visibility']) || trim((string)$input['visibility']) === '') {
                return ['error' => 'Field "visibility" must be a non-empty string.'];
            }

            $normalized = strtolower(trim((string)$input['visibility']));
            $map = [
                'visible' => MOD_BOOKING_OPTION_VISIBLE,
                'public' => MOD_BOOKING_OPTION_VISIBLE,
                'invisible' => MOD_BOOKING_OPTION_INVISIBLE,
                'hidden' => MOD_BOOKING_OPTION_INVISIBLE,
                'directlink' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                'visiblewithlink' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                'visible_with_link' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                'linkonly' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
            ];

            if (!isset($map[$normalized])) {
                return ['error' => 'Field "visibility" must be one of: visible, invisible, directlink.'];
            }
            $fromvisibility = $map[$normalized];
        }

        if ($frominvisible !== null && $fromvisibility !== null && $frominvisible !== $fromvisibility) {
            return ['error' => 'Fields "invisible" and "visibility" conflict. Provide only one visibility value.'];
        }

        if ($frominvisible !== null) {
            return ['value' => $frominvisible];
        }
        if ($fromvisibility !== null) {
            return ['value' => $fromvisibility];
        }

        return [];
    }

    /**
     * Return price categories keyed by lowercase identifier.
     *
     * @return array<string, \stdClass>
     */
    private static function get_price_categories_by_identifier(): array {
        global $DB;

        $records = $DB->get_records('booking_pricecategories', null, 'pricecatsortorder ASC, id ASC');
        $result = [];
        foreach ($records as $record) {
            $result[strtolower((string)$record->identifier)] = $record;
        }

        return $result;
    }

    /**
     * Format categories for user-facing messages.
     *
     * @param array<string, \stdClass> $categories
     * @return string
     */
    private static function format_price_categories_for_message(array $categories): string {
        $parts = [];
        foreach ($categories as $category) {
            if ((int)$category->disabled === 1) {
                continue;
            }
            $parts[] = (string)$category->identifier . ' (' . (string)$category->name . ')';
        }

        return empty($parts) ? '(none)' : implode(', ', $parts);
    }

    /**
     * Detect if a query refers to the previously worked-on option.
     *
     * @param string $query
     * @return bool
     */
    private static function is_last_option_reference(string $query): bool {
        $q = trim(strtolower($query));
        if ($q === '') {
            return false;
        }

        if (preg_match('/\b(last|previous|recent)\b/', $q)) {
            return true;
        }

        if (preg_match('/\b(worked on|just updated|you worked on)\b/', $q)) {
            return true;
        }

        // German phrasing support.
        if (preg_match('/\b(letzte|zuletzt|eben|gerade)\b/', $q)) {
            return true;
        }

        return false;
    }

    /**
     * Resolve last worked-on option id from thread metadata.
     *
     * @param int $cmid
     * @param int $userid
     * @return int|null
     */
    private static function resolve_last_option_for_user(int $cmid, int $userid): ?int {
        global $DB;

        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $cmid);
        if (!$thread) {
            return null;
        }

        $lastoptionid = (int)($store->get_thread_metadata_value((int)$thread->id, 'lastworkedoptionid') ?? 0);
        if ($lastoptionid <= 0) {
            return null;
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return null;
        }

        $exists = $DB->record_exists('booking_options', [
            'id' => $lastoptionid,
            'bookingid' => (int)$cm->instance,
        ]);

        return $exists ? $lastoptionid : null;
    }

    /**
     * Store the last worked-on option id in thread metadata.
     *
     * @param int $userid
     * @param int $cmid
     * @param int $optionid
     * @param int $bookingid
     * @return void
     */
    private static function remember_last_option_for_user(int $userid, int $cmid, int $optionid, int $bookingid): void {
        if ($userid <= 0 || $cmid <= 0 || $optionid <= 0 || $bookingid <= 0) {
            return;
        }

        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $cmid, $bookingid);
        $store->set_thread_metadata_value((int)$thread->id, 'lastworkedoptionid', $optionid);
        $store->set_thread_metadata_value((int)$thread->id, 'lastworkedoptionts', time());
    }

    /**
     * Build canonical link for an option by id.
     *
     * @param int $cmid
     * @param int $optionid
     * @return string
     */
    private static function build_option_link(int $cmid, int $optionid): string {
        $url = new \moodle_url('/mod/booking/view.php', [
            'id' => $cmid,
            'optionid' => $optionid,
            'whichview' => 'showonlyone',
        ]);
        return $url->out(false);
    }

    /**
     * Format option label for AI-visible outputs.
     *
     * @param int $cmid
     * @param int $optionid
     * @param string $name
     * @return string
     */
    private static function format_option_label(int $cmid, int $optionid, string $name): string {
        $cleanname = trim($name) !== '' ? trim($name) : '-';
        return 'id=' . $optionid . ' name="' . $cleanname . '" link=' . self::build_option_link($cmid, $optionid);
    }
}
