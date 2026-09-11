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

namespace mod_booking\local\wizard\booking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Global MOD_BOOKING_* constants — not loaded on non-booking pages (e.g. the
// dashboard agent entry point, thread 322), so pull them in explicitly.
require_once($CFG->dirroot . '/mod/booking/lib.php');

use context_module;
use mod_booking\local\wizard\engine\skill_interface;
use mod_booking\local\wizard\engine\attachment_resolver;
use mod_booking\local\wizard\engine\thread_memory;
use mod_booking\local\wizard\engine\skill_catalog;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\option\fields_info;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;

/**
 * Domain support for booking-related AI tasks.
 */
class booking_skill_support {
    /** @var string Thread metadata key for the last preview option ids. */
    private const LAST_PREVIEW_OPTION_IDS_METADATA_KEY = 'lastpreviewoptionids';

    /** @var array|null */
    private ?array $taskinstancescache = null;

    /** @var attachment_resolver|null Engine attachment resolver (injected by base_skill; used by the instance execute()). */
    private ?attachment_resolver $attachments;

    /** @var thread_memory|null Engine per-thread key/value memory (static: the consumer wrappers are static). */
    private static ?thread_memory $enginethreadmemory = null;

    /** @var skill_catalog|null Engine skill catalog (static: the consumer enumeration is static). */
    private static ?skill_catalog $enginecatalog = null;

    /**
     * Engine services are injected by the booking skill base (base_skill accessors), so this
     * support helper never names a concrete engine class and stays engine-agnostic. thread_memory
     * and skill_catalog feed static helpers, so they are kept in static holders populated here.
     *
     * @param attachment_resolver|null $attachments
     * @param thread_memory|null $threadmemory
     * @param skill_catalog|null $catalog
     */
    public function __construct(
        ?attachment_resolver $attachments = null,
        ?thread_memory $threadmemory = null,
        ?skill_catalog $catalog = null
    ) {
        $this->attachments = $attachments;
        if ($threadmemory !== null) {
            self::$enginethreadmemory = $threadmemory;
        }
        if ($catalog !== null) {
            self::$enginecatalog = $catalog;
        }
    }

    /**
     * Resolve a localized string with optional fixed language.
     *
     * @param string $key
     * @param string $component
     * @param string $lang
     * @return string
     */
    private static function resolve_string(string $key, string $component, string $lang = ''): string {
        if ($lang === '') {
            return get_string($key, $component);
        }

        return get_string_manager()->get_string($key, $component, null, $lang);
    }

    /**
     * Resolve booking module context id from a course-module id.
     *
     * @param int $cmid
     * @return int
     */
    private static function resolve_contextid_from_cmid(int $cmid): int {
        if ($cmid <= 0) {
            return 0;
        }

        return (int)context_module::instance($cmid, MUST_EXIST)->id;
    }

    /**
     * Return the task names this provider handles.
     *
     * @return string[]
     */
    public function get_skill_names(): array {
        $names = array_keys($this->get_skill_instances());

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

        foreach ($this->get_skill_instances() as $task) {
            if (!$task instanceof skill_interface || !method_exists($task, 'get_contextual_prompt_packs')) {
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
    public function get_skill_schema(string $taskname): array {
        if (!$this->has_skill_name($taskname)) {
            return [];
        }

        $task = $this->get_skill_instances()[$taskname] ?? null;
        return $task ? (array)$task->get_schema() : [];
    }

    /**
     * Run structural-only validation for a task input payload.
     *
     * @param string $taskname
     * @param array  $input
     * @param int    $cmid
     * @return array
     */
    public function check_structure(string $taskname, array $input, int $cmid): array {
        $task = $this->get_skill_instances()[$taskname] ?? null;
        if (!$task) {
            return [
                'valid' => false,
                'errors' => ['Unknown task: ' . $taskname],
                'ambiguities' => [],
                'issue_codes' => ['CONTRACT_TASK_NOT_FOUND'],
            ];
        }

        $structure = $task->check_structure($input);
        return [
            'valid' => (bool)($structure['valid'] ?? false),
            'errors' => array_values(array_unique(array_map('strval', (array)($structure['errors'] ?? [])))),
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique(array_map('strval', (array)($structure['issue_codes'] ?? [])))),
        ];
    }

    /**
     * Return instantiated booking task classes keyed by task name.
     *
     * @return array
     */
    private function get_skill_instances(): array {
        if ($this->taskinstancescache !== null) {
            return $this->taskinstancescache;
        }

        $this->taskinstancescache = self::$enginecatalog?->instances('mod_booking') ?? [];
        return $this->taskinstancescache;
    }

    /**
     * True when the given task name is registered by a task class.
     *
     * @param string $taskname
     * @return bool
     */
    private function has_skill_name(string $taskname): bool {
        return array_key_exists($taskname, $this->get_skill_instances());
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
        $mutationservice = new booking_skill_mutation_execute_service($this->attachments);
        $result = $mutationservice->execute($taskname, $input, $cmid, $userid, $this);
        if ($result !== null) {
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => get_string('agent_booking_unknown_task', 'booking', $taskname),
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
    private static function verify_persisted_option_state_for_skill(string $taskname, array $input, int $optionid): array {
        if ($optionid <= 0) {
            return [];
        }

        try {
            $support = new self();
            $task = $support->get_skill_instances()[$taskname] ?? null;
            if (!$task || !method_exists($task, 'verify_persisted_option_state')) {
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

        if (self::has_any_input_key($input, ['invisible', 'visibility', 'visible'])) {
            $register(MOD_BOOKING_OPTION_FIELD_INVISIBLE, get_string('optionvisibility', 'booking'));
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

        // A bare clock time carries no date. new \DateTime("10:00") would silently anchor it to
        // TODAY (thread 545: five "Tag 1–5" sessions all landed on the execution day) — refuse it
        // so validation asks for the date instead. Relative phrases ("next monday 10:00") and
        // full datetimes are unaffected.
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim($value))) {
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
     * Normalize datetime-ish values for stable queue identity hashing.
     *
     * @param string $value
     * @return string
     */
    public static function normalize_identity_datetime(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $value = str_replace(' ', 'T', (string)$value);
        return strtolower((string)$value);
    }

    /**
     * Normalize temporal input values to canonical formats used by mutation flows.
     *
     * - datetime fields are normalized to UNIX timestamps when parseable.
     * - slot clock fields are normalized to HH:MM (accepts HH:MM, HH:MM:SS, or minutes since midnight).
     *
     * @param array $input
     * @return array
     */
    public static function normalize_temporal_input(array $input): array {
        $normalized = $input;

        $datetimefields = [
            'coursestarttime',
            'courseendtime',
            'bookingopeningtime',
            'bookingclosingtime',
            'slot_valid_from',
            'slot_valid_until',
            'bookuserstimebooked',
        ];

        foreach ($datetimefields as $field) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }
            $parsed = self::parse_datetime($normalized[$field]);
            if ($parsed !== false) {
                $normalized[$field] = $parsed;
            }
        }

        foreach (['slot_opening_time', 'slot_closing_time'] as $clockfield) {
            if (!array_key_exists($clockfield, $normalized)) {
                continue;
            }
            $clock = self::normalize_clock_time_value($normalized[$clockfield]);
            if ($clock !== null) {
                $normalized[$clockfield] = $clock;
            }
        }

        if (!empty($normalized['optiondates']) && is_array($normalized['optiondates'])) {
            $optiondates = [];
            foreach ($normalized['optiondates'] as $item) {
                if (!is_array($item)) {
                    $optiondates[] = $item;
                    continue;
                }
                if (array_key_exists('coursestarttime', $item)) {
                    $parsed = self::parse_datetime($item['coursestarttime']);
                    if ($parsed !== false) {
                        $item['coursestarttime'] = $parsed;
                    }
                }
                if (array_key_exists('courseendtime', $item)) {
                    $parsed = self::parse_datetime($item['courseendtime']);
                    if ($parsed !== false) {
                        $item['courseendtime'] = $parsed;
                    }
                }
                $optiondates[] = $item;
            }
            $normalized['optiondates'] = $optiondates;
        }

        return $normalized;
    }

    /**
     * Normalize a clock-time value to HH:MM.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function normalize_clock_time_value(mixed $value): ?string {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
            $minutes = (int)$value;
            if ($minutes >= 0 && $minutes < 24 * 60) {
                $hours = intdiv($minutes, 60);
                $mins = $minutes % 60;
                return sprintf('%02d:%02d', $hours, $mins);
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $time = trim($value);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches) === 1) {
            $hours = (int)$matches[1];
            $mins = (int)$matches[2];
            if ($hours >= 0 && $hours <= 23 && $mins >= 0 && $mins <= 59) {
                return sprintf('%02d:%02d', $hours, $mins);
            }
        }

        return null;
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
        global $DB;

        $query = self::sanitize_person_lookup_query($optionquery);
        if ($query === '') {
            return [
                'status' => 'ambiguity',
                'issue_code' => 'OPTION_QUERY_REQUIRED',
                'message' => 'Please provide optionquery to identify the option.',
            ];
        }

        if (preg_match('/^\d+$/', $query)) {
            $cm = get_coursemodule_from_id('booking', $cmid);
            if ($cm && $DB->record_exists('booking_options', ['id' => (int)$query, 'bookingid' => (int)$cm->instance])) {
                return [
                    'status' => 'ok',
                    'optionid' => (int)$query,
                ];
            }
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
                'issue_code' => 'OPTION_AMBIGUOUS',
                'message' => 'Multiple options matched: ' . (string)($exact['candidates'] ?? '')
                    . '. Please provide optionid.',
            ];
        }

        $rows = self::search_option_candidates($cmid, $query, 5, $when);
        if (empty($rows)) {
            return [
                'status' => 'error',
                'issue_code' => 'OPTION_NOT_FOUND',
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
                'issue_code' => 'OPTION_AMBIGUOUS',
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
     * Resolve the context a booking rule lives in — the deterministic rule → context mapping
     * of the rule_targeted_skill trait.
     *
     * A rule is a context-bound row (booking_rules.contextid, frequently the SYSTEM context):
     * it pins its own operating context the way an option pins its activity, so asking "which
     * booking activity?" for a rule request is the wrong question (thread 584: three identical
     * activity lists for a rename of a system rule). Matches by id, or by name against BOTH the
     * technical rulename and the display name in rulejson (exact, case-insensitive).
     *
     * @param int $ruleid Explicit rule id (takes precedence).
     * @param string $rulequery Rule name (or numeric id as string).
     * @return int The rule's contextid, or 0 when no rule matches or the matches span
     *             several contexts (the skill's preflight then disambiguates by rule id).
     */
    public static function context_for_rule(int $ruleid, string $rulequery): int {
        global $DB;

        if ($ruleid > 0) {
            return (int)($DB->get_field('booking_rules', 'contextid', ['id' => $ruleid]) ?: 0);
        }

        $query = \core_text::strtolower(trim($rulequery));
        if ($query === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $query)) {
            return self::context_for_rule((int)$query, '');
        }

        $contextids = [];
        $records = $DB->get_records('booking_rules', [], '', 'id, rulename, rulejson, contextid');
        foreach ($records as $record) {
            $rulename = \core_text::strtolower(trim((string)($record->rulename ?? '')));
            $json = json_decode((string)($record->rulejson ?? '{}'));
            $displayname = is_object($json)
                ? \core_text::strtolower(trim((string)($json->name ?? '')))
                : '';
            if ($query === $rulename || ($displayname !== '' && $query === $displayname)) {
                $contextids[(int)$record->contextid] = true;
            }
        }

        return count($contextids) === 1 ? (int)array_key_first($contextids) : 0;
    }

    /**
     * Resolve the course-module id of the booking activity that owns an option.
     *
     * This is the deterministic option → activity mapping the target-context contract needs:
     * an option unambiguously identifies its booking instance, so a mutating skill can name the
     * option (optionid) and the engine can operate at that instance without any ambient context.
     * See the option_targeted_skill trait.
     *
     * @param int $optionid
     * @return int The cmid, or 0 when the option (or its module) does not exist.
     */
    public static function cmid_for_option(int $optionid): int {
        global $DB;

        if ($optionid <= 0) {
            return 0;
        }
        $sql = "SELECT cm.id
                  FROM {booking_options} bo
                  JOIN {booking} b ON b.id = bo.bookingid
                  JOIN {modules} m ON m.name = 'booking'
                  JOIN {course_modules} cm ON cm.instance = b.id AND cm.module = m.id
                 WHERE bo.id = :optionid";
        $cmid = $DB->get_field_sql($sql, ['optionid' => $optionid]);
        return $cmid ? (int)$cmid : 0;
    }

    /**
     * Resolve the booking activity for an option named by a site-wide text query.
     *
     * Used by the option_targeted_skill trait when a skill names an option (optionquery) but the
     * ambient context is not a booking activity (e.g. an MCP session at the system context). It
     * determines only the *activity* (cmid); the exact option is resolved later, in-instance, by
     * the skill's own preflight (resolve_single_option). Matching is by exact title first, then a
     * LIKE fallback; the distinct owning activities decide the outcome:
     *
     * - exactly one activity  → ['status' => 'ok', 'cmid' => int]
     * - several activities     → ['status' => 'ambiguous', 'candidates' => object[]] (option label,
     *                            cmid, course) so the caller can ask which one
     * - none                   → ['status' => 'not_found']
     *
     * A numeric query is treated as an optionid (delegates to cmid_for_option).
     *
     * @param string $query
     * @param string $when Optional temporal hint (currently unused site-wide; reserved).
     * @return array
     */
    public static function activity_for_option_query(string $query, string $when = ''): array {
        global $DB;

        $query = self::sanitize_person_lookup_query($query);
        if ($query === '') {
            return ['status' => 'not_found'];
        }

        if (preg_match('/^\d+$/', $query)) {
            $cmid = self::cmid_for_option((int)$query);
            return $cmid > 0 ? ['status' => 'ok', 'cmid' => $cmid] : ['status' => 'not_found'];
        }

        $select = "SELECT bo.id AS optionid, bo.text, cm.id AS cmid, c.id AS courseid, c.fullname AS coursename
                     FROM {booking_options} bo
                     JOIN {booking} b ON b.id = bo.bookingid
                     JOIN {course} c ON c.id = b.course
                     JOIN {modules} m ON m.name = 'booking'
                     JOIN {course_modules} cm ON cm.instance = b.id AND cm.module = m.id
                    WHERE ";

        // Exact (case-insensitive) title first; fall back to a LIKE match only when nothing matched.
        $rows = $DB->get_records_sql(
            $select . $DB->sql_equal('bo.text', ':title', false, false),
            ['title' => $query],
            0,
            50
        );
        if (empty($rows)) {
            $rows = $DB->get_records_sql(
                $select . $DB->sql_like('bo.text', ':title', false),
                ['title' => '%' . $DB->sql_like_escape($query) . '%'],
                0,
                50
            );
        }

        if (empty($rows)) {
            return ['status' => 'not_found'];
        }

        $bycmid = [];
        foreach ($rows as $row) {
            $bycmid[(int)$row->cmid][] = $row;
        }
        if (count($bycmid) === 1) {
            return ['status' => 'ok', 'cmid' => (int)array_key_first($bycmid)];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = (object)[
                'optionid' => (int)$row->optionid,
                'text' => (string)$row->text,
                'cmid' => (int)$row->cmid,
                'coursename' => (string)$row->coursename,
            ];
        }
        return ['status' => 'ambiguous', 'candidates' => $candidates];
    }

    /**
     * Search users through the existing external search_users implementation.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    private static function search_user_candidates(string $query, int $limit = 10): array {
        global $CFG;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            require_once($CFG->libdir . '/datalib.php');

            if (preg_match('/^\d+$/', $query)) {
                $user = \core_user::get_user((int)$query, 'id, firstname, lastname, email, deleted', IGNORE_MISSING);
                if ($user && !empty($user->id) && self::is_assignable_person($user)) {
                    return [[
                        'userid' => (int)$user->id,
                        'firstname' => (string)($user->firstname ?? ''),
                        'lastname' => (string)($user->lastname ?? ''),
                        'email' => (string)($user->email ?? ''),
                    ]];
                }
            }

            $result = search_users(0, 0, $query, 'lastname ASC, firstname ASC, id ASC');
        } catch (\Throwable $e) {
            return [];
        }

        $list = is_array($result) ? $result : [];
        $normalized = [];
        foreach ($list as $user) {
            $userid = (int)($user->id ?? $user['id'] ?? 0);
            // Core search_users filters deleted accounts but NOT the guest ("Guest user"
            // LIKE-matches queries containing "user") — never offer it as a person candidate.
            if ($userid <= 0 || isguestuser($userid)) {
                continue;
            }
            $normalized[] = [
                'userid' => $userid,
                'firstname' => (string)($user->firstname ?? $user['firstname'] ?? ''),
                'lastname' => (string)($user->lastname ?? $user['lastname'] ?? ''),
                'email' => (string)($user->email ?? $user['email'] ?? ''),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Search courses through booking::load_courses (the search_courses external's backend).
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    private static function search_course_candidates(string $query, int $limit = 10): array {
        global $DB;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            // Call the search directly instead of the external wrapper: the legacy external
            // class file-scope-includes externallib.php, which is illegal to autoload in a
            // non-isolated PHPUnit process (and its swallowed exception silently emptied every
            // course resolution in tests). The wrapper adds nothing but parameter validation.
            $result = booking::load_courses($query);
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
            $courseurl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
            $normalized[] = [
                'courseid' => $courseid,
                'fullname' => (string)($course->fullname ?? $course['fullname'] ?? ''),
                'shortname' => (string)($course->shortname ?? $course['shortname'] ?? ''),
                'courseurl' => $courseurl,
                'activeenrolledcount' => self::count_active_course_enrolments($courseid),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Count active enrolled users for a course.
     *
     * @param int $courseid
     * @return int
     */
    private static function count_active_course_enrolments(int $courseid): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        $now = time();
        // Moodle DML requires every named parameter to be unique, so :now is bound twice.
        $sql = "SELECT COUNT(DISTINCT ue.userid)\n"
            . "  FROM {user_enrolments} ue\n"
            . "  JOIN {enrol} e ON e.id = ue.enrolid\n"
            . "  JOIN {user} u ON u.id = ue.userid\n"
            . " WHERE e.courseid = :courseid\n"
            . "   AND e.status = :enrolstatus\n"
            . "   AND ue.status = :uestatus\n"
            . "   AND ue.timestart <= :nowstart\n"
            . "   AND (ue.timeend = 0 OR ue.timeend > :nowend)\n"
            . "   AND u.deleted = 0\n"
            . "   AND u.suspended = 0";

        return (int)$DB->count_records_sql($sql, [
            'courseid' => $courseid,
            'enrolstatus' => ENROL_INSTANCE_ENABLED,
            'uestatus' => ENROL_USER_ACTIVE,
            'nowstart' => $now,
            'nowend' => $now,
        ]);
    }

    /**
     * Resolve a single user id by query.
     *
     * @param string $query
     * @return array
     */
    public static function resolve_single_user(string $query): array {
        global $DB;

        $query = self::sanitize_person_lookup_query($query);
        if ($query === '') {
            return [
                'status' => 'ambiguity',
                'issue_code' => 'USER_QUERY_REQUIRED',
                'message' => get_string('agent_booking_resolve_user_query_required', 'booking'),
            ];
        }

        // An anonymization placeholder must never reach user matching: it is not a person
        // reference, and its word parts (e.g. "user" in "ANON_USER_1_both") can LIKE-match
        // unrelated accounts — observed live 2026-07-14: an unresolved placeholder ended in a
        // "provide the numeric user ID" clarification whose guessed answer assigned the GUEST
        // as trainer. Fail with a clean re-ask instead of resolving somebody wrong.
        if (self::is_unresolved_privacy_placeholder($query)) {
            return [
                'status' => 'error',
                'issue_code' => 'USER_REFERENCE_UNRESOLVED',
                'message' => get_string('agent_booking_resolve_user_reference_unresolved', 'booking'),
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

        if (preg_match('/^\d+$/', $query)) {
            $user = \core_user::get_user((int)$query, 'id, email, deleted', IGNORE_MISSING);
            if ($user && !empty($user->id) && self::is_assignable_person($user)) {
                return [
                    'status' => 'ok',
                    'userid' => (int)$user->id,
                    'email' => (string)$user->email,
                ];
            }
        }

        if (strpos($query, '@') !== false) {
            $user = \core_user::get_user_by_email($query, 'id, email, deleted', null, IGNORE_MISSING);
            if ($user && !empty($user->id) && self::is_assignable_person($user)) {
                return [
                    'status' => 'ok',
                    'userid' => (int)$user->id,
                    'email' => (string)$user->email,
                ];
            }
        }

        $users = self::search_user_candidates($query, 5);
        if (empty($users)) {
            // Fallback: direct name/username lookup when directory search returns no hits.
            $namequery = trim($query);
            if ($namequery !== '') {
                $matches = self::search_user_candidates($namequery, 6);
                if (count($matches) === 1) {
                    $match = $matches[0];
                    return [
                        'status' => 'ok',
                        'userid' => (int)$match['userid'],
                        'email' => (string)$match['email'],
                    ];
                }

                if (count($matches) > 1) {
                    $candidates = [];
                    foreach ($matches as $match) {
                        $fullname = trim((string)($match['firstname'] ?? '') . ' ' . (string)($match['lastname'] ?? ''));
                        $candidates[] = (int)$match['userid'] . ' (' . $fullname . ', ' . (string)$match['email'] . ')';
                    }

                    return [
                        'status' => 'ambiguity',
                        'issue_code' => 'USER_AMBIGUOUS',
                        'message' => get_string(
                            'agent_booking_resolve_user_ambiguous',
                            'booking',
                            implode(', ', $candidates)
                        ),
                    ];
                }
            }

            return [
                'status' => 'error',
                'issue_code' => 'USER_NOT_FOUND',
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
                'issue_code' => 'USER_AMBIGUOUS',
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
                'errors' => [get_string('agent_booking_enrolledincoursequery_required', 'booking')],
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
                    $errors[] = get_string('agent_booking_course_no_shortname', 'booking', $part);
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
        $ids = [];
        $errors = [];
        $ambiguities = [];
        if (empty($parts)) {
            return [
                'cohortids' => [],
                'errors' => ['Please provide enrolledincohortquery.'],
                'ambiguities' => [],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }

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
                $errors[] = get_string('agent_booking_cohort_no_match', 'booking', $part);
                continue;
            }
            if (count($matches) > 1) {
                $cands = [];
                foreach ($matches as $m) {
                    $cands[] = (int)$m->id . ' (' . (string)$m->name . ', ' . (string)$m->idnumber . ')';
                }
                $ambiguities[] = get_string('agent_booking_cohort_multiple_match', 'booking', (object)[
                    'query' => $part,
                    'candidates' => implode(', ', $cands),
                ]);
                continue;
            }

            $ids[] = (int)reset($matches)->id;
        }

        return [
            'cohortids' => array_values(array_unique($ids)),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issue_codes' => !empty($errors) ? ['RECOVERABLE_INPUT_ERROR'] : [],
        ];
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
        $ids = [];
        $errors = [];
        $ambiguities = [];
        if (empty($parts)) {
            return [
                'competencyids' => [],
                'errors' => [get_string('agent_booking_hascompetencyquery_required', 'booking')],
                'ambiguities' => [],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }

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
                $errors[] = get_string('agent_booking_competency_no_match', 'booking', $part);
                continue;
            }
            if (count($matches) > 1) {
                $cands = [];
                foreach ($matches as $m) {
                    $cands[] = (int)$m->id . ' (' . (string)$m->shortname . ')';
                }
                $ambiguities[] = get_string('agent_booking_competency_multiple_match', 'booking', (object)[
                    'query' => $part,
                    'candidates' => implode(', ', $cands),
                ]);
                continue;
            }

            $ids[] = (int)reset($matches)->id;
        }

        return [
            'competencyids' => array_values(array_unique($ids)),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issue_codes' => !empty($errors) ? ['RECOVERABLE_INPUT_ERROR'] : [],
        ];
    }

    /**
     * Resolve user query list to explicit user ids.
     *
     * @param string $rawquery
     * @return array
     */
    public static function resolve_users_for_restriction(string $rawquery): array {
        $parts = self::split_query_list($rawquery);
        $ids = [];
        $errors = [];
        $ambiguities = [];
        if (empty($parts)) {
            return [
                'userids' => [],
                'errors' => ['Please provide selectusersquery.'],
                'ambiguities' => [],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }

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

        return [
            'userids' => array_values(array_unique($ids)),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issue_codes' => !empty($errors) ? ['RECOVERABLE_INPUT_ERROR'] : [],
        ];
    }

    /**
     * Resolve user query list to bookable users (ids + emails).
     *
     * @param string $rawquery
     * @return array
     */
    public static function resolve_users_for_booking(string $rawquery): array {
        $parts = self::split_query_list($rawquery);
        $userids = [];
        $emails = [];
        $errors = [];
        $ambiguities = [];
        $issuecodes = [];
        if (empty($parts)) {
            return [
                'userids' => [],
                'emails' => [],
                'errors' => ['Please provide bookusersquery.'],
                'ambiguities' => [],
                'issue_codes' => ['BOOK_USERS_USER_QUERY_REQUIRED'],
                'issues' => [[
                    'code' => 'BOOK_USERS_USER_QUERY_REQUIRED',
                    'severity' => 'needs_clarification',
                    'message' => 'Please provide bookusersquery.',
                ]],
            ];
        }

        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part)) {
                $userid = (int)$part;
                $user = singleton_service::get_instance_of_user($userid);
                if (empty($user) || empty($user->id) || empty($user->email)) {
                    $errors[] = get_string('agent_booking_user_id_no_email', 'booking', $part);
                    $issuecodes[] = 'BOOK_USERS_USER_EMAIL_MISSING';
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
                    $errors[] = get_string('agent_booking_user_no_email', 'booking', $part);
                    $issuecodes[] = 'BOOK_USERS_USER_EMAIL_MISSING';
                    continue;
                }
                $emails[] = $email;
            } else if (($resolved['status'] ?? '') === 'ambiguity') {
                $ambiguities[] = (string)$resolved['message'];
                $issuecodes[] = (string)($resolved['issue_code'] ?? 'USER_AMBIGUOUS');
            } else {
                $errors[] = (string)$resolved['message'];
                $issuecodes[] = (string)($resolved['issue_code'] ?? 'USER_NOT_FOUND');
            }
        }

        $issues = [];
        foreach ($errors as $msg) {
            $msg = trim((string)$msg);
            if ($msg === '') {
                continue;
            }
            $issues[] = [
                'code' => 'BOOK_USERS_USER_RESOLVE_ERROR',
                'severity' => 'needs_clarification',
                'message' => $msg,
            ];
        }
        foreach ($ambiguities as $msg) {
            $msg = trim((string)$msg);
            if ($msg === '') {
                continue;
            }
            $issues[] = [
                'code' => 'BOOK_USERS_USER_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => $msg,
            ];
        }

        return [
            'userids' => array_values(array_unique($userids)),
            'emails' => array_values(array_unique($emails)),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issue_codes' => array_values(array_unique(array_filter(array_map('strval', $issuecodes)))),
            'issues' => $issues,
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
        $issuecodes = [];

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings) || empty($settings->id)) {
            return [
                'bookeduserids' => [],
                'errors' => [get_string('agent_booking_option_resolve_settings_failed', 'booking', $optionid)],
                'issue_codes' => ['BOOK_USERS_OPTION_NOT_FOUND'],
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
                $errors[] = get_string('agent_booking_user_cannot_book_blocked', 'booking', (object)[
                    'userid' => $targetuserid,
                    'conditions' => $blockersummary,
                    'followup' => $followup,
                ]);
                $issuecodes[] = 'BOOK_USERS_NOT_ALLOWED';
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
                    $errors[] = get_string('agent_booking_user_cannot_book_error_rollback', 'booking', (object)[
                        'userid' => $targetuserid,
                        'message' => $message,
                        'conditions' => $blockersummary,
                        'followup' => $followup,
                    ]);
                    $issuecodes[] = 'BOOK_USERS_NOT_ALLOWED';
                } else {
                    $errors[] = get_string('agent_booking_user_cannot_book_error', 'booking', (object)[
                        'userid' => $targetuserid,
                        'message' => $message,
                    ]);
                    $issuecodes[] = 'BOOK_USERS_BOOKING_DENIED';
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
                    $errors[] = get_string('agent_booking_user_booked_completion_failed', 'booking', (object)[
                        'userid' => $targetuserid,
                        'error' => $e->getMessage(),
                    ]);
                    $issuecodes[] = 'BOOK_USERS_COMPLETION_UPDATE_FAILED';
                }
            }
        }

        return [
            'bookeduserids' => array_values(array_unique($bookeduserids)),
            'errors' => $errors,
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * True if task is read-only and should be executed without confirmation.
     *
     * @param string $taskname
     * @return bool
     */
    public static function is_read_only_skill(string $taskname): bool {
        $support = new self();
        $task = $support->get_skill_instances()[$taskname] ?? null;
        return $task instanceof skill_interface ? $task->is_read_only() : false;
    }

    /**
     * Return a localized label for a schema property.
     *
     * @param string $propertyname
     * @param string $lang
     * @return string
     */
    private static function get_localized_property_label(string $propertyname, string $lang = ''): string {
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
            'invisible' => ['optionvisibility', 'booking'],
            'visibility' => ['optionvisibility', 'booking'],
            'visible' => ['optionvisibility', 'booking'],
            'duration' => ['duration', 'booking'],
            'coursequery' => ['associatedcourse', 'booking'],
            'prices' => ['price', 'booking'],
            'bookusersquery' => ['bookusers', 'booking'],
            'optionid' => ['ai_property_optionid', 'booking'],
            'optionquery' => ['ai_property_optionquery', 'booking'],
            'optionwhen' => ['ai_property_optionwhen', 'booking'],
            'teacherquery' => ['ai_property_teacherquery', 'booking'],
            'teacheremail' => ['ai_property_teacheremail', 'booking'],
            'optiontype' => ['optiontype', 'booking'],
            'slot_enabled' => ['optiontype_slotbooking', 'booking'],
            'selflearningcourse' => ['ai_property_selflearningcourse', 'booking'],
            'bookuserscompleted' => ['ai_property_bookuserscompleted', 'booking'],
            'bookuserstimebooked' => ['ai_property_bookuserstimebooked', 'booking'],
            'bookusersupdateexisting' => ['ai_property_bookusersupdateexisting', 'booking'],
            'customformjson' => ['ai_property_customformjson', 'booking'],
            'customformelements' => ['ai_property_customformelements', 'booking'],
            'customformdeleteinfoscheckboxadmin' => ['ai_property_customformdeleteinfoscheckboxadmin', 'booking'],
        ];

        if (isset($exactmap[$propertyname])) {
            [$key, $component] = $exactmap[$propertyname];
            return self::resolve_string($key, $component, $lang);
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

            $base = self::resolve_string(
                $stringkey,
                'booking',
                $lang
            );
            $suffix = substr($propertyname, strlen($prefix));
            $suffixlabel = self::get_localized_property_suffix_label($suffix, $lang);
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
     * Public wrapper for localized property labels in a fixed language.
     *
     * @param string $propertyname
     * @param string $lang
     * @return string
     */
    public static function get_localized_property_label_for_output_in_language(
        string $propertyname,
        string $lang = 'en'
    ): string {
        return self::get_localized_property_label($propertyname, $lang);
    }

    /**
     * Return a localized label for a property suffix.
     *
     * @param string $suffix
     * @param string $lang
     * @return string
     */
    private static function get_localized_property_suffix_label(string $suffix, string $lang = ''): string {
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
            return self::resolve_string($map[$normalized], 'booking', $lang);
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
            $errors[] = get_string('agent_booking_customformelements_max', 'booking');
            return ['errors' => $errors];
        }

        foreach ($elements as $idx => $element) {
            $n = $idx + 1;
            if (!is_array($element)) {
                $errors[] = get_string('agent_booking_customformelement_not_object', 'booking', $n);
                continue;
            }

            $formtype = trim((string)($element['formtype'] ?? ''));
            if ($formtype === '' || !in_array($formtype, $allowed, true)) {
                $errors[] = get_string('agent_booking_customformelement_invalid_formtype', 'booking', (object)[
                    'n' => $n,
                    'types' => implode(', ', $allowed),
                ]);
                continue;
            }

            if ($formtype !== 'deleteinfoscheckboxuser') {
                $label = trim((string)($element['label'] ?? ''));
                if ($label === '') {
                    $errors[] = get_string('agent_booking_customformelement_label_required', 'booking', (object)[
                        'n' => $n,
                        'formtype' => $formtype,
                    ]);
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
     * F3-W2 two-channel contract: 'errors' stays the legacy channel (byte-identical for
     * existing callers); 'error_details' carries one entry per error with a user-facing
     * 'user_cause' (plain-English LLM material — the synchronizer formulates the reply)
     * and a planner-only 'repair' instruction (format specs / JSON examples). Entries
     * whose legacy text is already user-appropriate keep it as user_cause with no repair.
     *
     * @param array $input
     * @return array
     */
    public static function validate_prices_input(array $input): array {
        $errors = [];
        $ambiguities = [];
        $errordetails = [];

        if (!array_key_exists('prices', $input) || $input['prices'] === null) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities, 'error_details' => $errordetails];
        }

        $prices = self::normalize_prices_input($input['prices']);
        if ($prices === null) {
            $errors[] = get_string('agent_booking_prices_not_object', 'booking');
            $errordetails[] = [
                'user_cause' => 'The price could not be understood in the form it was given. '
                    . 'Please state the price again, optionally per price category.',
                'repair' => get_string('agent_booking_prices_not_object', 'booking'),
            ];
            return ['errors' => $errors, 'ambiguities' => $ambiguities, 'error_details' => $errordetails];
        }

        if (empty($prices)) {
            $errors[] = get_string('agent_booking_prices_empty', 'booking');
            $errordetails[] = [
                'user_cause' => 'No usable price was provided. Please state the price.',
                'repair' => get_string('agent_booking_prices_empty', 'booking'),
            ];
            return ['errors' => $errors, 'ambiguities' => $ambiguities, 'error_details' => $errordetails];
        }

        $categories = self::get_price_categories_by_identifier();
        $unknown = [];
        foreach ($prices as $identifier => $value) {
            if (!isset($categories[strtolower($identifier)]) || (int)$categories[strtolower($identifier)]->disabled === 1) {
                $unknown[] = $identifier;
            }
            if (!is_numeric($value)) {
                $errors[] = get_string('agent_booking_price_not_numeric', 'booking', $identifier);
                $errordetails[] = [
                    'user_cause' => get_string('agent_booking_price_not_numeric', 'booking', $identifier),
                    'repair' => '',
                ];
                continue;
            }
            if ((float)$value < 0) {
                $errors[] = get_string('agent_booking_price_negative', 'booking', $identifier);
                $errordetails[] = [
                    'user_cause' => get_string('agent_booking_price_negative', 'booking', $identifier),
                    'repair' => '',
                ];
            }
        }

        if (!empty($unknown)) {
            $existinglist = self::format_price_categories_for_message($categories);
            $ambiguities[] = get_string('agent_booking_unknown_price_categories', 'booking', (object)[
                'unknown' => implode(', ', $unknown),
                'existing' => $existinglist,
            ]);
        }

        return ['errors' => $errors, 'ambiguities' => $ambiguities, 'error_details' => $errordetails];
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

        // A bare numeric price ("price": 30 / "prices": "30") is the measured top model guess
        // (W2 baseline 2026-07-12). It has exactly one honest reading — the default price
        // category — so normalize the shape instead of rejecting it. Non-numeric scalars
        // ("30 Euro") stay invalid and keep the existing error path.
        if (is_numeric($prices)) {
            return ['default' => (float)$prices];
        }

        if (!is_array($prices)) {
            return null;
        }

        // Fallback canonicalizer (thread 593): a list of price objects
        // [{"price":30,"label":"Normalpreis"},{"price":20,"category":"student"}] is the intuitive
        // shape a model reaches for when it does not know the identifiers. Flatten it to the
        // canonical {identifier: price}, resolving a label/name to its identifier via the real
        // categories. A member that cannot be mapped keeps its raw label as the key so the
        // existing unknown-category path clarifies with the category list — a price is never
        // silently dropped. (Constructor grounding is the primary fix; this catches the residue.)
        if (array_is_list($prices) && $prices !== [] && is_array($prices[0] ?? null)) {
            $prices = self::flatten_price_object_list($prices);
            if ($prices === null) {
                return null;
            }
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
     * Flatten a list of price objects to a {identifier: price} map (thread-593 fallback).
     *
     * Each member may carry the amount under price/value/amount and the category under
     * identifier/category/pricecategoryidentifier (used verbatim) or under label/name (matched
     * to a real category's identifier or name, case-insensitively). Unmatched labels are kept as
     * the key so the caller's unknown-category clarification lists the real categories.
     *
     * @param array $list
     * @return array<string,float>|null Null when a member has no usable numeric amount.
     */
    private static function flatten_price_object_list(array $list): ?array {
        $categories = self::get_price_categories_by_identifier();
        $bylabel = [];
        foreach ($categories as $identifier => $record) {
            $bylabel[\core_text::strtolower(trim((string)$record->name))] = (string)$identifier;
            $bylabel[\core_text::strtolower(trim((string)$record->identifier))] = (string)$identifier;
        }

        $flattened = [];
        foreach ($list as $member) {
            if (!is_array($member)) {
                return null;
            }
            $amount = $member['price'] ?? $member['value'] ?? $member['amount'] ?? null;
            if (!is_numeric($amount)) {
                return null;
            }
            $rawkey = trim((string)(
                $member['identifier']
                ?? $member['category']
                ?? $member['pricecategoryidentifier']
                ?? $member['label']
                ?? $member['name']
                ?? ''
            ));
            if ($rawkey === '') {
                $rawkey = 'default';
            }
            $key = $bylabel[\core_text::strtolower($rawkey)] ?? $rawkey;
            $flattened[$key] = (float)$amount;
        }

        return $flattened;
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
        $data->datesmarker = 1;
        unset($data->coursestarttime, $data->courseendtime, $data->starttime, $data->endtime);
        unset($data->coursestartdate, $data->courseenddate, $data->startdate, $data->enddate);

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
     * - visible: 0|1 (int/string) or bool (legacy alias; inverted to invisible)
     *
     * @param array $input
     * @return array
     */
    public static function normalize_visibility_input(array $input): array {
        $frominvisible = null;
        $fromvisibility = null;
        $fromvisible = null;

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

        if (array_key_exists('visible', $input)) {
            $raw = $input['visible'];
            if (is_bool($raw)) {
                $fromvisible = $raw ? MOD_BOOKING_OPTION_VISIBLE : MOD_BOOKING_OPTION_INVISIBLE;
            } else if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)))) {
                $value = (int)$raw;
                if (!in_array($value, [0, 1], true)) {
                    return ['error' => 'Field "visible" must be one of: 1 (visible), 0 (invisible).'];
                }
                $fromvisible = $value === 1 ? MOD_BOOKING_OPTION_VISIBLE : MOD_BOOKING_OPTION_INVISIBLE;
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
                    return ['error' => 'Field "visible" string value must be one of: visible, invisible, directlink.'];
                }
                $fromvisible = $map[$normalized];
            } else {
                return ['error' => 'Field "visible" must be a boolean, 0/1, or visibility string.'];
            }
        }

        if ($frominvisible !== null && $fromvisibility !== null && $frominvisible !== $fromvisibility) {
            return ['error' => 'Fields "invisible" and "visibility" conflict. Provide only one visibility value.'];
        }

        if ($frominvisible !== null && $fromvisible !== null && $frominvisible !== $fromvisible) {
            return ['error' => 'Fields "invisible" and "visible" conflict. Provide only one visibility value.'];
        }

        if ($fromvisibility !== null && $fromvisible !== null && $fromvisibility !== $fromvisible) {
            return ['error' => 'Fields "visibility" and "visible" conflict. Provide only one visibility value.'];
        }

        if ($frominvisible !== null) {
            return ['value' => $frominvisible];
        }
        if ($fromvisibility !== null) {
            return ['value' => $fromvisibility];
        }
        if ($fromvisible !== null) {
            return ['value' => $fromvisible];
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
     * The site's active price categories as "identifier (name)" pairs, for constructor grounding.
     *
     * Lets the parameter constructor map the user's wording ("for students") onto the real
     * category identifier ("student") and build the canonical prices object, instead of guessing
     * a labelled array (thread 593). Returns '' when no enabled category exists.
     *
     * @return string
     */
    public static function describe_active_price_categories(): string {
        return self::format_price_categories_for_message(self::get_price_categories_by_identifier());
    }

    /**
     * The active price category identifiers, lowercased, in sort order.
     *
     * @return string[]
     */
    public static function active_price_category_identifiers(): array {
        $ids = [];
        foreach (self::get_price_categories_by_identifier() as $identifier => $record) {
            if ((int)$record->disabled !== 1) {
                $ids[] = (string)$identifier;
            }
        }
        return $ids;
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

        $lastoptionid = (int)(self::$enginethreadmemory?->get_value(
            $userid,
            self::resolve_contextid_from_cmid($cmid),
            'lastworkedoptionid'
        ) ?? 0);
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

        $ctx = self::resolve_contextid_from_cmid($cmid);
        self::$enginethreadmemory?->set_value($userid, $ctx, 'lastworkedoptionid', $optionid);
        self::$enginethreadmemory?->set_value($userid, $ctx, 'lastworkedoptionts', time());
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

        $storedids = self::$enginethreadmemory?->get_value(
            $userid,
            self::resolve_contextid_from_cmid($cmid),
            self::LAST_PREVIEW_OPTION_IDS_METADATA_KEY
        );
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

        $ctx = self::resolve_contextid_from_cmid($cmid);
        self::$enginethreadmemory?->set_value($userid, $ctx, self::LAST_PREVIEW_OPTION_IDS_METADATA_KEY, $normalizedids);
        self::$enginethreadmemory?->set_value($userid, $ctx, 'lastpreviewoptionsts', time());
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
     * True when the query still carries an unresolved anonymization placeholder (ANON_USER_*).
     *
     * The token grammar is owned by the agent subplugin's privacy_anonymizer; probe it softly
     * (mod_booking must not hard-depend on a subplugin class) and treat it as no placeholder
     * when the subplugin is absent.
     *
     * @param string $query
     * @return bool
     */
    private static function is_unresolved_privacy_placeholder(string $query): bool {
        $anonymizer = '\\bookingextension_agent\\local\\wizard\\privacy_anonymizer';
        return class_exists($anonymizer)
            && method_exists($anonymizer, 'looks_like_anon_token')
            && $anonymizer::looks_like_anon_token($query);
    }

    /**
     * True when a resolved user record may be assigned/booked as a real person.
     *
     * The guest account (firstname "Guest user") is confirmed and not deleted, so core
     * search_users and id/email lookups CAN return it — but resolving a person reference to
     * the guest (or a deleted account) is never right.
     *
     * @param object $user Record carrying at least id (deleted optional).
     * @return bool
     */
    private static function is_assignable_person(object $user): bool {
        if (empty($user->id) || !empty($user->deleted)) {
            return false;
        }
        return !isguestuser((int)$user->id);
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
     * Build a user profile link (moodle_url — never let the LLM construct URLs).
     *
     * @param int $userid
     * @return string
     */
    public static function build_user_link(int $userid): string {
        return (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
    }

    /**
     * Format users as "Fullname (profile-url)" list entries for AI-visible output.
     *
     * Every entity mention in agent feedback must carry a real moodle_url link so
     * the synchronizer can present it clickable without inventing URLs.
     *
     * @param array $userids
     * @return string comma-separated list; empty when no user resolves
     */
    public static function format_user_links(array $userids): string {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (empty($userids)) {
            return '';
        }

        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, firstnamephonetic, '
            . 'lastnamephonetic, middlename, alternatename');

        $parts = [];
        foreach ($userids as $userid) {
            if (!isset($users[$userid])) {
                continue;
            }
            $parts[] = fullname($users[$userid]) . ' (' . self::build_user_link($userid) . ')';
        }

        return implode(', ', $parts);
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
    public static function verify_persisted_option_state_for_skill_for_execute(
        string $taskname,
        array $input,
        int $optionid
    ): array {
        return self::verify_persisted_option_state_for_skill($taskname, $input, $optionid);
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
