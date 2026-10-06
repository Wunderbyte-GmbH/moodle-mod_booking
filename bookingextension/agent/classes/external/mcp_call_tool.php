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
 * External service: execute one agent skill as an MCP tool call.
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
 * Execute one MCP tool call against the agent skill catalog.
 *
 * Thin shim over the engine-side MCP facade (services/mcp/): token-capable by
 * design — deliberately NO require_sesskey(), because MCP bridges call through
 * /webservice/rest/server.php with a web service token, not a browser session.
 * Security is layered inside the facade: readiness + mcpaccess here, then the
 * governance evaluator, licence gate, preflight and native capability guard in
 * the shared execution tail. Read-only skills execute immediately; mutating
 * skills return a confirmation preview (phase 2).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_call_tool extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Ambient context id the tool should operate in.'),
            'toolname' => new external_value(PARAM_RAW_TRIMMED, 'MCP tool name (underscore form) or canonical skill name.'),
            'argsjson' => new external_value(PARAM_RAW, 'Tool arguments as a JSON object.', VALUE_DEFAULT, '{}'),
            'idempotencykey' => new external_value(
                PARAM_ALPHANUMEXT,
                'Client-generated per-request key (e.g. a UUID); resend it on retries to avoid double execution.',
                VALUE_DEFAULT,
                ''
            ),
            'sessionid' => new external_value(
                PARAM_RAW_TRIMMED,
                'MCP session id; resend the same value across a session to keep its own pending '
                    . 'confirmation isolated from other clients on the same token. Empty = shared MCP thread.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Execute the tool call and return the MCP tool result as a JSON blob.
     *
     * @param int $contextid
     * @param string $toolname
     * @param string $argsjson
     * @param string $idempotencykey
     * @param string $sessionid MCP session id; scopes the confirm thread (empty = shared thread).
     * @return array
     */
    public static function execute(
        int $contextid,
        string $toolname,
        string $argsjson = '{}',
        string $idempotencykey = '',
        string $sessionid = ''
    ): array {
        global $USER;

        // No require_sesskey() by design: token-capable MCP entry point (see class docs).

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'toolname' => $toolname,
            'argsjson' => $argsjson,
            'idempotencykey' => $idempotencykey,
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

        $args = json_decode((string)$params['argsjson'], true);
        if (!is_array($args)) {
            return ['resultjson' => json_encode([
                'content' => [[
                    'type' => 'text',
                    'text' => get_string('mcp_error_invalid_input', 'bookingextension_agent', 'argsjson is not a JSON object'),
                ]],
                'structuredContent' => ['issue_codes' => ['MCP_INVALID_INPUT']],
                'isError' => true,
            ])];
        }

        // Long-running skill executions must not hold the session lock (token sessions included).
        \core\session\manager::write_close();

        $service = new mcp_execution_service(skill_registry::make_default(), new conversation_store(), $authz);
        $result = $service->call_tool(
            (string)$params['toolname'],
            $args,
            (int)$params['contextid'],
            (int)$USER->id,
            (string)$params['idempotencykey'],
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
