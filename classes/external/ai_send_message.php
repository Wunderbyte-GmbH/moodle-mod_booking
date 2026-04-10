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
use mod_booking\agent\authorization_service;
use mod_booking\agent\conversation_store;
use mod_booking\agent\interpreter;
use mod_booking\agent\orchestrator;
use mod_booking\agent\task_registry;
use mod_booking\singleton_service;

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

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'message' => $message]);
        $cmid    = $params['cmid'];
        $message = trim($params['message']);

        require_sesskey();

        $authz = new authorization_service();
        $authz->require_valid_context($cmid);
        $authz->require_use_capability($USER->id, $cmid);

        if (empty($message)) {
            return ['response_type' => 'error', 'message' => get_string('ai_empty_message', 'mod_booking'),
                    'commands' => '[]', 'ambiguities' => '[]', 'threadid' => 0, 'runid' => 0];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $store    = new conversation_store();
        $registry = task_registry::make_default();

        $thread = $store->get_or_create_thread((int)$USER->id, $cmid, (int)$cm->instance);
        $store->add_message($thread->id, 'user', $message);

        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orchestrator->is_provider_available($cmid, (int)$USER->id)) {
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_not_configured', 'mod_booking'),
                'commands'      => '[]',
                'ambiguities'   => '[]',
                'threadid'      => $thread->id,
                'runid'         => 0,
            ];
        }

        $result = $orchestrator->process($thread->id, $cmid, (int)$USER->id);

        $store->add_message($thread->id, 'assistant', $result['message'] ?? '', [
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
            'threadid'      => $thread->id,
            'runid'         => 0,
        ];
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
        ]);
    }
}
