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
use mod_booking\local\wizard\engine\base_skill;
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\booking\support\booking_mutation_validation;
use mod_booking\singleton_service;
use mod_booking\local\wizard\booking_option_preview_renderer;

/**
 * Base task delegating schema, validation and execution to booking support logic.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_skill_base extends base_skill {
    /** @var int How many booking activities the preview pane lists at most. */
    protected const PREVIEW_INSTANCE_LIMIT = 50;

    /** @var booking_skill_support|null */
    private static ?booking_skill_support $sharedsupport = null;

    /** @var booking_skill_support */
    protected booking_skill_support $support;

    /**
     * Prompt metadata map for all booking tasks.
     *
     * Maps task names to their input_fields_for_prompt and anchor_fields.
     * This allows skill_registry to use these fields for prompt generation
     * instead of relying on hardcoded fallback logic.
     *
     * @var array<string,array<string,array<int,string>>>
     */
    protected static array $promptmeta = [
        'mod_booking.create_option' => [
            // An activityquery is NOT a minimal input: it is an optional targeting hint the
            // module_target_resolver scope-cascade handles when absent (single instance → auto,
            // several → ask). Listing it as minimal made the model fabricate a target when the
            // user named none — in the authoring compound it grabbed the just-created course name
            // (thread 593). The property description still drives correct use when an activity IS
            // named; here we only stop signalling it as required.
            'input_fields_for_prompt' => ['text'],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.create_slotbooking_option' => [
            'input_fields_for_prompt' => [
                'text',
                'slot_opening_time',
                'slot_closing_time',
                'slot_duration_minutes',
                'slot_valid_from',
                'slot_valid_until',
            ],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.create_selflearning_option' => [
            'input_fields_for_prompt' => ['text'],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.create_user' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.update_option' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.bulk_update_options' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.search_options' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'mod_booking.get_option_details' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.search_users' => [
            'input_fields_for_prompt' => ['query'],
            'anchor_fields' => [],
        ],
        'mod_booking.search_courses' => [
            'input_fields_for_prompt' => ['query'],
            'anchor_fields' => [],
        ],
        'mod_booking.add_price_category' => [
            'input_fields_for_prompt' => ['name'],
            'anchor_fields' => [],
        ],
        'mod_booking.list_option_properties' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'mod_booking.list_actions' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'mod_booking.get_current_user' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'mod_booking.recreate_task_catalog' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'mod_booking.recall_memory' => [
            'input_fields_for_prompt' => ['mode', 'date_hint', 'query'],
            'anchor_fields' => [],
        ],
        'mod_booking.diagnose_booking_issue' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option', 'user'],
        ],
        'mod_booking.diagnose_cancellation_issue' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option', 'user'],
        ],
        'mod_booking.diagnose_waitinglist' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'mod_booking.book_users' => [
            'input_fields_for_prompt' => ['bookusersquery'],
            'anchor_fields' => ['option', 'user'],
        ],
        'mod_booking.core_get_user_profile' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.core_get_user_preferences' => [
            'input_fields_for_prompt' => ['userquery', 'prefkeys'],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.core_set_user_preference' => [
            'input_fields_for_prompt' => ['name', 'value'],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.core_get_user_enrolments' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user', 'course'],
        ],
        'mod_booking.core_get_current_user' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.core_enrol_user_manual' => [
            'input_fields_for_prompt' => ['userquery', 'coursequery', 'role'],
            'anchor_fields' => ['user', 'course'],
        ],
        'mod_booking.core_unenrol_user_manual' => [
            'input_fields_for_prompt' => ['userquery', 'coursequery'],
            'anchor_fields' => ['user', 'course'],
        ],
        'mod_booking.core_list_course_participants' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'mod_booking.core_get_user_roles_in_course' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'mod_booking.core_search_course_enrolments' => [
            'input_fields_for_prompt' => ['coursequery', 'query'],
            'anchor_fields' => ['course', 'user'],
        ],
        'mod_booking.core_list_course_groups' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'mod_booking.core_get_group_members' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group', 'user'],
        ],
        'mod_booking.core_create_group' => [
            'input_fields_for_prompt' => ['coursequery', 'name'],
            'anchor_fields' => ['course', 'group'],
        ],
        'mod_booking.core_update_group' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'mod_booking.core_delete_group' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'mod_booking.core_get_course_overview' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course'],
        ],
        'mod_booking.core_list_course_sections' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course'],
        ],
        'mod_booking.core_list_course_modules' => [
            'input_fields_for_prompt' => ['coursequery', 'section'],
            'anchor_fields' => ['course', 'module'],
        ],
        'mod_booking.core_get_module_details' => [
            'input_fields_for_prompt' => ['cmid', 'coursequery', 'modulequery'],
            'anchor_fields' => ['course', 'module'],
        ],
        'mod_booking.core_get_activity_completion_status' => [
            'input_fields_for_prompt' => ['coursequery', 'cmid', 'userquery'],
            'anchor_fields' => ['course', 'module', 'user'],
        ],
        'mod_booking.core_get_user_completion_report' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'mod_booking.core_list_course_calendar_events' => [
            'input_fields_for_prompt' => ['coursequery', 'timestart', 'timeend'],
            'anchor_fields' => ['course', 'event'],
        ],
        'mod_booking.core_list_user_calendar_events' => [
            'input_fields_for_prompt' => ['userquery', 'timestart', 'timeend'],
            'anchor_fields' => ['user', 'event'],
        ],
        'mod_booking.core_create_calendar_event' => [
            'input_fields_for_prompt' => ['title', 'timestart', 'timeend', 'coursequery'],
            'anchor_fields' => ['course', 'event'],
        ],
        'mod_booking.core_update_calendar_event' => [
            'input_fields_for_prompt' => ['eventid'],
            'anchor_fields' => ['event'],
        ],
        'mod_booking.core_delete_calendar_event' => [
            'input_fields_for_prompt' => ['eventid'],
            'anchor_fields' => ['event'],
        ],
        'mod_booking.core_list_grade_items' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'grade'],
        ],
        'mod_booking.core_get_user_grades_for_course' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user', 'grade'],
        ],
        'mod_booking.core_send_user_message' => [
            'input_fields_for_prompt' => ['recipient', 'message'],
            'anchor_fields' => ['user'],
        ],
        'mod_booking.core_get_site_summary' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['site'],
        ],
    ];

    /**
     * Example input map for booking tasks.
     *
     * @var array<string,array<string,mixed>>
     */
    protected static array $exampleinput = [
        'mod_booking.add_price_category' => [
            'identifier' => 'student',
            'name' => 'Student',
        ],
        'mod_booking.analyze_rules' => [
            'query' => 'booking confirmation',
            'active_only' => true,
        ],
        'mod_booking.book_users' => [
            'optionquery' => 'Birthday ANON_USER_1',
            'bookusersquery' => 'ANON_USER_1',
        ],
        // NOTE: mod_booking.bulk_update_options intentionally has NO entry here. It overrides
        // get_example_input() with a FLAT field example, mirroring update_option — bulk consumes flat
        // top-level fields, not a {changes:[{field,value}]} envelope. Do not add a changes example
        // for it (thread-206: the inherited changes envelope was silently ignored at execution).
        'mod_booking.configure_booking_instance' => [
            'action' => 'update',
            // A real configurable field (the constructor copies the example verbatim, #2411).
            'changes' => [['field' => 'cancancelbook', 'value' => '0']],
        ],
        // Wave 30 (UO-3): dates and times are placeholders in the parser's format - a concrete example time was
        // copied as the time of a session the user never gave; no maxanswers either (no default, never invented).
        'mod_booking.create_option' => [
            'text' => 'Birthday ANON_USER_1',
            'coursestarttime' => 'YYYY-MM-DDTHH:MM:SS',
            'courseendtime' => 'YYYY-MM-DDTHH:MM:SS',
        ],
        'mod_booking.create_slotbooking_option' => [
            'text' => 'Tennisplatz Slots Juli',
            'slot_opening_time' => 'HH:MM',
            'slot_closing_time' => 'HH:MM',
            'slot_duration_minutes' => 60,
            'slot_valid_from' => 'YYYY-MM-DD',
            'slot_valid_until' => 'YYYY-MM-DD',
            'slot_day_1' => true,
            'slot_day_2' => true,
            'slot_day_3' => true,
            'slot_day_4' => true,
            'slot_day_5' => true,
            'slot_day_6' => false,
            'slot_day_7' => false,
        ],
        'mod_booking.create_selflearning_option' => [
            'text' => 'Self-learning course ANON_USER_1',
            'maxanswers' => 30,
            'duration' => 14400,
            'teacherquery' => 'ANON_USER_1',
        ],
        'mod_booking.create_rule_from_template' => [
            'templatequery' => 'booking confirmation',
            'rulename' => 'Birthday reminder',
            'days' => 2,
        ],
        'mod_booking.create_user' => [
            'userquery' => 'Anna Example',
        ],
        'mod_booking.diagnose_booking_issue' => [
            'question' => 'Why can ANON_USER_1 not book Birthday ANON_USER_1?',
            'optionquery' => 'Birthday ANON_USER_1',
            'userquery' => 'ANON_USER_1',
        ],
        'mod_booking.diagnose_cancellation_issue' => [
            'question' => 'Why can I not cancel my booking?',
            'optionquery' => 'Birthday ANON_USER_1',
        ],
        // A named option focuses the report incl. the received messages (#2423, DUB-2).
        'mod_booking.diagnose_user_booking' => [
            'userquery' => 'ANON_USER_1',
            'optionquery' => 'Welding seminar',
            'includemessages' => true,
        ],
        'mod_booking.diagnose_waitinglist' => [
            'optionquery' => 'Birthday ANON_USER_1',
        ],
        'mod_booking.get_current_user' => [],
        'mod_booking.get_option_details' => [
            'optionquery' => 'Birthday ANON_USER_1',
        ],
        'mod_booking.list_actions' => [
            'scope' => 'booking',
        ],
        'mod_booking.list_option_properties' => [
            'scope' => 'mod_booking.create_option',
        ],
        'mod_booking.recreate_task_catalog' => [
            'force' => true,
        ],
        'mod_booking.recall_memory' => [
            'mode' => 'date_window',
            'date_hint' => 'last friday',
            'query' => 'document',
            'include_structured' => true,
        ],
        'mod_booking.search_courses' => [
            'query' => 'Mathematics',
        ],
        'mod_booking.search_users' => [
            'query' => 'ANON_USER_1',
        ],
        'mod_booking.search_options' => [
            'query' => 'Birthday',
        ],
        'mod_booking.update_option' => [
            'optionquery' => 'Birthday ANON_USER_1',
            'text' => 'Birthday ANON_USER_1',
        ],
        // Option and trainer by name — the constructor copies these values (#2423, UOT-2/3).
        'mod_booking.update_option_trainer' => [
            'optionquery' => 'Friday pilates',
            'teacherquery' => 'ANON_USER_1',
        ],
        'mod_booking.update_rule_from_template' => [
            'rulequery' => 'Birthday reminder',
            'rulename' => 'Updated reminder',
            'days' => 5,
        ],
        'mod_booking.core_get_user_profile' => [
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_get_user_preferences' => [
            'userquery' => '__current_user__',
            'prefkeys' => ['bookanyone'],
        ],
        'mod_booking.core_set_user_preference' => [
            'name' => 'bookanyone',
            'value' => '1',
            'confirmed' => true,
        ],
        'mod_booking.core_get_user_enrolments' => [
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_get_current_user' => [],
        'mod_booking.core_enrol_user_manual' => [
            'userquery' => 'ANON_USER_1',
            'coursequery' => 'Mathematics',
            'role' => 'student',
            'confirmed' => true,
        ],
        'mod_booking.core_unenrol_user_manual' => [
            'userquery' => 'ANON_USER_1',
            'coursequery' => 'Mathematics',
            'confirmed' => true,
        ],
        'mod_booking.core_list_course_participants' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_get_user_roles_in_course' => [
            'coursequery' => 'Mathematics',
            'userquery' => 'ANON_USER_1',
        ],
        'mod_booking.core_search_course_enrolments' => [
            'coursequery' => 'Mathematics',
            'query' => 'anon',
        ],
        'mod_booking.core_list_course_groups' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_get_group_members' => [
            'coursequery' => 'Mathematics',
            'groupquery' => 'Gruppe A',
        ],
        'mod_booking.core_create_group' => [
            'coursequery' => 'Mathematics',
            'name' => 'Gruppe A',
            'confirmed' => true,
        ],
        'mod_booking.core_update_group' => [
            'coursequery' => 'Mathematics',
            'groupquery' => 'Gruppe A',
            'name' => 'Gruppe B',
            'confirmed' => true,
        ],
        'mod_booking.core_delete_group' => [
            'coursequery' => 'Mathematics',
            'groupquery' => 'Gruppe B',
            'confirmed' => true,
        ],
        'mod_booking.core_get_course_overview' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_list_course_sections' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_list_course_modules' => [
            'coursequery' => 'Mathematics',
            'section' => 1,
        ],
        'mod_booking.core_get_module_details' => [
            'cmid' => 1,
        ],
        'mod_booking.core_get_activity_completion_status' => [
            'coursequery' => 'Mathematics',
            'cmid' => 1,
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_get_user_completion_report' => [
            'coursequery' => 'Mathematics',
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_list_course_calendar_events' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_list_user_calendar_events' => [
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_create_calendar_event' => [
            'title' => 'Team Meeting',
            'timestart' => 1767225600,
            'timeend' => 1767229200,
            'confirmed' => true,
        ],
        'mod_booking.core_update_calendar_event' => [
            'eventid' => 1,
            'title' => 'Updated Team Meeting',
            'confirmed' => true,
        ],
        'mod_booking.core_delete_calendar_event' => [
            'eventid' => 1,
            'confirmed' => true,
        ],
        'mod_booking.core_list_grade_items' => [
            'coursequery' => 'Mathematics',
        ],
        'mod_booking.core_get_user_grades_for_course' => [
            'coursequery' => 'Mathematics',
            'userquery' => '__current_user__',
        ],
        'mod_booking.core_send_user_message' => [
            'recipient' => 'ANON_USER_1',
            'message' => 'Hallo aus dem Agenten',
            'confirmed' => true,
        ],
        'mod_booking.core_get_site_summary' => [],
    ];

    /** @var string[] Native Moodle capabilities for this skill's core action (Gate 2). */
    private array $nativecapabilities = [];

    /**
     * Constructor.
     *
     * @param bool $readonly
     * @param string $riskclass
     * @param string[] $nativecapabilities Native Moodle capability(ies) of the core action this skill
     *        performs (e.g. mod/booking:editownoption). The engine enforces these centrally at the
     *        operating context via native_capability_guard (Gate 2) — every mutating booking skill
     *        MUST declare them so the central guard, not just the skill's own preflight, protects it.
     */
    public function __construct(bool $readonly, string $riskclass, array $nativecapabilities = []) {
        if (!skill_risk_class::is_valid($riskclass)) {
            throw new \coding_exception('Invalid risk class declared for booking task: ' . trim($riskclass));
        }

        parent::__construct($readonly, $riskclass);
        $this->nativecapabilities = array_values(array_filter(array_map(
            static fn($cap): string => trim((string)$cap),
            $nativecapabilities
        )));
        if (self::$sharedsupport === null) {
            // Inject the active engine's services (resolved via base_skill accessors) so the
            // support helper stays engine-agnostic.
            self::$sharedsupport = new booking_skill_support(
                $this->attachments(),
                $this->thread_memory(),
                $this->skill_catalog()
            );
        }
        $this->support = self::$sharedsupport;
    }

    /**
     * Native Moodle capabilities for this skill's core action (Gate 2), declared via the constructor.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return $this->nativecapabilities;
    }

    /**
     * Return the task name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Return the schema for this task.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => '',
            'readonly' => $this->is_read_only(),
            'properties' => [],
        ];
    }

    /**
     * Return task-owned example input for prompt routing.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        $taskname = $this->get_name();
        return self::$exampleinput[$taskname] ?? [];
    }

    /**
     * Optionally enrich a schema with prompt_meta if declared in $promptmeta.
     *
     * Subclasses can call this helper at the end of get_schema() to automatically
     * inject input_fields_for_prompt and anchor_fields without manual duplication:
     *
     *   public function get_schema(): array {
     *       $schema = [ ... ];
     *       return $this->enrich_schema_with_prompt_meta($schema);
     *   }
     *
     * If the task is not in $promptmeta, returns schema unchanged.
     * If schema already has prompt_meta, does not override it.
     *
     * @param  array $schema
     * @return array Enriched schema (or unchanged if no metadata found).
     */
    protected function enrich_schema_with_prompt_meta(array $schema): array {
        if (!empty($schema['prompt_meta'])) {
            return $schema;
        }

        $taskname = $this->get_name();
        if (!isset(self::$promptmeta[$taskname])) {
            return $schema;
        }

        $schema['prompt_meta'] = self::$promptmeta[$taskname];
        return $schema;
    }

    /**
     * Add option result fields used by observation summaries.
     *
     * @param array $result
     * @param array $input
     * @param int $cmid
     * @param string $action
     * @return array
     */
    protected function enrich_legacy_option_result(array $result, array $input, int $cmid, string $action): array {
        $optionid = (int)($result['optionid'] ?? $result['resultid'] ?? $input['optionid'] ?? 0);
        if ($optionid <= 0) {
            return $result;
        }

        $result['optionid'] = $optionid;

        $cm = get_coursemodule_from_id('booking', $cmid);
        if ($cm) {
            $result['bookingid'] = (int)$cm->instance;
        }

        if (empty($result['observation_full'])) {
            $result['observation_full'] = $this->build_legacy_option_observation($optionid, $input, $result, $action);
        }

        return $result;
    }

    /**
     * Apply an explicit legacy create visibility after creation.
     *
     * @param array $input
     * @param int $optionid
     * @param int $cmid
     * @param int $userid
     * @return string
     */
    protected function apply_legacy_create_visibility_if_requested(
        array $input,
        int $optionid,
        int $cmid,
        int $userid
    ): string {
        if (
            $optionid <= 0
            || (
                !array_key_exists('invisible', $input)
                && !array_key_exists('visibility', $input)
                && !array_key_exists('visible', $input)
            )
        ) {
            return '';
        }

        $normalized = booking_skill_support::normalize_visibility_input($input);
        if (!isset($normalized['value'])) {
            return (string)($normalized['error'] ?? '');
        }

        $service = new booking_skill_mutation_execute_service($this->attachments());
        $result = $service->execute(
            update_option_skill::TASK_NAME,
            ['optionid' => $optionid, 'invisible' => (int)$normalized['value']],
            $cmid,
            $userid,
            $this->support
        );

        if (is_array($result) && (string)($result['status'] ?? '') === 'executed') {
            return '';
        }

        return is_array($result) ? (string)($result['detail'] ?? '') : 'Visibility update did not run.';
    }

    /**
     * Build text observation for option mutations.
     *
     * @param int $optionid
     * @param array $input
     * @param array $result
     * @param string $action
     * @return string
     */
    private function build_legacy_option_observation(int $optionid, array $input, array $result, string $action): string {
        $settings = null;
        try {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        } catch (\Throwable $e) {
            $settings = null;
        }

        $title = trim((string)($settings->text ?? $input['text'] ?? ''));
        $parts = ['Booking option ' . $action . ':', 'optionid=' . $optionid];
        if (isset($result['bookingid'])) {
            $parts[] = 'bookingid=' . (int)$result['bookingid'];
        }
        if ($title !== '') {
            $parts[] = 'title="' . $title . '"';
        }

        if ($settings !== null) {
            if (isset($settings->type)) {
                $parts[] = 'type=' . (int)$settings->type;
            } else if (isset($settings->optiontype)) {
                $parts[] = 'type=' . (int)$settings->optiontype;
            }
            if (isset($settings->maxanswers)) {
                $parts[] = 'maxanswers=' . (int)$settings->maxanswers;
            }
            if (isset($settings->invisible)) {
                $parts[] = 'invisible=' . (int)$settings->invisible;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Validate common mutation fields without DB access.
     *
     * @param array $input
     * @param bool $allowbookusersquery
     * @return array<int,string>
     */
    protected function validate_common_mutation_structure(array $input, bool $allowbookusersquery = true): array {
        $errors = [];

        if (
            array_key_exists('invisible', $input)
            || array_key_exists('visibility', $input)
            || array_key_exists('visible', $input)
        ) {
            $normalizedvisibility = booking_skill_support::normalize_visibility_input($input);
            if (!empty($normalizedvisibility['error'])) {
                $errors[] = (string)$normalizedvisibility['error'];
            }
        }

        if (array_key_exists('optiondatesmode', $input)) {
            $mode = strtolower(trim((string)$input['optiondatesmode']));
            if (!in_array($mode, ['append', 'replace'], true)) {
                $errors[] = get_string('agent_validation_optiondatesmode_invalid', 'booking');
            }
        }

        $overrides = is_array($input['override'] ?? null) ? $input['override'] : [];
        foreach (['coursestarttime', 'courseendtime', 'bookingopeningtime', 'bookingclosingtime'] as $fieldname) {
            if (!array_key_exists($fieldname, $input) || array_key_exists('optiondates', $input)) {
                continue;
            }
            $value = $input[$fieldname];
            $isplaceholder = $value === 0 || $value === '0' || $value === '' || $value === null;
            if ($isplaceholder && in_array($fieldname, $overrides, true)) {
                continue;
            }
            if (!booking_skill_support::parse_datetime($value)) {
                $errors[] = get_string('agent_validation_' . $fieldname . '_invalid', 'booking');
            }
        }

        if (!empty($input['optiondates']) && empty(booking_skill_support::extract_optiondates($input))) {
            $errors[] = get_string('agent_validation_optiondates_invalid', 'booking');
        }

        if (!$allowbookusersquery && !empty($input['bookusersquery'])) {
            $errors[] = get_string('agent_booking_bulk_update_bookusersquery_unsupported', 'booking');
        }

        return array_values(array_unique($errors));
    }

    /**
     * Execute the task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        return $this->support->execute($this->get_name(), $input, $cmid, $userid);
    }

    /**
     * Return optional contextual prompt packs for this task.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Shared contextual prompt pack that teaches the planner how to attach an image.
     *
     * Any mutating option task that accepts a headerimage_token (create_option, update_option,
     * bulk_update_options) exposes the same behaviour, so the guidance lives here once. The
     * shared execute service resolves the attachment token to the option header image; the
     * planner's only job is to route the token into the headerimage_token parameter and never
     * to invent a key or misuse text/description for it.
     *
     * @return array<string,mixed>
     */
    protected function header_image_attachment_prompt_pack(): array {
        // Skills that inherit this pack without accepting the key themselves (e.g. the
        // slotbooking/selflearning create variants) must not tell the model to send it —
        // that is exactly the schema/guidance drift the drift guard test protects against.
        // They advertise the two-step route instead.
        $hasdirectkey = array_key_exists(
            'headerimage_token',
            (array)($this->get_schema()['properties'] ?? [])
        );

        $direct = [
            '- When the user provides an image attachment ("[Attachment: <filename> — Attachment-Token: <token>]")'
                . ' and asks to set it as header/cover image of a booking option:'
                . ' put the token value verbatim into the "headerimage_token" parameter.',
            '- This also applies when creating an option: "create option … with this image" is supported —'
                . ' pass the attachment token as headerimage_token in the same create command.',
        ];
        $twostep = [
            '- This task cannot set a header/cover image directly: create the option first, then pass the'
                . ' attachment token verbatim as headerimage_token via mod_booking.update_option.',
        ];

        return [
            'id' => 'mod_booking.header_image_attachment',
            'triggers' => [
                'image', 'header', 'photo', 'cover', 'headerimage', 'header image', 'attachment',
            ],
            'guidance' => array_merge($hasdirectkey ? $direct : $twostep, [
                '- NEVER put an attachment token into "text" or "description".',
                '- The "text" parameter is ONLY for the option title — never for tokens or file references.',
            ]),
        ];
    }

    /**
     * Shared "do not invent keys" guidance for schema-mismatch retries.
     *
     * When construction emits parameter keys a task does not support, the retry/clarification
     * text must give the model a legitimate exit instead of forcing another task_call: use only
     * the allowed keys, and if part of the user's intent maps to no allowed key, say so honestly
     * rather than inventing a key or silently dropping it. This is scoped to genuinely
     * unsupported *optional* parts — missing required fields and ambiguous targets are handled by
     * their own clarification branches and must still be asked for, not waved away here.
     *
     * The wording deliberately avoids promising an automatic retry: a schema-mismatch surfaces as
     * a terminal clarification (RECOVERABLE_INPUT_ERROR → response_type=clarification), so a
     * "will retry automatically" phrasing would be a false promise once the synchronizer renders
     * it for the user.
     *
     * @param  string[] $allowedkeys Canonical keys this task accepts.
     * @return string
     */
    protected function build_unsupported_params_guidance(array $allowedkeys): string {
        $parts = [];
        if (!empty($allowedkeys)) {
            $parts[] = 'Only use the allowed keys for this task: '
                . implode(', ', array_slice(array_values($allowedkeys), 0, 24)) . '.';
        }
        $parts[] = 'If part of the user\'s request cannot be expressed with any allowed key '
            . '(for example an image when this task offers no image key), do NOT invent a key '
            . 'and do NOT silently drop it: send a corrected task with only the valid keys AND '
            . 'clearly tell the user which part could not be applied and why.';
        $parts[] = 'Do not promise an automatic retry; there is none — either send the corrected '
            . 'task now or explain what is not possible.';
        return implode(' ', $parts);
    }

    /**
     * Verify that requested values are visible in persisted option settings.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return [];
    }

    /**
     * Run service-level preflight validation and return an enriched primitive preflight result.
     *
     * @param  string $taskname       Fully-qualified task name (e.g. booking.update_option).
     * @param  array  $preparedinput  Input with local resolution already applied.
     * @param  int    $cmid
     * @param  int    $userid
     * @param  array  $existingissues Issues already collected before calling this helper.
     * @param  string $lang           Output language code (may be empty).
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function apply_service_preflight(
        string $taskname,
        array $preparedinput,
        int $cmid,
        int $userid,
        array $existingissues = [],
        string $lang = ''
    ): array {
        $servicepreflight = booking_mutation_validation::validate_common($preparedinput, $cmid, $taskname);

        $issues = $existingissues;
        if (!empty($servicepreflight['errors']) || !empty($servicepreflight['ambiguities'])) {
            $serviceissuecodes = array_values(array_filter(array_map('strval', (array)($servicepreflight['issue_codes'] ?? []))));
            foreach ((array)($servicepreflight['errors'] ?? []) as $idx => $err) {
                $issues[] = [
                    'code'     => (string)($serviceissuecodes[$idx] ?? 'PREFLIGHT_ERROR'),
                    'severity' => 'needs_clarification',
                    'message'  => (string)$err,
                ];
            }
            $issues = array_merge($issues, self::ambiguity_issues($servicepreflight));
            return $this->invalid($issues);
        }

        return $this->pass(self::requester_as_trainer($preparedinput));
    }

    /**
     * The requester marker in teacherquery, resolved to the requester's address before the confirmation (#2569).
     *
     * The preview and the staged command then name the real trainer instead of the marker; execution uses the
     * address like any other trainer address.
     *
     * @param array $input
     * @return array
     */
    protected static function requester_as_trainer(array $input): array {
        global $USER;
        if (
            empty($input['teacheremail'])
            && booking_skill_support::is_requester_marker($input['teacherquery'] ?? '')
            && !empty($USER->email)
        ) {
            $input['teacheremail'] = (string)$USER->email;
            unset($input['teacherquery']);
        }
        return $input;
    }

    /**
     * Person fields of the booking skills beyond the engine's naming convention (*userquery, teacherquery): the
     * comma-separated person lists. Declared so the engine treats them as person references - the requester in such
     * a list becomes the requester marker (#2569) and resolves like every other entry.
     *
     * @return string[]
     */
    public function get_person_reference_fields(): array {
        return $this->fields_in_schema(['bookusersquery', 'selectusersquery']);
    }

    /**
     * Person fields whose empty value does NOT mean the requester: an option without trainer is valid, and an empty
     * restriction list leaves the restriction alone. The engine offers a yes/no companion for each (#2569) and turns
     * a set one into the requester.
     *
     * @return array<string,string> field => description of its yes/no companion
     */
    public function get_requester_flag_fields(): array {
        $flags = [
            'teacherquery' => 'true when the requester is the teacher.',
            'selectusersquery' => 'true when the requester is one of the users the restriction lets in.',
        ];
        return array_intersect_key($flags, array_flip($this->fields_in_schema(array_keys($flags))));
    }

    /**
     * The given fields that this skill's schema actually has: the shared declarations above hold for every booking
     * skill, but a skill without such a field declares nothing.
     *
     * @param string[] $fields
     * @return string[]
     */
    private function fields_in_schema(array $fields): array {
        $properties = (array)($this->get_schema()['properties'] ?? []);
        return array_values(array_filter($fields, static fn(string $field): bool => array_key_exists($field, $properties)));
    }

    /**
     * Preflight issues for the ambiguities of {@see booking_mutation_validation::validate_common()}.
     *
     * An ambiguity that comes with candidates (a person matched several users) becomes a choice: the issue carries
     * the field and the candidates, and its message lists no user ids (P6a, #2569). Every other ambiguity keeps its
     * text.
     *
     * @param array $servicepreflight Result of validate_common().
     * @return array
     */
    protected static function ambiguity_issues(array $servicepreflight): array {
        $choices = (array)($servicepreflight['ambiguity_choices'] ?? []);
        $issues = [];
        foreach (array_values((array)($servicepreflight['ambiguities'] ?? [])) as $idx => $amb) {
            $issue = [
                'code'     => 'PREFLIGHT_AMBIGUITY',
                'severity' => 'needs_clarification',
                'message'  => (string)$amb,
            ];
            $choice = $choices[$idx] ?? null;
            if (is_array($choice) && !empty($choice['candidates'])) {
                $issue['message'] = (string)($choice['message'] ?? $amb);
                $issue['field'] = (string)($choice['field'] ?? '');
                $issue['candidates'] = array_values((array)$choice['candidates']);
            }
            $issues[] = $issue;
        }
        return $issues;
    }

    /**
     * Resolve cmid strictly from module context id.
     *
     * @param int $contextid
     * @return int
     */
    protected function resolve_cmid_from_context_or_cmid(int $contextid): int {
        if ($contextid <= 0) {
            return 0;
        }

        $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
        if ($ctx instanceof \context_module && (int)($ctx->instanceid ?? 0) > 0) {
            return (int)$ctx->instanceid;
        }

        return 0;
    }

    /**
     * Graceful guard for non-booking contexts (dashboard/course/system agent).
     *
     * Booking skills need a booking instance; when the agent runs from a global
     * entry point the resolved cmid is 0 and downstream helpers would crash with
     * "invalid course module id" (thread 323). Returning a clarification instead
     * lets the conversation recover ("which course / booking activity do you
     * mean?"). The cross-context target resolution (in progress) will fill the
     * cmid BEFORE this guard fires, so both mechanisms compose.
     *
     * @param int $resolvedcmid result of resolve_cmid_from_context_or_cmid()
     * @param array $input
     * @param int $contextid The context the call runs in; a course context narrows the offered activities to that course.
     * @return array|null primitive invalid result, or null when a booking instance is in scope
     */
    protected function require_booking_instance_scope(int $resolvedcmid, array $input = [], int $contextid = 0): ?array {
        if ($resolvedcmid > 0) {
            return null;
        }

        // Option-aware first (#2334 residual): ambiguous -> candidates, nowhere -> honest
        // not-found. Only a truly unnamed target gets the generic scope clarification.
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionquery !== '' && empty($input['optionid'])) {
            $resolved = booking_skill_support::activity_for_option_query(
                $optionquery,
                trim((string)($input['optionwhen'] ?? ''))
            );
            if (($resolved['status'] ?? '') === 'ambiguous') {
                $labels = [];
                foreach ((array)($resolved['candidates'] ?? []) as $candidate) {
                    $labels[] = booking_skill_support::build_option_link_for_output(
                        (int)$candidate->cmid,
                        (int)$candidate->optionid
                    ) . ' (' . format_string($candidate->coursename) . ')';
                }
                return $this->invalid([[
                    'code' => 'CONTEXT_TARGET_UNRESOLVED',
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_booking_option_target_ambiguous', s($optionquery), '')
                        . ' ' . implode('; ', $labels),
                    'repair' => 'Retry with the optionid of the intended candidate, or name the '
                        . 'booking activity via activityquery.',
                ]]);
            }
            if (($resolved['status'] ?? '') === 'not_found') {
                return $this->invalid([[
                    'code' => 'OPTION_NOT_FOUND',
                    'severity' => 'needs_clarification',
                    'message' => get_string('agent_booking_option_query_notfound_anywhere', 'mod_booking', s($optionquery)),
                    'repair' => 'No option matched anywhere. Retry with a different optionquery, '
                        . 'an optionid, or name the booking activity via activityquery.',
                ]]);
            }
        }

        // Thread 23508 (Wunderbyte-GmbH/Wunderbyte-GmbH#2453): since read-only commands pass this preflight
        // before execute() (agent 81b7a56), the guard is what the user gets - so it offers the same choice the
        // execute twin build_no_instance_scope_result() offers: the booking activities of the course the user
        // stands in, otherwise every activity they can access on the site. The bare text is reserved for a
        // user who can access no booking activity at all.
        [$instances, $heading] = $this->instance_candidates_for_context($contextid);
        if (!empty($instances)) {
            return $this->invalid([[
                'code' => 'RECOVERABLE_INPUT_ERROR',
                'severity' => 'needs_clarification',
                'message' => self::build_instance_candidates_message($instances, $heading),
                'repair' => 'No target booking activity. Retry with the cmid (or activityquery) of one of the '
                    . 'listed activities when the user names it; otherwise ask which one to use.',
            ]]);
        }

        return $this->invalid([[
            'code' => 'RECOVERABLE_INPUT_ERROR',
            'severity' => 'needs_clarification',
            'message' => get_string('agent_booking_no_instance_in_scope', 'booking'),
        ]]);
    }

    /**
     * The booking activities to offer when a call has no target: those of the course the context belongs
     * to (when it has any), otherwise all the user can access on the site.
     *
     * @param int $contextid The context the call runs in (0 = unknown).
     * @return array{0: array[], 1: string} Instances of list_accessible_booking_instances() and the heading.
     */
    protected function instance_candidates_for_context(int $contextid): array {
        $courseid = 0;
        if ($contextid > 0) {
            $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
            if ($ctx instanceof \context_course) {
                $courseid = (int)$ctx->instanceid;
            }
        }
        if ($courseid > 0) {
            $instances = $this->list_accessible_booking_instances($courseid);
            if (!empty($instances)) {
                $coursename = (string)($instances[0]['coursename'] ?? '');
                return [$instances, get_string('agent_booking_no_instance_in_course_choose', 'booking', $coursename)];
            }
        }
        return [$this->list_accessible_booking_instances(), get_string('agent_booking_no_instance_in_scope_courses', 'booking')];
    }

    /**
     * The user text that offers booking activities to choose from: heading, up to ten linked entries with
     * their facts (most options first), the number of further entries, and the totals.
     *
     * @param array[] $instances Entries of {@see self::list_accessible_booking_instances()}, already sorted.
     * @param string $heading The first line.
     * @return string
     */
    protected static function build_instance_candidates_message(array $instances, string $heading): string {
        $shown = array_slice($instances, 0, 10);
        $totaloptions = array_sum(array_column($instances, 'optioncount'));
        $lines = [];
        foreach ($shown as $instance) {
            $lines[] = '- [' . $instance['name'] . '](' . $instance['url'] . ') '
                . self::instance_facts_label($instance) . ' — '
                . get_string('course') . ': [' . $instance['coursename'] . '](' . $instance['courseurl'] . ')';
        }
        $list = implode("\n", $lines);
        if (count($instances) > count($shown)) {
            $list .= "\n" . get_string(
                'agent_booking_no_instance_more',
                'booking',
                count($instances) - count($shown)
            );
        }

        return $heading
            . "\n\n" . $list
            . "\n\n" . get_string('agent_booking_no_instance_totals', 'booking', (object)[
                'options' => $totaloptions,
                'instances' => count($instances),
            ]);
    }

    /**
     * Execute-path twin of require_booking_instance_scope() for READ-ONLY skills.
     *
     * R0 skills run without the preflight gate (the executor calls execute()
     * directly), so the scope guard must also exist here. Returns a complete,
     * non-crashing result that tells the user — and instructs the planner — to
     * ask for the course/booking activity instead of surfacing a raw
     * "invalid course module id" error (threads 323/326).
     *
     * @param int $resolvedcmid result of resolve_cmid_from_context_or_cmid()
     * @param array $input
     * @return array|null null when a booking instance is in scope
     */
    protected function build_no_instance_scope_result(int $resolvedcmid, array $input = []): ?array {
        if ($resolvedcmid > 0) {
            return null;
        }

        // Option-aware first (#2334 residual): a NAMED option that is ambiguous across
        // activities gets its candidates listed; one matching nothing gets the honest
        // not-found - never the generic open-an-activity text.
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionquery !== '' && empty($input['optionid'])) {
            $resolved = booking_skill_support::activity_for_option_query(
                $optionquery,
                trim((string)($input['optionwhen'] ?? ''))
            );
            if (($resolved['status'] ?? '') === 'ambiguous') {
                $labels = [];
                foreach ((array)($resolved['candidates'] ?? []) as $candidate) {
                    $labels[] = booking_skill_support::build_option_link_for_output(
                        (int)$candidate->cmid,
                        (int)$candidate->optionid
                    ) . ' (' . format_string($candidate->coursename) . ')';
                }
                $message = $this->localized_string('agent_booking_option_target_ambiguous', s($optionquery), '')
                    . ' ' . implode('; ', $labels);
                return [
                    'status' => 'error',
                    'detail' => $message,
                    'usermessage' => $message,
                    'resultid' => null,
                    'observation_full' => $message,
                    'debugmessage' => 'Ambiguous option across activities: ' . s($optionquery),
                ];
            }
            if (($resolved['status'] ?? '') === 'not_found') {
                $message = get_string('agent_booking_option_query_notfound_anywhere', 'mod_booking', s($optionquery));
                return [
                    'status' => 'error',
                    'detail' => $message,
                    'usermessage' => $message,
                    'resultid' => null,
                    'observation_full' => $message,
                    'debugmessage' => 'Option query matched nothing anywhere: ' . s($optionquery),
                ];
            }
        }

        $instances = $this->list_accessible_booking_instances();
        if (empty($instances)) {
            $message = get_string('agent_booking_no_instance_in_scope', 'booking');
            $observation = 'SCOPE NOTE (about this one call only, not about the site): this call ran without a '
                . 'target booking activity, and the user cannot access any booking activity anywhere on this '
                . 'site. Tell the user that — do NOT retry this skill and do NOT invent results.';
        } else {
            $shown = array_slice($instances, 0, 10);
            $totaloptions = array_sum(array_column($instances, 'optioncount'));
            $message = self::build_instance_candidates_message(
                $instances,
                get_string('agent_booking_no_instance_in_scope_courses', 'booking')
            );
            $observation = 'SCOPE NOTE (about this one call only, not about the site): this call ran without a '
                . 'target booking activity, so nothing was searched yet — it does NOT mean the site has no '
                . 'booking activities or options. The site holds ' . $totaloptions . ' booking options the user '
                . 'can see, spread over ' . count($instances) . ' booking activities. The user CAN access these '
                . 'booking activities (cmid and the number of options they hold, most first): '
                . implode('; ', array_map(
                    static fn(array $i): string => $i['name'] . ' [cmid ' . $i['cmid'] . '] in course '
                        . $i['coursename'] . ' holds ' . $i['optioncount'] . ' options',
                    $shown
                ))
                . '. An activity holding 0 options has nothing to offer - do not propose it unless the user asks '
                . 'to create something there. If the request (or an earlier message) already names one of these '
                . 'activities or a concrete '
                . 'booking option, retry the skill now with that target (optionid/optionquery, or '
                . 'activityquery/cmid when the skill schema offers it). Otherwise ask the user which booking '
                . 'activity to use. Never invent results.';
        }

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            // The chat text shows ten entries; the preview pane has room for the whole list.
            'previewinstances' => $instances,
            'observation_full' => $observation,
            // Navigational metadata (course/instance names) + instructions: treated as
            // engine text, exempt from privacy anonymization like moodle_context names.
            'observation_engine_static' => true,
        ];
    }

    /**
     * The facts that decide whether an activity is worth naming, in brackets behind its name.
     *
     * @param array $instance One entry of {@see self::list_accessible_booking_instances()}.
     * @return string
     */
    protected static function instance_facts_label(array $instance): string {
        return get_string('agent_booking_no_instance_facts', 'booking', (object)[
            'cmid' => (int)($instance['cmid'] ?? 0),
            'options' => (int)($instance['optioncount'] ?? 0),
        ]);
    }

    /**
     * List booking instances the current user can actually access, with links.
     *
     * Only runs in the rare no-scope case (agent opened outside a booking
     * activity), so the per-course access checks are acceptable. Hidden courses
     * and instances the user cannot see are filtered out via get_fast_modinfo's
     * uservisible flag.
     *
     * @param int $courseid Only the instances of this course (0 = the whole site).
     * @return array
     */
    protected function list_accessible_booking_instances(int $courseid = 0): array {
        global $DB;

        $where = $courseid > 0 ? ' WHERE c.id = :courseid' : '';
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname, c.visible
               FROM {booking} b
               JOIN {course} c ON c.id = b.course" . $where . "
              ORDER BY c.fullname",
            $courseid > 0 ? ['courseid' => $courseid] : []
        );

        // How many options each instance holds, in ONE grouped query - the list is only useful if
        // it says where there is something to find, and the caller shows at most ten entries.
        $counts = $DB->get_records_sql(
            "SELECT bookingid,
                    COUNT(1) AS alloptions,
                    SUM(CASE WHEN invisible = 1 THEN 1 ELSE 0 END) AS hiddenoptions
               FROM {booking_options}
           GROUP BY bookingid"
        );

        $instances = [];
        foreach ($courses as $course) {
            try {
                if (!can_access_course($course)) {
                    continue;
                }
                $modinfo = get_fast_modinfo($course);
                foreach ($modinfo->get_instances_of('booking') as $cm) {
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $count = $counts[(int)$cm->instance] ?? null;
                    $all = (int)($count->alloptions ?? 0);
                    $hidden = (int)($count->hiddenoptions ?? 0);
                    // Count what THIS user may see, the same way the option tables filter them
                    // (invisible = 1 is hidden without the capability; invisible = 2 stays visible).
                    $mayseehidden = has_capability(
                        'mod/booking:canseeinvisibleoptions',
                        \context_module::instance((int)$cm->id)
                    );

                    $instances[] = [
                        'cmid' => (int)$cm->id,
                        'name' => format_string($cm->name),
                        'url' => (new \moodle_url('/mod/booking/view.php', ['id' => (int)$cm->id]))->out(false),
                        'courseid' => (int)$course->id,
                        'coursename' => format_string($course->fullname),
                        'courseurl' => (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false),
                        'optioncount' => $mayseehidden ? $all : max(0, $all - $hidden),
                    ];
                }
            } catch (\Throwable $e) {
                // Never let the helper break the graceful path.
                continue;
            }
        }

        // Most to find first: the caller shows only the first ten, and an alphabetical order can
        // push every populated activity out of that window.
        usort($instances, static function (array $a, array $b): int {
            return [$b['optioncount'], $a['coursename'], $a['name']]
                <=> [$a['optioncount'], $b['coursename'], $b['name']];
        });

        return $instances;
    }

    /**
     * Gate 2: enforce the native Moodle capability for a mutating skill's core action.
     *
     * The agent must never grant a right the user does not natively have — independent of
     * the agent skill capability (Gate 1). Call this from preflight() with the already
     * resolved course-module id. Returns a preflight failure when the capability is missing
     * at the module context, or null when the user may proceed.
     *
     * @param string $capability e.g. 'mod/booking:addoption'
     * @param int    $cmid       Resolved course-module id.
     * @param int    $userid
     * @return array<string,mixed>|null primitive invalid result, or null when the capability is held
     */
    protected function require_native_capability(string $capability, int $cmid, int $userid): ?array {
        if ($cmid <= 0) {
            // No target booking activity could be resolved (e.g. the agent was invoked at the site /
            // course context, not inside a booking instance). This is NOT a permission problem — the
            // capability check simply has no module to check against. Ask which booking activity to act
            // on instead of emitting a misleading "you lack <capability>" message (the user may well
            // hold the capability). Mirrors how the course skills clarify a missing course context.
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_missing_target_activity', 'mod_booking'),
                'code' => 'MISSING_TARGET_ACTIVITY',
                'repair' => 'No target activity resolved. Retry with optionid, a sharper optionquery, '
                    . 'or name the booking activity via activityquery.',
            ]]);
        }
        if (!has_capability($capability, \context_module::instance($cmid), $userid)) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', $capability),
                'code' => 'NO_NATIVE_CAPABILITY',
            ]]);
        }
        return null;
    }

    /**
     * Resolve the operating cmid for an option-scoped mutating skill and enforce Gate 2 there.
     *
     * This is the option-skill counterpart to calling resolve_cmid_from_context_or_cmid() +
     * require_native_capability() directly. It exists so that, when the target activity could not
     * be pinned from the named option (e.g. an optionquery that matches options in several
     * activities, reached at a non-module operating context such as an MCP system-context session),
     * the user gets an option-aware clarification listing the candidate activities — never the
     * misleading "open a booking activity" message, and never a false "you lack permission".
     *
     * The option_targeted_skill trait resolves the operating context for the common cases (optionid,
     * or an optionquery unique to one activity) before preflight runs, so here $operatingcontextid
     * is already the right module context and this method is a thin pass-through; the clarification
     * branch only fires for a genuinely ambiguous or context-less option reference.
     *
     * @param array  $input             The command input (optionid / optionquery / optionwhen).
     * @param int    $operatingcontextid The operating context id resolved by the pipeline.
     * @param string $capability        The native capability to enforce at the resolved activity.
     * @param int    $userid
     * @param string $lang              Output language for the clarification message.
     * @return array A resolved cmid ('cmid'), or a clarification to return ('clarification').
     */
    protected function resolve_option_operating_context(
        array $input,
        int $operatingcontextid,
        string $capability,
        int $userid,
        string $lang = ''
    ): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($operatingcontextid);

        if ($cmid <= 0) {
            $optionquery = trim((string)($input['optionquery'] ?? ''));
            if ($optionquery !== '' && empty($input['optionid'])) {
                $resolved = booking_skill_support::activity_for_option_query(
                    $optionquery,
                    trim((string)($input['optionwhen'] ?? ''))
                );
                if (($resolved['status'] ?? '') === 'not_found') {
                    // Not-found stays a not-found (#2334): the old fallback blamed the missing
                    // ambient activity and dead-ended API clients on "open a booking activity".
                    return ['clarification' => $this->invalid([[
                        'severity' => 'needs_clarification',
                        'code' => 'OPTION_NOT_FOUND',
                        'message' => $this->localized_string(
                            'agent_booking_option_query_notfound_anywhere',
                            s($optionquery),
                            $lang
                        ),
                        'repair' => 'No option matched anywhere. Retry with a different optionquery, '
                            . 'an optionid, or name the booking activity via activityquery.',
                    ]])];
                }
                if (($resolved['status'] ?? '') === 'ambiguous') {
                    $labels = [];
                    foreach ($resolved['candidates'] as $candidate) {
                        $labels[] = booking_skill_support::build_option_link_for_output(
                            (int)$candidate->cmid,
                            (int)$candidate->optionid
                        ) . ' (' . format_string($candidate->coursename) . ')';
                    }
                    return ['clarification' => $this->invalid([[
                        'code' => 'CONTEXT_TARGET_UNRESOLVED',
                        'severity' => 'needs_clarification',
                        'message' => $this->localized_string(
                            'agent_booking_option_target_ambiguous',
                            s($optionquery),
                            $lang
                        ) . ' ' . implode('; ', $labels),
                    ]])];
                }
            }
            // Genuinely no resolvable activity: fall back to the generic missing-activity clarification.
            return ['clarification' => $this->require_native_capability($capability, 0, $userid)];
        }

        $capdenied = $this->require_native_capability($capability, $cmid, $userid);
        if ($capdenied !== null) {
            return ['clarification' => $capdenied];
        }
        return ['cmid' => $cmid];
    }

    /**
     * Build a brief technical debug message for a task execution.
     *
     * @param string $taskname
     * @param array $input
     * @param array $extra Optional extra lines (e.g. result summary).
     * @return string
     */
    protected function build_task_debug_message(string $taskname, array $input, array $extra = []): string {
        $parts = [];

        // Recursively flatten complex nested arrays for display.
        $flatten = static function ($item) use (&$flatten) {
            if (is_array($item)) {
                $subsliced = array_slice($item, 0, 5);
                return '[' . implode(', ', array_map($flatten, $subsliced)) . ']';
            }
            return (string)$item;
        };

        foreach ($input as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $sliced = array_slice($value, 0, 5);
                $parts[] = $key . '=' . $flatten($sliced);
            } else {
                $parts[] = $key . '=' . $value;
            }
        }
        $lines = ['Task: ' . $taskname];
        if (!empty($parts)) {
            $lines[] = 'Params: ' . implode(', ', $parts);
        }
        foreach ($extra as $line) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Resolve preferred output language from task input.
     *
     * @param array $input
     * @return string
     */
    protected function get_output_language(array $input): string {
        return trim((string)($input['outputlang'] ?? ''));
    }

    /**
     * Coerce a possibly non-string input value to a string, safely.
     *
     * The planner/LLM occasionally emits a query field (e.g. optionquery) as an array. Casting
     * that with (string) raises an "Array to string conversion" warning and yields the literal
     * "Array", which then never resolves ("No option matched optionquery 'Array'", thread 593
     * matrix run). Non-scalar values become an empty string so the skill clarifies cleanly instead.
     *
     * @param mixed $value
     * @return string
     */
    protected static function scalar_string($value): string {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Normalize query-like identity values for stable queue-dedup hashing.
     *
     * @param string $value
     * @return string
     */
    protected function normalize_identity_query(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string)$value);
    }

    /**
     * Normalize an identity payload recursively for stable queue-dedup hashing.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function normalize_identity_value($value) {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_values(array_map(fn($entry) => $this->normalize_identity_value($entry), $value));
            }

            ksort($value);
            foreach ($value as $key => $entry) {
                $value[$key] = $this->normalize_identity_value($entry);
            }
            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Read a localized string, optionally forcing a specific output language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'booking', $a);
        }

        return get_string_manager()->get_string($identifier, 'booking', $a, $targetlang);
    }

    /**
     * Enforce a hard maximum character length on a string.
     *
     * @param string $text
     * @param int $maxchars
     * @return string
     */
    protected function enforce_max_chars(string $text, int $maxchars): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '' || $maxchars <= 0) {
            return '';
        }

        if (\core_text::strlen($normalized) <= $maxchars) {
            return $normalized;
        }

        $ellipsis = '...';
        $available = max(1, $maxchars - \core_text::strlen($ellipsis));
        $trimmed = trim(\core_text::substr($normalized, 0, $available));
        return $trimmed . $ellipsis;
    }

    /**
     * Return the preview descriptor for booking skills.
     *
     * @return array
     */
    /**
     * Provide the preview for an executed booking result as ready-to-insert data.
     *
     * Returns server-rendered HTML produced by the booking plugin's own renderer — the agent
     * framework never inspects the type or renders anything itself; it just forwards this block.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    /**
     * Remember the option ids a result previewed, so a follow-up "update those options" turn can
     * resolve them (the "use_preview_context_for_update" feature). Called duck-typed by the
     * executor; the framework itself carries no booking knowledge.
     *
     * @param array $optionids
     * @param int $cmid
     * @param int $userid
     * @return void
     */
    public function remember_preview_options(array $optionids, int $cmid, int $userid): void {
        $optionids = array_values(array_filter(array_map('intval', $optionids), static fn(int $id): bool => $id > 0));
        if (empty($optionids)) {
            return;
        }
        booking_skill_support::remember_last_preview_options_for_user_for_execute($userid, $cmid, $optionids);
    }

    /**
     * Provide the preview for an executed booking result as ready-to-insert data.
     *
     * Returns server-rendered HTML produced by the booking plugin's own renderer — the agent
     * framework never inspects the type or renders anything itself; it just forwards this block.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        // The "which booking activity do you mean" answer previews the activities themselves:
        // the whole list, linked, ordered by how much each one holds.
        if (!empty($resultentry['previewinstances']) && is_array($resultentry['previewinstances'])) {
            return self::build_instance_list_preview($resultentry['previewinstances']);
        }

        $optionids = [];
        if (isset($resultentry['previewoptionids']) && is_array($resultentry['previewoptionids'])) {
            foreach ($resultentry['previewoptionids'] as $id) {
                $optionids[] = (int)$id;
            }
        }
        if (isset($resultentry['resultid']) && (int)$resultentry['resultid'] > 0) {
            $optionids[] = (int)$resultentry['resultid'];
        }
        $optionids = array_values(array_unique(array_filter($optionids, static fn(int $id): bool => $id > 0)));
        if (empty($optionids)) {
            return null;
        }

        $rendered = (new booking_option_preview_renderer())->render(['optionids' => $optionids], $contextid, $userid);
        $html = (string)($rendered['html'] ?? '');
        if (trim($html) === '') {
            return null;
        }

        // The card's behaviour (booking button, prepage pages) lives in the templates' render-time
        // JS, which the client has to execute after injecting the HTML. Shipping the markup alone
        // would hand the preview pane a booking button that no handler listens to.
        return [
            'type' => 'booking_option',
            'html' => $html,
            'js' => (string)($rendered['js'] ?? ''),
            'payload' => ['optionids' => $optionids],
        ];
    }

    /**
     * Preview block for the list of accessible booking activities.
     *
     * Plain server-rendered markup (links only, no behaviour), so it needs no render-time JS.
     *
     * @param array $instances Entries of {@see self::list_accessible_booking_instances()}.
     * @return array|null The preview descriptor, or null when there is nothing to show.
     */
    protected static function build_instance_list_preview(array $instances): ?array {
        $shown = array_slice($instances, 0, self::PREVIEW_INSTANCE_LIMIT);
        if (empty($shown)) {
            return null;
        }

        $items = '';
        foreach ($shown as $instance) {
            $items .= \html_writer::tag(
                'li',
                \html_writer::link((string)$instance['url'], (string)$instance['name'])
                . ' ' . \html_writer::tag(
                    'span',
                    self::instance_facts_label((array)$instance),
                    ['class' => 'text-muted small']
                )
                . ' — ' . get_string('course') . ': '
                . \html_writer::link((string)$instance['courseurl'], (string)$instance['coursename']),
                ['class' => 'mb-1']
            );
        }

        $html = \html_writer::tag(
            'h5',
            get_string('agent_booking_no_instance_in_scope_courses', 'booking'),
            ['class' => 'mb-2']
        ) . \html_writer::tag('ul', $items, ['class' => 'list-unstyled']);

        if (count($instances) > count($shown)) {
            $html .= \html_writer::tag(
                'p',
                get_string('agent_booking_no_instance_more', 'booking', count($instances) - count($shown)),
                ['class' => 'text-muted small mb-0']
            );
        }

        return [
            'type' => 'booking_instance_list',
            'html' => \html_writer::div($html, 'booking-ai-preview-item'),
            'payload' => ['cmids' => array_map(static fn(array $i): int => (int)$i['cmid'], $shown)],
        ];
    }

    /**
     * A clarification issue for an option query that matched nothing, carrying the activity's options as choices.
     *
     * "Choices instead of an error" (George 2026-09-24): the engine hands the choices to the planner once
     * (PREFLIGHT_CHOICES_OFFERED), which picks the option by meaning and by its attributes (start, weekday) or
     * asks the user naming them. The code never matches a name here. The message is the skill's own localized
     * text: the shared resolver's "No option matched optionquery ..." is English, names a schema field and
     * repeats the user's words, so it is not carried into this issue.
     *
     * @param string $code Skill issue code.
     * @param int $cmid Booking activity.
     * @param int[] $preferredids Option ids most relevant to this skill, listed first.
     * @param string $lang Output language.
     * @return array Issue with field "optionid" and "candidates"; without candidates when the activity has none.
     */
    protected function option_miss_choices_issue(string $code, int $cmid, array $preferredids = [], string $lang = ''): array {
        $choices = booking_skill_support::option_choices($cmid, $preferredids);
        $issue = [
            'code' => $code,
            'severity' => 'needs_clarification',
            'message' => $this->localized_string(
                empty($choices) ? 'agent_booking_diagnose_error_option_resolve' : 'agent_booking_option_miss_choose_listed',
                null,
                $lang
            ),
        ];
        if (!empty($choices)) {
            $issue['field'] = 'optionid';
            $issue['candidates'] = $choices;
        }
        return $issue;
    }

    /**
     * The clarification issue for an option query that the shared resolver could not pin to one option.
     *
     * L45 UOT-2 (thread 14816): the update skills handed on the resolver's text ("No option matched optionquery ...",
     * "Please provide optionid") - English, naming schema fields - and no option to choose from. A miss now offers the
     * activity's options, several hits offer the hits first; the text is the language pack's. Nothing is matched by
     * name here: the planner picks by meaning and by the choices' start and weekday, or asks naming them.
     *
     * @param array $result Result of booking_skill_support::resolve_single_option() (status 'error' or 'ambiguity').
     * @param int $cmid Booking activity.
     * @param string $query The option query as given.
     * @param string $when The date qualifier as given.
     * @param string $lang Output language.
     * @return array Issue in the choice shape of option_miss_choices_issue().
     */
    protected function option_resolution_issue(array $result, int $cmid, string $query, string $when, string $lang = ''): array {
        if ((string)($result['status'] ?? '') !== 'ambiguity') {
            return $this->option_miss_choices_issue('OPTION_RESOLUTION_FAILED', $cmid, [], $lang);
        }
        $hits = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['optionid'] ?? 0),
            booking_skill_support::search_option_candidates_for_preview($cmid, $query, 50, $when)
        )));
        $issue = $this->option_miss_choices_issue('OPTION_RESOLUTION_AMBIGUOUS', $cmid, $hits, $lang);
        if (!empty($issue['candidates'])) {
            $issue['message'] = $this->localized_string('agent_booking_option_several_choose_listed', null, $lang);
        }
        return $issue;
    }

    /**
     * Resolve a diagnose-family option strictly within the current instance.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param string $lang
     * @return array{status:string,optionid?:int,message?:string,issue_code?:string}
     */
    protected function resolve_diagnose_option(array $input, int $cmid, int $userid, string $lang = ''): array {
        global $DB;

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return ['status' => 'ok', 'optionid' => $optionid];
            }

            // Stale or foreign optionid: prefer resolving a given title over failing hard.
            if ($optionquery !== '') {
                return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
            }

            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_option_not_in_instance', null, $lang),
            ];
        }

        if ($optionquery === '') {
            return [
                'status' => 'ambiguity',
                'message' => $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang),
            ];
        }

        if (booking_skill_support::is_last_option_reference($optionquery)) {
            $lastids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
            if (count($lastids) === 1) {
                return ['status' => 'ok', 'optionid' => (int)$lastids[0]];
            }
            if (count($lastids) > 1) {
                return [
                    'status' => 'ambiguity',
                    'message' => $this->localized_string('agent_booking_diagnose_ambiguity_last_preview_multiple', null, $lang),
                ];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_last_preview_none', null, $lang),
            ];
        }

        return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
    }
}
