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

namespace mod_booking\agent;

use mod_booking\agent\interfaces\agent_interpreter;

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
    const ALLOWED_RESPONSE_TYPES = ['clarification', 'confirmation_request', 'task_call', 'error'];

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
            return $this->error_result('LLM returned an unknown or missing response_type: ' . ($responsetype ?? '(none)'));
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
            'response_type' => $responsetype, // 'confirmation_request' or 'task_call'
            'message'       => $this->safe_string($parsed['message'] ?? ''),
            'commands'      => $validatedcommands,
            'ambiguities'   => [],
            'errors'        => [],
        ];
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
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

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

            $provider = $this->registry->get_provider($taskname);
            $input = $cmd['input'] ?? [];
            if (!is_array($input)) {
                $errors[] = "$label: 'input' must be an object/array.";
                continue;
            }

            // Domain + semantic validation.
            $result = $provider->validate($taskname, $input, $cmid);
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
