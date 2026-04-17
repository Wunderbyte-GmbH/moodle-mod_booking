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
    private const ALLOWED_RESPONSE_TYPES = ['clarification', 'confirmation_request', 'task_call', 'error'];

    /** Canonical token representing the current executor user. */
    private const CURRENT_USER_TOKEN = '__current_user__';

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

        // Passthrough for clarification and error types.
        if ($responsetype === 'clarification') {
            return [
                'response_type' => 'clarification',
                'message'       => $this->safe_string($parsed['message'] ?? ''),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [],
            ];
        }

        if ($responsetype === 'error') {
            return [
                'response_type' => 'error',
                'message'       => $this->safe_string($parsed['message'] ?? 'AI returned an error.'),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$this->safe_string($parsed['message'] ?? '')],
            ];
        }

        // Stages 3–6: Full validation for command-bearing responses.
        $commands = $parsed['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $this->error_result('Response type requires at least one command but none were provided.');
        }

        [$validatedcommands, $errors, $ambiguities] = $this->validate_commands($commands, $cmid);

        // Stage 5: Any ambiguity stops execution.
        if (!empty($ambiguities)) {
            return [
                'response_type' => 'clarification',
                'message'       => $this->safe_string($parsed['message'] ?? implode(' ', $ambiguities)),
                'commands'      => [],
                'ambiguities'   => $ambiguities,
                'errors'        => [],
            ];
        }

        if (!empty($errors)) {
            return [
                'response_type' => 'error',
                'message'       => implode(' | ', $errors),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => $errors,
            ];
        }

        return [
            'response_type' => $responsetype,
            'message'       => $this->safe_string($parsed['message'] ?? ''),
            'commands'      => $validatedcommands,
            'ambiguities'   => [],
            'errors'        => [],
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
     * Validate all commands and return [validated, errors, ambiguities].
     *
     * @param  array $commands
     * @param  int   $cmid
     * @return array [array $validated, string[] $errors, string[] $ambiguities]
     */
    private function validate_commands(array $commands, int $cmid): array {
        $validated = [];
        $errors = [];
        $ambiguities = [];

        $allowedtasks = $this->registry->get_task_names();

        foreach ($commands as $idx => $cmd) {
            $label = 'Command #' . ($idx + 1);

            // Schema validation: required top-level keys.
            if (!isset($cmd['task'])) {
                $errors[] = "$label: missing 'task' key.";
                continue;
            }

            $taskname = $cmd['task'];
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

            // Domain + semantic validation.
            $result = $task->validate($input, $cmid);
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

        return [$validated, $errors, $ambiguities];
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
}
