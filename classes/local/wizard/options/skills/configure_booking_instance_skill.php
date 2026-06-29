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
use stdClass;

/**
 * Task: configure the current booking activity instance.
 *
 * Supports two modes via the "action" input field:
 *  - list_fields  (safe, read-only path): returns the catalog of configurable fields with
 *                  descriptions and current values.  Use this to discover what can be changed
 *                  before committing a concrete update command.
 *  - update       (mutating, confirmation-gated): applies a set of field/value changes to the
 *                  booking activity and persists them via booking_update_instance().
 *
 * The schema intentionally omits the full field catalog to keep the initial prompt concise.
 * The LLM should call with action=list_fields first when the user wants to know what is
 * configurable, and only then issue a targeted action=update command.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_booking_instance_skill extends booking_skill_base {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.configure_booking_instance';

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
    ];

    /**
     * Constructor — this task is mutating (requires confirmation).
     */
    public function __construct() {
        parent::__construct(false, \bookingextension_agent\local\wizard\dto\skill_risk_class::R2, ['mod/booking:updatebooking']);
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
        return option_preview_builder::configure_instance_descriptor($input, self::CONFIGURABLE_FIELDS);
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
            'description' => 'Configure the current booking activity instance settings.'
                . ' Use action=list_fields to discover which fields exist and their current values.'
                . ' Use action=update to apply specific changes.'
                . ' Natural-language mapping: questions like "what can I configure" or "show current settings" '
                . 'should map to action=list_fields; requests like "change X to Y" should map to action=update '
                . 'with a changes array.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_configure_booking_instance',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_configure_booking_instance',
            'example_utterances' => [
                'Change the settings of this booking activity',
                'Add a new price category for students',
                'What can I configure for this booking instance?',
                'Show the current activity-level settings',
                'Rename the booking activity and adjust its defaults',
            ],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Required. "list_fields" to get a catalog of all configurable fields with current values.'
                        . ' "update" to apply changes (requires "changes" array).'
                        . ' Use "list_fields" for exploratory requests and "update" only for concrete change requests.',
                    'required' => true,
                ],
                'changes' => [
                    'type' => 'array',
                    'description' => 'For action=update: array of {field, value} objects to apply.'
                        . ' Use action=list_fields first to discover valid field names.',
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
                $validfields = array_keys(self::CONFIGURABLE_FIELDS);
                foreach ($changes as $idx => $change) {
                    if (!is_array($change)) {
                        $errors[] = "changes[$idx]: must be an object with \"field\" and \"value\".";
                        continue;
                    }
                    $field = trim((string)($change['field'] ?? ''));
                    if ($field === '') {
                        $errors[] = "changes[$idx]: \"field\" is required.";
                    } else if (!in_array($field, $validfields, true)) {
                        $errors[] = "changes[$idx]: unknown field \"$field\"."
                            . ' Use action=list_fields to see valid field names.';
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
                'message' => 'This action needs a target booking activity. Please open a booking activity, '
                    . 'or tell me which booking activity (and course) it should apply to.',
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

        // List_fields is safe — no further checks needed.
        if ($action === 'list_fields') {
            return $this->pass($input);
        }

        // For update: validate field types.
        $changes = (array)($input['changes'] ?? []);
        $issues = [];
        foreach ($changes as $idx => $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string)($change['field'] ?? ''));
            if (!isset(self::CONFIGURABLE_FIELDS[$field])) {
                continue;
            }
            $meta = self::CONFIGURABLE_FIELDS[$field];
            $value = $change['value'] ?? '';
            $typevalid = $this->validate_field_value_type($field, $meta['type'], $value);
            if ($typevalid !== null) {
                $issues[] = [
                    'severity' => 'needs_clarification',
                    'message' => "Field \"$field\": $typevalid",
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
        global $DB;

        $action = trim((string)($input['action'] ?? ''));
        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return $this->error_result("Could not resolve booking instance for cmid=$cmid.");
        }

        $bookingid = (int)$cm->instance;

        // Action: list_fields.
        if ($action === 'list_fields') {
            return $this->execute_list_fields($bookingid, $cmid);
        }

        // Action: update.
        if ($action === 'update') {
            return $this->execute_update($input, $bookingid, $cmid, $cm);
        }

        return $this->error_result("Unknown action \"$action\".");
    }

    // -------------------------------------------------------------------------
    // Private: action handlers.

    /**
     * Return the catalog of configurable fields with current values.
     *
     * @param int $bookingid
     * @param int $cmid
     * @return array
     */
    private function execute_list_fields(int $bookingid, int $cmid): array {
        global $DB, $CFG;

        $record = $DB->get_record('booking', ['id' => $bookingid]);
        $fields = [];
        foreach (self::CONFIGURABLE_FIELDS as $key => $meta) {
            $current = $record ? ($record->$key ?? null) : null;
            $fields[] = [
                'field' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'description' => $meta['description'],
                'current_value' => $current,
            ];
        }

        $editlink = (new \moodle_url('/course/modedit.php', ['update' => $cmid]))->out(false);

        $summary = count($fields) . ' configurable field(s) for booking instance id=' . $bookingid . ".\n";
        foreach ($fields as $f) {
            $current = ($f['current_value'] !== null && $f['current_value'] !== '')
                ? (string)$f['current_value']
                : '(empty)';
            $summary .= "- {$f['field']} ({$f['label']}, {$f['type']}): {$current}\n";
        }
        $summary .= "Edit link: $editlink";

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'observation_full' => $summary,
            'fields' => $fields,
            'link' => $editlink,
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, [], [
                'action: list_fields',
                'bookingid: ' . $bookingid,
                'fields returned: ' . count($fields),
            ]),
        ];
    }

    /**
     * Apply the requested changes to the booking instance record.
     *
     * Uses booking_update_instance() from lib.php to go through the canonical
     * update path (events, caches, etc.) rather than writing to DB directly.
     *
     * @param array    $input
     * @param int      $bookingid
     * @param int      $cmid
     * @param stdClass $cm
     * @return array
     */
    private function execute_update(array $input, int $bookingid, int $cmid, stdClass $cm): array {
        global $DB, $CFG;

        // Load current record as base.
        $record = $DB->get_record('booking', ['id' => $bookingid]);
        if (!$record) {
            return $this->error_result("Booking instance id=$bookingid not found.");
        }

        $changes = (array)($input['changes'] ?? []);
        $applied = [];
        $skipped = [];

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
            $value = $this->cast_value($field, $meta['type'], $change['value'] ?? '');
            $record->$field = $value;
            $applied[] = $field . ' = ' . $this->format_value_for_summary($value);
        }

        if (empty($applied)) {
            return $this->error_result('No valid changes were provided.');
        }

        // Booking_update_instance() expects ->instance, not ->id.
        $record->instance = $record->id;
        $record->coursemodule = $cm->id;

        // Include lib.php where booking_update_instance is defined.
        if (!function_exists('booking_update_instance')) {
            require_once($CFG->dirroot . '/mod/booking/lib.php');
        }

        booking_update_instance($record);

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
        $str = (string)$value;
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
}
