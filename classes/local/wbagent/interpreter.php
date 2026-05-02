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

use mod_booking\local\wbagent\booking\support\slot_booking_normalizer;
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
        'SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED',
    ];

    /** @var task_registry */
    private task_registry $registry;

    /** @var slot_booking_normalizer */
    private slot_booking_normalizer $slotbookingnormalizer;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     */
    public function __construct(task_registry $registry) {
        $this->registry = $registry;
        $this->slotbookingnormalizer = new slot_booking_normalizer();
    }

    /**
     * Parse and validate raw LLM output.
     *
     * @param string $rawresponse
     * @param int    $cmid
     * @param int    $userid
     * @return array
     */
    public function interpret(string $rawresponse, int $cmid, int $userid, string $lastusermessage = ''): array {
        // Stage 1: Parse.
        $parsed = $this->parse($rawresponse);
        if ($parsed === null) {
            return $this->error_result('Failed to parse LLM response as JSON.');
        }

        // Stage 2: Classify response type.
        $responsetype = $parsed['response_type'] ?? null;
        if (!in_array($responsetype, self::ALLOWED_RESPONSE_TYPES, true)) {
            $normalized = $this->normalize_task_like_response($parsed, $lastusermessage);
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
                'ambiguity_options' => [],
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
                'ambiguity_options' => [],
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
                'ambiguity_options' => [],
                'errors'        => [],
            ];
        }

        // Stages 3–6: Full validation for command-bearing responses.
        $commands = $parsed['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $this->error_result('Response type requires at least one command but none were provided.');
        }

        [$validatedcommands, $errors, $ambiguities, $ambiguityoptions, $attemptedtasks, $issuecodes, $confirmablecommands] =
            $this->validate_commands($commands, $cmid, $userid);

        // Stage 5: Any ambiguity from backend validation stops execution and forces clarification.
        // The confirm button must NEVER appear when unresolved questions remain.

        if (!empty($errors)) {
            $validationmessage = $this->user_facing_validation_message($errors, $lang);
            $recoverableinputerror = $this->is_recoverable_input_validation_error($errors);
            if (!empty($confirmablecommands)) {
                return [
                    'response_type' => 'confirmation_request',
                    'lang'          => $lang,
                    'message'       => $validationmessage,
                    'used_triggers' => $usedtriggers,
                    'commands'      => $confirmablecommands,
                    'ambiguities'   => [],
                    'ambiguity_options' => $ambiguityoptions,
                    'errors'        => $errors,
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'   => $issuecodes,
                ];
            }
            return [
                'response_type' => $recoverableinputerror ? 'clarification' : 'error',
                'lang'          => $lang,
                'message'       => $validationmessage,
                'used_triggers' => $usedtriggers,
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => $errors,
                'attempted_tasks' => $attemptedtasks,
                'issue_codes'   => $issuecodes,
            ];
        }

        if (!empty($ambiguities)) {
            if (empty($errors) && !empty($confirmablecommands)) {
                return [
                    'response_type' => 'confirmation_request',
                    'lang'          => $lang,
                    // For backend-driven confirmable issues, prefer task-validator wording
                    // over generic LLM confirmation text so the user sees the real reason.
                    'message'       => $this->confirmation_message_from_ambiguities($ambiguities),
                    'used_triggers' => $usedtriggers,
                    'commands'      => $confirmablecommands,
                    'ambiguities'   => [],
                    'ambiguity_options' => $ambiguityoptions,
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
                'ambiguity_options' => $ambiguityoptions,
                'errors'        => [],
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
            'ambiguity_options' => [],
            'errors'        => [],
            'attempted_tasks' => $attemptedtasks,
            'issue_codes'   => $issuecodes,
        ];
    }

    /**
     * Normalize common task-like malformed outputs into canonical task_call payload.
     *
     * @param array  $parsed
     * @param string $lastusermessage  Latest user message text, used as question-field fallback.
     * @return array|null
     */
    private function normalize_task_like_response(array $parsed, string $lastusermessage = ''): ?array {
        $allowedtasks = $this->registry->get_task_names();

        $responsetype = (string)($parsed['response_type'] ?? '');
        if ($responsetype !== '' && in_array($responsetype, $allowedtasks, true)) {
            $input = is_array($parsed['input'] ?? null) ? $parsed['input'] : [];
            $input = $this->hydrate_question_field($responsetype, $input, $lastusermessage);
            return [
                'response_type' => 'task_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'commands' => [
                    [
                        'task' => $responsetype,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => $input,
                    ],
                ],
            ];
        }

        // LLM returned a trigger ID as response_type — map it back to the corresponding task name.
        if ($responsetype !== '') {
            $triggermap = $this->registry->get_trigger_id_to_task_name_map();
            if (isset($triggermap[$responsetype])) {
                $taskname = $triggermap[$responsetype];
                $input = is_array($parsed['input'] ?? null) ? $parsed['input'] : [];
                $input = $this->hydrate_question_field($taskname, $input, $lastusermessage);
                return [
                    'response_type' => 'task_call',
                    'lang' => $this->safe_string($parsed['lang'] ?? ''),
                    'used_triggers' => [$responsetype],
                    'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                    'commands' => [
                        [
                            'task' => $taskname,
                            'version' => (int)($parsed['version'] ?? 1),
                            'input' => $input,
                        ],
                    ],
                ];
            }
        }

        $task = (string)($parsed['task'] ?? '');
        if ($task !== '' && in_array($task, $allowedtasks, true)) {
            $input = is_array($parsed['input'] ?? null) ? $parsed['input'] : [];
            $input = $this->hydrate_question_field($task, $input, $lastusermessage);
            return [
                'response_type' => 'task_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'commands' => [
                    [
                        'task' => $task,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => $input,
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * If a task expects a 'question' field and it is missing/empty, fill it from lastusermessage.
     *
     * @param string $taskname
     * @param array  $input
     * @param string $lastusermessage
     * @return array
     */
    private function hydrate_question_field(string $taskname, array $input, string $lastusermessage): array {
        if ($lastusermessage === '' || trim((string)($input['question'] ?? '')) !== '') {
            return $input;
        }

        $task = $this->registry->get_task($taskname);
        if ($task === null) {
            return $input;
        }

        $schema = $task->get_schema();
        $props  = $schema['properties'] ?? [];
        if (isset($props['question'])) {
            $input['question'] = $lastusermessage;
        }

        return $input;
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
     * @param array $parsed
     * @return array
     */
    private function extract_used_triggers(array $parsed): array {
        $triggerregistry = new message_trigger_registry($this->registry);
        return $triggerregistry->normalize_used_triggers($parsed['used_triggers'] ?? []);
    }

    /**
     * Validate all commands and return [validated, errors, ambiguities].
     */
    private function validate_commands(array $commands, int $cmid, int $userid): array {
        $validated = [];
        $errors = [];
        $ambiguities = [];
                $ambiguityoptions = [];
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
            // Privacy precheck can cause ANON_USER tokens in LLM task input.
            // Resolve them before preflight validation so lookups (e.g. optionquery)
            // use original values consistently.
            $anonymizer = new privacy_anonymizer(new conversation_store());
            $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            $candidatecommand = [
                'task'    => $taskname,
                'version' => $cmd['version'] ?? 1,
                'input'   => $input,
            ];

            // Domain + semantic validation.
            $result = $task->validate($input, $cmid);
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $e) {
                    $errors[] = "$label: $e";
                }
            }

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
                        if ($code === 'SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED') {
                            // Second-stage confirmed execution for admin-overridable blockers (e.g. selectuser).
                            $confirmcommand['input']['confirmed'] = true;
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

            if (!empty($result['ambiguities'])) {
                foreach ($result['ambiguities'] as $a) {
                    $ambiguities[] = "$label: $a";
                }
                if (!empty($result['ambiguity_options']) && is_array($result['ambiguity_options'])) {
                    $ambiguityoptions = array_merge(
                        $ambiguityoptions,
                        $this->normalize_ambiguity_options($result['ambiguity_options'], $label, (string)$taskname)
                    );
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
            $ambiguityoptions,
            array_values(array_unique($attemptedtasks)),
            array_values(array_unique($issuecodes)),
            $confirmablecommands,
        ];
    }

    /**
     * Normalize task-provided structured ambiguity options for frontend consumption.
     *
     * @param array $options
     * @param string $label
     * @param string $taskname
     * @return array
     */
    private function normalize_ambiguity_options(array $options, string $label, string $taskname): array {
        $normalized = [];
        foreach ($options as $index => $option) {
            if (!is_array($option)) {
                continue;
            }

            $id = trim((string)($option['id'] ?? ''));
            $optionlabel = trim((string)($option['label'] ?? ''));
            $query = trim((string)($option['query'] ?? ''));
            if ($optionlabel === '' && $query === '') {
                continue;
            }

            if ($id === '') {
                $id = strtolower($taskname) . ':' . ($index + 1);
            }

            $normalized[] = [
                'id' => $id,
                'label' => $optionlabel,
                'query' => $query,
                'task' => $taskname,
                'command_label' => $label,
                'path' => trim((string)($option['path'] ?? '')),
                'title' => trim((string)($option['title'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * Canonicalize user self-references in known user-query fields.
     *
     * @param array $input
     * @return array
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
     * Delegates domain-specific normalization (slot-booking, self-learning) to
     * {@see slot_booking_normalizer} so the interpreter itself remains free of
     * booking domain knowledge.
     *
     * @param string $taskname
     * @param array $input
     * @return array
     */
    private function canonicalize_command_input(string $taskname, array $input): array {
        return $this->slotbookingnormalizer->normalize($taskname, $input);
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
            'ambiguity_options' => [],
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
     * @param array $parsed
     * @param array $ambiguities
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
     * Build a confirmation message from validator-provided ambiguity lines.
     *
     * This is used for confirmable backend issues to avoid generic LLM text
     * like "Moechten Sie ... buchen?" hiding the actual reason.
     *
     * @param array $ambiguities
     * @return string
     */
    private function confirmation_message_from_ambiguities(array $ambiguities): string {
        $cleanambiguities = array_map(fn(string $line): string => $this->strip_command_prefix($line), $ambiguities);
        $cleanambiguities = array_values(array_filter($cleanambiguities, static fn(string $line): bool => trim($line) !== ''));

        if (!empty($cleanambiguities)) {
            return $this->safe_string(implode(' ', $cleanambiguities));
        }

        return '';
    }

    /**
     * Build a user-facing error text from validation errors.
     *
     * @param array $errors
     * @param string $lang
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
     * @param array $errors
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
