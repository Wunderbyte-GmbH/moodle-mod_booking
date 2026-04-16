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
use mod_booking\local\wbagent\execution_feedback_service;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\message_trigger_registry;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\privacy_anonymizer;
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
    /** Issue codes that indicate duplicate-title confirmation context. */
    private const DUPLICATE_TITLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
    ];

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
            return ['response_type' => 'error', 'message' => self::localized_string('ai_empty_message', 'mod_booking'),
                'displaymessage' => self::localized_string('ai_empty_message', 'mod_booking'), 'privacyapplied' => 0,
                    'commands' => '[]', 'ambiguities' => '[]', 'errorsjson' => '[]',
                    'attemptedtasksjson' => '[]', 'issuecodesjson' => '[]', 'pendingconfirmationcode' => '',
                    'threadid' => 0, 'runid' => 0, 'previewoptionid' => 0];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $registry = task_registry::make_default();
        $triggerregistry = new message_trigger_registry($registry);
        $store = new conversation_store();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orchestrator->is_provider_available($cmid, (int)$USER->id)) {
            return [
                'response_type' => 'error',
                'message'       => self::localized_string('ai_provider_not_configured', 'mod_booking'),
                'displaymessage' => self::localized_string('ai_provider_not_configured', 'mod_booking'),
                'privacyapplied' => 0,
                'commands'      => '[]',
                'ambiguities'   => '[]',
                'errorsjson'    => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => '[]',
                'pendingconfirmationcode' => '',
                'threadid'      => 0,
                'runid'         => 0,
                'previewoptionid' => 0,
            ];
        }

        $thread = $store->get_or_create_thread((int)$USER->id, $cmid, (int)$cm->instance);
        $threadid = (int)$thread->id;
        $anonymizer = new privacy_anonymizer($store);

        $precheck = $anonymizer->precheck_user_message($threadid, $message);
        $message = (string)($precheck['sanitizedmessage'] ?? $message);

        $previewoptionid = self::resolve_preview_option_id($store, $threadid, $cmid);

        $store->add_message($threadid, 'user', $message);

        $result = $orchestrator->process($threadid, $cmid, (int)$USER->id);
        $outputlang = trim((string)($result['lang'] ?? ''));
        if ($outputlang === '') {
            $outputlang = current_language();
        }
        $store->set_thread_metadata_value($threadid, 'last_output_lang', $outputlang);
        $result['used_triggers'] = $triggerregistry->normalize_used_triggers($result['used_triggers'] ?? []);

        if ($previewoptionid > 0 && self::result_has_trigger($result, 'core.is_preview_request')) {
            $result = [
                'response_type' => 'clarification',
                'message' => self::localized_string('ai_preview_latest_option', 'mod_booking', null, $outputlang),
                'used_triggers' => $result['used_triggers'] ?? [],
                'commands' => [],
                'ambiguities' => [],
                'errors' => [],
                'attempted_tasks' => [],
                'issue_codes' => [],
                'pending_confirmation_code' => '',
            ];
        }

        if (($result['response_type'] ?? '') !== 'confirm_pending'
            && self::result_has_trigger($result, 'core.is_confirmation_message')) {
            $result['response_type'] = 'confirm_pending';
        }

        // LLM recognised the user message as a confirmation of the pending intent.
        if (($result['response_type'] ?? '') === 'confirm_pending') {
            $pendingintent = $store->get_pending_intent($threadid);
            if ($pendingintent !== null) {
                $confirmcommands = is_array($pendingintent['commands'] ?? null)
                    ? (array)$pendingintent['commands']
                    : [];
                if (empty($confirmcommands)) {
                    return [
                        'response_type'  => 'clarification',
                        'message'        => self::localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang),
                        'displaymessage' => self::localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang),
                        'privacyapplied' => 0,
                        'commands'       => '[]',
                        'ambiguities'    => '[]',
                        'errorsjson'     => '[]',
                        'attemptedtasksjson' => '[]',
                        'issuecodesjson' => '[]',
                        'pendingconfirmationcode' => '',
                        'threadid'       => $threadid,
                        'runid'          => 0,
                        'resultsjson'    => '[]',
                        'previewoptionid' => 0,
                    ];
                }

                $confirmationvalidation = self::prevalidate_confirmation_commands($confirmcommands, $registry, $cmid);
                if (!$confirmationvalidation['valid']) {
                    $invalidmessage = self::build_confirmation_validation_message($confirmationvalidation, $outputlang);
                    return [
                        'response_type'  => 'clarification',
                        'message'        => $invalidmessage,
                        'displaymessage' => $invalidmessage,
                        'privacyapplied' => 0,
                        'commands'       => '[]',
                        'ambiguities'    => json_encode($confirmationvalidation['ambiguities'] ?? []),
                        'errorsjson'     => json_encode($confirmationvalidation['errors'] ?? []),
                        'attemptedtasksjson' => json_encode($confirmationvalidation['attempted_tasks'] ?? []),
                        'issuecodesjson' => json_encode($confirmationvalidation['issue_codes'] ?? []),
                        'pendingconfirmationcode' => '',
                        'threadid'       => $threadid,
                        'runid'          => 0,
                        'resultsjson'    => '[]',
                        'previewoptionid' => 0,
                    ];
                }

                $confirmmessage  = self::localized_string('ai_confirm_pending_intent', 'mod_booking', null, $outputlang);
                $intentkey = hash('sha256', (string)(int)$USER->id . ':' . $threadid . '::' . json_encode($confirmcommands));
                $store->set_pending_intent($threadid, $confirmcommands, $intentkey, (int)$USER->id, (int)$cmid);
                $updatedpendingintent = $store->get_pending_intent($threadid);
                $confirmationcode = (string)($updatedpendingintent['confirmationcode'] ?? '');
                $store->add_message($threadid, 'assistant', $confirmmessage, [
                    'response_type' => 'confirmation_request',
                    'commands'      => $confirmcommands,
                    'ambiguities'   => [],
                    'errors'        => [],
                    'attempted_tasks' => [],
                    'issue_codes'   => [],
                    'pending_confirmation_code' => $confirmationcode,
                ]);
                return [
                    'response_type'  => 'confirmation_request',
                    'message'        => $confirmmessage,
                    'displaymessage' => $confirmmessage,
                    'privacyapplied' => 0,
                    'commands'       => json_encode($confirmcommands),
                    'ambiguities'    => '[]',
                    'errorsjson'     => '[]',
                    'attemptedtasksjson' => '[]',
                    'issuecodesjson' => '[]',
                    'pendingconfirmationcode' => $confirmationcode,
                    'threadid'       => $threadid,
                    'runid'          => 0,
                    'resultsjson'    => '[]',
                    'previewoptionid' => 0,
                ];
            }
            // No pending intent found – treat as a clarification nudge.
            $result['response_type'] = 'clarification';
            $result['message'] = self::localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang);
            $result['commands'] = [];
        }

        if (self::result_has_trigger($result, 'core.force_new_duplicate_option')
            && self::has_recent_duplicate_title_prompt($store, $threadid)) {
            $result = self::apply_duplicate_title_override($result);
        }

        // Safety net: if the user clearly asks for lookup/listing but the model returns
        // a mutating confirmation, block accidental carry-over from prior intent.
        $islookuprequest = self::result_has_trigger($result, 'core.is_lookup_request');
        $isconfirmationrequest = (($result['response_type'] ?? '') === 'confirmation_request');
        $hasmutatingcommands = self::has_mutating_commands($result, $registry);
        if ($islookuprequest && $isconfirmationrequest && $hasmutatingcommands) {
            $result = [
                'response_type' => 'clarification',
                'message' => self::localized_string('ai_lookup_detected_blocked_mutation', 'mod_booking', null, $outputlang),
                'commands' => [],
                'ambiguities' => [],
                'errors' => [],
                'attempted_tasks' => $result['attempted_tasks'] ?? [],
                'issue_codes' => $result['issue_codes'] ?? [],
            ];
        }

        // Hardened confirm flow expects pending intents for all mutating confirmations.
        // If the LLM incorrectly emits task_call for a mutating command, normalize it.
        if (self::has_mutating_commands($result, $registry) && ($result['response_type'] ?? '') === 'task_call') {
            $result['response_type'] = 'confirmation_request';
            $normalizedmessage = trim((string)($result['message'] ?? ''));
            $normalized = core_text::strtolower($normalizedmessage);
            if ($normalizedmessage === '' || in_array($normalized, ['executing', 'executing.', 'running', 'running.'], true)) {
                $result['message'] = '';
            }
        }

        if (in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            $commands = $result['commands'] ?? [];
            if (is_array($commands) && !empty($commands)) {
                $split = self::split_commands_by_mutability($commands, $registry);
                $readonlycommands = $split['readonly'];
                $mutatingcommands = $split['mutating'];
                $readonlyexecution = null;

                if (!empty($readonlycommands)) {
                    $readonlyexecution = self::auto_execute_read_only_commands([
                        'response_type' => 'task_call',
                        'message' => '',
                        'commands' => $readonlycommands,
                    ], $registry, $store, $authz, $threadid, $cmid, (int)$USER->id, $outputlang);
                }

                if (!empty($mutatingcommands)) {
                    // Keep write operations confirmation-gated while still allowing read-only tasks to run immediately.
                    $result['response_type'] = 'confirmation_request';
                    $result['commands'] = $mutatingcommands;

                    $confirmmessage = trim((string)($result['message'] ?? ''));
                    if ($confirmmessage === '') {
                        $confirmmessage = self::fallback_message_for_result($result, $outputlang);
                    }

                    if (is_array($readonlyexecution)) {
                        if (self::execution_result_has_failures($readonlyexecution)) {
                            $result = [
                                'response_type' => 'clarification',
                                'message' => trim((string)($readonlyexecution['message'] ?? '')),
                                'commands' => [],
                                'ambiguities' => [],
                                'errors' => (array)($readonlyexecution['errors'] ?? []),
                                'runid' => (int)($readonlyexecution['runid'] ?? 0),
                                'results' => is_array($readonlyexecution['results'] ?? null)
                                    ? $readonlyexecution['results']
                                    : [],
                            ];
                        } else {
                            $readonlymessage = trim((string)($readonlyexecution['message'] ?? ''));
                            if ($readonlymessage !== '') {
                                $result['message'] = $readonlymessage . "\n\n" . $confirmmessage;
                            } else {
                                $result['message'] = $confirmmessage;
                            }
                            $result['runid'] = (int)($readonlyexecution['runid'] ?? 0);
                            $result['results'] = is_array($readonlyexecution['results'] ?? null)
                                ? $readonlyexecution['results']
                                : [];
                        }
                    } else {
                        $result['message'] = $confirmmessage;
                    }
                } else if (is_array($readonlyexecution)) {
                    $result = $readonlyexecution;
                }
            }
        }

        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            $confirmationvalidation = self::prevalidate_confirmation_commands(
                (array)$result['commands'],
                $registry,
                $cmid
            );
            if (!$confirmationvalidation['valid']) {
                $result = [
                    'response_type' => 'clarification',
                    'message' => self::build_confirmation_validation_message($confirmationvalidation, $outputlang),
                    'commands' => [],
                    'ambiguities' => (array)($confirmationvalidation['ambiguities'] ?? []),
                    'errors' => (array)($confirmationvalidation['errors'] ?? []),
                    'attempted_tasks' => (array)($confirmationvalidation['attempted_tasks'] ?? []),
                    'issue_codes' => (array)($confirmationvalidation['issue_codes'] ?? []),
                ];
            }
        }

        // Store mutating confirmation_request responses as pending intent so the LLM
        // can return confirm_pending on the next user message.
        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            // Format: userid:threadid::commandsjson — double colon separates header from payload.
            $intentkey = hash('sha256', (string)(int)$USER->id . ':' . $threadid . '::' . json_encode($result['commands']));
            $store->set_pending_intent($threadid, $result['commands'], $intentkey, (int)$USER->id, (int)$cmid);
            $pendingintent = $store->get_pending_intent($threadid);
            $result['pending_confirmation_code'] = (string)($pendingintent['confirmationcode'] ?? '');
        } else {
            // Any non-confirmation response clears a stale pending intent.
            $store->clear_pending_intent($threadid);
            $result['pending_confirmation_code'] = '';
        }

        $result['message'] = self::normalize_agent_message_language($result, $message, $outputlang);
        $displaymessage = (string)($result['message'] ?? '');
        $privacyapplied = 0;
        $displayresult = $anonymizer->deanonymize_message_for_display($threadid, $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        $store->add_message($threadid, 'assistant', $result['message'] ?? '', [
            'response_type' => $result['response_type'],
            'used_triggers' => $result['used_triggers'] ?? [],
            'commands'      => $result['commands'] ?? [],
            'ambiguities'   => $result['ambiguities'] ?? [],
            'errors'        => $result['errors'] ?? [],
            'attempted_tasks' => $result['attempted_tasks'] ?? [],
            'issue_codes'   => $result['issue_codes'] ?? [],
            'pending_confirmation_code' => $result['pending_confirmation_code'] ?? '',
        ]);

        return [
            'response_type' => $result['response_type'] ?? 'error',
            'message'       => $result['message'] ?? '',
            'displaymessage' => $displaymessage,
            'privacyapplied' => $privacyapplied,
            'commands'      => json_encode($result['commands'] ?? []),
            'ambiguities'   => json_encode($result['ambiguities'] ?? []),
            'errorsjson'    => json_encode($result['errors'] ?? []),
            'attemptedtasksjson' => json_encode($result['attempted_tasks'] ?? []),
            'issuecodesjson' => json_encode($result['issue_codes'] ?? []),
            'pendingconfirmationcode' => (string)($result['pending_confirmation_code'] ?? ''),
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
     * Check whether a response contains at least one mutating (non-read-only) command.
     *
     * @param array<string,mixed> $result
     * @param task_registry $registry
     * @return bool
     */
    private static function has_mutating_commands(array $result, task_registry $registry): bool {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return false;
        }

        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }
            if (!$registry->is_read_only_task($taskname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect failed read-only execution so dependent mutating commands are not offered for confirmation.
     *
     * @param array<string,mixed> $execution
     * @return bool
     */
    private static function execution_result_has_failures(array $execution): bool {
        if ((string)($execution['response_type'] ?? '') === 'error') {
            return true;
        }

        $results = $execution['results'] ?? [];
        if (!is_array($results)) {
            return false;
        }

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = core_text::strtolower(trim((string)($entry['status'] ?? '')));
            if (in_array($status, ['error', 'failed', 'skipped'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split commands into read-only and mutating groups.
     *
     * Unknown or malformed commands are treated as mutating for safety.
     *
     * @param array<int,mixed> $commands
     * @param task_registry $registry
     * @return array{readonly:array<int,array<string,mixed>>,mutating:array<int,array<string,mixed>>}
     */
    private static function split_commands_by_mutability(array $commands, task_registry $registry): array {
        $readonly = [];
        $mutating = [];

        foreach ($commands as $command) {
            if (!is_array($command)) {
                $mutating[] = ['task' => '', 'input' => []];
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && $registry->is_read_only_task($taskname)) {
                $readonly[] = $command;
                continue;
            }

            $mutating[] = $command;
        }

        return [
            'readonly' => $readonly,
            'mutating' => $mutating,
        ];
    }

    /**
     * Check whether a normalized interpreter result includes a specific trigger id.
     *
     * @param array<string,mixed> $result
     * @param string $triggerid
     * @return bool
     */
    private static function result_has_trigger(array $result, string $triggerid): bool {
        $usedtriggers = $result['used_triggers'] ?? [];
        if (!is_array($usedtriggers) || trim($triggerid) === '') {
            return false;
        }

        foreach ($usedtriggers as $candidate) {
            if (trim((string)$candidate) === $triggerid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-validate commands before showing a confirmation button.
     *
     * @param array<int,mixed> $commands
     * @param task_registry $registry
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,attempted_tasks:array<int,string>,issue_codes:array<int,string>}
     */
    private static function prevalidate_confirmation_commands(array $commands, task_registry $registry, int $cmid): array {
        $errors = [];
        $ambiguities = [];
        $attemptedtasks = [];
        $issuecodes = [];

        foreach ($commands as $idx => $command) {
            $label = 'Command #' . ($idx + 1);
            if (!is_array($command)) {
                $errors[] = $label . ': malformed command payload.';
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $errors[] = $label . ': missing task.';
                continue;
            }
            $attemptedtasks[] = $taskname;

            $task = $registry->get_task($taskname);
            if ($task === null) {
                $errors[] = $label . ': task ' . $taskname . ' is not registered.';
                continue;
            }

            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                $errors[] = $label . ': input must be an object/array.';
                continue;
            }

            $validation = $task->validate($input, $cmid);
            foreach ((array)($validation['errors'] ?? []) as $error) {
                $msg = trim((string)$error);
                if ($msg !== '') {
                    $errors[] = $msg;
                }
            }
            foreach ((array)($validation['ambiguities'] ?? []) as $ambiguity) {
                $msg = trim((string)$ambiguity);
                if ($msg !== '') {
                    $ambiguities[] = $msg;
                }
            }

            $issues = $validation['issues'] ?? [];
            if (is_array($issues)) {
                foreach ($issues as $issue) {
                    if (!is_array($issue)) {
                        continue;
                    }
                    $code = trim((string)($issue['code'] ?? ''));
                    if ($code !== '') {
                        $issuecodes[] = $code;
                    }
                }
            }
        }

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => array_values(array_unique($errors)),
            'ambiguities' => array_values(array_unique($ambiguities)),
            'attempted_tasks' => array_values(array_unique($attemptedtasks)),
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Build user-facing clarification text from pre-confirmation validation result.
     *
     * @param array<string,mixed> $validation
     * @param string $outputlang
     * @return string
     */
    private static function build_confirmation_validation_message(array $validation, string $outputlang): string {
        $errors = (array)($validation['errors'] ?? []);
        $ambiguities = (array)($validation['ambiguities'] ?? []);

        $parts = [];
        if (!empty($errors)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $errors)));
        }
        if (!empty($ambiguities)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $ambiguities)));
        }

        $message = trim(implode(' ', array_filter($parts)));
        if ($message !== '') {
            return $message;
        }

        return self::localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang);
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
            $rawresults = $exec->execute_commands($commands, $cmid, $userid, $idempotencykey, $runid);
            $feedbackservice = new execution_feedback_service($store);
            $feedback = $feedbackservice->build_completion_feedback(
                $threadid,
                $cmid,
                $userid,
                $commands,
                $rawresults,
                $outputlang
            );
            $results = $feedback['results'];
            $store->update_run_status($runid, 'completed', $results);
            $message = trim((string)($feedback['message'] ?? ''));
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
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for user UI (de-masked if privacy mode applies).'),
            'privacyapplied' => new external_value(PARAM_INT, '1 if display masking indicator applied, otherwise 0.'),
            'commands'      => new external_value(PARAM_RAW, 'JSON-encoded array of proposed commands.'),
            'ambiguities'   => new external_value(PARAM_RAW, 'JSON-encoded array of ambiguity questions.'),
            'errorsjson'    => new external_value(PARAM_RAW, 'JSON-encoded technical validation errors.'),
            'attemptedtasksjson' => new external_value(PARAM_RAW, 'JSON-encoded attempted task names.'),
            'issuecodesjson' => new external_value(PARAM_RAW, 'JSON-encoded issue codes from task validation.'),
            'pendingconfirmationcode' => new external_value(PARAM_TEXT, 'One-time pending confirmation code for debug.'),
            'threadid'      => new external_value(PARAM_INT, 'Thread id.'),
            'runid'         => new external_value(PARAM_INT, 'Run id (0 if not yet created).'),
            'resultsjson'   => new external_value(PARAM_RAW, 'JSON-encoded execution results (if available).'),
            'previewoptionid' => new external_value(PARAM_INT, 'Latest option id to preview directly, if available.'),
        ]);
    }

    /**
     * Check whether the recent assistant response asked about duplicate titles.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return bool
     */
    private static function has_recent_duplicate_title_prompt(conversation_store $store, int $threadid): bool {
        $messages = $store->get_recent_messages($threadid, 8);
        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $msg) {
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }

            if ((string)($structured['response_type'] ?? '') !== 'confirmation_request') {
                continue;
            }

            $issuecodes = $structured['issue_codes'] ?? [];
            if (!is_array($issuecodes)) {
                continue;
            }

            $normalizedcodes = array_values(array_filter(array_map(
                static fn($code): string => strtoupper(trim((string)$code)),
                $issuecodes
            )));

            if (!empty(array_intersect(self::DUPLICATE_TITLE_ISSUE_CODES, $normalizedcodes))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure create_option commands include duplicate_title override after explicit user confirmation.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function apply_duplicate_title_override(array $result): array {
        if (!in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            return $result;
        }

        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }

        $changed = false;
        foreach ($commands as $idx => $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== 'booking.create_option') {
                continue;
            }

            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                continue;
            }

            $overrides = $input['override'] ?? [];
            if (!is_array($overrides)) {
                $overrides = [];
            }

            if (!in_array('duplicate_title', $overrides, true)) {
                $overrides[] = 'duplicate_title';
                $input['override'] = array_values(array_unique($overrides));
                $commands[$idx]['input'] = $input;
                $changed = true;
            }
        }

        if ($changed) {
            $result['commands'] = array_values($commands);
        }

        return $result;
    }
}
