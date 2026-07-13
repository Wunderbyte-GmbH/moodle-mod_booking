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

use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\booking\support\booking_mutation_validation;
use mod_booking\local\wizard\engine\queue_identity_provider_interface;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\local\wizard\engine\module_targeted_skill;

/**
 * Task definition for booking.create_option.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    // Generic activity-instance targeting: the engine resolves the operating booking instance
    // (ambient course first, then site-wide) and asks when ambiguous, instead of this skill
    // guessing a cmid. Inherited by the slot-booking / self-learning create subclasses.
    use module_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.create_option';

    /**
     * The module type whose instances this skill (and its create subclasses) targets.
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
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, ['mod/booking:addoption']);
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
     * Human-readable, curated preview of the option to be created (tier-3 confirmation preview).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::create_descriptor($input);
    }

    /**
     * Build queue business identity for create_option deduplication.
     *
     * The first implementation intentionally keys identity to title +
     * start/end datetime as requested.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $appliedaliases = [];
        $normalized = self::normalize_create_option_input($input, $appliedaliases);
        $normalized = self::strip_create_targeting_fields($normalized);

        $title = self::normalize_identity_string((string)($normalized['text'] ?? ''));
        $start = booking_skill_support::normalize_identity_datetime((string)($normalized['coursestarttime'] ?? ''));
        $end = booking_skill_support::normalize_identity_datetime((string)($normalized['courseendtime'] ?? ''));

        if (($start === '' || $end === '') && !empty($normalized['optiondates']) && is_array($normalized['optiondates'])) {
            $firstdate = reset($normalized['optiondates']);
            if (is_array($firstdate)) {
                if ($start === '') {
                    $start = booking_skill_support::normalize_identity_datetime((string)($firstdate['coursestarttime'] ?? ''));
                }
                if ($end === '') {
                    $end = booking_skill_support::normalize_identity_datetime((string)($firstdate['courseendtime'] ?? ''));
                }
            }
        }

        // Target context (contextid/cmid) is implicitly covered by thread scope:
        // each thread is exclusively bound to one user+contextid, so the business
        // identity is unique within the context without an explicit contextid entry.
        return [
            'task' => self::TASK_NAME,
            'text' => $title,
            'coursestarttime' => $start,
            'courseendtime' => $end,
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $properties = array_merge([
            'text' => [
                'type' => 'string',
                'description' => 'Title of the new booking option.',
                'required' => true,
            ],
            'override' => [
                'type' => 'array',
                'description' => 'Explicit override tokens for confirmed exceptions (e.g. duplicate_title).',
                'required' => false,
            ],
            'outputlang' => [
                'type' => 'string',
                'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                'required' => false,
            ],
            'activityquery' => [
                'type' => 'string',
                'description' => 'The booking activity the user named as the target, if any. If the user names a '
                    . 'booking activity in THIS message OR an earlier one — e.g. answering a "which booking '
                    . 'activity?" question with a name like "selflearning" — you MUST put that exact name here '
                    . 'verbatim, so the option is created in that activity. Leave empty ONLY when the user named no '
                    . 'specific activity (then the activity in scope is used, and the system asks which one if '
                    . 'several exist). Never guess or invent a name. This is the booking activity, NEVER a course — '
                    . 'do NOT use courseid or coursequery for this task; to LINK a Moodle course use '
                    . 'linkedcoursequery.',
                'required' => false,
            ],
            'linkedcoursequery' => [
                'type' => 'string',
                'description' => 'The Moodle COURSE participants enter after booking this option (booking → '
                    . 'enrolment). Pass the course full name/shortname to resolve and link it at create time. '
                    . 'This is NOT the target booking activity (that is activityquery) — it is the course the '
                    . 'booking gives access to. Leave empty when the user linked no course.',
                'required' => false,
            ],
        ], option_schema_definition::common_properties());

        // New options are always created hidden. Visibility can be changed later via booking.update_option only.
        unset($properties['invisible'], $properties['visibility']);

        if (static::TASK_NAME === self::TASK_NAME) {
            // General create_option focuses on normal dated options. Expose ONLY the core dated-
            // option fields so parameter construction faces a small, unambiguous schema. The ~70
            // inherited availability/condition fields (cohort/competency/userprofile/customform/…)
            // overwhelm the constructor and are rarely expressed at create time — they can be set
            // afterwards via update_option. optiontype is set internally and stripped in
            // check_structure, so it is intentionally NOT prompt-facing.
            // headerimage_token IS prompt-facing: the shared execute service
            // (booking_skill_mutation_execute_service::apply_headerimage_token_to_data) already
            // maps an attachment token to the option header image at create time, so "create it
            // with this image" is a supported request — not an invented key.
            $allowed = array_flip([
                'text', 'description', 'coursestarttime', 'courseendtime', 'optiondates', 'optiondatesmode',
                'maxanswers', 'teacherquery', 'teacheremail', 'prices',
                'bookingopeningtime', 'bookingclosingtime', 'maxoverbooking',
                'override', 'outputlang', 'activityquery', 'linkedcoursequery', 'headerimage_token',
            ]);
            $properties = array_intersect_key($properties, $allowed);
        }

        $schema = [
            'version' => 1,
            'description' => 'Create a new booking option in the current booking activity. '
                . 'Canonical keys for normal dated options are text, coursestarttime, courseendtime, '
                . 'maxanswers and optiondates; to target a named booking activity use activityquery '
                . '(never invent keys like coursequery; to LINK a Moodle course that participants enter '
                . 'after booking use linkedcoursequery). '
                . 'Do not use non-canonical keys like day/date/start/end for this task. '
                . 'Use this general create task when the user asks for a standard dated option '
                . '(for example "create a workshop next Tuesday from 10:00 to 12:00"). '
                . 'Also use this task for recurring weekday event series with fixed start/end times, '
                . 'numbered titles (for example "Lecture 1, Lecture 2, ..."), trainer assignment, and capacity. '
                . 'Use the specialized slot-booking task for appointment-slot availability and '
                . 'the specialized self-learning task for duration-based self-learning offers. '
                . 'New options are created as invisible by default and can be made visible later via update.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_create_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_create_option',
            'example_utterances' => [
                'Create a new booking option for a workshop next Tuesday from 10:00 to 12:00',
                'Add a bookable event titled "First Aid Course" with 20 seats',
                'Set up a weekday lecture series Monday to Friday next week',
                'I want to create a course that people can book',
                'Make a new dated event with a start and end time',
            ],
            'properties' => $properties,
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.create_option_canonical_fallback',
                'description' => 'Use only when the command explicitly names mod_booking.create_option or when the '
                    . 'user clearly wants a normal dated booking option. For normal dated events and numbered '
                    . 'weekday event series, use mod_booking.create_option. Use canonical keys '
                    . 'text, coursestarttime, courseendtime, maxanswers (and optiondates when needed). '
                    . 'Do not send extra keys like day/date/start/end in task input. '
                    . 'Dated series requests (for example "next week Monday-Friday") stay on '
                    . 'mod_booking.create_option, not slotbooking.',
            ],
            [
                'id' => 'mod_booking.force_create_duplicate_title',
                'description' => 'User explicitly confirms creating a new option although a duplicate title exists.',
            ],
            [
                'id' => 'mod_booking.skip_location_specification',
                'description' => 'User explicitly confirms creating a normal option without location/address.',
            ],
            [
                'id' => 'mod_booking.create_location_first_then_option',
                'description' => 'User asks to create/resolve a missing location first and then continue with option creation.',
            ],
            [
                'id' => 'mod_booking.select_option_type_change',
                'description' => 'User explicitly selects or confirms a normal booking option type. '
                    . 'Use mod_booking.create_option for standard dated options and weekday series with fixed '
                    . 'session times, numbering, trainer and capacity.',
                'examples' => [
                    'Create consecutively numbered events for next week on Monday, Wednesday and Thursday' .
                    'Create a dated weekday lecture series for next week (Mon/Wed/Thu, 20:00-22:00)',
                ],
            ],
        ];
    }

    /**
     * Normalize common LLM aliases to the canonical create_option schema.
     *
     * @param array $input
     * @param array $appliedaliases Canonical key => alias key actually used.
     * @return array
     */
    private static function normalize_create_option_input(array $input, array &$appliedaliases = []): array {
        foreach (array_keys($input) as $rawkey) {
            if (!is_string($rawkey)) {
                continue;
            }

            $trimmedkey = trim($rawkey);
            if ($trimmedkey === '' || $trimmedkey === $rawkey || array_key_exists($trimmedkey, $input)) {
                continue;
            }

            $input[$trimmedkey] = $input[$rawkey];
            $appliedaliases[$trimmedkey] = $rawkey;
            unset($input[$rawkey]);
        }

        $aliasgroups = [
            'text' => ['title', 'name', 'optionname', 'option_title', 'identifier', 'label', 'option_name', 'optiontext'],
            'maxanswers' => ['limit', 'limitanswers', 'spots', 'capacity', 'maxparticipants', 'max_participants'],
            'coursestarttime' => ['starttime', 'start', 'from'],
            'courseendtime' => ['endtime', 'end', 'to'],
            'bookingopeningtime' => ['bookingopen', 'booking_open', 'bookingopens', 'booking_opens'],
            'bookingclosingtime' => ['bookingclose', 'booking_close', 'bookingcloses', 'booking_closes'],
            'optiondates' => ['bookingdates', 'dates', 'sessions', 'occurrences'],
            'teacherquery' => ['teacher', 'trainer', 'instructor', 'instructorty', 'lecturer'],
            'teacheremail' => ['instructoremail', 'teacher_mail', 'teacher_email'],
            // W2 baseline 2026-07-12: 'price' (singular) is the measured top guess (5 of 7
            // wrong-key events). The scalar shape ("price": 30) is canonicalized right below.
            'prices' => ['price'],
        ];

        foreach ($aliasgroups as $canonical => $aliases) {
            if (!self::is_placeholder_value($input[$canonical] ?? null)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if (!array_key_exists($alias, $input) || self::is_placeholder_value($input[$alias])) {
                    continue;
                }

                $input[$canonical] = $input[$alias];
                $appliedaliases[$canonical] = $alias;
                break;
            }
        }

        // Canonicalize the prices SHAPE as early as the key: a bare numeric ("price": 30) means
        // the default price category, and the prepared input feeds the confirm preview — the
        // user must see the canonical object there, not a scalar. Mirrors
        // booking_skill_support::normalize_prices_input(), which stays the execute-side backstop.
        if (isset($input['prices']) && is_numeric($input['prices'])) {
            $input['prices'] = ['default' => (float)$input['prices']];
        }

        // Fuzzy fallback for unpredictable planner key variants (e.g. traineruserwm).
        foreach (array_keys($input) as $rawkey) {
            if (!is_string($rawkey) || $rawkey === '') {
                continue;
            }
            if ($rawkey === 'teacherquery' || $rawkey === 'teacheremail') {
                continue;
            }

            $value = $input[$rawkey] ?? null;
            if (self::is_placeholder_value($value)) {
                continue;
            }

            $keynorm = strtolower((string)preg_replace('/[^a-z0-9]+/', '', $rawkey));
            $ispeoplehint = (bool)preg_match('/teacher|trainer|instructor|lecturer/', $keynorm);
            if (!$ispeoplehint) {
                continue;
            }

            if (self::is_placeholder_value($input['teacherquery'] ?? null)) {
                $input['teacherquery'] = $value;
                $appliedaliases['teacherquery'] = $rawkey;
                unset($input[$rawkey]);
                continue;
            }

            if (
                self::is_placeholder_value($input['teacheremail'] ?? null)
                && is_string($value)
                && strpos($value, '@') !== false
            ) {
                $input['teacheremail'] = $value;
                $appliedaliases['teacheremail'] = $rawkey;
                unset($input[$rawkey]);
            }
        }

        foreach ($aliasgroups as $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $input)) {
                    unset($input[$alias]);
                }
            }
        }

        if (isset($input['optiondates']) && is_array($input['optiondates'])) {
            $input['optiondates'] = self::normalize_optiondate_items($input['optiondates']);
        }

        return $input;
    }

    /**
     * Normalize common LLM aliases inside optiondates arrays.
     *
     * @param array $optiondates
     * @return array
     */
    private static function normalize_optiondate_items(array $optiondates): array {
        $normalized = [];
        foreach ($optiondates as $item) {
            if (!is_array($item)) {
                continue;
            }

            $dateitem = [];
            foreach ($item as $key => $value) {
                if (is_string($key) && trim($key) !== '') {
                    $dateitem[trim($key)] = $value;
                }
            }

            foreach (['starttime', 'start', 'from', 'start_date', 'startdate'] as $alias) {
                if (!array_key_exists('coursestarttime', $dateitem) && array_key_exists($alias, $dateitem)) {
                    $dateitem['coursestarttime'] = $dateitem[$alias];
                }
            }
            foreach (['endtime', 'end', 'to', 'end_date', 'enddate'] as $alias) {
                if (!array_key_exists('courseendtime', $dateitem) && array_key_exists($alias, $dateitem)) {
                    $dateitem['courseendtime'] = $dateitem[$alias];
                }
            }

            $date = $dateitem['date'] ?? $dateitem['day'] ?? null;
            if ($date !== null) {
                if (!array_key_exists('coursestarttime', $dateitem) && array_key_exists('start_time', $dateitem)) {
                    $dateitem['coursestarttime'] = trim((string)$date) . 'T' . trim((string)$dateitem['start_time']);
                }
                if (!array_key_exists('courseendtime', $dateitem) && array_key_exists('end_time', $dateitem)) {
                    $dateitem['courseendtime'] = trim((string)$date) . 'T' . trim((string)$dateitem['end_time']);
                }
            }

            // Keep only canonical fields expected by downstream parsing.
            $canonical = [];
            if (array_key_exists('coursestarttime', $dateitem)) {
                $canonical['coursestarttime'] = $dateitem['coursestarttime'];
            }
            if (array_key_exists('courseendtime', $dateitem)) {
                $canonical['courseendtime'] = $dateitem['courseendtime'];
            }
            if (array_key_exists('optiondateid', $dateitem)) {
                $canonical['optiondateid'] = $dateitem['optiondateid'];
            }
            if (array_key_exists('daystonotify', $dateitem)) {
                $canonical['daystonotify'] = $dateitem['daystonotify'];
            }

            if (!empty($canonical)) {
                $normalized[] = $canonical;
            }
        }

        return $normalized;
    }

    /**
     * Build a compact retry message explaining canonical create-option keys.
     *
     * @param array $appliedaliases Canonical key => alias key actually used.
     * @param array $unknownprops
     * @param array $missingprops
     * @param bool $includeenlabelkeymap
     * @return string
     */
    private function build_create_option_retry_message(
        array $appliedaliases,
        array $unknownprops = [],
        array $missingprops = [],
        bool $includeenlabelkeymap = false
    ): string {
        $parts = [
            'Task execution was not successful.',
            'Retry ' . static::TASK_NAME . ' once with corrected canonical keys.',
        ];

        if ($includeenlabelkeymap) {
            $parts[] = 'Use canonical property keys only; do not use localized labels.';
        }

        if (static::TASK_NAME === 'mod_booking.create_slotbooking_option') {
            $parts[] = 'For slot booking include at least: text, slot_valid_from, slot_valid_until, '
                . 'slot_opening_time, slot_closing_time, slot_duration_minutes, '
                . 'slot_max_participants_per_slot, and one active slot_day_X=true.';
        }

        if (!empty($appliedaliases)) {
            $mapped = [];
            foreach ($appliedaliases as $canonical => $alias) {
                $mapped[] = $alias . ' -> ' . $canonical;
            }
            $parts[] = 'Applied alias mapping: ' . implode(', ', $mapped) . '.';
        }

        if (!empty($missingprops)) {
            $parts[] = 'Still missing required fields: ' . implode(', ', $missingprops) . '.';
        }

        if (!empty($unknownprops)) {
            $parts[] = 'Remove unknown keys: ' . implode(', ', $unknownprops) . '.';
        }

        if (static::TASK_NAME === self::TASK_NAME) {
            $parts[] = 'For normal dated create_option use canonical keys: '
                . 'text, coursestarttime, courseendtime, maxanswers, optiondates.';
            $parts[] = 'Do not use non-canonical keys such as day, date, start, end.';
        }

        if (in_array('activityquery', $this->get_supported_property_names(), true)) {
            $parts[] = 'To create the option in a named booking activity pass that name in activityquery; '
                . 'to link the Moodle course participants enter after booking pass linkedcoursequery — '
                . 'never use keys like coursequery or bookinginstancequery.';
        }

        $parts[] = 'Do not switch task and do not call documentation tasks for this repair.';
        // Shared escape-hatch: only use allowed keys, and if some part of the request maps to no
        // key, tell the user honestly instead of inventing one or promising an automatic retry.
        $parts[] = $this->build_unsupported_params_guidance($this->get_supported_property_names());

        return implode(' ', $parts);
    }

    /**
     * Build a key reference from schema + localized labels.
     *
     * @param bool $withdescriptions
     * @param bool $labelstokey
     * @return string
     */
    private function build_supported_property_reference(
        bool $withdescriptions = true,
        bool $labelstokey = false
    ): string {
        $schema = $this->get_schema();
        $properties = (array)($schema['properties'] ?? []);
        if (empty($properties)) {
            return '';
        }

        $entries = [];
        foreach ($properties as $key => $definition) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $label = trim((string)booking_skill_support::get_localized_property_label_for_output_in_language($key, 'en'));
            $description = trim((string)($definition['description'] ?? ''));

            if ($labelstokey) {
                $entry = ($label !== '' ? $label : $key) . ' -> ' . $key;
            } else {
                $entry = $key;
                if ($label !== '' && $label !== $key) {
                    $entry .= ' (' . $label . ')';
                }
                if ($withdescriptions && $description !== '') {
                    $entry .= ': ' . $description;
                }
            }

            $entries[] = $entry;
        }

        if (empty($entries)) {
            return '';
        }

        return implode($withdescriptions ? ' | ' : ', ', $entries);
    }

    /**
     * Structural validation - pure, no DB access.
     *
     * Checks that the required 'text' (title) field is present.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>,repair?:array<int,string>,observation_full?:string}
     */
    public function check_structure(array $input): array {
        $rawinput = $input;
        $appliedaliases = [];
        $input = self::normalize_create_option_input($input, $appliedaliases);
        $input = self::strip_create_targeting_fields($input);
        $rawinput = self::strip_create_targeting_fields($rawinput);

        if (static::TASK_NAME === 'mod_booking.create_slotbooking_option') {
            unset($input['optiontype'], $input['slot_enabled']);
            unset($rawinput['optiontype'], $rawinput['slot_enabled']);
        }

        if (static::TASK_NAME === self::TASK_NAME) {
            // Normal create_option always resolves to normal type at execute-time.
            // Tolerate planner noise keys instead of failing hard with schema mismatch.
            unset($input['type'], $input['optiontype']);
            unset($rawinput['type'], $rawinput['optiontype']);
        }

        $resolvedtype = self::resolve_requested_option_type($input);
        if (static::TASK_NAME === 'mod_booking.create_slotbooking_option') {
            $resolvedtype = 'slotbooking';
        }

        $text = trim((string)($input['text'] ?? ''));
        $missingtitle = ($text === '');
        $unknownprops = array_values(array_unique(array_merge(
            $this->get_unknown_input_property_names($input),
            $this->get_unknown_optiondate_item_keys($rawinput)
        )));
        $retrymessage = $this->build_create_option_retry_message(
            $appliedaliases,
            $unknownprops,
            $missingtitle ? ['text'] : []
        );

        // F3-W2 two-channel cause contract: 'errors' carries ONLY the user cause — plain-English
        // LLM material, never rendered directly (the synchronizer formulates the reply in the
        // user's language, hard rule 90c9399); 'repair' carries the planner-only instructions
        // (key lists, format specs, retry directives). Supplying 'repair' flips this skill's
        // audience guarantee (parameter_contract_validator).
        if ($missingtitle) {
            $observation = $this->build_unknown_property_observation($unknownprops, $rawinput, ['text']);
            return [
                'valid'  => false,
                'errors' => ['What title should the new booking option have?'],
                'repair' => [$retrymessage],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                'observation_full' => $observation,
            ];
        }

        $commonerrors = $this->validate_common_mutation_structure($input, true);
        if (!empty($commonerrors)) {
            return [
                'valid' => false,
                'errors' => ['Some of the provided details for the new booking option (for example '
                    . 'dates or times) could not be understood as given.'],
                'repair' => $commonerrors,
            ];
        }

        if (!empty($unknownprops)) {
            $observation = $this->build_unknown_property_observation($unknownprops, $rawinput);
            return [
                'valid' => false,
                'errors' => ['Some requested details do not match anything a new booking option '
                    . 'supports, so nothing was created.'],
                'repair' => [$observation],
                'observation_full' => $observation,
            ];
        }

        return ['valid' => true, 'errors' => [], 'repair' => []];
    }

    /**
     * Allowed keys inside an optiondates item: the canonical fields plus every alias the
     * normalizer maps. Anything else would be dropped SILENTLY by normalization — thread 545:
     * [{"timestart":…,"timeend":…}] normalized to [] and the clock-string fallback coerced all
     * five sessions to TODAY, invisible in the confirmation preview.
     *
     * @var array<int,string>
     */
    private const OPTIONDATE_ITEM_KEYS = [
        'coursestarttime', 'courseendtime', 'optiondateid', 'daystonotify',
        'starttime', 'start', 'from', 'start_date', 'startdate',
        'endtime', 'end', 'to', 'end_date', 'enddate',
        'date', 'day', 'start_time', 'end_time',
    ];

    /**
     * Unknown keys inside RAW optiondates items, reported as "optiondates[].<key>".
     *
     * Must run on the pre-normalization input: the normalizer strips unknown item keys before
     * the top-level unknown-property check ever sees them, which is exactly how the thread-545
     * inputs passed validation while losing their dates.
     *
     * @param array $input The RAW (pre-normalization) input.
     * @return array<int,string>
     */
    private function get_unknown_optiondate_item_keys(array $input): array {
        if (
            empty($input['optiondates'])
            || !is_array($input['optiondates'])
            || !in_array('optiondates', $this->get_supported_property_names(), true)
        ) {
            return [];
        }

        $allowed = array_flip(self::OPTIONDATE_ITEM_KEYS);
        $unknown = [];
        foreach ($input['optiondates'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (array_keys($item) as $key) {
                $key = trim((string)$key);
                if ($key !== '' && !isset($allowed[$key])) {
                    $unknown[] = 'optiondates[].' . $key;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Return unknown input keys that are not part of the create_option schema.
     *
     * @param array $input
     * @return array<int,string>
     */
    private function get_unknown_input_property_names(array $input): array {
        $supported = array_flip($this->get_supported_property_names());
        $unknown = [];

        foreach (array_keys($input) as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!isset($supported[$key])) {
                $unknown[] = $key;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Return the full create_option schema property names.
     *
     * @return array<int,string>
     */
    private function get_supported_property_names(): array {
        $schema = $this->get_schema();
        $properties = array_keys((array)($schema['properties'] ?? []));
        $properties = array_values(array_filter(array_map('strval', $properties)));
        sort($properties);
        return $properties;
    }

    /**
     * Build long-form observation text for schema-mismatch retries.
     *
     * @param array $unknownprops
     * @param array $input
     * @param array $missingprops
     * @return string
     */
    private function build_unknown_property_observation(
        array $unknownprops,
        array $input,
        array $missingprops = []
    ): string {
        $unknowntext = empty($unknownprops) ? '(none)' : implode(', ', $unknownprops);
        $message = 'Task execution was not successful. Create option schema mismatch. Unknown properties: '
            . $unknowntext . '.';

        $appliedaliases = [];
        $normalized = self::normalize_create_option_input($input, $appliedaliases);
        unset($normalized);

        return trim(implode(' ', array_filter([
            $message,
            $this->build_create_option_retry_message($appliedaliases, $unknownprops, $missingprops, true),
        ])));
    }

    /**
     * Build a concise preflight hint for missing/confirmable fields.
     *
     * @param array $missingrequired
     * @param array $confirmablewithoutfields
     * @return string
     */
    private function build_missing_fields_preflight_hint(
        array $missingrequired,
        array $confirmablewithoutfields = []
    ): string {
        $parts = ['Preflight: ' . static::TASK_NAME . ' needs additional input.'];

        if (!empty($missingrequired)) {
            $parts[] = 'Missing required fields: ' . implode(', ', array_values(array_unique($missingrequired))) . '.';
        }

        if (!empty($confirmablewithoutfields)) {
            $parts[] = 'Can continue without these if user confirms: '
                . implode(', ', array_values(array_unique($confirmablewithoutfields))) . '.';
        }

        $parts[] = 'Either provide the missing fields or confirm to proceed without the confirmable ones.';

        return implode(' ', $parts);
    }

    /**
     * Deep preflight validation — duplicate-title check, type-specific fields, slot sanity.
     *
     * Returns prepared_input ready for execute() with normalised overrides applied.
     * Does NOT perform writes.
     *
     * @param  array $input
     * @param  int   $contextid
     * @param  int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        // Backstop: when the engine could not resolve the booking instance (cmid 0 — e.g. the agent
        // runs from a global entry point and the cross-context target was not resolved/persisted),
        // ask which booking activity instead of letting downstream helpers crash with
        // "Invalid course module" (thread 562). The generic module-target resolver fills the cmid
        // before this fires on the normal path; this only catches the unresolved case.
        $scopeissue = $this->require_booking_instance_scope($cmid);
        if ($scopeissue !== null) {
            return $scopeissue;
        }
        $capdenied = $this->require_native_capability('mod/booking:addoption', $cmid, $userid);
        if ($capdenied !== null) {
            return $capdenied;
        }
        $lang = $this->get_output_language($input);
        $issues = [];
        $errors = [];
        $appliedaliases = [];
        $input = self::normalize_create_option_input($input, $appliedaliases);
        $input = self::strip_create_targeting_fields($input);
        // Stash the resolved target activity for the confirm preview
        // (option_preview_builder::target_rows). strip_create_targeting_fields() drops it
        // again on the execute path, so it never reaches the mutation service.
        $input['targetcmid'] = $cmid;

        if (static::TASK_NAME === self::TASK_NAME) {
            unset($input['slot_enabled'], $input['selflearningcourse'], $input['duration'], $input['disablecancel']);
            foreach (array_keys($input) as $key) {
                if (is_string($key) && str_starts_with($key, 'slot_')) {
                    unset($input[$key]);
                }
            }
        }

        // Linked-course resolution (prompt-facing key linkedcoursequery, U5c): resolve early so an
        // unresolvable course surfaces as a clarification instead of a late execute error, then map
        // to the mutation service's canonical coursequery key. coursequery itself stays a REJECTED
        // input key on create (thread-548 anti-hallucination guidance) — only the prepared input
        // carries it, after this skill resolved the link deliberately.
        $linkedcoursequery = trim((string)($input['linkedcoursequery'] ?? ''));
        unset($input['linkedcoursequery']);
        if ($linkedcoursequery !== '') {
            $linkresult = booking_skill_support::resolve_single_course($linkedcoursequery);
            if (($linkresult['status'] ?? '') !== 'ok') {
                $issues[] = [
                    'code'           => 'LINKED_COURSE_UNRESOLVED',
                    'severity'       => 'needs_clarification',
                    'message'        => (string)($linkresult['message'] ?? ''),
                    'user_question'  => (string)($linkresult['message'] ?? ''),
                    'remedy_options' => ['ASK_LINKED_COURSE'],
                ];
                return $this->invalid($issues);
            }
            $input['coursequery'] = $linkedcoursequery;
        }

        // Title is required (structural, but re-check for safety).
        if (empty($input['text'])) {
            // F3-W2: message/user_question are USER-channel fields — the retry directive with
            // its canonical-key vocabulary is planner material and moves to 'repair'.
            $retrymessage = $this->build_create_option_retry_message($appliedaliases, [], ['text']);
            $issues[] = [
                'code'           => 'MISSING_TITLE',
                'severity'       => 'needs_clarification',
                'message'        => $this->localized_string('agent_booking_create_option_missing_title', null, $lang),
                'user_question'  => $this->localized_string('agent_booking_create_option_which_title_question', null, $lang),
                'repair'         => $retrymessage,
                'remedy_options' => ['ASK_TITLE'],
            ];
            return $this->invalid($issues);
        }

        $overrides = self::normalize_overrides(is_array($input['override'] ?? null) ? $input['override'] : []);

        // Duplicate-title check.
        $duplicatecheck = booking_skill_support::find_existing_options_by_exact_title($cmid, (string)$input['text']);
        $allowduplicatetitle = in_array('duplicate_title', $overrides, true);
        if (!$allowduplicatetitle && ($duplicatecheck['status'] ?? '') === 'single') {
            $existingid = (int)($duplicatecheck['optionid'] ?? 0);
            $issues[] = [
                'code'           => 'DUPLICATE_TITLE_CONFIRM_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string('agent_booking_create_option_exists_single', $existingid, $lang),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_duplicate_exists_single_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CONFIRM_CREATE_WITH_DUPLICATE_TITLE', 'UPDATE_EXISTING_INSTEAD'],
            ];
            return $this->invalid($issues);
        } else if (!$allowduplicatetitle && ($duplicatecheck['status'] ?? '') === 'multiple') {
            $issues[] = [
                'code'           => 'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string(
                    'agent_booking_create_option_exists_multiple',
                    (string)($duplicatecheck['candidates'] ?? ''),
                    $lang
                ),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_duplicate_exists_multiple_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CONFIRM_CREATE_WITH_DUPLICATE_TITLE', 'SELECT_EXISTING_OPTION_TO_UPDATE'],
            ];
            return $this->invalid($issues);
        }

        // Duplicate-signature check: same title plus same normalized start/end window.
        // This blocks accidental follow-up re-creates even when title-duplicate was
        // previously confirmed in another turn.
        $allowduplicatesignature = in_array('duplicate_signature', $overrides, true);
        if (!$allowduplicatesignature) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
            $bookingid = (int)($cm->instance ?? 0);
            $startsig = self::normalize_signature_timestamp($input['coursestarttime'] ?? null);
            $endsig = self::normalize_signature_timestamp($input['courseendtime'] ?? null);

            if ($bookingid > 0 && $startsig > 0 && $endsig > 0) {
                global $DB;
                $existing = $DB->get_record('booking_options', [
                    'bookingid' => $bookingid,
                    'text' => (string)$input['text'],
                    'coursestarttime' => $startsig,
                    'courseendtime' => $endsig,
                ], 'id', IGNORE_MISSING);

                if ($existing && !empty($existing->id)) {
                    $issues[] = [
                        'code' => 'DUPLICATE_SIGNATURE_CONFIRM_REQUIRED',
                        'severity' => 'needs_confirmation',
                        'message' => 'An option with the same title and time window already exists (id=' .
                            (int)$existing->id . ').',
                        'user_question' => 'Do you want to create another option with the exact same title and time window?',
                        'remedy_options' => ['REUSE_EXISTING_OPTION', 'CONFIRM_CREATE_WITH_DUPLICATE_SIGNATURE'],
                    ];
                    return $this->invalid($issues);
                }
            }
        }

        $resolvedtype = self::resolve_requested_option_type($input);
        if ($resolvedtype === 'unknown') {
            $resolvedtype = 'normal';
        }

        // Intentionally no required-field preflight beyond title.
        // Missing teacher/location/schedule/capacity must not block creation.

        // Slot-booking sanity (soft issues only).
        if ($resolvedtype === 'slotbooking') {
            $slotissues = self::validate_slotbooking_sanity($input);
            if (!empty($slotissues)) {
                $issues = array_merge($issues, $slotissues);
            }
        }

        // Placeholder value check.
        $placeholderfields = self::check_placeholder_values($input, $overrides, $resolvedtype, $lang);
        if (!empty($placeholderfields)) {
            $issues[] = [
                'code'           => 'CONFIRMATION_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string('agent_booking_create_option_confirm_missing_values', null, $lang),
                'user_question'  => $this->localized_string('agent_booking_create_option_confirm_missing_values', null, $lang),
                'remedy_options' => ['ADD_OVERRIDE_AND_RETRY'],
            ];
            foreach ($placeholderfields as $err) {
                $issues[] = ['code' => 'PLACEHOLDER_VALUE', 'severity' => 'needs_clarification', 'message' => $err];
            }
        }

        // Location soft-confirmation.
        $allowemptylocation = in_array('location', $overrides, true) || in_array('address', $overrides, true);
        if (!$allowemptylocation && isset($input['location']) && trim((string)$input['location']) !== '') {
            $issues[] = [
                'code'           => 'LOCATION_NOT_FOUND_POSSIBLE',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string(
                    'agent_booking_create_option_location_not_found_question',
                    null,
                    $lang
                ),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_location_not_found_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CREATE_LOCATION_THEN_CREATE_OPTION', 'ASK_FOR_DIFFERENT_LOCATION'],
            ];
        }

        // Service-level preflight (teacher resolution, date normalization, etc.).
        $servicepreflight = booking_mutation_validation::validate_common($input, $cmid, self::TASK_NAME);
        if (!empty($servicepreflight['errors']) || !empty($servicepreflight['ambiguities'])) {
            $serviceissuecodes = array_values(array_filter(array_map('strval', (array)($servicepreflight['issue_codes'] ?? []))));
            // F3-W2: consume the two-channel error_details — issue 'message' is a USER-channel
            // field, so it carries the user_cause; format specs/JSON examples ride in 'repair'.
            $servicedetails = array_values((array)($servicepreflight['error_details'] ?? []));
            foreach ((array)($servicepreflight['errors'] ?? []) as $idx => $err) {
                $detail = is_array($servicedetails[$idx] ?? null) ? $servicedetails[$idx] : [];
                $usercause = trim((string)($detail['user_cause'] ?? ''));
                $repair = trim((string)($detail['repair'] ?? ''));
                $issue = [
                    'code' => (string)($serviceissuecodes[$idx] ?? 'PREFLIGHT_ERROR'),
                    'severity' => 'needs_clarification',
                    'message' => $usercause !== '' ? $usercause : (string)$err,
                ];
                if ($repair !== '') {
                    $issue['repair'] = $repair;
                }
                $issues[] = $issue;
            }
            foreach ((array)($servicepreflight['ambiguities'] ?? []) as $amb) {
                $issues[] = ['code' => 'PREFLIGHT_AMBIGUITY', 'severity' => 'needs_clarification', 'message' => (string)$amb];
            }
            return $this->invalid($issues);
        }

        $preparedinput = $input;

        // Canonicalize prices into the prepared input so the confirmation preview shows the
        // canonical {identifier: price} object (not a bare scalar or the labelled array a model
        // may have sent, thread 593) and execute runs the same shape. validate_common already
        // accepted the prices above, so normalization here cannot fail; leave it untouched if it
        // somehow returns null.
        if (isset($preparedinput['prices'])) {
            $canonicalprices = booking_skill_support::normalize_prices_input_for_execute($preparedinput['prices']);
            if (is_array($canonicalprices)) {
                $preparedinput['prices'] = $canonicalprices;
            }
        }

        // If there are only confirmable issues (no blocking ones), use confirmable().
        $blockingissues = array_filter(
            $issues,
            static fn(array $i): bool => ($i['severity'] ?? '') === 'needs_clarification'
        );
        if (!empty($blockingissues)) {
            return $this->invalid($issues);
        }
        if (!empty($issues)) {
            return $this->confirmable($preparedinput, $issues);
        }

        return $this->pass($preparedinput);
    }

    /**
     * Build a structured issue payload for downstream user-facing clarification logic.
     *
     * @param string $code
     * @param string $question
     * @param array $remedies
     * @return array
     */
    private static function build_issue(string $code, string $question, array $remedies = []): array {
        return [
            'code' => $code,
            'severity' => 'needs_confirmation',
            'user_question' => $question,
            'remedy_options' => $remedies,
        ];
    }

    /**
     * Returns true when any of the given keys exists in input.
     *
     * @param array $input
     * @param array $keys
     * @return bool
     */
    private static function has_any_key(array $input, array $keys): bool {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve requested booking option type from normalized AI input.
     *
     * @param array $input
     * @return string One of normal|selflearning|slotbooking|unknown.
     */
    private static function resolve_requested_option_type(array $input): string {
        if (!empty($input['slot_enabled'])) {
            return 'slotbooking';
        }

        if (array_key_exists('optiontype', $input)) {
            $normalized = strtolower(trim((string)$input['optiontype']));
            if (in_array($normalized, ['0', 'normal', 'withdates', 'with_dates', 'default'], true)) {
                return 'normal';
            }
            if (in_array($normalized, ['1', 'selflearning', 'self-learning', 'selflearningcourse'], true)) {
                return 'selflearning';
            }
            if (in_array($normalized, ['2', 'slot', 'slotbooking', 'slot-booking'], true)) {
                return 'slotbooking';
            }
        }

        if (!empty($input['selflearningcourse'])) {
            return 'selflearning';
        }

        // Keep type resolution strictly schema-driven. Do not infer control flow from
        // free text or language-specific tokens.

        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                return 'slotbooking';
            }
        }

        return 'unknown';
    }

    /**
     * Validate missing required input depending on the selected option type.
     *
     * @param array $input
     * @param string $resolvedtype
     * @param array $overrides
     * @return array
     */
    private static function validate_type_specific_required_fields(
        array $input,
        string $resolvedtype,
        array $overrides = []
    ): array {
        $errors = [];

        if ($resolvedtype === 'normal') {
            if (!array_key_exists('maxanswers', $input)) {
                $errors[] = get_string('agent_booking_create_normal_missing_maxanswers', 'booking');
            }

            $hasoptiondates = self::has_any_key($input, ['optiondates']);
            $hassinglestart = self::has_any_key($input, ['coursestarttime']);

            if (!$hasoptiondates && !$hassinglestart) {
                $errors[] = get_string('agent_booking_create_normal_missing_startdate', 'booking');
            }

            if (!$hasoptiondates && !self::has_any_key($input, ['duration', 'courseendtime'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_duration', 'booking');
            }

            $allowemptylocation = in_array('location', $overrides, true) || in_array('address', $overrides, true);
            if (!$allowemptylocation && !self::has_any_key($input, ['location', 'address'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_location', 'booking');
            }

            $allowemptyteacher = in_array('teacherquery', $overrides, true) || in_array('teacheremail', $overrides, true);
            if (!$allowemptyteacher && !self::has_any_key($input, ['teacherquery', 'teacheremail'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_teacher', 'booking');
            }

            return $errors;
        }

        if ($resolvedtype === 'selflearning') {
            if (!array_key_exists('maxanswers', $input)) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_maxanswers', 'booking');
            }

            if (!array_key_exists('duration', $input)) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_duration', 'booking');
            }

            if (!self::has_any_key($input, ['teacherquery', 'teacheremail'])) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_teacher', 'booking');
            }

            return $errors;
        }

        if ($resolvedtype === 'slotbooking') {
            $slotweekdaykeys = [
                'slot_day_1', 'slot_day_2', 'slot_day_3', 'slot_day_4', 'slot_day_5', 'slot_day_6', 'slot_day_7',
            ];

            $slottype = strtolower(trim((string)($input['slot_type'] ?? 'fixed')));

            if ($slottype === 'userdefined') {
                if ((int)($input['slot_custom_max_duration'] ?? 0) <= 0) {
                    $errors[] = get_string('agent_booking_create_slotbooking_missing_custom_duration', 'booking');
                }
            } else {
                // Required: slot duration (how long is each slot).
                if (!array_key_exists('slot_duration_minutes', $input)) {
                    $errors[] = get_string('agent_booking_create_slotbooking_missing_duration', 'booking');
                }
            }

            // Required: max participants per slot.
            if (!array_key_exists('slot_max_participants_per_slot', $input)) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_participants', 'booking');
            }

            // Required: time window.
            if (!self::has_any_key($input, ['slot_opening_time', 'slot_closing_time'])) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_timewindow', 'booking');
            }

            // Required: validity date range.
            if (!self::has_any_key($input, ['slot_valid_from', 'slot_valid_until'])) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_validity', 'booking');
            }

            // Required: at least one weekday must be EXPLICITLY set to true.
            $hasactiveday = false;
            foreach ($slotweekdaykeys as $dk) {
                if (!empty($input[$dk])) {
                    $hasactiveday = true;
                    break;
                }
            }
            if (!$hasactiveday) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_weekday', 'booking');
            }

            return $errors;
        }

        return $errors;
    }

    /**
     * Smart sanity check for slotbooking configuration.
     * Only checks things that cannot be expressed as hard validation errors.
     *
     * @param array $input
     * @return array Array of issues.
     */
    private static function validate_slotbooking_sanity(array $input): array {
        $issues = [];

        // Check: slot_duration_minutes covers the entire daily window → only 1 slot per day.
        // The user likely confused the time window with the individual slot length.
        if (
            isset($input['slot_opening_time'], $input['slot_closing_time'], $input['slot_duration_minutes'])
        ) {
            $openingmins = self::parse_time_hhmm_to_minutes((string)$input['slot_opening_time']);
            $closingmins = self::parse_time_hhmm_to_minutes((string)$input['slot_closing_time']);
            $windowmins  = $closingmins - $openingmins;
            $slotmins    = (int)$input['slot_duration_minutes'];

            if ($windowmins > 0 && $slotmins >= $windowmins) {
                $issues[] = self::build_issue(
                    'SLOTBOOKING_DURATION_EQUALS_WINDOW',
                    'The slot duration (' . $slotmins . ' min) covers the entire availability window '
                        . $input['slot_opening_time'] . '-' . $input['slot_closing_time']
                        . ' (' . $windowmins . ' min), which would create only 1 slot per day. '
                        . 'Did you mean a shorter slot duration (e.g. 30 minutes), '
                        . 'or do you really want just 1 slot covering the whole window?',
                    ['SET_SHORTER_SLOT_DURATION', 'CONFIRM_SINGLE_SLOT_PER_DAY']
                );
            }
        }

        return $issues;
    }

    /**
     * Parse a HH:MM string to total minutes since midnight.
     *
     * @param string $hhmm e.g. "12:00" or "16:30"
     * @return int Minutes since midnight, or 0 on parse failure.
     */
    private static function parse_time_hhmm_to_minutes(string $hhmm): int {
        $parts = explode(':', trim($hhmm));
        if (count($parts) < 2) {
            return 0;
        }
        return (int)$parts[0] * 60 + (int)$parts[1];
    }


    /**
     * Check for placeholder values (0, empty string, null) and require override confirmation.
     *
     * @param array $input
     * @param array $overrides
     * @param string $resolvedtype
     * @param string $lang
     *
     * @return array
     *
     */
    private static function check_placeholder_values(
        array $input,
        array $overrides,
        string $resolvedtype = 'normal',
        string $lang = ''
    ): array {
        $errors = [];

        // Define field pairs where at least one should have a real value.
        $fieldpairs = [
            ['coursestarttime', 'optiondates'],
            ['duration', 'courseendtime'],
            ['location', 'address'],
            ['teacherquery', 'teacheremail'],
        ];

        if ($resolvedtype !== 'slotbooking') {
            $fieldpairs[] = 'maxanswers';
        }

        // Field labels for friendly messages.
        $labels = [
            'coursestarttime' => 'start date/time',
            'optiondates' => 'date ranges',
            'duration' => 'duration',
            'courseendtime' => 'end date/time',
            'location' => 'location',
            'address' => 'address',
            'teacherquery' => 'teacher',
            'teacheremail' => 'teacher email',
            'maxanswers' => 'max participants',
        ];

        foreach ($fieldpairs as $pair) {
            if (is_string($pair)) {
                // Single required field like 'maxanswers'.
                if (array_key_exists($pair, $input)) {
                    $val = $input[$pair];
                    if (self::is_placeholder_value($val)) {
                        if (!in_array($pair, $overrides, true)) {
                            $label = $labels[$pair] ?? $pair;
                            $errors[] = get_string_manager()->get_string(
                                'agent_booking_create_option_placeholder_override_required_single',
                                'booking',
                                (object)['label' => $label, 'field' => $pair],
                                $lang
                            );
                        }
                    }
                }
            } else {
                // Pair of fields - check if both are placeholders.
                $pairvalues = [];
                $pairhaspair = false;
                foreach ((array)$pair as $fieldname) {
                    if (array_key_exists($fieldname, $input)) {
                        $pairhaspair = true;
                        $val = $input[$fieldname];
                        $pairvalues[$fieldname] = self::is_placeholder_value($val);
                    }
                }

                if ($pairhaspair && count($pairvalues) > 0) {
                    // Check if any field in the pair has a non-placeholder value.
                    $hasrealvalue = false;
                    $placeholderfields = [];
                    foreach ($pairvalues as $fieldname => $isplaceholder) {
                        if ($isplaceholder) {
                            $placeholderfields[] = $fieldname;
                        } else {
                            $hasrealvalue = true;
                        }
                    }

                    // If all fields in the pair are placeholders and no real value found.
                    if (!$hasrealvalue && !empty($placeholderfields)) {
                        $needsoverride = [];
                        $labelssubset = [];
                        foreach ($placeholderfields as $fieldname) {
                            if (!in_array($fieldname, $overrides, true)) {
                                $needsoverride[] = $fieldname;
                                $labelssubset[] = $labels[$fieldname] ?? $fieldname;
                            }
                        }

                        if (!empty($needsoverride)) {
                            $desc = implode(' or ', $labelssubset);
                            $fieldlist = implode('", "', $needsoverride);
                            $errors[] = get_string_manager()->get_string(
                                'agent_booking_create_option_placeholder_override_required',
                                'booking',
                                (object)['labels' => $desc, 'fields' => $fieldlist],
                                $lang
                            );
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Check if a value is a placeholder (0, '0', empty string, null).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_placeholder_value($value): bool {
        return $value === 0 || $value === '0' || $value === '' || $value === null || (is_array($value) && empty($value));
    }

    /**
     * Normalize free-text fields for stable queue identity hashing.
     *
     * @param string $value
     * @return string
     */
    private static function normalize_identity_string(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        return strtolower((string)$value);
    }

    /**


    /**
     * Whether a normalized field has a non-empty meaningful value.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_non_empty_value(mixed $value): bool {
        if (self::is_placeholder_value($value)) {
            return false;
        }

        return trim((string)$value) !== '';
    }

    /**
     * Normalize override tokens to lowercase, trimmed strings.
     *
     * @param array $overrides
     * @return array
     */
    private static function normalize_overrides(array $overrides): array {
        $normalized = [];
        foreach ($overrides as $override) {
            if (!is_string($override)) {
                continue;
            }

            $token = strtolower(trim($override));
            if ($token === '') {
                continue;
            }
            $normalized[] = $token;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Ground the constructor in the site's REAL price categories (thread 593).
     *
     * The static prices example ({"default":10,"student":20}) is generic doc text, so the
     * constructor cannot know the real identifiers nor map "for students" onto one — it then
     * invents a labelled array ([{price:30,label:"Studierendenpreis"}]). Injecting the actual
     * categories (identifier + name) plus the object-shape rule lets it build the canonical
     * {identifier: price}. Inherited by the whole create family. DB-derived data + an
     * instruction, no lexical detection (hard rule).
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        $categories = booking_skill_support::describe_active_price_categories();
        if (empty(booking_skill_support::active_price_category_identifiers())) {
            return [
                'guidance' => [
                    '- This site has NO price categories configured: do not send a prices field; '
                        . 'if the user asks for a price, say pricing must be set up first.',
                ],
            ];
        }
        return [
            'guidance' => [
                '- The prices field MUST be a JSON object mapping these EXACT price category '
                    . 'identifiers to numeric amounts. Categories available on this site (identifier '
                    . 'and label): ' . $categories . '. Map the user\'s wording to the closest '
                    . 'identifier (e.g. a student/reduced price → the "student" identifier if present). '
                    . 'NEVER use labels as keys, NEVER use an array, NEVER invent identifiers.',
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array
     */
    public function get_contextual_prompt_packs(): array {
        return [
            $this->header_image_attachment_prompt_pack(),
            [
                'id' => 'mod_booking.create_option_required_details',
                'triggers' => [
                    'create option', 'new option', 'create booking option', 'add option',
                    'new booking option', 'create a new option',
                    'create booking option',
                ],
                'guidance' => [
                    '- For mutating intent, prepare booking.create_option and use confirmation_request first.',
                    '- booking.create_option always creates options as invisible.',
                    '- If the user explicitly wants the option visible, first create it, then run booking.update_option '
                        . 'to set visibility/invisible.',
                    '- For mod_booking.create_option, do NOT send optiontype; choose the correct create task instead.',
                    '- For dated sessions in mod_booking.create_option, provide optiondates as a list and let '
                        . 'execution map this to booking_option::update indexed fields (_0, _1, ...).',
                    '- Use this task for normal options. For slotbooking use mod_booking.create_slotbooking_option.',
                    '- For self-learning use mod_booking.create_selflearning_option.',
                    '- Do not ask end users for internal type names; infer the best type from intent and phrasing.',
                    '- If type is still unclear, ask behavior-focused questions (e.g. fixed dates, self-paced, or bookable slots),'
                        . ' not technical labels.',
                    '- Do not ask whether to create or update when the user asks to create a booking possibility.',
                    '- If validation asks for confirmation, do not invent new wording; follow the issue question.',
                    '- To proceed after explicit user confirmation of exceptions, retry with matching override tokens.',
                    '- Known override tokens in create flow include: duplicate_title, coursestarttime, duration,'
                        . ' location, address, teacherquery, teacheremail, maxanswers.',
                    '- Prefer concise clarification questions; avoid technical text in user-facing message.',
                ],
            ],
            [
                'id' => 'mod_booking.course_teacher',
                'triggers' => ['course', 'teacher', 'trainer'],
                'guidance' => [
                    '- To connect the Moodle course participants enter after booking, pass its name as'
                        . ' linkedcoursequery at create time (never invent keys like coursequery or courseid;'
                        . ' on mod_booking.update_option the same link is set via coursequery).',
                    '- To target a named booking activity, pass its name as activityquery — that is WHERE the'
                        . ' option is created, while linkedcoursequery is the course the booking gives access to.',
                    '- Use teacherquery or teacheremail to assign responsible teacher.',
                    '- If the user says to assign themselves as teacher (e.g. "me as teacher"),'
                        . ' set teacherquery to the current user/self-reference instead of asking for an e-mail address.',
                    '- Only ask for teacheremail when no resolvable teacher name or self-reference was provided.',
                ],
            ],
            [
                'id' => 'mod_booking.availability_conditions',
                'triggers' => [
                    'availability', 'enrolled', 'cohort', 'competency',
                    'previously booked', 'overlapping', 'profile field', 'condition', 'restriction',
                ],
                'guidance' => [
                    '- Availability conditions cannot be set while creating: create_option has none of these keys.'
                        . ' Create the option first, then set the conditions via mod_booking.update_option.',
                    '- In mod_booking.update_option use enrolledincoursequery (+ optional enrolledincourseoperator)'
                        . ' for the enrolled-in-course condition.',
                    '- In mod_booking.update_option use enrolledincohortquery (+ optional enrolledincohortoperator)'
                        . ' for the cohort condition.',
                    '- In mod_booking.update_option use hascompetencyquery (+ optional hascompetencyoperator)'
                        . ' for the competency condition.',
                    '- In mod_booking.update_option use previouslybookedquery'
                        . ' (+ optional previouslybookedrequirecompletion) for prerequisites.',
                    '- In mod_booking.update_option use selectusersquery for an explicit allowlist'
                        . ' and nooverlappingmode with "block" or "warn".',
                    '- In mod_booking.update_option use allowedtobookininstance'
                        . ' (+ optional allowedtobookininstancecapabilitynotneeded)'
                        . ' and userprofilestandard*/userprofilecustom* fields for profile-based conditions.',
                ],
            ],
            [
                'id' => 'mod_booking.selflearning_cancel',
                'triggers' => ['self-learning', 'selflearning', 'duration', 'hours', 'cancel', 'storno', 'stornieren'],
                'guidance' => [
                    '- Self-learning is its own task: use mod_booking.create_selflearning_option with'
                        . ' selflearningcourse=true and duration in seconds (e.g. 4h = 14400)'
                        . ' — create_option has no such keys.',
                    '- Cancellation behaviour is set via mod_booking.update_option: keep disablecancel'
                        . ' absent or false to allow self-cancellation, set disablecancel=true to prevent it.',
                ],
            ],
            [
                'id' => 'mod_booking.bookusers',
                'triggers' => ['book user', 'book users', 'assign user', 'enrol user'],
                'guidance' => [
                    '- create_option cannot book users (it has no bookusersquery key): create the option first,'
                        . ' then book the users via mod_booking.update_option.',
                    '- In mod_booking.update_option, map the person to bookusersquery and the option to optionquery'
                        . ' (e.g. "book <person> into option <option>").',
                    '- Do not ask for user id or e-mail when a name query is already present.',
                    '- Ask for a more specific user identifier only after a real ambiguity (multiple matched users).',
                    '- Optional fields in mod_booking.update_option: bookuserstimebooked, bookuserscompleted,'
                        . ' bookusersupdateexisting.',
                    '- For pure booking in mod_booking.update_option, do not include'
                        . ' additional option-update fields in the same command.',
                ],
            ],
            [
                'id' => 'mod_booking.datetime',
                'triggers' => ['date', 'time', 'datetime', 'tomorrow', 'today', 'next', 'this week', 'next week'],
                'guidance' => [
                    '- Resolve relative dates against current Moodle timezone and current datetime from system prompt.',
                    '- Prefer ISO 8601 for date/time fields in command input.',
                    '- Never use old hardcoded timestamps from examples.',
                    '- For recurring series phrased as "next week" without explicit weekday constraints, '
                        . 'default to Monday-Friday (5 occurrences).',
                    '- If a resolved date appears in the past and user did not ask for past dates, ask clarification.',
                ],
            ],
            [
                'id' => 'mod_booking.customform',
                'triggers' => [
                    'custom form', 'customform', 'formular', 'form element', 'formelement', 'bo_cond',
                    'checkbox', 'dropdown', 'select element',
                ],
                'guidance' => [
                    '- Custom form conditions cannot be set while creating: create the option first, then use'
                        . ' "customformelements" via mod_booking.update_option (one item per row).',
                    '- Supported formtype values: advcheckbox, static, shorttext, select, url, mail,'
                        . ' deleteinfoscheckboxuser, enrolusersaction.',
                    '- Each item can include: label, value, required, enroluserstowaitinglist.',
                    '- For formtype "select", value must be a multiline string with one option per line.',
                    '- Select line formats: key => Display name; key => Display name => Max bookings => Price => Allowed user IDs.',
                    '- Key must not contain spaces or special characters.',
                ],
            ],
        ];
    }

    /**
     * Verify that relevant fields were persisted as requested.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * @param  array $preparedinput  Resolved input from preflight().
     * @param  int   $contextid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        // Backstop (execute twin of the preflight guard): never run a create against an unresolved
        // booking instance (cmid 0). Return a graceful "which booking activity?" result instead of
        // surfacing a raw "Invalid course module" from get_coursemodule_from_id (thread 562).
        $noscope = $this->build_no_instance_scope_result($cmid);
        if ($noscope !== null) {
            return $noscope;
        }
        $preparedinput = self::strip_create_targeting_fields($preparedinput);
        if (static::TASK_NAME === self::TASK_NAME) {
            unset($preparedinput['slot_enabled'], $preparedinput['selflearningcourse']);
            foreach (array_keys($preparedinput) as $key) {
                if (is_string($key) && str_starts_with($key, 'slot_')) {
                    unset($preparedinput[$key]);
                }
            }
            $preparedinput['optiontype'] = 'normal';
            unset($preparedinput['duration'], $preparedinput['disablecancel']);
        }
        $service = new booking_skill_mutation_execute_service($this->attachments());
        $result = $service->execute(self::TASK_NAME, $preparedinput, $cmid, $userid, $this->support);
        if (is_array($result)) {
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $preparedinput,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => $this->localized_string('agent_booking_unknown_task', self::TASK_NAME),
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
        ];
    }

    /**
     * Drop targeting fields that belong to update/bulk flows.
     *
     * create_option always creates a fresh option and must never resolve an
     * existing option by query/id in preflight or execute.
     *
     * @param array $input
     * @return array
     */
    private static function strip_create_targeting_fields(array $input): array {
        unset($input['optionquery'], $input['optionid'], $input['optionwhen']);
        // Preview-only key stashed by preflight for the confirmation preview
        // (option_preview_builder::target_rows); never an option field.
        unset($input['targetcmid']);
        // Framework addressing keys the planner sometimes adds to the option payload. They are
        // never part of a create-option input (the option is created in the resolved booking
        // instance), so normalize them away instead of rejecting them as "unknown keys" — the
        // latter caused spurious "schema mismatch" failures (e.g. create_selflearning_option).
        // activityquery is a targeting handle consumed by the engine (get_target_selector) BEFORE
        // preflight; it must never leak into the option fields.
        unset(
            $input['courseid'],
            $input['contextid'],
            $input['cmid'],
            $input['bookingid'],
            $input['activityquery']
        );
        return $input;
    }

    /**
     * Normalize a timestamp-like value to integer signature form.
     *
     * @param mixed $value
     * @return int
     */
    private static function normalize_signature_timestamp($value): int {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $intvalue = (int)trim($value);
            return $intvalue > 0 ? $intvalue : 0;
        }

        return 0;
    }
}
