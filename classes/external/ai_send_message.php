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
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use core_text;
use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\task_registry;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Send a user message to the AI agent and receive the AI's response.
 *
 * This endpoint:
 *  1. Validates sesskey, capability, and context.
 *  2. Persists the user message.
 *  3. Invokes the orchestrator to call the AI provider.
 *  4. Interprets the response and persists it.
 *  5. Returns the structured response to the client.
 *
 * @package    mod_booking
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
            'cmid'    => new external_value(PARAM_INT, 'Course-module id of the booking instance.'),
            'message' => new external_value(PARAM_RAW, 'User message text.'),
        ]);
    }

    /**
     * Send a message to the AI agent.
     *
     * @param int    $cmid
     * @param string $message
     * @return array
     */
    public static function execute(int $cmid, string $message): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'message' => $message]);
        $cmid    = $params['cmid'];
        $message = trim($params['message']);
        $authz = new authorization_service();
        $authz->require_valid_context($cmid);
        $context = context_module::instance($cmid);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, $cmid);

        if (empty($message)) {
            return ['response_type' => 'error', 'message' => get_string('ai_empty_message', 'mod_booking'),
                    'commands' => '[]', 'ambiguities' => '[]', 'threadid' => 0, 'runid' => 0, 'previewoptionid' => 0];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $registry = task_registry::make_default();
        $store = new conversation_store();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orchestrator->is_provider_available($cmid, (int)$USER->id)) {
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_not_configured', 'mod_booking'),
                'commands'      => '[]',
                'ambiguities'   => '[]',
                'threadid'      => 0,
                'runid'         => 0,
                'previewoptionid' => 0,
            ];
        }

        $thread = $store->get_or_create_thread((int)$USER->id, $cmid, (int)$cm->instance);
        $threadid = (int)$thread->id;

        $previewoptionid = self::resolve_preview_option_id($store, $threadid, $cmid);
        if ($previewoptionid > 0 && self::is_preview_request($message)) {
            $previewmessage = get_string('ai_preview_latest_option', 'mod_booking');
            $store->add_message($threadid, 'user', $message);
            $store->add_message($threadid, 'assistant', $previewmessage, [
                'response_type' => 'clarification',
                'previewoptionid' => $previewoptionid,
            ]);

            return [
                'response_type' => 'clarification',
                'message' => $previewmessage,
                'commands' => '[]',
                'ambiguities' => '[]',
                'threadid' => $threadid,
                'runid' => 0,
                'previewoptionid' => $previewoptionid,
            ];
        }

        $store->add_message($threadid, 'user', $message);
        $outputlang = self::infer_output_language($message);

        // Pending-intent: if user sends a short confirmation, re-use the stored pending intent
        // instead of making a new LLM call.  This binds "ja/yes/ok/…" strictly to the most
        // recent confirmation_request for this thread.
        if (self::is_confirmation_only_message($message)) {
            $pendingintent = $store->get_pending_intent($threadid);
            if ($pendingintent !== null) {
                $store->clear_pending_intent($threadid);
                $confirmcommands = $pendingintent['commands'];
                $confirmmessage  = get_string('ai_confirm_pending_intent', 'mod_booking');
                $store->add_message($threadid, 'assistant', $confirmmessage, [
                    'response_type' => 'confirmation_request',
                    'commands'      => $confirmcommands,
                    'ambiguities'   => [],
                    'errors'        => [],
                ]);
                return [
                    'response_type'  => 'confirmation_request',
                    'message'        => $confirmmessage,
                    'commands'       => json_encode($confirmcommands),
                    'ambiguities'    => '[]',
                    'threadid'       => $threadid,
                    'runid'          => 0,
                    'resultsjson'    => '[]',
                    'previewoptionid' => 0,
                ];
            }
        }

        $result = $orchestrator->process($threadid, $cmid, (int)$USER->id);
        if (self::should_auto_execute_read_only($result, $registry)) {
            $result = self::auto_execute_read_only_commands(
                $result,
                $registry,
                $store,
                $authz,
                $threadid,
                $cmid,
                (int)$USER->id,
                $outputlang
            );
        }

        // Store mutating confirmation_request responses as pending intent so the next short
        // "yes/ja/confirm" can replay them without a new LLM round-trip.
        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            // Format: userid:threadid::commandsjson — double colon separates header from payload.
            $intentkey = hash('sha256', (int)$USER->id . ':' . $threadid . '::' . json_encode($result['commands']));
            $store->set_pending_intent($threadid, $result['commands'], $intentkey);
        } else {
            // Any non-confirmation response clears a stale pending intent.
            $store->clear_pending_intent($threadid);
        }

        $result['message'] = self::normalize_agent_message_language($result, $message, $outputlang);

        $store->add_message($threadid, 'assistant', $result['message'] ?? '', [
            'response_type' => $result['response_type'],
            'commands'      => $result['commands'] ?? [],
            'ambiguities'   => $result['ambiguities'] ?? [],
            'errors'        => $result['errors'] ?? [],
        ]);

        return [
            'response_type' => $result['response_type'] ?? 'error',
            'message'       => $result['message'] ?? '',
            'commands'      => json_encode($result['commands'] ?? []),
            'ambiguities'   => json_encode($result['ambiguities'] ?? []),
            'threadid'      => $threadid,
            'runid'         => (int)($result['runid'] ?? 0),
            'resultsjson'   => json_encode($result['results'] ?? []),
            'previewoptionid' => 0,
        ];
    }

    /**
     * Check whether the interpreted response can be auto-executed without confirmation.
     *
     * @param array<string,mixed> $result
     * @param task_registry $registry
     * @return bool
     */
    private static function should_auto_execute_read_only(array $result, task_registry $registry): bool {
        $responsetype = (string)($result['response_type'] ?? '');
        if (!in_array($responsetype, ['task_call', 'confirmation_request'], true)) {
            return false;
        }

        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return false;
        }

        foreach ($commands as $command) {
            if (!is_array($command)) {
                return false;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '' || !$registry->is_read_only_task($taskname)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute read-only commands directly and return an execution result payload.
     *
     * @param array<string,mixed> $result
     * @param task_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $outputlang
     * @return array<string,mixed>
     */
    private static function auto_execute_read_only_commands(
        array $result,
        task_registry $registry,
        conversation_store $store,
        authorization_service $authz,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $commands = (array)($result['commands'] ?? []);
        $idempotencykey = hash('sha256', $userid . ':' . $cmid . ':' . $threadid
            . ':' . json_encode($commands) . ':' . microtime(true));

        $runid = $store->create_run($threadid, $userid, $cmid, $idempotencykey, $commands);

        try {
            $store->update_run_status($runid, 'running');
            $exec = new executor($registry, $store, $authz);
            $results = $exec->execute_commands($commands, $cmid, $userid, $idempotencykey, $runid);
            $store->update_run_status($runid, 'completed', $results);
            $message = trim((string)($result['message'] ?? ''));
            if ($message === '') {
                $message = self::fallback_message_for_result($result, $outputlang);
            }
            if ($message === '') {
                $message = self::localized_string('ai_run_executed', 'booking', null, $outputlang);
            }

            return [
                'response_type' => 'execution_result',
                'message' => $message,
                'commands' => [],
                'ambiguities' => [],
                'errors' => [],
                'runid' => (int)$runid,
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            $failureresults = [[
                'status' => 'error',
                'detail' => $e->getMessage(),
                'resultid' => null,
            ]];
            $store->update_run_status($runid, 'failed', $failureresults);

            return [
                'response_type' => 'error',
                'message' => self::localized_string('ai_provider_error', 'booking', null, $outputlang),
                'commands' => [],
                'ambiguities' => [],
                'errors' => [$e->getMessage()],
                'runid' => (int)$runid,
                'results' => $failureresults,
            ];
        }
    }

    /**
     * Ensure assistant status text follows the user language for command-bearing responses.
     *
     * @param array<string,mixed> $result
     * @param string $usermessage
     * @param string $outputlang
     * @return string
     */
    private static function normalize_agent_message_language(array $result, string $usermessage, string $outputlang): string {
        $message = trim((string)($result['message'] ?? ''));

        if ($message === '') {
            return self::fallback_message_for_result($result, $outputlang);
        }

        return $message;
    }

    /**
     * Build a deterministic fallback message per response/task and language.
     *
     * @param array<string,mixed> $result
     * @param string $outputlang
     * @return string
     */
    private static function fallback_message_for_result(array $result, string $outputlang = ''): string {
        $responsetype = (string)($result['response_type'] ?? '');
        $commands = $result['commands'] ?? [];
        $firsttask = '';
        if (is_array($commands) && !empty($commands) && is_array($commands[0] ?? null)) {
            $firsttask = (string)($commands[0]['task'] ?? '');
        }

        if ($responsetype === 'confirmation_request') {
            if ($firsttask === 'booking.search_options') {
                return self::localized_string('ai_status_confirm_booking_search_options', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.update_option') {
                return self::localized_string('ai_status_confirm_booking_update_option', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.bulk_update_options') {
                return self::localized_string('ai_status_confirm_booking_bulk_update_options', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.create_option') {
                return self::localized_string('ai_status_confirm_booking_create_option', 'booking', null, $outputlang);
            }
            return self::localized_string('ai_status_confirm_default', 'booking', null, $outputlang);
        }

        if ($responsetype === 'task_call') {
            if ($firsttask === 'booking.search_options') {
                return self::localized_string('ai_status_taskcall_booking_search_options', 'booking', null, $outputlang);
            }
            if ($firsttask === 'entities.list_all_entities') {
                return self::localized_string('ai_status_taskcall_entities_list_all', 'booking', null, $outputlang);
            }
            if ($firsttask === 'entities.search') {
                return self::localized_string('ai_status_taskcall_entities_search', 'booking', null, $outputlang);
            }
            if ($firsttask === 'entities.create_entity') {
                return self::localized_string('ai_status_taskcall_entities_create', 'booking', null, $outputlang);
            }
            if ($firsttask === 'shopping_cart.get_items') {
                return self::localized_string('ai_status_taskcall_shopping_cart_items', 'booking', null, $outputlang);
            }
            if ($firsttask === 'shopping_cart.get_totals') {
                return self::localized_string('ai_status_taskcall_shopping_cart_totals', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.search_users') {
                return self::localized_string('ai_status_taskcall_booking_search_users', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.search_courses') {
                return self::localized_string('ai_status_taskcall_booking_search_courses', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.update_option') {
                return self::localized_string('ai_status_taskcall_booking_update_option', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.bulk_update_options') {
                return self::localized_string('ai_status_taskcall_booking_bulk_update_options', 'booking', null, $outputlang);
            }
            if ($firsttask === 'booking.create_option') {
                return self::localized_string('ai_status_taskcall_booking_create_option', 'booking', null, $outputlang);
            }
            return self::localized_string('ai_status_taskcall_default', 'booking', null, $outputlang);
        }

        return trim((string)($result['message'] ?? ''));
    }

    /**
     * Infer preferred output language from the latest user message.
     *
     * @param string $message
     * @return string
     */
    private static function infer_output_language(string $message): string {
        $normalized = core_text::strtolower(trim($message));
        if ($normalized === '') {
            return current_language();
        }

        // Detect German even in mixed-domain phrasing like "welche booking options hast du".
        if (preg_match('/[äöüß]/u', $normalized)) {
            return 'de';
        }

        $germanhints = [
            'zeige', 'mir', 'alle', 'bitte', 'und', 'oder', 'welche', 'was', 'wer', 'wieso', 'warum',
            'hast', 'habe', 'haben', 'du', 'ihr', 'wir', 'kannst', 'koennen', 'können',
            'suche', 'liste', 'locations', 'orte', 'standorte', 'optionen',
        ];
        foreach ($germanhints as $hint) {
            if (strpos($normalized, $hint) !== false) {
                return 'de';
            }
        }

        return current_language();
    }

    /**
     * Resolve a localized string in a requested language by temporarily forcing current language.
     *
     * @param string $identifier
     * @param string $component
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private static function localized_string(string $identifier, string $component, $a = null, string $lang = ''): string {
        $currentlang = current_language();
        $targetlang = trim($lang);
        $switched = $targetlang !== '' && $targetlang !== $currentlang;

        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return get_string($identifier, $component, $a);
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }

    /**
     * Check whether the user is asking for a preview of the latest option.
     *
     * @param string $message
     * @return bool
     */
    private static function is_preview_request(string $message): bool {
        $message = core_text::strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        // Avoid broad tokens like "zeige" that match unrelated requests (e.g. "zeige mir alle locations").
        $previewtokens = [
            'preview',
            'show preview',
            'show latest option',
            'show last option',
            'vorschau',
            'letzte option',
            'zuletzt',
            'neueste option',
        ];
        foreach ($previewtokens as $token) {
            if (strpos($message, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the latest worked option id from thread metadata and verify it belongs to the cm.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param int $cmid
     * @return int
     */
    private static function resolve_preview_option_id(conversation_store $store, int $threadid, int $cmid): int {
        global $DB;

        $optionid = (int)($store->get_thread_metadata_value($threadid, 'lastworkedoptionid') ?? 0);
        if ($optionid <= 0) {
            return 0;
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 0;
        }

        $exists = $DB->record_exists('booking_options', [
            'id' => $optionid,
            'bookingid' => (int)$cm->instance,
        ]);

        return $exists ? $optionid : 0;
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response_type' => new external_value(PARAM_TEXT, 'Response type from the AI.'),
            'message'       => new external_value(PARAM_RAW, 'AI message / summary for the user.'),
            'commands'      => new external_value(PARAM_RAW, 'JSON-encoded array of proposed commands.'),
            'ambiguities'   => new external_value(PARAM_RAW, 'JSON-encoded array of ambiguity questions.'),
            'threadid'      => new external_value(PARAM_INT, 'Thread id.'),
            'runid'         => new external_value(PARAM_INT, 'Run id (0 if not yet created).'),
            'resultsjson'   => new external_value(PARAM_RAW, 'JSON-encoded execution results (if available).'),
            'previewoptionid' => new external_value(PARAM_INT, 'Latest option id to preview directly, if available.'),
        ]);
    }

    /**
     * Short-form confirmation tokens that re-apply the pending intent without a new LLM call.
     *
     * A message matching one of these tokens (case-insensitive, after trim) is treated as a
     * bare confirmation and will replay the most recent pending intent for the thread.
     */
    private const CONFIRMATION_TOKENS = [
        'ja', 'yes', 'ok', 'confirm', 'bestätige', 'bestätigen',
        'jep', 'yep', 'sure', 'go ahead', 'los', 'weiter',
        'mach es', 'tu es', 'do it', 'proceed',
    ];

    /**
     * Check whether a message is a short confirmation response only (no new mutation intent).
     *
     * @param string $message
     * @return bool
     */
    private static function is_confirmation_only_message(string $message): bool {
        return in_array(mb_strtolower(trim($message)), self::CONFIRMATION_TOKENS, true);
    }
}
