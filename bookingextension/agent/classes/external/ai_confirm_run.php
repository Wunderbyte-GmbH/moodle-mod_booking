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
 * External service: confirm an AI agent run.
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
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use bookingextension_agent\local\wizard\services\proposed_action_preview;

/**
 * Confirm a proposed AI run and execute directly or via async skill.
 *
 * This class is intentionally a thin WS adapter:
 * - validates auth/context/input
 * - delegates run-confirm orchestration to confirm_run_service
 * - applies display deanonymization and response formatting
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_confirm_run extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'queue_item_id' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id to confirm.'),
            'allow_session' => new external_value(
                PARAM_BOOL,
                'Allow confirmations for this thread in the current session.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Confirm and execute a pending run.
     *
     * @param int $contextid
     * @param int $threadid
     * @param string $queueitemid
     * @param bool $allowsession
     * @return array
     */
    public static function execute(int $contextid, int $threadid, string $queueitemid, bool $allowsession = false): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'threadid' => $threadid,
            'queue_item_id' => $queueitemid,
            'allow_session' => $allowsession,
        ]);

        $authz = new authorization_service();
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);

        $params['contextid'] = (int)$context->id;
        // Only module contexts carry a cmid; other context levels pass 0.
        $cmid = ($context->contextlevel === CONTEXT_MODULE) ? (int)$context->instanceid : 0;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);

        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$context->id)) {
            $errormessage = $problem['message'];
            $issuecode = $problem['code'] === 'permission_denied' ? 'PERMISSION_ERROR' : 'AGENT_UNAVAILABLE';
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => ws_message_formatter::format_ws_message($errormessage, $context),
                'displaymessage' => ws_message_formatter::format_ws_message($errormessage, $context),
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'issuecodesjson' => json_encode([$issuecode]),
                'errorsjson' => json_encode([$problem['code']]),
                'queueitemid' => '',
                'previewjson' => '',
            ];
        }

        $store = new conversation_store();

        // Never confirm a run against a thread the caller does not own (sesskey proves intent, not ownership).
        if (
            (int)$params['threadid'] > 0
                && !$store->thread_belongs_to_user((int)$params['threadid'], (int)$USER->id, (int)$context->id)
        ) {
            $errormessage = get_string('error_ai_permission_denied', 'bookingextension_agent');
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => 0,
                'response_type' => 'error',
                'message' => ws_message_formatter::format_ws_message($errormessage, $context),
                'displaymessage' => ws_message_formatter::format_ws_message($errormessage, $context),
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'issuecodesjson' => json_encode(['PERMISSION_ERROR']),
                'errorsjson' => json_encode(['permission_denied']),
                'queueitemid' => '',
                'previewjson' => '',
            ];
        }

        $registry = skill_registry::make_default();
        $service = new confirm_run_service($registry, $store, $authz);

        $payload = $service->confirm(
            (int)$params['contextid'],
            $cmid,
            (int)$params['threadid'],
            (int)$USER->id,
            (string)$params['queue_item_id'],
            // Session-wide allowances suspend the per-action confirm gate, so they are
            // capability-gated (admin-only by default). Without the capability the flag is
            // silently ignored and the click degrades to a normal one-time confirmation —
            // matching the UI, which hides the session button for such users.
            (bool)$params['allow_session']
                && has_capability('bookingextension/agent:confirmforsession', $context)
        );

        // A multi-step confirm chain can return a fresh confirmation request: when it does and the
        // service produced no executed-result preview, fall back to the proposed-command preview.
        $previewjson = (string)($payload['previewjson'] ?? '');
        if (trim($previewjson) === '' && (string)($payload['response_type'] ?? '') === 'confirmation_request') {
            $previewjson = proposed_action_preview::build_preview_json(
                is_array($payload['commands'] ?? null) ? (array)$payload['commands'] : [],
                $registry,
                (int)($payload['series_remaining'] ?? 0)
            );
        }
        // Source C: a preflight clarification inside this confirm turn (e.g. an autoconfirmed
        // follow-up whose preflight failed) may have stashed a skill-provided preview in thread
        // metadata (see preview_passthrough). Always consumed (cleared); attached only when the
        // turn ends as a clarification and nothing else produced a preview.
        $previewjson = preview_passthrough::consume_clarification_preview_json(
            $store,
            (int)($payload['threadid'] ?? (int)$params['threadid']),
            (string)($payload['response_type'] ?? ''),
            $previewjson
        );

        $message = (string)($payload['message'] ?? '');
        $displaymessage = $message;
        $privacyapplied = 0;
        $anonymizer = new privacy_anonymizer($store);
        $displayresult = $anonymizer->deanonymize_message_for_display((int)$params['threadid'], $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        // Same presentation boundary as in ai_send_message (#2424): the preview table is display, so it is
        // resolved here rather than travelling to the client with raw tokens.
        $decodedpreview = json_decode((string)$previewjson, true);
        if (is_array($decodedpreview)) {
            $previewjson = (string)json_encode(
                $anonymizer->deanonymize_value_for_display((int)$params['threadid'], $decodedpreview)
            );
        }

        return [
            'success' => (bool)($payload['success'] ?? false),
            'runid' => (int)($payload['runid'] ?? 0),
            'threadid' => (int)($payload['threadid'] ?? (int)$params['threadid']),
            'response_type' => (string)($payload['response_type'] ?? 'error'),
            'message' => ws_message_formatter::format_ws_message($message, $context),
            'displaymessage' => ws_message_formatter::format_ws_message($displaymessage, $context),
            'privacyapplied' => $privacyapplied,
            'autoconfirm' => (int)($payload['autoconfirm'] ?? 0),
            'commands' => json_encode((array)($payload['commands'] ?? [])),
            'resultsjson' => json_encode((array)($payload['results'] ?? [])),
            'issuecodesjson' => json_encode((array)($payload['issue_codes'] ?? [])),
            'errorsjson' => json_encode((array)($payload['errors'] ?? [])),
            'queueitemid' => (string)($payload['queueitemid'] ?? ''),
            'previewjson' => $previewjson,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the run was successfully queued.'),
            'runid' => new external_value(PARAM_INT, 'The id of the created run.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'response_type' => new external_value(PARAM_TEXT, 'Final response type from the runtime.'),
            'message' => new external_value(PARAM_RAW, 'Status message.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for the user.'),
            'privacyapplied' => new external_value(PARAM_INT, 'Whether display deanonymization was applied.'),
            'autoconfirm' => new external_value(PARAM_INT, 'Whether the UI should auto-trigger confirmation.'),
            'commands' => new external_value(PARAM_RAW, 'JSON-encoded command list.'),
            'resultsjson' => new external_value(PARAM_RAW, 'JSON-encoded execution results.'),
            'issuecodesjson' => new external_value(PARAM_RAW, 'JSON-encoded issue codes.'),
            'errorsjson' => new external_value(PARAM_RAW, 'JSON-encoded errors.'),
            'queueitemid' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id for the next confirmation step.'),
            'previewjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded preview descriptor payload.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }
}
