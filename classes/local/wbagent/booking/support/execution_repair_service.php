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

namespace mod_booking\local\wbagent\booking\support;

use context_module;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\task_registry;

/**
 * LLM-based repair service for executor failures.
 *
 * This service analyses executor errors together with the original commands
 * and asks the model for either a repaired command sequence or a short
 * user-facing explanation when no repair is possible.
 *
 * The service sends execution errors, the latest user input, and available
 * task schemas to the LLM. The model may return either:
 *  - a repaired command list (confirmation_request/task_call), or
 *  - a short user-friendly explanation when no repair is possible.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execution_repair_service {
    /** @var callable|null */
    private $llmresolver;

    /**
     * Constructor.
     *
     * @param callable|null $llmresolver Optional callable for tests.
     * Signature: fn(string $prompt, int $cmid, int $userid): string
     *
     * Phase 4 note: this service still calls the LLM and the interpreter
     * internally. A future refactor should make AgentRuntime own all LLM
     * interaction and inject the interpreter here so it can be tested without
     * a real API key. The $llmresolver parameter already provides a seam for
     * testing the LLM call path.
     */
    public function __construct(?callable $llmresolver = null) {
        $this->llmresolver = $llmresolver;
    }

    /**
     * Analyse executor results and propose a repaired command sequence.
     *
     * @param array $commands      The commands that were sent to execute_commands().
     * @param array $rawresults    The results returned by execute_commands().
     * @param int   $repairattempts How many repairs have already been attempted for this thread.
     * @param int   $maxrepairs    Maximum allowed repair attempts (from settings).
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $outputlang
     * @param task_registry $registry
     * @param conversation_store $store
     * @return array{can_repair: bool, repaired_commands: array, reason: string, user_message: string}
     */
    public function analyze(
        array $commands,
        array $rawresults,
        int $repairattempts,
        int $maxrepairs,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang,
        task_registry $registry,
        conversation_store $store
    ): array {
        if ($repairattempts >= $maxrepairs) {
            return [
                'can_repair' => false,
                'repaired_commands' => [],
                'reason' => 'max_repair_count_reached',
                'user_message' => $this->localized_string('ai_repair_no_solution_message', $outputlang),
            ];
        }

        $failedindexes = $this->collect_executor_failures($rawresults);
        if (empty($failedindexes)) {
            return [
                'can_repair' => false,
                'repaired_commands' => [],
                'reason' => 'no_executor_errors',
                'user_message' => '',
            ];
        }

        $anonymizer = new privacy_anonymizer($store);
        $lastusermessage = $this->extract_last_user_message($store->get_recent_messages($threadid, 20));
        $promptusermessage = (string)$anonymizer->anonymize_value_for_llm($threadid, $lastusermessage);
        $prompterrors = [];
        foreach ($failedindexes as $idx => $detail) {
            $prompterrors[$idx] = (string)$anonymizer->anonymize_value_for_llm($threadid, (string)$detail);
        }
        $promptcommands = $this->anonymize_commands_for_prompt($commands, $threadid, $anonymizer);

        $prompt = $this->build_repair_prompt(
            $promptcommands,
            $prompterrors,
            $promptusermessage,
            $registry->get_all_schemas()
        );

        try {
            $rawllm = $this->call_repair_llm($prompt, $cmid, $userid);
        } catch (\Throwable $e) {
            return [
                'can_repair' => false,
                'repaired_commands' => [],
                'reason' => 'llm_call_failed',
                'user_message' => $this->localized_string('ai_repair_no_solution_message', $outputlang),
            ];
        }

        $parsed = (new interpreter($registry))->interpret($rawllm, $cmid, $userid);
        $responsetype = (string)($parsed['response_type'] ?? '');
        $parsedcommands = is_array($parsed['commands'] ?? null) ? (array)$parsed['commands'] : [];

        if (in_array($responsetype, ['task_call', 'confirmation_request'], true) && !empty($parsedcommands)) {
            $usermessage = trim((string)($parsed['message'] ?? ''));
            if ($usermessage === '') {
                $usermessage = $this->localized_string('ai_repair_proposal_message', $outputlang);
            }
            return [
                'can_repair' => true,
                'repaired_commands' => array_values($parsedcommands),
                'reason' => 'llm_repair_plan_generated',
                'user_message' => $usermessage,
            ];
        }

        $usermessage = trim((string)($parsed['message'] ?? ''));
        if ($usermessage === '') {
            $usermessage = $this->localized_string('ai_repair_no_solution_message', $outputlang);
        }

        return [
            'can_repair' => false,
            'repaired_commands' => [],
            'reason' => 'llm_no_repair_plan',
            'user_message' => $usermessage,
        ];
    }

    /**
     * Collect executor failure details for each failed result index.
     *
     * @param array $rawresults
     * @return array<int, string> map of result-index => lower-case detail
     */
    private function collect_executor_failures(array $rawresults): array {
        $failures = [];
        foreach ($rawresults as $idx => $result) {
            if (!is_array($result)) {
                continue;
            }
            $status = strtolower(trim((string)($result['status'] ?? '')));
            if ($status !== 'error') {
                continue;
            }
            $detail = strtolower(trim((string)($result['detail'] ?? '')));
            if ($detail === '') {
                continue;
            }
            $failures[$idx] = $detail;
        }
        return $failures;
    }

    /**
     * Extract last user-authored message from conversation history.
     *
     * @param array $messages
     * @return string
     */
    private function extract_last_user_message(array $messages): string {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if (!is_object($message)) {
                continue;
            }
            if ((string)($message->role ?? '') !== 'user') {
                continue;
            }
            $content = trim((string)($message->content ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    /**
     * Build strict repair prompt with failed details, user intent, and all task schemas.
     *
     * @param array $commands
     * @param array $stalefailures
     * @param string $lastusermessage
     * @param array $taskschemas
     * @return string
     */
    private function build_repair_prompt(
        array $commands,
        array $stalefailures,
        string $lastusermessage,
        array $taskschemas
    ): string {
        $payload = [
            'last_user_message' => $lastusermessage,
            'failed_commands' => $commands,
            'executor_errors' => $stalefailures,
            'available_tasks' => $taskschemas,
        ];

        return implode("\n", [
            'You are a repair-planner for booking task execution.',
            'Given executor errors, the original user intent, and available task schemas,',
            'decide if a repair command list is possible.',
            'Primary objective: return executable repaired commands whenever a safe repair path exists.',
            'Rules:',
            '1) Use ONLY tasks from available_tasks.',
            '2) If a repair is possible, return response_type="confirmation_request" with repaired commands.',
            '3) If no repair is possible, return response_type="clarification" with a short user-friendly message.',
            '4) Return ONLY valid JSON. No markdown.',
            '5) Prefer a repair plan over asking the user again if any available task can resolve the error.',
            '6) Typical example: if a create_option command fails because teacher/user is not found',
            'and booking.create_user exists, return two commands in order:',
            'booking.create_user then booking.create_option.',
            '7) The message field MUST be written in the same language as the last_user_message.',
            '8) If you propose a repair plan, message must briefly explain WHY the first run',
            'failed and WHAT the updated command plan will do.',
            'Required JSON shape:',
            '{"response_type":"confirmation_request|clarification","message":"...","commands":[...]}',
            'Input payload:',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Resolve a lang-specific string.
     *
     * @param string $identifier
     * @param string $outputlang
     * @return string
     */
    private function localized_string(string $identifier, string $outputlang): string {
        $lang = trim($outputlang) !== '' ? $outputlang : current_language();
        return (string)\get_string_manager()->get_string($identifier, 'mod_booking', null, $lang);
    }

    /**
     * De-anonymize command inputs for prompt quality.
     *
     * @param array $commands
     * @param int $threadid
     * @param privacy_anonymizer $anonymizer
     * @return array
     */
    private function anonymize_commands_for_prompt(array $commands, int $threadid, privacy_anonymizer $anonymizer): array {
        $result = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $copy = $command;
            $input = $copy['input'] ?? [];
            if (is_array($input)) {
                $copy['input'] = $anonymizer->anonymize_value_for_llm($threadid, $input);
            }
            $result[] = $copy;
        }
        return $result;
    }

    /**
     * Call LLM for repair planning.
     *
     * @param string $prompt
     * @param int $cmid
     * @param int $userid
     * @return string
     */
    private function call_repair_llm(string $prompt, int $cmid, int $userid): string {
        if (is_callable($this->llmresolver)) {
            $raw = (string)call_user_func($this->llmresolver, $prompt, $cmid, $userid);
            if (trim($raw) === '') {
                throw new \RuntimeException('Empty repair LLM response.');
            }
            return $raw;
        }

        if (!class_exists('\\core_ai\\manager')) {
            throw new \RuntimeException('core_ai manager is not available.');
        }

        $context = context_module::instance($cmid);
        $manager = di::get(ai_manager::class);
        if (!$manager->is_action_available(generate_text::class)) {
            throw new \RuntimeException('core_ai generate_text action is not available.');
        }

        $action = new generate_text(
            contextid: $context->id,
            userid: $userid,
            prompttext: $prompt,
        );
        $response = $manager->process_action($action);
        if (!$response->get_success()) {
            throw new \RuntimeException((string)($response->get_errormessage() ?? 'Provider returned an error.'));
        }

        $rawtext = (string)($response->get_response_data()['generatedcontent'] ?? '');
        if (trim($rawtext) === '') {
            throw new \RuntimeException('Provider returned empty repair content.');
        }

        return $rawtext;
    }
}
