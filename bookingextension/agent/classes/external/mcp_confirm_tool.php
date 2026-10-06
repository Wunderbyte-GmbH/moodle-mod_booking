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
 * External service: confirm and execute a pending MCP mutation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\mcp\mcp_execution_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Second call of the MCP two-call confirm flow for mutating skills.
 *
 * Thin token-capable shim (deliberately NO require_sesskey, like the other MCP
 * entries). The facade verifies the confirmation code issued with the preview
 * response before consuming the pending intent; the executor then re-verifies
 * the guard token bound to skill + operating context + prepared input. The MCP
 * thread is per user+context, so ownership is structural.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_confirm_tool extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Ambient context id used for the original tool call.'),
            'queueitemid' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id from the preview response.'),
            'confirmationcode' => new external_value(PARAM_ALPHANUMEXT, 'Confirmation code from the preview response.'),
            'sessionid' => new external_value(
                PARAM_RAW_TRIMMED,
                'MCP session id; must match the value used for the originating tool call so the '
                    . 'confirmation resolves against this session\'s pending intent. Empty = shared MCP thread.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Confirm the pending mutation and return the MCP tool result as JSON.
     *
     * @param int $contextid
     * @param string $queueitemid
     * @param string $confirmationcode
     * @param string $sessionid
     * @return array
     */
    public static function execute(
        int $contextid,
        string $queueitemid,
        string $confirmationcode,
        string $sessionid = ''
    ): array {
        global $USER;

        // No require_sesskey() by design: token-capable MCP entry point (see class docs).

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'queueitemid' => $queueitemid,
            'confirmationcode' => $confirmationcode,
            'sessionid' => $sessionid,
        ]);

        $authz = new authorization_service();
        $readiness = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid']);
        if ($readiness !== null) {
            return ['resultjson' => json_encode([
                'content' => [['type' => 'text', 'text' => (string)($readiness['message'] ?? $readiness['code'])]],
                'structuredContent' => ['issue_codes' => ['MCP_NOT_READY', (string)$readiness['code']]],
                'isError' => true,
            ])];
        }

        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('bookingextension/agent:mcpaccess', $context);

        // Mutations can be long-running; never hold the session lock.
        \core\session\manager::write_close();

        $service = new mcp_execution_service(skill_registry::make_default(), new conversation_store(), $authz);
        $result = $service->confirm_tool(
            (string)$params['queueitemid'],
            (string)$params['confirmationcode'],
            (int)$params['contextid'],
            (int)$USER->id,
            true,
            (string)$params['sessionid']
        );

        return ['resultjson' => json_encode($result)];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resultjson' => new external_value(
                PARAM_RAW,
                'MCP tool result as JSON: {content: [...], structuredContent: {...}, isError: bool}.'
            ),
        ]);
    }
}
