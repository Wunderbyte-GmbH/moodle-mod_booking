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

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\agent_runtime;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\task_registry;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

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
            $emptymsg = get_string('ai_empty_message', 'mod_booking');
            return [
                'response_type'         => 'error',
                'message'               => $emptymsg,
                'displaymessage'        => $emptymsg,
                'privacyapplied'        => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'attemptedtasksjson'    => '[]',
                'issuecodesjson'        => '[]',
                'pendingconfirmationcode' => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewoptionid'       => 0,
            ];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $registry = task_registry::make_default();
        $store = new conversation_store();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orchestrator->is_provider_available($cmid, (int)$USER->id)) {
            $notconfiguredmsg = get_string('ai_provider_not_configured', 'mod_booking');
            return [
                'response_type'         => 'error',
                'message'               => $notconfiguredmsg,
                'displaymessage'        => $notconfiguredmsg,
                'privacyapplied'        => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'attemptedtasksjson'    => '[]',
                'issuecodesjson'        => '[]',
                'pendingconfirmationcode' => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewoptionid'       => 0,
            ];
        }

        $thread = $store->get_or_create_thread((int)$USER->id, $cmid, (int)$cm->instance);
        $threadid = (int)$thread->id;
        $anonymizer = new privacy_anonymizer($store);

        // Privacy precheck before storing the user message.
        $precheck = $anonymizer->precheck_user_message($threadid, $message);
        $message = (string)($precheck['sanitizedmessage'] ?? $message);

        $store->add_message($threadid, 'user', $message);

        // Delegate the full agent loop to AgentRuntime.
        $runtime = new agent_runtime($registry, $orchestrator, $store, $authz);
        $result = $runtime->run($threadid, $cmid, (int)$USER->id);

        // Display-side privacy deanonymisation (presentation concern, stays here).
        $displaymessage = (string)($result['message'] ?? '');
        $privacyapplied = 0;
        $displayresult = $anonymizer->deanonymize_message_for_display($threadid, $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        return [
            'response_type'         => $result['response_type'] ?? 'error',
            'message'               => $result['message'] ?? '',
            'displaymessage'        => $displaymessage,
            'privacyapplied'        => $privacyapplied,
            'commands'              => json_encode($result['commands'] ?? []),
            'ambiguities'           => json_encode($result['ambiguities'] ?? []),
            'ambiguityoptionsjson'  => json_encode($result['ambiguity_options'] ?? []),
            'errorsjson'            => json_encode($result['errors'] ?? []),
            'attemptedtasksjson'    => json_encode($result['attempted_tasks'] ?? []),
            'issuecodesjson'        => json_encode($result['issue_codes'] ?? []),
            'pendingconfirmationcode' => (string)($result['pending_confirmation_code'] ?? ''),
            'threadid'              => $threadid,
            'runid'                 => (int)($result['runid'] ?? 0),
            'resultsjson'           => json_encode($result['results'] ?? []),
            'previewoptionid'       => 0,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response_type' => new external_value(PARAM_TEXT, 'Response type from the AI.'),
            'message'       => new external_value(PARAM_RAW, 'AI message / summary for the user.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for user UI (de-masked if privacy mode applies).'),
            'privacyapplied' => new external_value(PARAM_INT, '1 if display masking indicator applied, otherwise 0.'),
            'commands'      => new external_value(PARAM_RAW, 'JSON-encoded array of proposed commands.'),
            'ambiguities'   => new external_value(PARAM_RAW, 'JSON-encoded array of ambiguity questions.'),
            'ambiguityoptionsjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded structured ambiguity options for clickable frontend suggestions.'
            ),
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
}
