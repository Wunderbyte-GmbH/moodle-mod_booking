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
 * External service: list agent skills as MCP tool definitions.
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
 * Return the MCP tool definitions the current user can execute in a context.
 *
 * Thin shim over the engine-side MCP facade (services/mcp/): token-capable by
 * design — deliberately NO require_sesskey(), because MCP bridges call through
 * /webservice/rest/server.php with a web service token, not a browser session.
 * Read-only: listing tools mutates nothing.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_list_tools extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Ambient context id the tools should operate in.'),
        ]);
    }

    /**
     * Return the tool list as a JSON blob.
     *
     * The tool definitions are dynamically shaped (JSON Schema per tool), so the
     * function returns one JSON document instead of a typed external structure —
     * per-skill typed signatures would defeat the generic facade.
     *
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        global $USER;

        // No require_sesskey() by design: token-capable MCP entry point (see class docs).

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
        ]);

        $authz = new authorization_service();
        $readiness = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid']);
        if ($readiness !== null) {
            return ['toolsjson' => json_encode(['tools' => [], 'error' => $readiness])];
        }

        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('bookingextension/agent:mcpaccess', $context);

        $service = new mcp_execution_service(skill_registry::make_default(), new conversation_store(), $authz);
        $tools = $service->list_tools((int)$params['contextid'], (int)$USER->id);

        return ['toolsjson' => json_encode(['tools' => $tools, 'error' => null])];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'toolsjson' => new external_value(PARAM_RAW, 'JSON document: {tools: [...], error: null|{code,message}}.'),
        ]);
    }
}
