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
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\booking\tasks\add_price_category_task;
use mod_booking\local\wbagent\booking\tasks\base_booking_task;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\create_user_task;
use mod_booking\local\wbagent\booking\tasks\list_actions_task;
use mod_booking\local\wbagent\booking\tasks\list_option_properties_task;
use mod_booking\local\wbagent\booking\tasks\search_courses_task;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\booking\tasks\search_users_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;
use mod_booking\local\wbagent\booking\tasks\get_current_user_task;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_bookit;
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
    /** @var string Thread metadata key for the last preview option ids. */
    private const LAST_PREVIEW_OPTION_IDS_METADATA_KEY = 'lastpreviewoptionids';

    /** @var array|null */
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
     * @return array
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
        return $task ? (array)$task->get_schema() : [];
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
        $task = $this->get_task_instances()[$taskname] ?? null;
        if (!$task) {
            return [
                'valid' => false,
                'errors' => ['Unknown task: ' . $taskname],
                'ambiguities' => [],
            ];
        }

        return $task->validate($input, $cmid);
    }

    /**
     * Return instantiated booking task classes keyed by task name.
     *
     * @return array
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
     * Execute a validated command.
     *
     * @param string $taskname
     * @param array  $input
     * @param int    $cmid
     * @param int    $userid
     * @return array
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid): array {
        $task = $this->get_task_instances()[$taskname] ?? null;
        if ($task) {
            return $task->execute($input, $cmid, $userid);
        }

        return [
            'status' => 'error',
            'detail' => 'Unknown booking task: ' . $taskname,
            'resultid' => null,
        ];
    }

    /**
     * Run task-specific post-apply verification against persisted option settings.
     *
     * @param string $taskname
     * @param array $input
     * @param int $optionid
     * @return array
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
     * @param array $input
     * @param int $userid
     * @return int[]
     */
    private static function resolve_bulk_option_ids(int $cmid, array $input, int $userid = 0): array {
        global $DB;

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return [];
        }

        if (!empty($input['optionids']) && is_array($input['optionids'])) {
            $requestedids = array_values(array_filter(array_map('intval', $input['optionids'])));
            $validids = array_values(array_filter(
                $requestedids,
                static function (int $id) use ($DB, $cm): bool {
                    return $id > 0 && $DB->record_exists(
                        'booking_options',
                        ['id' => $id, 'bookingid' => (int)$cm->instance]
                    );
                }
            ));

            if (!empty($validids)) {
                return $validids;
            }

            if ($userid > 0) {
                return self::remap_preview_ordinals_to_option_ids($cmid, $userid, $requestedids);
            }

            return [];
        }

        if (!empty($input['optionquery']) && is_string($input['optionquery'])) {
            if ($userid > 0 && self::is_last_preview_selection_reference((string)$input['optionquery'])) {
                return self::resolve_last_preview_option_ids_for_user($cmid, $userid);
            }
            $rows = self::search_option_candidates($cmid, trim((string)$input['optionquery']), 500, '');
            return array_values(array_map(fn(array $row): int => (int)($row['optionid'] ?? 0), $rows));
        }

        if (!empty($input['apply_to_all'])) {
            $records = $DB->get_records('booking_options', ['bookingid' => (int)$cm->instance], '', 'id');
            return array_values(array_map('intval', array_keys($records)));
        }

        if ($userid > 0) {
            return self::resolve_last_preview_option_ids_for_user($cmid, $userid);
        }

        return [];
    }

    /**
     * Validate whether current user can update requested field groups.
     *
     * @param array $input
     * @param int $contextid
     * @return array
     */
    public static function validate_update_field_permissions(array $input, int $contextid): array {
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
     * @param array $input
     * @return array
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
     * @param array $input
     * @param array $keys
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
    public static function parse_datetime(mixed $value): int|false {
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
     * @return array
     */
    public static function extract_optiondates(array $input): array {
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
     * @return array
     */
    private static function search_option_candidates(
        int $cmid,
        string $query,
        int $limit = 10,
        string $when = ''
    ): array {
        $query = self::sanitize_person_lookup_query($query);
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
     * @return array
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
     * Public wrapper for user search used by read-task executor.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function search_user_candidates_for_preview(string $query, int $limit = 10): array {
        return self::search_user_candidates($query, $limit);
    }

    /**
     * Public wrapper for course search used by read-task executor.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function search_course_candidates_for_preview(string $query, int $limit = 10): array {
        return self::search_course_candidates($query, $limit);
    }

    /**
     * Resolve a single option id by query and optional temporal hint.
     *
     * @param int $cmid
     * @param string $optionquery
     * @param string $when
     * @return array
     */
    public static function resolve_single_option(int $cmid, string $optionquery, string $when = ''): array {
        $query = self::sanitize_person_lookup_query($optionquery);
        if ($query === '') {
            return ['status' => 'ambiguity', 'message' => 'Please provide optionquery to identify the option.'];
        }

        // If there is exactly one case-insensitive exact title match, prefer it over fuzzy hits.
        $exact = self::find_existing_options_by_exact_title($cmid, $query);
        if (($exact['status'] ?? '') === 'single') {
            return [
                'status' => 'ok',
                'optionid' => (int)$exact['optionid'],
            ];
        }

        if (($exact['status'] ?? '') === 'multiple') {
            return [
                'status' => 'ambiguity',
                'message' => 'Multiple options matched: ' . (string)($exact['candidates'] ?? '')
                    . '. Please provide optionid.',
            ];
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
     * @return array
     */
    public static function find_existing_options_by_exact_title(int $cmid, string $title): array {
        $title = self::sanitize_person_lookup_query($title);
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
     * @return array
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
     * @return array
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
     * @return array
     */
    public static function resolve_single_user(string $query): array {
        $query = self::sanitize_person_lookup_query($query);
        if ($query === '') {
            return [
                'status' => 'ambiguity',
                'message' => get_string('agent_booking_resolve_user_query_required', 'booking'),
            ];
        }

        // Resolve self-reference keywords to the currently logged-in user.
        $normalizedquery = strtolower(trim((string)$query, " \t\n\r\0\x0B.,;:!?\"'"));
        $normalizedquery = preg_replace('/\s+/', ' ', $normalizedquery) ?? $normalizedquery;
        $selfrefkeywords = [
            '__current_user__',
            'me',
            'myself',
            'i',
            'ich',
            'mich',
            'current',
            'current user',
            'the current user',
            'currentuser',
            'aktueller benutzer',
            'der aktuelle benutzer',
        ];
        if (in_array($normalizedquery, $selfrefkeywords, true)) {
            global $USER;
            if (!empty($USER->id) && !empty($USER->email)) {
                return [
                    'status' => 'ok',
                    'userid' => (int)$USER->id,
                    'email'  => (string)$USER->email,
                ];
            }
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
     * @return array
     */
    public static function resolve_single_course(string $query): array {
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
     *   courseids: array,
     *   shortnames: array,
     *   errors: array,
     *   ambiguities: array
     * }
     */
    public static function resolve_courses_for_restriction(string $rawquery): array {
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
     * @return array
     */
    private static function split_query_list(string $raw): array {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $p): bool => $p !== ''));
    }

    /**
     * Resolve cohort queries to cohort ids.
     *
     * @param string $rawquery
     * @return array
     */
    public static function resolve_cohorts_for_restriction(string $rawquery): array {
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
     * @return array
     */
    public static function resolve_competencies_for_restriction(string $rawquery): array {
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
     * @return array
     */
    public static function resolve_users_for_restriction(string $rawquery): array {
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
     * @return array
     */
    public static function resolve_users_for_booking(string $rawquery): array {
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
     * Confirmation-flow conditions (id <= 1) are not treated as hard blockers;
     * bookit is called twice when needed to progress through them.
     *
     * @param int $optionid
     * @param array $userids
     * @param array $meta
     * @return array{bookeduserids: array, errors: array}
     */
    public static function book_users_for_option(int $optionid, array $userids, array $meta): array {
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

            // Pre-check: only hard blockers that are not confirmation flow steps.
            // Confirmation conditions (confirmbookit, confirmation, confirmaskforconfirmation, etc.)
            // have IDs <= 1 and represent "please confirm" pages, not real blockers.
            // Bookit needs to be called (twice) to progress through them.
            $results = bo_info::get_condition_results((int)$settings->id, $targetuserid, true);
            $hardresults = array_filter($results, function ($r) {
                return (int)($r['id'] ?? 0) > 1;
            });
            if (!empty($hardresults)) {
                $blockersummary = self::summarize_condition_blockers($hardresults);
                $followup = self::blocking_followup_question($hardresults);
                $errors[] = 'User ' . $targetuserid . ' cannot be booked due to blocking conditions '
                    . ': ' . $blockersummary . ' ' . $followup;
                continue;
            }

            // Let bookit handle confirmation flows. Call twice if needed.
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
            get_current_user_task::TASK_NAME,
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
            'optiontype' => ['optiontype', 'mod_booking'],
            'slot_enabled' => ['optiontype_slotbooking', 'mod_booking'],
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
     * Public wrapper for localized property labels used by read-task executor.
     *
     * @param string $propertyname
     * @return string
     */
    public static function get_localized_property_label_for_output(string $propertyname): string {
        return self::get_localized_property_label($propertyname);
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
            create_user_task::TASK_NAME => 'ai_action_create_user',
            update_option_task::TASK_NAME => 'ai_action_update_option',
            search_options_task::TASK_NAME => 'ai_action_search_options',
            search_users_task::TASK_NAME => 'ai_action_search_users',
            search_courses_task::TASK_NAME => 'ai_action_search_courses',
            add_price_category_task::TASK_NAME => 'ai_action_add_price_category',
            list_option_properties_task::TASK_NAME => 'ai_action_list_option_properties',
            list_actions_task::TASK_NAME => 'ai_action_list_actions',
            get_current_user_task::TASK_NAME => 'ai_action_get_current_user',
        ];

        if (isset($map[$taskname])) {
            return get_string($map[$taskname], 'mod_booking');
        }

        return $taskname;
    }

    /**
     * Public wrapper for localized action labels used by read-task executor.
     *
     * @param string $taskname
     * @return string
     */
    public static function get_localized_action_label_for_output(string $taskname): string {
        return self::get_localized_action_label($taskname);
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
     * @return array
     */
    public static function validate_customform_elements(array $elements): array {
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
     * @return array
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
     * @return array
     */
    public static function detect_forbidden_fields_for_bookusers_update(array $input): array {
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
     * @return array|null
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
     * @return array
     */
    public static function validate_prices_input(array $input): array {
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
     * @return array|null
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
     * @param array $newdates
     * @return array
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
     * @param array $optiondates
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
     * @param array $input
     * @return array
     */
    public static function normalize_visibility_input(array $input): array {
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
     * @return array
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
     * @param array $categories
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
    public static function is_last_option_reference(string $query): bool {
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
     * Detect if a query refers to the last preview selection rather than a single option.
     *
     * @param string $query
     * @return bool
     */
    public static function is_last_preview_selection_reference(string $query): bool {
        $q = trim(strtolower($query));
        if ($q === '') {
            return false;
        }

        $patterns = [
            '/\b(all|both|these|those|shown|displayed|found)\b/',
            '/\b(all\s+(two|three|four|five|\d+))\b/',
            '/\b(alle|allen|beide|diese|jene|gezeigten|gefundenen|saemtlichen|sämtlichen)\b/',
            '/\b(alle\s+(zwei|drei|vier|fuenf|fünf|\d+))\b/',
            '/\b(allen\s+(zweien|dreien|vieren|fuenfen|fünfen))\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
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
     * Resolve the last preview option ids remembered for the user in this booking context.
     *
     * @param int $cmid
     * @param int $userid
     * @return int[]
     */
    private static function resolve_last_preview_option_ids_for_user(int $cmid, int $userid): array {
        global $DB;

        if ($cmid <= 0 || $userid <= 0) {
            return [];
        }

        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $cmid);
        if (!$thread) {
            return [];
        }

        $storedids = $store->get_thread_metadata_value((int)$thread->id, self::LAST_PREVIEW_OPTION_IDS_METADATA_KEY);
        if (!is_array($storedids) || empty($storedids)) {
            return [];
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return [];
        }

        $validids = [];
        foreach ($storedids as $storedid) {
            $optionid = (int)$storedid;
            if ($optionid <= 0) {
                continue;
            }
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                $validids[] = $optionid;
            }
        }

        return array_values(array_unique($validids));
    }

    /**
     * Remember the last preview option ids for this user and booking context.
     *
     * @param int $userid
     * @param int $cmid
     * @param array $optionids
     * @return void
     */
    private static function remember_last_preview_options_for_user(int $userid, int $cmid, array $optionids): void {
        if ($userid <= 0 || $cmid <= 0 || empty($optionids)) {
            return;
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return;
        }

        $normalizedids = array_values(array_unique(array_filter(array_map('intval', $optionids))));
        if (empty($normalizedids)) {
            return;
        }

        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $cmid, (int)$cm->instance);
        $store->set_thread_metadata_value((int)$thread->id, self::LAST_PREVIEW_OPTION_IDS_METADATA_KEY, $normalizedids);
        $store->set_thread_metadata_value((int)$thread->id, 'lastpreviewoptionsts', time());
    }

    /**
     * Remap ordinal pseudo ids like [1,2,3] onto the most recent preview option ids.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $requestedids
     * @return int[]
     */
    private static function remap_preview_ordinals_to_option_ids(int $cmid, int $userid, array $requestedids): array {
        $previewids = self::resolve_last_preview_option_ids_for_user($cmid, $userid);
        if (empty($previewids) || empty($requestedids)) {
            return [];
        }

        $mappedids = [];
        foreach ($requestedids as $requestedid) {
            $ordinal = (int)$requestedid;
            if ($ordinal <= 0 || $ordinal > count($previewids)) {
                return [];
            }
            $mappedids[] = (int)$previewids[$ordinal - 1];
        }

        return array_values(array_unique($mappedids));
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
     * Remove privacy display markers and normalize whitespace for person lookups.
     *
     * @param string $query
     * @return string
     */
    private static function sanitize_person_lookup_query(string $query): string {
        $clean = preg_replace('/\s*👤\s*/u', ' ', $query);
        $clean = preg_replace('/\s+/', ' ', (string)$clean);
        return trim((string)$clean, " \t\n\r\0\x0B.,;:!?\"'");
    }

    /**
     * Public wrapper for option links used by read-task executor.
     *
     * @param int $cmid
     * @param int $optionid
     * @return string
     */
    public static function build_option_link_for_output(int $cmid, int $optionid): string {
        return self::build_option_link($cmid, $optionid);
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

    /**
     * Execute wrapper for price normalization.
     *
     * @param mixed $prices
     * @return array|null
     */
    public static function normalize_prices_input_for_execute($prices): ?array {
        return self::normalize_prices_input($prices);
    }

    /**
     * Execute wrapper for customform element normalization.
     *
     * @param array $elements
     * @return array
     */
    public static function normalize_customform_elements_for_execute(array $elements): array {
        return self::normalize_customform_elements($elements);
    }

    /**
     * Execute wrapper for resolving bulk target ids.
     *
     * @param int $cmid
     * @param array $input
     * @param int $userid
     * @return int[]
     */
    public static function resolve_bulk_option_ids_for_execute(int $cmid, array $input, int $userid = 0): array {
        return self::resolve_bulk_option_ids($cmid, $input, $userid);
    }

    /**
     * Execute wrapper for "last option" resolution.
     *
     * @param int $cmid
     * @param int $userid
     * @return int|null
     */
    public static function resolve_last_option_for_user_for_execute(int $cmid, int $userid): ?int {
        return self::resolve_last_option_for_user($cmid, $userid);
    }

    /**
     * Execute wrapper for last-option metadata persistence.
     *
     * @param int $userid
     * @param int $cmid
     * @param int $optionid
     * @param int $bookingid
     * @return void
     */
    public static function remember_last_option_for_user_for_execute(
        int $userid,
        int $cmid,
        int $optionid,
        int $bookingid
    ): void {
        self::remember_last_option_for_user($userid, $cmid, $optionid, $bookingid);
    }

    /**
     * Execute wrapper for preview-option metadata persistence.
     *
     * @param int $userid
     * @param int $cmid
     * @param array $optionids
     * @return void
     */
    public static function remember_last_preview_options_for_user_for_execute(int $userid, int $cmid, array $optionids): void {
        self::remember_last_preview_options_for_user($userid, $cmid, $optionids);
    }

    /**
     * Execute wrapper for resolving last preview option ids.
     *
     * @param int $cmid
     * @param int $userid
     * @return int[]
     */
    public static function resolve_last_preview_option_ids_for_user_for_execute(int $cmid, int $userid): array {
        return self::resolve_last_preview_option_ids_for_user($cmid, $userid);
    }

    /**
     * Execute wrapper for append-date merge.
     *
     * @param int $optionid
     * @param array $newdates
     * @return array
     */
    public static function merge_existing_optiondates_with_new_for_execute(int $optionid, array $newdates): array {
        return self::merge_existing_optiondates_with_new($optionid, $newdates);
    }

    /**
     * Execute wrapper for applying optiondates payload.
     *
     * @param \stdClass $data
     * @param array $optiondates
     * @return void
     */
    public static function apply_optiondates_to_update_data_for_execute(\stdClass $data, array $optiondates): void {
        self::apply_optiondates_to_update_data($data, $optiondates);
    }

    /**
     * Execute wrapper for post-apply verification.
     *
     * @param string $taskname
     * @param array $input
     * @param int $optionid
     * @return array
     */
    public static function verify_persisted_option_state_for_task_for_execute(
        string $taskname,
        array $input,
        int $optionid
    ): array {
        return self::verify_persisted_option_state_for_task($taskname, $input, $optionid);
    }

    /**
     * Execute wrapper for booking users through booking_bookit.
     *
     * @param int $optionid
     * @param array $userids
     * @param array $meta
     * @return array
     */
    public static function book_users_via_bookit_for_execute(int $optionid, array $userids, array $meta): array {
        return self::book_users_for_option($optionid, $userids, $meta);
    }
}
