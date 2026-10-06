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
 * External service: send a message to the AI agent.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\attachment\attachment_processor;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use bookingextension_agent\local\wizard\services\proposed_action_preview;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Send a user message to the AI agent and receive the AI's response.
 *
 * This is a thin API wrapper.  All orchestration logic lives in
 * {@see agent_runtime}.  This class is responsible only for:
 *  1. Auth / sesskey validation.
 *  2. Privacy precheck and storing the user message.
 *  3. Delegating to AgentRuntime::run().
 *  4. Applying display-side privacy deanonymisation.
 *  5. Formatting the result for the external API contract.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_send_message extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id of the booking instance.'),
            'message'   => new external_value(PARAM_RAW, 'User message text.'),
            'threadid'  => new external_value(
                PARAM_INT,
                'Optional thread id to pin this message to an existing active thread.',
                VALUE_DEFAULT,
                0
            ),
            'attachments' => new external_value(
                PARAM_RAW,
                'Optional JSON array of attachment tokens: [{"token":"tok_abc","type":"image"}, ...]',
                VALUE_DEFAULT,
                '[]'
            ),
            'pagecontext' => new external_value(
                PARAM_RAW,
                'Optional JSON object describing the user\'s current page (pagetype, url, course, activity). '
                    . 'Best-effort hint only; sanitised server-side and never used for authorization.',
                VALUE_DEFAULT,
                '{}'
            ),
        ]);
    }

    /**
     * Send a message to the AI agent.
     *
     * @param int    $contextid
     * @param string $message
     * @param int    $threadid
     * @param string $attachments
     * @param string $pagecontext
     * @return array
     */
    public static function execute(
        int $contextid,
        string $message,
        int $threadid = 0,
        string $attachments = '[]',
        string $pagecontext = '{}'
    ): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'contextid' => $contextid,
                'message' => $message,
                'threadid' => $threadid,
                'attachments' => $attachments,
                'pagecontext' => $pagecontext,
            ]
        );
        $contextid   = (int)$params['contextid'];
        $message     = trim($params['message']);
        $threadid    = (int)($params['threadid'] ?? 0);
        $attachments = (string)($params['attachments'] ?? '[]');
        $pagecontext = (string)($params['pagecontext'] ?? '{}');
        $authz = new authorization_service();
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $contextid = (int)$context->id;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);

        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$context->id)) {
            $errormessage = $problem['message'];
            $issuecode = $problem['code'] === 'permission_denied' ? 'PERMISSION_ERROR' : 'AGENT_UNAVAILABLE';
            return [
                'response_type'         => 'error',
                'message'               => $errormessage,
                'displaymessage'        => $errormessage,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => json_encode([$problem['code']]),
                'issuecodesjson'        => json_encode([$issuecode]),
                'phasetracejson'        => '[]',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewjson'           => '',
            ];
        }

        if (empty($message)) {
            $emptymsg = get_string('ai_empty_message', 'bookingextension_agent');
            return [
                'response_type'         => 'error',
                'message'               => $emptymsg,
                'displaymessage'        => $emptymsg,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'issuecodesjson'        => '[]',
                'phasetracejson'        => '[]',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewjson'           => '',
            ];
        }

        // Hard input-length guard: reject over-long messages immediately, before any provider/token spend
        // or thread creation. Counts the raw message (multibyte-safe) prior to attachment augmentation.
        $maxinputlength = (int)get_config('bookingextension_agent', 'maxinputlength');
        if ($maxinputlength <= 0) {
            $maxinputlength = 2000;
        }
        if (\core_text::strlen($message) > $maxinputlength) {
            $toolongmsg = get_string('ai_input_too_long', 'bookingextension_agent');
            return [
                'response_type'         => 'error',
                'message'               => $toolongmsg,
                'displaymessage'        => $toolongmsg,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'issuecodesjson'        => '[]',
                'phasetracejson'        => '[]',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewjson'           => '',
            ];
        }

        $registry = skill_registry::make_default();
        $store = new conversation_store();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        $runtimeproviderstatus = $orchestrator->get_runtime_provider_status($contextid);
        if (empty($runtimeproviderstatus['runtimeavailable'])) {
            $reason = $runtimeproviderstatus['failurereason'] ?? '';
            $errormessage = get_string('ai_provider_not_configured', 'bookingextension_agent');

            $reasonmap = [
                'subsystem_missing' => 'error_ai_subsystem_missing',
                'no_provider'       => 'error_ai_no_provider',
                'provider_inactive' => 'error_ai_provider_inactive',
                'actions_missing'   => 'error_ai_actions_missing',
                'course_disabled'   => 'error_ai_course_disabled',
                'context_disabled'  => 'error_ai_context_disabled',
            ];

            if ($reason !== '' && isset($reasonmap[$reason])) {
                $errormessage = get_string($reasonmap[$reason], 'bookingextension_agent');
            } else if ($reason === 'exception_thrown') {
                // Internal failure of the status check itself — not a provider error.
                $errormessage = get_string('error_ai_internal_status', 'bookingextension_agent');
            } else {
                if (!empty($runtimeproviderstatus['provideractive']) && empty($runtimeproviderstatus['courseenabled'])) {
                    $errormessage = get_string('error_ai_course_disabled', 'bookingextension_agent');
                } else if (!empty($runtimeproviderstatus['provideractive']) && empty($runtimeproviderstatus['contextenabled'])) {
                    $errormessage = get_string('error_ai_context_disabled', 'bookingextension_agent');
                }
            }

            return [
                'response_type'         => 'error',
                'message'               => $errormessage,
                'displaymessage'        => $errormessage,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => json_encode([$reason !== '' ? $reason : 'runtime_unavailable']),
                'issuecodesjson'        => json_encode(['RUNTIME_UNAVAILABLE']),
                'phasetracejson'        => '[]',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewjson'           => '',
            ];
        }

        $thread = $store->get_owned_active_thread($threadid, (int)$USER->id, $contextid);
        if ($thread === null) {
            $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        }
        $threadid = (int)$thread->id;

        // Record the user's current page (sanitised, best-effort) so the runtime context can tell the
        // agent WHERE the user is. Overwritten every message so it stays fresh as the user navigates.
        $store->set_thread_metadata_value($threadid, '_page_context', self::sanitize_page_context($pagecontext));

        $anonymizer = new privacy_anonymizer($store);
        $store->set_thread_metadata_value($threadid, '_confirm_previews', []);

        // Privacy precheck before storing the user message.
        $precheck = $anonymizer->precheck_user_message($threadid, $message);
        $message = (string)($precheck['sanitizedmessage'] ?? $message);

        // Augment message with any file attachments (images → token hint, PDFs → extracted text).
        $attachmentlist = @json_decode($attachments, true);
        if (is_array($attachmentlist) && count($attachmentlist) > 0) {
            $message = (new attachment_processor())->augment_message($message, $attachmentlist, (int)$USER->id, $contextid);
        }

        $store->add_message($threadid, 'user', $message);

        // Progress-only status for polling UI; this must not trigger extra LLM calls.
        $store->clear_step_messages($threadid);
        $store->add_step_message($threadid, 1, (string)get_string('ai_thinking', 'bookingextension_agent'), 'runtime.loop');

        // Release the session lock before the blocking LLM call so that
        // concurrent step-polling requests (ai_poll_thread) can be served
        // without waiting for this long-running request to complete.
        \core\session\manager::write_close();

        // Agentic loop: read-only tool calls are executed internally (no user confirmation
        // needed), observations are fed back to the LLM, and only the final user-visible
        // response (clarification, confirmation_request, error) is persisted.
        $runtime = new agent_runtime($registry, $orchestrator, $store, $authz);
        $result = $runtime->run_loop($threadid, $contextid, (int)$USER->id);

        // Display-side privacy deanonymisation (presentation concern, stays here).
        $displaymessage = (string)($result['message'] ?? '');
        $privacyapplied = 0;
        $displayresult = $anonymizer->deanonymize_message_for_display($threadid, $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        $formattedmessage = ws_message_formatter::format_ws_message((string)($result['message'] ?? ''), $context);
        $formatteddisplaymessage = ws_message_formatter::format_ws_message($displaymessage, $context);
        $issuecodes = self::normalize_string_list($result['issue_codes'] ?? []);
        $errors = self::normalize_string_list($result['errors'] ?? []);
        $autoconfirmblocked = !empty($issuecodes) || !empty($errors);
        $responsequeueitemid = self::resolve_response_queue_item_id($store, $threadid);
        $responsecommands = self::resolve_response_commands($store, $threadid, $responsequeueitemid, $result);
        $phasetracejson = self::encode_phase_trace_for_response($result);

        // Preview of EXECUTED results (post-execution skill previews accumulated across the chain).
        $previewjson = preview_passthrough::resolve_preview_json(
            (array)($result['results'] ?? []),
            $threadid,
            '_confirm_previews',
            (array)($result['loop_results'] ?? [])
        );
        // For a pure confirmation request nothing has executed yet, so fall back to a human-readable,
        // skill-agnostic preview of the PROPOSED command(s) instead of the raw parameter dump.
        if (trim($previewjson) === '' && (string)($result['response_type'] ?? '') === 'confirmation_request') {
            $previewjson = proposed_action_preview::build_preview_json($responsecommands, $registry);
        }
        // Source C: a preflight clarification may have stashed a skill-provided preview in thread
        // metadata (see preview_passthrough) — a preflight fail never reaches the executor, so
        // neither source above can carry it. Always consumed (cleared); attached only when this
        // turn actually ends as a clarification and nothing else produced a preview.
        $previewjson = preview_passthrough::consume_clarification_preview_json(
            $store,
            $threadid,
            (string)($result['response_type'] ?? ''),
            $previewjson
        );

        // Display-side resolution for the structured payloads (#2424): ambiguity options are rendered as
        // buttons and pasted back into the input box, preview rows are shown as a table. Both used to carry
        // raw tokens because only the scalar message went through the resolver. Engine texts keep the token,
        // the display resolves it — so this happens here, at the presentation boundary, and nowhere earlier.
        $ambiguityoptions = $anonymizer->deanonymize_value_for_display($threadid, (array)($result['ambiguity_options'] ?? []));
        $ambiguities = $anonymizer->deanonymize_value_for_display($threadid, (array)($result['ambiguities'] ?? []));
        $decodedpreview = json_decode((string)$previewjson, true);
        if (is_array($decodedpreview)) {
            $previewjson = (string)json_encode($anonymizer->deanonymize_value_for_display($threadid, $decodedpreview));
        }

        return [
            'response_type'         => $result['response_type'] ?? 'error',
            'message'               => $formattedmessage,
            'displaymessage'        => $formatteddisplaymessage,
            'privacyapplied'        => $privacyapplied,
            'autoconfirm'           => (int)(
                (string)($result['response_type'] ?? '') === 'confirmation_request'
                && $store->is_confirmation_allowed_for_thread((int)$USER->id, $contextid, $threadid)
                && !$autoconfirmblocked
            ),
            'commands'              => json_encode($responsecommands),
            'ambiguities'           => json_encode($ambiguities),
            'ambiguityoptionsjson'  => json_encode($ambiguityoptions),
            'errorsjson'            => json_encode($errors),
            'issuecodesjson'        => json_encode($issuecodes),
            'phasetracejson'        => $phasetracejson,
            'queueitemid'           => $responsequeueitemid,
            'threadid'              => $threadid,
            'runid'                 => (int)($result['runid'] ?? 0),
            'resultsjson'           => json_encode($result['results'] ?? []),
            'previewjson'           => $previewjson,
        ];
    }

    /**
     * Normalize any list-like value into a compact non-empty string list.
     *
     * @param mixed $value
     */
    private static function normalize_string_list($value): array {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            $text = trim((string)$entry);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values($normalized);
    }

    /**
     * Resolve queue item id for the active confirmation step in a thread.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return string
     */
    private static function resolve_response_queue_item_id(conversation_store $store, int $threadid): string {
        $pendingintentsvc = new pending_intent_service($store);
        $pendingintent = $pendingintentsvc->get($threadid);
        if (!is_array($pendingintent)) {
            return '';
        }

        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        return (string)($queueitemids[0] ?? '');
    }

    /**
     * Resolve the command payload that should be exposed in the WS response.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $queueitemid
     * @param array $result
     * @return array[]
     */
    private static function resolve_response_commands(
        conversation_store $store,
        int $threadid,
        string $queueitemid,
        array $result
    ): array {
        if ((string)($result['response_type'] ?? '') !== 'confirmation_request' || $queueitemid === '') {
            return is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        }

        $queuesvc = new queue_manager($store);
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        if (!is_array($item)) {
            return is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        }

        $skill = trim((string)($item['skill'] ?? ''));
        if ($skill === '') {
            return [];
        }

        $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
            ? (array)$item['prepared_input']
            : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);
        $command = [
            'skill' => $skill,
            'version' => max(1, (int)($item['version'] ?? 1)),
            'input' => $input,
        ];
        $guardtoken = trim((string)($item['guard_token'] ?? ''));
        if ($guardtoken !== '') {
            $command['guard_token'] = $guardtoken;
        }
        $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
        if (!empty($dependson)) {
            $command['depends_on'] = $dependson;
        }

        return [$command];
    }

    /**
     * Encode split-pipeline phase trace for external API consumers.
     *
     * @param array $result
     * @return string
     */
    private static function encode_phase_trace_for_response(array $result): string {
        $phasetrace = [];
        if (isset($result['phase_trace']) && is_array($result['phase_trace'])) {
            $phasetrace = $result['phase_trace'];
        } else if (
            isset($result['planner_result'])
            && is_array($result['planner_result'])
            && isset($result['planner_result']['phase_trace'])
            && is_array($result['planner_result']['phase_trace'])
        ) {
            $phasetrace = (array)$result['planner_result']['phase_trace'];
        }

        $encoded = json_encode($phasetrace);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * Sanitise the client-supplied current-page descriptor before it enters the prompt.
     *
     * The payload is server-generated (the navbar hook) but relayed by the browser, so it is treated as
     * untrusted: whitelist keys, coerce types, strip markup/control characters and cap lengths so it can
     * never inject prompt lines or bloat the context. Informational only — never an authorization source.
     *
     * @param string $json
     * @return array
     */
    private static function sanitize_page_context(string $json): array {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        $clean = static function ($value): string {
            $text = strip_tags((string)$value);
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            return \core_text::substr($text, 0, 200);
        };
        $out = [];
        foreach (['pagetype', 'url', 'heading', 'coursename', 'modname', 'activityname'] as $key) {
            if (isset($raw[$key]) && (string)$raw[$key] !== '') {
                $cleaned = $clean($raw[$key]);
                if ($cleaned !== '') {
                    $out[$key] = $cleaned;
                }
            }
        }
        foreach (['contextlevel', 'courseid', 'cmid'] as $key) {
            if (isset($raw[$key]) && (int)$raw[$key] > 0) {
                $out[$key] = (int)$raw[$key];
            }
        }
        return $out;
    }

    /**
     * Returns external function result schema.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response_type' => new external_value(PARAM_TEXT, 'Response type from the AI.'),
            'message'       => new external_value(PARAM_RAW, 'AI message / summary for the user.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for user UI (de-masked if privacy mode applies).'),
            'privacyapplied' => new external_value(PARAM_INT, '1 if display masking indicator applied, otherwise 0.'),
            'autoconfirm'    => new external_value(PARAM_INT, '1 if the UI should auto-trigger confirmation, otherwise 0.'),
            'commands'      => new external_value(PARAM_RAW, 'JSON-encoded array of proposed commands.'),
            'ambiguities'   => new external_value(PARAM_RAW, 'JSON-encoded array of ambiguity questions.'),
            'ambiguityoptionsjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded structured ambiguity options for clickable frontend suggestions.'
            ),
            'errorsjson'    => new external_value(PARAM_RAW, 'JSON-encoded technical validation errors.'),
            'issuecodesjson' => new external_value(PARAM_RAW, 'JSON-encoded issue codes from skill validation.'),
            'phasetracejson' => new external_value(
                PARAM_RAW,
                'JSON-encoded split-pipeline phase trace (discovery/selection/parameter_construction).',
                VALUE_DEFAULT,
                '[]'
            ),
            'queueitemid' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id for confirmation.'),
            'threadid'      => new external_value(PARAM_INT, 'Thread id.'),
            'runid'         => new external_value(PARAM_INT, 'Run id (0 if not yet created).'),
            'resultsjson'   => new external_value(PARAM_RAW, 'JSON-encoded execution results (if available).'),
            'previewjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded preview descriptor payload.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }
}
