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

namespace mod_booking\local\wbagent;

use context_module;
use core_ai\manager as ai_manager;
use core_ai\aiactions\generate_text;
use core\di;
use core_text;
use mod_booking\local\wbagent\interfaces\agent_interpreter;

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
    public const MAX_HISTORY_MESSAGES = 20;

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
            $manager = di::get(ai_manager::class);

            if (!$manager->is_action_available(generate_text::class)) {
                return false;
            }
            if (!method_exists($manager, 'is_action_enabled_in_context')) {
                return true;
            }

            return $manager->is_action_enabled_in_context($context, generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Process a user message: call the LLM and interpret the response.
     *
     * @param  int      $threadid     Thread id.
     * @param  int      $cmid         Course-module id.
     * @param  int      $userid       User id.
     * @param  string[] $observations Optional structured observation strings from prior internal loop steps.
     *                                Injected into the prompt so the LLM can reason about tool results
     *                                before producing its next response.  Never persisted to the DB.
     * @return array  Interpreter result.
     */
    public function process(int $threadid, int $cmid, int $userid, array $observations = []): array {
        $context = context_module::instance($cmid);

        $systemprompt = $this->build_system_prompt($cmid);
        $messages     = $this->store->get_recent_messages($threadid, self::MAX_HISTORY_MESSAGES);
        $prompt       = $this->build_prompt($systemprompt, $messages, $observations);

        try {
            $action   = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
            );
            $manager = di::get(ai_manager::class);
            $response = $manager->process_action($action);

            if (!$response->get_success()) {
                $errormessage = $response->get_errormessage() ?? 'Provider returned an error.';
                $errorcode = (int)$response->get_errorcode();
                $errorname = (string)$response->get_error();
                $issuecodes = ai_error_classifier::classify_from_response($errormessage, $errorcode, $errorname);
                return [
                    'response_type' => 'error',
                    'message'       => get_string('ai_provider_error', 'mod_booking'),
                    'commands'      => [],
                    'ambiguities'   => [],
                    'errors'        => [$errormessage],
                    'issue_codes'   => $issuecodes,
                ];
            }

            $rawtext = (string)($response->get_response_data()['generatedcontent'] ?? '');
            if ($rawtext === '') {
                return [
                    'response_type' => 'error',
                    'message'       => get_string('ai_provider_error', 'mod_booking'),
                    'commands'      => [],
                    'ambiguities'   => [],
                    'errors'        => ['Provider returned empty content.'],
                    'issue_codes'   => [],
                ];
            }
        } catch (\Throwable $e) {
            $issuecodes = ai_error_classifier::classify_from_response($e->getMessage(), (int)$e->getCode(), '');
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_error', 'mod_booking'),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$e->getMessage()],
                'issue_codes'   => $issuecodes,
            ];
        }

        $lastusermessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $lastusermessage = trim((string)($msg->content ?? ''));
                break;
            }
        }

        return $this->interpreter->interpret($rawtext, $cmid, $userid, $lastusermessage);
    }

    /**
     * Return the default initial system prompt template.
     *
     * Supported placeholders:
     * - {{bookingname}}
     * - {{timezonename}}
     * - {{nowiso}}
     * - {{tasklist}}
     * - {{schemajson}}
     *
     * @return string
     */
    public static function get_default_initial_prompt_template(): string {
        $path = self::get_default_initial_prompt_template_path();
        if (!is_readable($path)) {
            return 'You are an AI assistant for Moodle booking. Respond only with valid JSON.';
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return 'You are an AI assistant for Moodle booking. Respond only with valid JSON.';
        }

        return (string)$content;
    }

    /**
     * Return absolute path to the default initial prompt markdown file.
     *
     * @return string
     */
    public static function get_default_initial_prompt_template_path(): string {
        return __DIR__ . '/prompts/initial_system_prompt.md';
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
        $triggerregistry = new message_trigger_registry($this->registry);
        $triggerjson = json_encode($triggerregistry->get_available_triggers(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $timezonename = (string)(get_config('core', 'timezone') ?? '');
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }
        try {
            $tz = new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            $timezonename = date_default_timezone_get();
            $tz = new \DateTimeZone($timezonename);
        }
        $nowiso = (new \DateTime('now', $tz))->format(\DateTimeInterface::ATOM);

        $cm = get_coursemodule_from_id('booking', $cmid);
        $bookingname = $cm ? format_string($cm->name) : 'this booking instance';

        $template = (string)(get_config('booking', 'aiinitialprompt') ?? '');
        if (trim($template) === '') {
            $template = self::get_default_initial_prompt_template();
        }

        $prompt = strtr($template, [
            '{{bookingname}}' => $bookingname,
            '{{timezonename}}' => $timezonename,
            '{{nowiso}}' => $nowiso,
            '{{tasklist}}' => $tasklist,
            '{{schemajson}}' => (string)$schemajson,
        ]);

        // Enforce language mirroring even when administrators provide a custom prompt template.
        $prompt .= "\n\nNON-OPTIONAL LANGUAGE POLICY:\n"
            . "- Use the same language as the latest user message for all user-facing text in JSON fields (especially 'message').\n"
            . "- Do not switch language unless the user switches language.\n"
            . "- Return a valid ISO 639-1 value in 'lang' and ensure all user-facing text strictly matches that language.\n"
            . "- If lang='cs', answer in Czech; if lang='de', answer in German; if lang='en', answer in English; etc.\n";

        $prompt .= "\n\nNON-OPTIONAL TRIGGER POLICY:\n"
            . "- Evaluate the latest user message against AVAILABLE MESSAGE TRIGGERS below.\n"
            . "- Return a JSON array field 'used_triggers' with trigger ids that apply to the latest user message.\n"
            . "- Do NOT invent trigger ids. Use only ids from the catalog.\n"
            . "- If none apply, return an empty array for 'used_triggers'.\n"
            . "- Keep response_type independent and correct; triggers are additional structured signals.\n"
            . "\nAVAILABLE MESSAGE TRIGGERS:\n"
            . (string)$triggerjson
            . "\n\nREQUIRED OUTPUT FIELD:\n"
            . "- Every response MUST include: \"used_triggers\": [\"...\"]\n";

        return $prompt;
    }

    /**
     * Build the full prompt string from system prompt + message history + observations.
     *
     * Observations (from prior internal loop tool executions) are injected after the
     * conversation history and before the [ASSISTANT] marker so the LLM can incorporate
     * tool results into its next decision without those results ever being stored as
     * conversation messages.
     *
     * @param  string      $systemprompt
     * @param  \stdClass[] $messages
     * @param  string[]    $observations  Structured observation strings (may be empty).
     * @return string
     */
    private function build_prompt(string $systemprompt, array $messages, array $observations = []): string {
        $contextualguidance = $this->build_contextual_guidance($messages);
        if ($contextualguidance !== '') {
            $systemprompt .= "\n\nCONTEXT-SPECIFIC GUIDANCE:\n" . $contextualguidance;
        }

        $parts = ["[SYSTEM]\n{$systemprompt}"];

        foreach ($messages as $msg) {
            $role    = strtoupper($msg->role ?? 'user');
            $content = $msg->content ?? '';
            $parts[] = "[{$role}]\n{$content}";
        }

        // Inject tool observations from prior internal loop steps.
        // These are ephemeral — they are NOT stored in the conversation history.
        foreach ($observations as $idx => $observation) {
            $num = $idx + 1;
            $parts[] = "[OBSERVATION {$num}]\n{$observation}";
        }

        $parts[] = '[ASSISTANT]';
        return implode("\n\n", $parts);
    }

    /**
     * Build extra guidance only when specific topics appear in recent messages.
     *
     * @param array $messages
     * @return string
     */
    private function build_contextual_guidance(array $messages): string {
        $joined = '';
        foreach ($messages as $msg) {
            $joined .= "\n" . (string)($msg->content ?? '');
        }
        $joined = core_text::strtolower($joined);

        $guidancelines = [];
        $packs = $this->registry->get_contextual_prompt_packs();
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            if (!$this->matches_contextual_pack($pack, $joined)) {
                continue;
            }

            $lines = $pack['guidance'] ?? [];
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $guidancelines[] = $line;
                }
            }
        }

        if (empty($guidancelines)) {
            return '';
        }

        return implode("\n", array_values(array_unique($guidancelines)));
    }

    /**
     * Check whether a contextual prompt pack matches current message context.
     *
     * @param array $pack
     * @param string $joined
     * @return bool
     */
    private function matches_contextual_pack(array $pack, string $joined): bool {
        $triggers = $pack['triggers'] ?? [];
        if (!is_array($triggers) || empty($triggers)) {
            return false;
        }

        foreach ($triggers as $trigger) {
            $needle = core_text::strtolower(trim((string)$trigger));
            if ($needle === '') {
                continue;
            }

            if (preg_match('/[\s_\-]/', $needle)) {
                if (strpos($joined, $needle) !== false) {
                    return true;
                }
                continue;
            }

            $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
            if ((bool)preg_match($pattern, $joined)) {
                return true;
            }
        }

        return false;
    }
}
