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
 * LLM output interpreter pipeline.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

use mod_booking\local\wbagent\interfaces\agent_interpreter;

/**
 * Mandatory trust boundary between raw LLM output and the executor.
 *
 * Pipeline stages:
 *  1. JSON/structure parsing
 *  2. Response-type classification (allow-list)
 *  3. Schema validation for task_call / confirmation_request
 *  4. Domain / semantic validation via the task registry
 *  5. Ambiguity detection – any ambiguity stops execution and asks the user
 *  6. Normalisation (dates, IDs)
 *  7. Emission of execution-safe command objects
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class interpreter implements agent_interpreter {
    /** Allowed response_type values from the LLM. */
    private const ALLOWED_RESPONSE_TYPES = ['clarification', 'confirmation_request', 'task_call', 'error', 'confirm_pending'];

    /** Canonical token representing the current executor user. */
    private const CURRENT_USER_TOKEN = '__current_user__';

    /** Issue codes that should produce a confirmation_request with pending intent. */
    private const CONFIRMABLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
        'CONFIRMATION_REQUIRED',
        'MISSING_LOCATION_CONFIRM_REQUIRED',
        'LOCATION_NOT_FOUND_POSSIBLE',
        'SLOTBOOKING_DURATION_EQUALS_WINDOW',
    ];

    /** @var task_registry */
    private task_registry $registry;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     */
    public function __construct(task_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Parse and validate raw LLM output.
     *
     * @param string $rawresponse
     * @param int    $cmid
     * @param int    $userid
     * @return array
     */
    public function interpret(string $rawresponse, int $cmid, int $userid): array {
        // Stage 1: Parse.
        $parsed = $this->parse($rawresponse);
        if ($parsed === null) {
            return $this->error_result('Failed to parse LLM response as JSON.');
        }

        // Stage 2: Classify response type.
        $responsetype = $parsed['response_type'] ?? null;
        if (!in_array($responsetype, self::ALLOWED_RESPONSE_TYPES, true)) {
            $normalized = $this->normalize_task_like_response($parsed);
            if ($normalized !== null) {
                $parsed = $normalized;
                $responsetype = $parsed['response_type'];
            } else {
                return $this->error_result('LLM returned an unknown or missing response_type: ' . ($responsetype ?? '(none)'));
            }
        }

        $lang = $this->safe_string($parsed['lang'] ?? '');
        $usedtriggers = $this->extract_used_triggers($parsed);

        // Passthrough for clarification, error, and confirm_pending types.
        if ($responsetype === 'clarification') {
            return [
                'response_type' => 'clarification',
                'lang'          => $lang,
                'message'       => $this->strip_command_prefix($this->safe_string($parsed['message'] ?? '')),
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [],
            ];
        }

        if ($responsetype === 'error') {
            $errormessage = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? 'AI returned an error.'));
            return [
                'response_type' => 'error',
                'lang'          => $lang,
                'message'       => $errormessage,
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$errormessage],
            ];
        }

        if ($responsetype === 'confirm_pending') {
            return [
                'response_type' => 'confirm_pending',
                'lang'          => $lang,
                'message'       => '',
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [],
            ];
        }

        // Stages 3–6: Full validation for command-bearing responses.
        $commands = $parsed['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $this->error_result('Response type requires at least one command but none were provided.');
        }

        [$validatedcommands, $errors, $ambiguities, $attemptedtasks, $issuecodes, $confirmablecommands] =
            $this->validate_commands($commands, $cmid);

        // Stage 5: Any ambiguity from backend validation stops execution and forces clarification.
        // The confirm button must NEVER appear when unresolved questions remain.
        if (!empty($ambiguities)) {
            if (empty($errors) && !empty($confirmablecommands)) {
                return [
                    'response_type' => 'confirmation_request',
                    'lang'          => $lang,
                    'message'       => $this->clarification_message($parsed, $ambiguities),
                    'used_triggers' => $usedtriggers,
                    'commands'      => $confirmablecommands,
                    'ambiguities'   => [],
                    'errors'        => [],
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'   => $issuecodes,
                ];
            }

            return [
                'response_type' => 'clarification',
                'lang'          => $lang,
                'message'       => $this->clarification_message($parsed, $ambiguities),
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => $ambiguities,
                'errors'        => [],
                'attempted_tasks' => $attemptedtasks,
                'issue_codes'   => $issuecodes,
            ];
        }

        if (!empty($errors)) {
            $validationmessage = $this->user_facing_validation_message($errors, $lang);
            $recoverableinputerror = $this->is_recoverable_input_validation_error($errors);
            return [
                'response_type' => $recoverableinputerror ? 'clarification' : 'error',
                'lang'          => $lang,
                'message'       => $validationmessage,
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => $errors,
                'attempted_tasks' => $attemptedtasks,
                'issue_codes'   => $issuecodes,
            ];
        }

        return [
            'response_type' => $responsetype,
            'lang'          => $lang,
            'message'       => $this->safe_string($parsed['message'] ?? ''),
            'used_triggers' => $usedtriggers,
            'commands'      => $validatedcommands,
            'ambiguities'   => [],
            'errors'        => [],
            'attempted_tasks' => $attemptedtasks,
            'issue_codes'   => $issuecodes,
        ];
    }

    /**
     * Normalize common task-like malformed outputs into canonical task_call payload.
     *
     * @param array $parsed
     * @return array|null
     */
    private function normalize_task_like_response(array $parsed): ?array {
        $allowedtasks = $this->registry->get_task_names();

        $responsetype = (string)($parsed['response_type'] ?? '');
        if ($responsetype !== '' && in_array($responsetype, $allowedtasks, true)) {
            return [
                'response_type' => 'task_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'commands' => [
                    [
                        'task' => $responsetype,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => is_array($parsed['input'] ?? null) ? $parsed['input'] : [],
                    ],
                ],
            ];
        }

        $task = (string)($parsed['task'] ?? '');
        if ($task !== '' && in_array($task, $allowedtasks, true)) {
            return [
                'response_type' => 'task_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'commands' => [
                    [
                        'task' => $task,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => is_array($parsed['input'] ?? null) ? $parsed['input'] : [],
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * Parse raw LLM output to an array.
     *
     * The LLM is instructed to respond in JSON.  We attempt to extract a
     * JSON object even if surrounded by markdown fences.
     *
     * @param  string     $rawresponse
     * @return array|null Parsed array or null on failure.
     */
    private function parse(string $rawresponse): ?array {
        $text = trim($rawresponse);

        // Strip markdown fences.
        $text = preg_replace('/^\x60\x60\x60(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*\x60\x60\x60$/', '', $text);

        $data = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // Fallback: try to extract the first {...} block.
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Extract used trigger ids from raw payload and allow-list them.
     *
     * @param array<string,mixed> $parsed
     * @return array<int,string>
     */
    private function extract_used_triggers(array $parsed): array {
        $triggerregistry = new message_trigger_registry($this->registry);
        return $triggerregistry->normalize_used_triggers($parsed['used_triggers'] ?? []);
    }

    /**
     * Validate all commands and return [validated, errors, ambiguities].
     *
     * @param  array $commands
     * @param  int   $cmid
     */
    private function validate_commands(array $commands, int $cmid): array {
        $validated = [];
        $errors = [];
        $ambiguities = [];
          $attemptedtasks = [];
        $issuecodes = [];
        $confirmablecommands = [];

        $allowedtasks = $this->registry->get_task_names();
        $seencommandsigs = [];

        foreach ($commands as $idx => $cmd) {
            $label = 'Command #' . ($idx + 1);

            // Deduplicate: skip exact duplicate commands (same task + same input).
            $cmdsig = md5(json_encode(['task' => $cmd['task'] ?? '', 'input' => $cmd['input'] ?? []]));
            if (isset($seencommandsigs[$cmdsig])) {
                continue;
            }
            $seencommandsigs[$cmdsig] = true;

            // Schema validation: required top-level keys.
            if (!isset($cmd['task'])) {
                $errors[] = "$label: missing 'task' key.";
                continue;
            }

            $taskname = $cmd['task'];
            $attemptedtasks[] = (string)$taskname;
            if (!in_array($taskname, $allowedtasks, true)) {
                $errors[] = "$label: task '$taskname' is not allowed.";
                continue;
            }

            $task = $this->registry->get_task($taskname);
            if (!$task) {
                $errors[] = "$label: task '$taskname' is not registered.";
                continue;
            }

            $input = $cmd['input'] ?? [];
            if (!is_array($input)) {
                $errors[] = "$label: 'input' must be an object/array.";
                continue;
            }

            $input = $this->normalize_self_user_references($input);
            $input = $this->canonicalize_command_input((string)$taskname, $input);
            $candidatecommand = [
                'task'    => $taskname,
                'version' => $cmd['version'] ?? 1,
                'input'   => $input,
            ];

            // Domain + semantic validation.
            $result = $task->validate($input, $cmid);
            if (!empty($result['issues']) && is_array($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    if (!is_array($issue)) {
                        continue;
                    }

                    $code = trim((string)($issue['code'] ?? ''));
                    if ($code !== '') {
                        $issuecodes[] = $code;
                    }

                    // Missing required fields should be surfaced via concrete validation errors,
                    // not as a generic confirmation-style ambiguity.
                    if ($code === 'MISSING_REQUIRED_FIELDS') {
                        continue;
                    }

                    $severity = trim((string)($issue['severity'] ?? ''));
                    if (
                        $severity === 'needs_confirmation'
                        && $taskname === 'booking.create_option'
                        && in_array($code, self::CONFIRMABLE_ISSUE_CODES, true)
                    ) {
                        $confirmcommand = $candidatecommand;
                        if ($code === 'MISSING_LOCATION_CONFIRM_REQUIRED') {
                            $overrides = is_array($confirmcommand['input']['override'] ?? null)
                                ? $confirmcommand['input']['override']
                                : [];
                            $overrides[] = 'location';
                            $overrides[] = 'address';
                            $confirmcommand['input']['override'] = array_values(array_unique(array_map(
                                static fn($token): string => strtolower(trim((string)$token)),
                                $overrides
                            )));
                        }
                        $confirmablecommands[] = $confirmcommand;
                    }

                    $question = trim((string)($issue['user_question'] ?? ''));
                    if ($question !== '') {
                        $ambiguities[] = "$label: $question";
                        continue;
                    }

                    if ($code !== '') {
                        $ambiguities[] = "$label: Please confirm how to proceed ($code).";
                    }
                }

                if (!empty($ambiguities)) {
                    continue;
                }
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $e) {
                    $errors[] = "$label: $e";
                }
                continue;
            }
            if (!empty($result['ambiguities'])) {
                foreach ($result['ambiguities'] as $a) {
                    $ambiguities[] = "$label: $a";
                }
                continue;
            }

            // Stage 6: Normalise dates.
            if (isset($input['coursestarttime']) && !is_int($input['coursestarttime'])) {
                $ts = strtotime($input['coursestarttime']);
                if ($ts !== false) {
                    $input['coursestarttime'] = $ts;
                }
            }
            if (isset($input['courseendtime']) && !is_int($input['courseendtime'])) {
                $ts = strtotime($input['courseendtime']);
                if ($ts !== false) {
                    $input['courseendtime'] = $ts;
                }
            }

            // Stage 7: Emit execution-safe command.
            $validated[] = [
                'task'    => $taskname,
                'version' => $cmd['version'] ?? 1,
                'input'   => $input,
            ];
        }

        return [
            $validated,
            $errors,
            $ambiguities,
            array_values(array_unique($attemptedtasks)),
            array_values(array_unique($issuecodes)),
            $confirmablecommands,
        ];
    }

    /**
     * Canonicalize user self-references in known user-query fields.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize_self_user_references(array $input): array {
        $fields = ['teacherquery', 'selectusersquery', 'bookusersquery'];
        foreach ($fields as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                continue;
            }

            $raw = trim($input[$field]);
            if ($raw === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $raw));
            $normalizedparts = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $normalizedparts[] = $this->is_self_reference_phrase($part)
                    ? self::CURRENT_USER_TOKEN
                    : $part;
            }

            if (!empty($normalizedparts)) {
                $input[$field] = implode(', ', $normalizedparts);
            }
        }

        return $input;
    }

    /**
     * Canonicalize task input before validation/confirmation is returned to UI.
     *
     * @param string $taskname
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function canonicalize_command_input(string $taskname, array $input): array {
        if (!in_array($taskname, ['booking.create_option', 'booking.update_option'], true)) {
            return $input;
        }

        // Self-learning: interpret "no limit" phrases as a high practical capacity.
        if ($this->is_selflearning_input($input) && !array_key_exists('maxanswers', $input)) {
            $nolimittext = $this->collect_text_fields($input);
            if (preg_match('/\b(kein\s+limit|unbegrenzt|ohne\s+limit|no\s+limit|unlimited)\b/u', $nolimittext)) {
                $input['maxanswers'] = 999999;
            }
        }

        if (!$this->is_slotbooking_input($input)) {
            return $input;
        }

        $input['slot_enabled'] = true;

        $combinedtext = $this->collect_text_fields($input);
        $hascustomindicator = preg_match(
            '/\b(custom|userdefined|user-defined|flexibel|frei\s+waehlbar|frei\s+wählbar|selber\s+entscheiden|selbst\s+entscheiden|max(?:imal)?|hoechstens|höchstens|up\s+to|at\s+most)\b/u',
            $combinedtext
        ) === 1;

        if (empty($input['slot_type']) || !is_string($input['slot_type'])) {
            $input['slot_type'] = $hascustomindicator ? 'userdefined' : 'fixed';
        } else {
            $slottype = strtolower(trim((string)$input['slot_type']));
            if (in_array($slottype, ['custom', 'user-defined', 'user defined'], true)) {
                $input['slot_type'] = 'userdefined';
            }
        }

        if ($hascustomindicator && ($input['slot_type'] ?? '') === 'fixed') {
            $input['slot_type'] = 'userdefined';
        }

        if (empty($input['slot_booking_view_mode']) || !is_string($input['slot_booking_view_mode'])) {
            $input['slot_booking_view_mode'] = 'calendar';
        }

        if (isset($input['slot_duration_minutes'])) {
            $input['slot_duration_minutes'] = max(1, (int)$input['slot_duration_minutes']);
        }

        if (isset($input['slot_interval_minutes'])) {
            $input['slot_interval_minutes'] = max(1, (int)$input['slot_interval_minutes']);
        } else if (isset($input['slot_duration_minutes'])) {
            $input['slot_interval_minutes'] = max(1, (int)$input['slot_duration_minutes']);
        }

        if (($input['slot_type'] ?? '') === 'userdefined') {
            if (!isset($input['slot_custom_max_duration']) || (int)$input['slot_custom_max_duration'] <= 0) {
                $maxseconds = $this->extract_max_duration_seconds($combinedtext);
                if ($maxseconds === null && isset($input['slot_duration_minutes'])) {
                    $maxseconds = max(60, (int)$input['slot_duration_minutes'] * 60);
                }
                if ($maxseconds === null) {
                    $maxseconds = 60 * 60;
                }
                $input['slot_custom_max_duration'] = (int)$maxseconds;
            }

            if (!isset($input['slot_custom_min_duration']) || (int)$input['slot_custom_min_duration'] <= 0) {
                $input['slot_custom_min_duration'] = 15 * 60;
            }

            if (!isset($input['slot_custom_max_days']) || (int)$input['slot_custom_max_days'] <= 0) {
                $input['slot_custom_max_days'] = DAYSECS;
            }

            if (!isset($input['slot_custom_start_interval_minutes']) || (int)$input['slot_custom_start_interval_minutes'] <= 0) {
                $input['slot_custom_start_interval_minutes'] = 1;
            }
        }

        foreach (['slot_valid_from', 'slot_valid_until'] as $datefield) {
            if (isset($input[$datefield])) {
                $ts = $this->to_unix_timestamp($input[$datefield]);
                if ($ts !== null) {
                    $input[$datefield] = $ts;
                }
            }
        }

        for ($day = 1; $day <= 7; $day++) {
            $key = 'slot_day_' . $day;
            $input[$key] = !empty($input[$key]) ? 1 : 0;
        }

        if (isset($input['slot_max_participants_per_slot'])) {
            $input['slot_max_participants_per_slot'] = max(1, (int)$input['slot_max_participants_per_slot']);
        }

        if (isset($input['slot_max_slots_per_user'])) {
            $input['slot_max_slots_per_user'] = max(1, (int)$input['slot_max_slots_per_user']);
        } else {
            $input['slot_max_slots_per_user'] = 1;
        }

        if (!array_key_exists('slot_type_change_has_answers', $input)) {
            $input['slot_type_change_has_answers'] = 0;
        }
        if (!array_key_exists('slot_type_change_confirm', $input)) {
            $input['slot_type_change_confirm'] = 0;
        }

        return $input;
    }

    /**
     * Detect whether command input targets slotbooking.
     *
     * @param array<string,mixed> $input
     * @return bool
     */
    private function is_slotbooking_input(array $input): bool {
        if (!empty($input['slot_enabled'])) {
            return true;
        }

        $optiontype = strtolower(trim((string)($input['optiontype'] ?? '')));
        if (in_array($optiontype, ['2', 'slot', 'slotbooking', 'slot-booking'], true)) {
            return true;
        }

        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert flexible date input to unix timestamp.
     *
     * @param mixed $value
     * @return int|null
     */
    private function to_unix_timestamp($value): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && (string)(int)$value === trim((string)$value)) {
            return (int)$value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = strtotime($value);
        if ($parsed === false) {
            return null;
        }

        return (int)$parsed;
    }

    /**
     * Decide whether a phrase refers to the current executor user.
     *
     * @param string $value
     * @return bool
     */
    private function is_self_reference_phrase(string $value): bool {
        $normalized = strtolower(trim($value, " \t\n\r\0\x0B.,;:!?\"'"));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        $selfrefkeywords = [
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
            'self',
            'my',
            'mein',
            'ich halte',
            self::CURRENT_USER_TOKEN,
        ];

        return in_array($normalized, $selfrefkeywords, true);
    }

    /**
     * Collect free-text fields into one lowercased string for intent cues.
     *
     * @param array<string,mixed> $input
     * @return string
     */
    private function collect_text_fields(array $input): string {
        $chunks = [];
        foreach (['text', 'description', 'teacherquery', 'coursequery'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $chunks[] = $input[$field];
            }
        }
        return strtolower(trim(implode(' ', $chunks)));
    }

    /**
     * Detect whether command input targets self-learning option type.
     *
     * @param array<string,mixed> $input
     * @return bool
     */
    private function is_selflearning_input(array $input): bool {
        if (!empty($input['selflearningcourse'])) {
            return true;
        }

        $optiontype = strtolower(trim((string)($input['optiontype'] ?? '')));
        return in_array($optiontype, ['1', 'selflearning', 'self-learning', 'selflearningcourse'], true);
    }

    /**
     * Extract a "maximum slot duration" hint from text and return seconds.
     *
     * @param string $text
     * @return int|null
     */
    private function extract_max_duration_seconds(string $text): ?int {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(eine\s+stunde|1\s*stunde|1h|60\s*min(?:uten)?)\b/u', $text)) {
            return 60 * 60;
        }

        if (preg_match('/\b(\d{1,3})\s*(?:min|minute|minuten)\b/u', $text, $m)) {
            return max(1, (int)$m[1]) * 60;
        }

        if (preg_match('/\b(\d{1,2})\s*(?:h|std|stunde|stunden)\b/u', $text, $m)) {
            return max(1, (int)$m[1]) * 3600;
        }

        return null;
    }

    /**
     * Build a standard error result.
     *
     * @param string $message
     * @return array
     */
    private function error_result(string $message): array {
        return [
            'response_type' => 'error',
            'message'       => $message,
            'commands'      => [],
            'ambiguities'   => [],
            'errors'        => [$message],
        ];
    }

    /**
     * Safely extract a string value, stripping tags.
     *
     * @param  mixed $value
     * @return string
     */
    private function safe_string($value): string {
        return strip_tags((string)($value ?? ''));
    }

    /**
     * Build a user-facing clarification message from ambiguities.
     *
     * Avoid placeholder LLM texts like "Executing." when validation asked for clarification.
     *
     * @param array<string,mixed> $parsed
     * @param array<int,string> $ambiguities
     * @return string
     */
    private function clarification_message(array $parsed, array $ambiguities): string {
        $message = $this->safe_string($parsed['message'] ?? '');
        $normalized = strtolower(trim($message));
        $cleanambiguities = array_map(fn(string $line): string => $this->strip_command_prefix($line), $ambiguities);

        // Prefer the LLM-authored clarification text so wording and language follow
        // the detected lang field from structured JSON.
        if ($normalized !== '' && !in_array($normalized, ['executing', 'executing.', 'running', 'running.'], true)) {
            return $this->strip_command_prefix($message);
        }

        // Fallback only when the LLM message is empty or placeholder-like.
        if (!empty($cleanambiguities)) {
            return $this->safe_string(implode(' ', $cleanambiguities));
        }

        return $this->strip_command_prefix($message);
    }

    /**
     * Build a user-facing error text from validation errors.
     *
     * @param array<int,string> $errors
     * @return string
     */
    private function user_facing_validation_message(array $errors, string $lang = ''): string {
        $clean = array_map(fn(string $line): string => $this->strip_command_prefix($line), $errors);

        $joined = strtolower(implode(' ', $clean));
        $isgerman = str_starts_with(strtolower($lang), 'de');

        $missingslotduration = strpos($joined, 'slot_duration_minutes') !== false;
        $missingslotcapacity = strpos($joined, 'slot_max_participants_per_slot') !== false;
        $missingslotrange = strpos($joined, 'slot_valid_from') !== false || strpos($joined, 'slot_valid_until') !== false;
        $missingslotdays = strpos($joined, 'slot_day_') !== false;
        $hasslotbookingcontext = strpos($joined, 'slot booking type') !== false || strpos($joined, 'slot-buchungsart') !== false;

        if ($hasslotbookingcontext && ($missingslotduration || $missingslotcapacity || $missingslotrange || $missingslotdays)) {
            $parts = [];

            if ($missingslotduration) {
                $parts[] = $isgerman
                    ? 'die Dauer pro Slot in Minuten'
                    : 'the duration per slot in minutes';
            }

            if ($missingslotcapacity) {
                $parts[] = $isgerman
                    ? 'wie viele Personen pro Slot buchen duerfen'
                    : 'how many people can book each slot';
            }

            if ($missingslotrange) {
                $parts[] = $isgerman
                    ? 'den Zeitraum, in dem Termine verfuegbar sein sollen (von/bis)'
                    : 'the date range in which slots should be available (from/until)';
            }

            if ($missingslotdays) {
                $parts[] = $isgerman
                    ? 'an welchen Wochentagen Termine angeboten werden sollen'
                    : 'on which weekdays slots should be offered';
            }

            if (!empty($parts)) {
                if ($isgerman) {
                    return 'Damit ich die Sprechstunde korrekt als Slot-Buchung anlegen kann, brauche ich noch: '
                        . implode('; ', $parts) . '.';
                }

                return 'To create the office hours correctly as a slot booking, I still need: '
                    . implode('; ', $parts) . '.';
            }
        }

        return implode(' ', $clean);
    }

    /**
     * Determine whether validation errors are recoverable missing-input cases.
     *
     * @param array<int,string> $errors
     * @return bool
     */
    private function is_recoverable_input_validation_error(array $errors): bool {
        $joined = strtolower(implode(' ', $errors));

        $markers = [
            'please provide',
            'slot_duration_minutes',
            'slot_max_participants_per_slot',
            'slot_valid_from',
            'slot_valid_until',
            'slot_day_',
            'missing',
        ];

        foreach ($markers as $marker) {
            if (strpos($joined, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove technical prefixes like "Command #1:" from user-facing texts.
     *
     * @param string $text
     * @return string
     */
    private function strip_command_prefix(string $text): string {
        $clean = preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', $text);
        return $this->safe_string($clean ?? $text);
    }
}
