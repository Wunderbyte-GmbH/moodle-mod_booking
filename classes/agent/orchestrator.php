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
 * AI orchestration layer.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\agent;

use context_module;
use core_ai\manager as ai_manager;
use core_ai\aiactions\generate_text;
use mod_booking\agent\interfaces\agent_interpreter;

/**
 * Orchestrates LLM interaction via core_ai.
 *
 * Responsibilities:
 *  - Assemble a state-based system prompt (not full raw chat history).
 *  - Send the conversation context to the AI provider.
 *  - Hand the raw response off to the interpreter.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator {

    /** Maximum number of recent messages to include in the prompt. */
    const MAX_HISTORY_MESSAGES = 20;

    /** @var task_registry */
    private task_registry $registry;

    /** @var interpreter */
    private agent_interpreter $interpreter;

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param task_registry      $registry
     * @param agent_interpreter  $interpreter
     * @param conversation_store $store
     */
    public function __construct(
        task_registry $registry,
        agent_interpreter $interpreter,
        conversation_store $store
    ) {
        $this->registry    = $registry;
        $this->interpreter = $interpreter;
        $this->store       = $store;
    }

    /**
     * Check whether a Moodle core_ai provider is configured and available.
     *
     * @param int $cmid   Course-module id.
     * @param int $userid User id.
     * @return bool
     */
    public function is_provider_available(int $cmid, int $userid): bool {
        if (!class_exists('\core_ai\manager')) {
            return false;
        }
        try {
            $context = context_module::instance($cmid);
            $providers = ai_manager::get_providers_for_action(generate_text::class, $context, $userid);
            return !empty($providers);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Process a user message: call the LLM and interpret the response.
     *
     * @param  int    $threadid  Thread id.
     * @param  int    $cmid      Course-module id.
     * @param  int    $userid    User id.
     * @return array  Interpreter result.
     */
    public function process(int $threadid, int $cmid, int $userid): array {
        $context = context_module::instance($cmid);

        $systemprompt = $this->build_system_prompt($cmid);
        $messages     = $this->store->get_recent_messages($threadid, self::MAX_HISTORY_MESSAGES);
        $prompt       = $this->build_prompt($systemprompt, $messages);

        try {
            $action   = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
            );
            $response = ai_manager::process_action($action);

            if (!$response->get_success()) {
                return [
                    'response_type' => 'error',
                    'message'       => get_string('ai_provider_error', 'mod_booking'),
                    'commands'      => [],
                    'ambiguities'   => [],
                    'errors'        => [$response->get_errormessage() ?? 'Provider returned an error.'],
                ];
            }

            $rawtext = $response->get_generatedcontent();
        } catch (\Throwable $e) {
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_error', 'mod_booking'),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$e->getMessage()],
            ];
        }

        return $this->interpreter->interpret($rawtext, $cmid, $userid);
    }

    /**
     * Build the state-based system prompt with task schemas embedded.
     *
     * @param  int    $cmid
     * @return string System prompt text.
     */
    private function build_system_prompt(int $cmid): string {
        $schemas = $this->registry->get_all_schemas();
        $tasklist = implode(', ', $this->registry->get_task_names());
        $schemajson = json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $cm = get_coursemodule_from_id('booking', $cmid);
        $bookingname = $cm ? format_string($cm->name) : 'this booking instance';

        return <<<PROMPT
You are an AI assistant for the Moodle booking activity "{$bookingname}".
Your job is to help administrators create and update booking options.

STRICT RULES:
- You MUST respond ONLY with a valid JSON object.  No free text outside the JSON.
- The JSON MUST contain a "response_type" field with one of these values: clarification, confirmation_request, task_call, error.
- You MUST NOT execute or suggest actions outside the allowed task list.
- You MUST NOT invent option IDs. Use only IDs supplied by the user or the system.
- If you are unsure about any field, set response_type to "clarification" and ask.
- Never partially execute. Either all commands are confirmed or none.

ALLOWED TASKS: {$tasklist}

TASK SCHEMAS:
{$schemajson}

RESPONSE FORMAT:

For clarification (you need more information):
{"response_type": "clarification", "message": "Your question to the user."}

For confirmation_request (you have enough info, present to the user for approval):
{"response_type": "confirmation_request", "message": "Summary for user.", "commands": [{"task": "booking.create_option", "version": 1, "input": {"text": "My option"}}]}

For error:
{"response_type": "error", "message": "Description of the problem."}

After the user confirms, respond with:
{"response_type": "task_call", "message": "Executing.", "commands": [...same commands...]}
PROMPT;
    }

    /**
     * Build the full prompt string from system prompt + message history.
     *
     * @param  string      $systemprompt
     * @param  \stdClass[] $messages
     * @return string
     */
    private function build_prompt(string $systemprompt, array $messages): string {
        $parts = ["[SYSTEM]\n{$systemprompt}"];

        foreach ($messages as $msg) {
            $role    = strtoupper($msg->role ?? 'user');
            $content = $msg->content ?? '';
            $parts[] = "[{$role}]\n{$content}";
        }

        $parts[] = '[ASSISTANT]';
        return implode("\n\n", $parts);
    }
}
