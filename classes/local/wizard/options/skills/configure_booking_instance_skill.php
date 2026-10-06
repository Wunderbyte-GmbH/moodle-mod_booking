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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking\local\wizard\options\skills;

use context_module;
use mod_booking\booking;
use mod_booking\local\wizard\engine\module_targeted_skill;
use mod_booking\utils\wb_payment;
use stdClass;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task: update (configure) the current booking activity instance — WRITE-ONLY.
 *
 * Applies a set of field/value changes to the booking activity and writes exactly these fields
 * (mutating, confirmation-gated). The former read path
 * (action=list_fields) moved to the read-only skill mod_booking.list_instance_settings
 * ({@see list_instance_settings_skill}); a pure read must never travel through the
 * confirmation queue. The "action" input field is kept for compatibility: action=list_fields
 * answers with a graceful redirect to the read skill instead of an empty confirm preview.
 *
 * The schema intentionally omits the full field catalog to keep the initial prompt concise.
 * The LLM should call mod_booking.list_instance_settings first when the user wants to know
 * what is configurable, and only then issue a targeted action=update command here.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_booking_instance_skill extends booking_skill_base implements skill_trigger_provider_interface {
    use module_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.configure_booking_instance';

    /**
     * This skill targets a booking activity instance.
     *
     * @return string
     */
    public function get_target_modname(): string {
        return 'booking';
    }

    /**
     * Fields the agent is allowed to read and change, with human-readable metadata.
     *
     * Each entry: 'field' => ['label' => string, 'type' => string, 'description' => string]
     *
     * This is intentionally NOT included in get_schema() to avoid token bloat in the initial
     * prompt.  Access it via action=list_fields.
     */
    private const CONFIGURABLE_FIELDS = [
        'name' => [
            'label' => 'Name',
            'type' => 'string',
            'description' => 'Display name of the booking activity.',
        ],
        'intro' => [
            'label' => 'Description / Intro',
            'type' => 'string',
            'description' => 'Introductory text shown above the booking list (plain text or HTML).',
        ],
        'organizatorname' => [
            'label' => 'Organizer name',
            'type' => 'string',
            'description' => 'Name of the person or organization running this booking.',
        ],
        'eventtype' => [
            'label' => 'Event type',
            'type' => 'string',
            'description' => 'Free-text label for the kind of event (e.g. "Seminar", "Workshop").',
        ],
        'maxperuser' => [
            'label' => 'Max bookings per user',
            'type' => 'integer',
            'description' => 'Maximum number of booking options a single user may book (0 = unlimited).',
        ],
        'allowupdate' => [
            'label' => 'Allow booking updates',
            'type' => 'boolean',
            'description' => 'Whether participants can change their booking after confirmation (1 = yes, 0 = no).',
        ],
        'cancancelbook' => [
            'label' => 'Allow cancellation',
            'type' => 'boolean',
            'description' => 'Whether participants are allowed to cancel their own booking (1 = yes, 0 = no).',
        ],
        'sendmail' => [
            'label' => 'Send confirmation email',
            'type' => 'boolean',
            'description' => 'Whether a confirmation email is sent to participants on booking (1 = yes, 0 = no).',
        ],
        'sendmailtobooker' => [
            'label' => 'Send email to booker',
            'type' => 'boolean',
            'description' => 'Whether the person performing the booking (e.g. manager) also receives a copy (1 = yes, 0 = no).',
        ],
        'daystonotify' => [
            'label' => 'Days before event to notify participants',
            'type' => 'integer',
            'description' => 'How many days before the event start a reminder email is sent to participants (0 = disabled).',
        ],
        'notifyemail' => [
            'label' => 'Notification email address (participants)',
            'type' => 'string',
            'description' => 'Additional email address to notify alongside participants.',
        ],
        'daystonotifyteachers' => [
            'label' => 'Days before event to notify teachers',
            'type' => 'integer',
            'description' => 'How many days before the event start a reminder is sent to teachers (0 = disabled).',
        ],
        'notifyemailteachers' => [
            'label' => 'Notification email address (teachers)',
            'type' => 'string',
            'description' => 'Additional email address to notify alongside teachers.',
        ],
        'bookingpolicy' => [
            'label' => 'Booking policy',
            'type' => 'string',
            'description' => 'Policy text participants must accept before booking (plain text or HTML).',
        ],
        'pollurl' => [
            'label' => 'Poll / survey URL (participants)',
            'type' => 'string',
            'description' => 'URL of a survey or poll shown to participants after booking.',
        ],
        'pollurltext' => [
            'label' => 'Poll URL link text (participants)',
            'type' => 'string',
            'description' => 'Clickable link label for the participant poll URL.',
        ],
        'pollurlteachers' => [
            'label' => 'Poll / survey URL (teachers)',
            'type' => 'string',
            'description' => 'URL of a survey shown to teachers.',
        ],
        'pollurlteacherstext' => [
            'label' => 'Poll URL link text (teachers)',
            'type' => 'string',
            'description' => 'Clickable link label for the teacher poll URL.',
        ],
        'paginationnum' => [
            'label' => 'Options per page',
            'type' => 'integer',
            'description' => 'Number of booking options shown per page in the list view (0 = site default).',
        ],
        'duration' => [
            'label' => 'Default duration',
            'type' => 'string',
            'description' => 'Default duration string for new booking options (e.g. "60").',
        ],
        'points' => [
            'label' => 'Points',
            'type' => 'float',
            'description' => 'Points awarded to participants who complete a booking option.',
        ],
        'showinapi' => [
            'label' => 'Show in API',
            'type' => 'boolean',
            'description' => 'Whether this booking instance and its options are exposed via the public API (1 = yes, 0 = no).',
        ],
        // The view settings live in the instance JSON ('storage'), take view ids ('choice' / 'choicelist', see
        // get_choices()) and, beyond the list view, need Booking PRO ('requirespro' for the whole field).
        'viewparam' => [
            'label' => 'Default view',
            'type' => 'choice',
            'storage' => 'json',
            'labelstring' => 'viewparam',
            'description' => 'Default view of the booking options: one view id (list, cards, list with image left, right'
                . ' or left over half the width). Every view except the list view needs Booking PRO.',
        ],
        'switchtemplates' => [
            'label' => 'Users can switch between views',
            'type' => 'boolean',
            'storage' => 'json',
            'requirespro' => true,
            'labelstring' => 'switchtemplates',
            'description' => 'Whether users can switch between views (1 = yes, 0 = no). Needs Booking PRO.',
        ],
        'switchtemplatesselection' => [
            'label' => 'Views users can switch between',
            'type' => 'choicelist',
            'storage' => 'json',
            'requirespro' => true,
            'labelstring' => 'switchtemplatesselection',
            'description' => 'The views users can switch between: comma-separated view ids. Needs Booking PRO.',
        ],
    ];

    /**
     * The configurable-field catalog, shared with the read-only list skill.
     *
     * mod_booking.list_instance_settings reads the same catalog (plus current values), so
     * both skills always describe the identical set of fields.
     *
     * @return array
     */
    public static function get_configurable_fields(): array {
        return self::CONFIGURABLE_FIELDS;
    }

    /**
     * The configurable identifiers with their labels as structured remedies for a clarification.
     *
     * @return array<int,array{id:string,label:string}>
     */
    private static function field_remedies(): array {
        $remedies = [];
        foreach (self::CONFIGURABLE_FIELDS as $identifier => $meta) {
            $remedies[] = ['id' => (string)$identifier, 'label' => (string)$meta['label']];
        }
        return $remedies;
    }

    /**
     * Construction hint: the constructor never sees the property schema, only example values and
     * guidance, so the configurable identifiers are handed over here (data-derived, #2411).
     *
     * @param int $contextid Context id.
     * @param int $userid Acting user.
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        $fields = [];
        foreach (self::CONFIGURABLE_FIELDS as $identifier => $meta) {
            $fields[] = $identifier . ' (' . $meta['label'] . ')';
        }
        $views = [];
        foreach (booking::get_array_of_all_views('en') as $id => $label) {
            $views[] = $id . ' (' . $label . ')';
        }
        return [
            'guidance' => [
                '- changes[].field MUST be one of these EXACT identifiers: ' . implode(', ', $fields)
                    . '. Map the user\'s wording to the closest identifier; NEVER invent field names.',
                '- viewparam takes ONE view id, switchtemplatesselection a comma-separated list of view ids. View ids: '
                    . implode(', ', $views) . '.',
            ],
        ];
    }

    /**
     * The choices of a 'choice' / 'choicelist' field: all views, independent of the license.
     *
     * The license is checked after the value was recognised, so a PRO view is answered with the PRO hint and not
     * with "unknown value".
     *
     * @param string $field Field identifier.
     * @param string $lang Output language ('' = current).
     * @return array|null id => localized label; null for a field without choices.
     */
    private static function get_choices(string $field, string $lang = ''): ?array {
        $type = (string)(self::CONFIGURABLE_FIELDS[$field]['type'] ?? '');
        if ($type !== 'choice' && $type !== 'choicelist') {
            return null;
        }
        return booking::get_array_of_all_views($lang === '' ? null : $lang);
    }

    /**
     * The ids a choice value names, or null when a part is not the id of a choice.
     *
     * Accepts an id, a list of ids or a comma-separated string of ids. A word is never mapped to an id here:
     * it is answered with the choices, and selection picks the id (engine path for offered choices).
     *
     * @param mixed $value Raw value from the construction.
     * @param array $choices id => label.
     * @param bool $multiple Whether several ids are allowed.
     * @return int[]|null
     */
    private static function parse_choice_ids($value, array $choices, bool $multiple): ?array {
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            if ((string)(int)$part !== $part || !array_key_exists((int)$part, $choices)) {
                return null;
            }
            $ids[] = (int)$part;
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids) || (!$multiple && count($ids) !== 1)) {
            return null;
        }
        return $ids;
    }

    /**
     * Localized name of a configurable setting for user texts (never the field identifier).
     *
     * @param string $field Field identifier.
     * @param string $lang Output language ('' = current).
     * @return string
     */
    private function setting_label(string $field, string $lang): string {
        return $this->localized_string((string)(self::CONFIGURABLE_FIELDS[$field]['labelstring'] ?? ''), null, $lang);
    }

    /**
     * The clarification for a setting that needs Booking PRO on a site without it (hint with link, no choices).
     *
     * @param string $field Field identifier.
     * @param string $lang Output language ('' = current).
     * @return array
     */
    private function requires_pro_issue(string $field, string $lang): array {
        $message = $this->localized_string('agent_booking_configure_requires_pro', $this->setting_label($field, $lang), $lang);
        return [
            'severity' => 'needs_clarification',
            'code' => 'CONFIGURE_INSTANCE_REQUIRES_PRO',
            'message' => $message,
            'user_question' => $message,
        ];
    }

    /**
     * Constructor — this task is mutating (requires confirmation).
     */
    public function __construct() {
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, ['mod/booking:updatebooking']);
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
     * Human-readable preview of the instance settings change (tier-3): each changed field.
     *
     * @param array $input Prepared input ({action, changes:[{field,value}]}).
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        // Choice fields show the localized labels of the chosen ids on the card, not the ids.
        $lang = trim((string)($input['outputlang'] ?? ''));
        $fieldspec = self::CONFIGURABLE_FIELDS;
        foreach (array_keys($fieldspec) as $field) {
            $choices = self::get_choices($field, $lang);
            if ($choices !== null) {
                $fieldspec[$field]['options'] = $choices;
            }
        }
        return option_preview_builder::configure_instance_descriptor($input, $fieldspec);
    }

    /**
     * Return task schema.
     *
     * Note: the full list of configurable fields is intentionally omitted here to keep
     * the initial planner prompt concise.  The LLM should issue action=list_fields first
     * to discover what can be changed.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // The selector sees only the first 240 characters (sentence-aware): mode, input and the
            // read-only sibling come first (#2411, run 9 CBI-2).
            'description' => 'CHANGE settings of the booking activity instance (write): action=update with a changes'
                . ' array, e.g. confirmation mails, cancellation, bookings per user.',
            'is' => 'Writing a setting of a booking activity, including its name.',
            // Wave 32 (UA-3, L43 thread 13277): a link or other activity of the course went to this card, whose NOT
            // named only hiding and moving; counterpart of course.update_activity's NOT line (<= 160 characters).
            'not' => 'Reading settings (list_instance_settings); a link or another activity of the course, or generic '
                . 'edits like hiding or moving (course.update_activity).',
            'readonly' => $this->is_read_only(),
            'prompt_meta' => [
                'intent' => 'Change settings of one booking activity instance: name, intro, organizer, limits, mails, views.',
            ],
            'fallback_confirm_string_key' => 'ai_status_confirm_configure_booking_instance',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_configure_booking_instance',
            'example_utterances' => [
                'Change the settings of this booking activity',
                'Set the maximum bookings per user to 3',
                'Turn off the confirmation emails of this booking instance',
                'Rename the booking activity and adjust its defaults',
                'Show the booking options of this activity as cards and let users switch views',
            ],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course-module id of the booking activity, when it is known — e.g. from a '
                        . 'candidate list that names "cmid <id>" or from a link. Takes precedence over '
                        . 'activityquery; use it to pick one of several activities that share a name.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of the target booking activity, when it is not the '
                        . 'current one (e.g. over MCP, which runs at the system context). If omitted and the '
                        . 'site has a single booking activity in scope it is used automatically.',
                    'required' => false,
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Required. Always "update" (requires the "changes" array).'
                        . ' The legacy value "list_fields" is only accepted for compatibility and answers'
                        . ' with a redirect to the read-only skill mod_booking.list_instance_settings.',
                    'required' => true,
                ],
                'changes' => [
                    'type' => 'array',
                    'description' => 'For action=update: array of {field, value} objects to apply.'
                        . ' Use the read-only skill mod_booking.list_instance_settings first to'
                        . ' discover valid field names.',
                    'required' => false,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string', 'description' => 'Field name (snake_case).'],
                            'value' => ['type' => 'string', 'description' => 'New value (always as string; will be cast).'],
                        ],
                    ],
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for response strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Check task input structure (no DB access).
     *
     * @param array $input
     * @return array{valid:bool,errors:array,ambiguities:array}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $action = trim((string)($input['action'] ?? ''));

        if (!in_array($action, ['list_fields', 'update'], true)) {
            $errors[] = 'action must be "list_fields" or "update".';
        }

        if ($action === 'update') {
            $changes = $input['changes'] ?? null;
            if (!is_array($changes) || empty($changes)) {
                $errors[] = 'action=update requires a non-empty "changes" array.';
            } else {
                // Shape only. An unknown field NAME is a recoverable input error handled in preflight
                // (clarification with the configurable identifiers as remedies, #2411): as a structural
                // error it made the engine retry the constructor until the loop budget was exhausted.
                foreach ($changes as $idx => $change) {
                    if (!is_array($change)) {
                        $errors[] = "changes[$idx]: must be an object with \"field\" and \"value\".";
                        continue;
                    }
                    $field = trim((string)($change['field'] ?? ''));
                    if ($field === '') {
                        $errors[] = "changes[$idx]: \"field\" is required.";
                    }
                    if (!array_key_exists('value', $change)) {
                        $errors[] = "changes[$idx]: \"value\" is required.";
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Preflight validation with DB access.
     *
     * Checks that the user has capability to manage the booking instance and
     * pre-validates field values before showing the confirmation dialog.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($cmid <= 0) {
            // No target booking activity (e.g. invoked at the site context). Not a permission problem
            // and not a place to call context_module::instance() (which would throw) — ask which one.
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_missing_target_activity', 'mod_booking'),
                'repair' => 'Name the booking activity via activityquery.',
                'code' => 'MISSING_TARGET_ACTIVITY',
            ]]);
        }
        // Capability check.
        $context = context_module::instance($cmid);
        if (!has_capability('mod/booking:updatebooking', $context, $userid)) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', 'mod/booking:updatebooking'),
                'code' => 'NO_CAPABILITY_CONFIGURE_INSTANCE',
            ]]);
        }

        $action = trim((string)($input['action'] ?? ''));

        // Reads moved to the read-only skill mod_booking.list_instance_settings — a pure read
        // must never enter the confirmation queue (it produced an empty confirm preview).
        // Answer with a graceful redirect instead of queueing.
        if ($action === 'list_fields') {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_configure_use_list_instance_settings', 'booking'),
                'code' => 'RECOVERABLE_INPUT_ERROR',
            ]]);
        }

        // Stash the resolved target activity so the confirm preview can name it
        // (option_preview_builder::target_rows). Execute ignores this key.
        $input['targetcmid'] = $cmid;

        // For update: unknown field names are a clarification (with the configurable identifiers as
        // structured remedies), known fields are type-checked.
        $changes = (array)($input['changes'] ?? []);
        $issues = [];
        $lang = $this->get_output_language($input);
        foreach ($changes as $idx => $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string)($change['field'] ?? ''));
            if (!isset(self::CONFIGURABLE_FIELDS[$field])) {
                $issues[] = [
                    'severity' => 'needs_clarification',
                    'code' => 'CONFIGURE_INSTANCE_UNKNOWN_FIELD',
                    'field' => 'changes',
                    'message' => $this->localized_string('agent_booking_configure_unknown_field', $field, $lang),
                    'user_question' => $this->localized_string('agent_booking_configure_unknown_field_question', $field, $lang),
                    'remedy_options' => self::field_remedies(),
                ];
                continue;
            }
            $meta = self::CONFIGURABLE_FIELDS[$field];
            $value = $change['value'] ?? '';
            $choices = self::get_choices($field, $lang);
            if ($choices !== null) {
                $ids = self::parse_choice_ids($value, $choices, $meta['type'] === 'choicelist');
                if ($ids === null) {
                    // Not an id of a choice (e.g. the user's word for a view): offer the choices. The engine hands
                    // them to selection for one re-plan, which constructs the command again with the chosen id.
                    $question = $this->localized_string('agent_booking_configure_choice_question', (object)[
                        'setting' => $this->setting_label($field, $lang),
                        'choices' => implode(', ', $choices),
                    ], $lang);
                    $issues[] = [
                        'severity' => 'needs_clarification',
                        'code' => 'CONFIGURE_INSTANCE_VALUE_NOT_A_CHOICE',
                        'field' => 'changes[' . $idx . '].value',
                        'message' => $question,
                        'user_question' => $question,
                        'candidates' => array_map(
                            static fn(int $id, string $label): array => ['id' => $id, 'label' => $label],
                            array_keys($choices),
                            array_values($choices)
                        ),
                    ];
                    continue;
                }
                $needspro = !empty($meta['requirespro']) || array_diff($ids, [MOD_BOOKING_VIEW_PARAM_LIST]) !== [];
                if ($needspro && !wb_payment::pro_version_is_activated()) {
                    $issues[] = $this->requires_pro_issue($field, $lang);
                    continue;
                }
                $input['changes'][$idx]['value'] = $meta['type'] === 'choice' ? $ids[0] : $ids;
                continue;
            }
            if (!empty($meta['requirespro']) && !wb_payment::pro_version_is_activated()) {
                $issues[] = $this->requires_pro_issue($field, $lang);
                continue;
            }
            $typevalid = $this->validate_field_value_type($field, $meta['type'], $value);
            if ($typevalid !== null) {
                $issues[] = [
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_booking_configure_value_invalid', null, $lang),
                    'code' => 'CONFIGURE_INSTANCE_FIELD_TYPE_ERROR',
                ];
            }
        }

        if (!empty($issues)) {
            return $this->invalid($issues);
        }

        return $this->pass($input);
    }

    /**
     * Execute the task.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);

        $action = trim((string)($input['action'] ?? ''));

        // Action: list_fields — moved to the read-only skill mod_booking.list_instance_settings.
        // Answer with a graceful redirect (established wrong-tool pattern), never a crash;
        // checked before target resolution because a redirect needs no target.
        if ($action === 'list_fields') {
            return $this->build_list_fields_redirect_result();
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return $this->error_result("Could not resolve booking instance for cmid=$cmid.");
        }

        $bookingid = (int)$cm->instance;

        // Action: update.
        if ($action === 'update') {
            return $this->execute_update($input, $bookingid, $cmid, $cm);
        }

        return $this->error_result("Unknown action \"$action\".");
    }

    // -------------------------------------------------------------------------
    // Private: action handlers.

    /**
     * Graceful redirect for legacy action=list_fields calls (read path moved).
     *
     * Mirrors build_no_instance_scope_result(): a complete, non-crashing result whose
     * observation instructs the planner to call mod_booking.list_instance_settings.
     *
     * @return array
     */
    private function build_list_fields_redirect_result(): array {
        $message = get_string('agent_booking_configure_use_list_instance_settings', 'booking');

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            'observation_full' => 'WRONG SKILL: mod_booking.configure_booking_instance is write-only'
                . ' (action=update). To list the configurable booking instance settings with their'
                . ' current values, call the read-only skill mod_booking.list_instance_settings'
                . ' instead. Do NOT retry action=list_fields on this skill.',
            // Engine-facing routing text without user data: exempt from anonymization.
            'observation_engine_static' => true,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, [], [
                'action: list_fields',
                'redirect: mod_booking.list_instance_settings',
            ]),
        ];
    }

    /**
     * Apply the requested changes to the booking instance record.
     *
     * Writes only the requested fields and keeps the side effects of an instance update
     * (event with the change list, cache refresh); see the comment in the method body.
     *
     * @param array    $input
     * @param int      $bookingid
     * @param int      $cmid
     * @param stdClass $cm
     * @return array
     */
    private function execute_update(array $input, int $bookingid, int $cmid, stdClass $cm): array {
        global $DB;

        // Load current record as base.
        $record = $DB->get_record('booking', ['id' => $bookingid]);
        if (!$record) {
            return $this->error_result("Booking instance id=$bookingid not found.");
        }

        $changes = (array)($input['changes'] ?? []);
        $applied = [];
        $skipped = [];
        $update = (object)['id' => $bookingid];
        // JSON-backed settings are set on the stored JSON; every other key in it stays as it is.
        $jsonholder = (object)['json' => (string)($record->json ?? '')];
        $jsonchanged = false;

        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string)($change['field'] ?? ''));
            if (!isset(self::CONFIGURABLE_FIELDS[$field])) {
                $skipped[] = $field . ' (unknown)';
                continue;
            }
            $meta = self::CONFIGURABLE_FIELDS[$field];
            $choices = self::get_choices($field);
            if ($choices !== null) {
                $ids = self::parse_choice_ids($change['value'] ?? '', $choices, $meta['type'] === 'choicelist');
                if ($ids === null) {
                    $skipped[] = $field . ' (invalid value)';
                    continue;
                }
                $value = $meta['type'] === 'choice' ? $ids[0] : $ids;
            } else {
                $value = $this->cast_value($field, $meta['type'], $change['value'] ?? '');
            }
            if (($meta['storage'] ?? '') === 'json') {
                booking::add_data_to_json($jsonholder, $field, $value);
                $jsonchanged = true;
            } else {
                $update->$field = $value;
            }
            $applied[] = $field . ' = ' . $this->format_value_for_summary($value);
        }

        if (empty($applied)) {
            return $this->error_result('No valid changes were provided.');
        }
        if ($jsonchanged) {
            // As on the settings form: without the view switcher there is no selection of views to switch between.
            $switcher = json_decode($jsonholder->json, true)['switchtemplates'] ?? null;
            if ($switcher !== null && empty($switcher)) {
                booking::remove_key_from_json($jsonholder, 'switchtemplatesselection');
            }
            $update->json = $jsonholder->json;
        }

        // Only the requested fields are written. booking_update_instance() handles mod_form data: every
        // setting that a DB record carries only in the JSON or not at all (timerestrict, viewparam,
        // disablecancel, ...) reads as empty there and was reset (Wunderbyte-GmbH/Wunderbyte-GmbH#2494).
        // Its side effects for a changed instance are kept: event with the change list, cache refresh.
        $update->timemodified = time();
        $changelist = booking::booking_instance_get_changes($record, $update);
        $context = context_module::instance($cmid);
        \mod_booking\event\bookinginstance_updated::create([
            'context' => $context,
            'objectid' => $cmid,
            'other' => ['changes' => $changelist ?? ''],
        ])->trigger();
        $DB->update_record('booking', $update);
        booking::purge_cache_for_booking_instance_by_cmid($cmid);
        \course_modinfo::purge_course_module_cache($cm->course, $cmid);
        rebuild_course_cache($cm->course, false, true);

        $editlink = (new \moodle_url('/course/modedit.php', ['update' => $cmid]))->out(false);
        $summary = 'Booking instance updated. Changed: ' . implode(', ', $applied) . '.';
        if (!empty($skipped)) {
            $summary .= ' Skipped (unknown): ' . implode(', ', $skipped) . '.';
        }
        $summary .= ' Edit: ' . $editlink;

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'observation_full' => $summary,
            'applied' => $applied,
            'skipped' => $skipped,
            'link' => $editlink,
            'resultid' => $bookingid,
            'task' => self::TASK_NAME,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'bookingid: ' . $bookingid,
                'applied: ' . implode(', ', $applied),
                'skipped: ' . implode(', ', $skipped),
            ]),
        ];
    }

    // -------------------------------------------------------------------------
    // Private: helpers.

    /**
     * Validate that a value is compatible with the declared field type.
     *
     * @param string $field
     * @param string $type
     * @param mixed  $value
     * @return string|null  Error string or null if valid.
     */
    private function validate_field_value_type(string $field, string $type, $value): ?string {
        switch ($type) {
            case 'integer':
                if (!is_numeric($value)) {
                    return "Expected integer, got \"$value\".";
                }
                break;
            case 'float':
                if (!is_numeric($value)) {
                    return "Expected numeric value, got \"$value\".";
                }
                break;
            case 'boolean':
                $normalized = strtolower(trim((string)$value));
                if (!in_array($normalized, ['0', '1', 'true', 'false', 'yes', 'no'], true)) {
                    return 'Expected boolean (0/1/true/false/yes/no).';
                }
                break;
        }
        return null;
    }

    /**
     * Cast a string value to the correct PHP type for the DB field.
     *
     * @param string $field
     * @param string $type
     * @param mixed  $raw
     * @return mixed
     */
    private function cast_value(string $field, string $type, $raw) {
        switch ($type) {
            case 'integer':
            case 'boolean':
                $normalized = strtolower(trim((string)$raw));
                if (in_array($normalized, ['true', 'yes', '1'], true)) {
                    return 1;
                }
                if (in_array($normalized, ['false', 'no', '0'], true)) {
                    return 0;
                }
                return (int)$raw;
            case 'float':
                return (float)$raw;
            default:
                return (string)$raw;
        }
    }

    /**
     * Format a value for display in the summary string.
     *
     * @param mixed $value
     * @return string
     */
    private function format_value_for_summary($value): string {
        if ($value === null) {
            return '(null)';
        }
        $str = is_array($value) ? implode(',', $value) : (string)$value;
        if (strlen($str) > 80) {
            return substr($str, 0, 77) . '...';
        }
        return $str;
    }

    /**
     * Build a generic error result array.
     *
     * @param string $message
     * @return array
     */
    private function error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
        ];
    }

    /**
     * Build a compact debug string for the result payload.
     *
     * @param string $taskname
     * @param array  $input
     * @param array  $extra
     * @return string
     */
    protected function build_task_debug_message(string $taskname, array $input, array $extra = []): string {
        return $taskname . ' | ' . implode(', ', $extra);
    }

    /**
     * The situation in which the selector routes here; rendered as the card's WHEN line.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.configure_booking_instance_request',
                'description' => 'The user wants a setting of the booking activity itself changed, not a single'
                    . ' booking option inside it.',
            ],
        ];
    }
}
