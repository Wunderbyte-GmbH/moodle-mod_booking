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
 * MCP tool provider that publishes agent skills to tool_oauthmcp.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\mcp;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\registry\tool_source_interface;

/**
 * Exposes the wizard skill catalog to the remote MCP server (tool_oauthmcp) as
 * native, individually-schema'd tools — the same catalog the stdio bridge serves
 * locally, but server-side over HTTP so claude.ai connectors get one MCP tool per
 * skill instead of the generic meta-functions.
 *
 * This class deliberately depends on tool_oauthmcp types: it is only ever
 * instantiated from the collect_tool_providers hook listener, which fires solely
 * when tool_oauthmcp is installed and dispatches. The skills themselves stay
 * engine-agnostic; only this thin adapter knows the MCP server exists.
 *
 * Every gate still lives in the shared facade services this delegates to
 * (mcp_tool_catalog_service for listing/exposure, mcp_execution_service for the
 * governance/licence/capability/preflight/guard-token/confirm machinery).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_hook_tool_provider implements tool_source_interface {
    /** @var string Source id used in tool_oauthmcp governance rows and audit events. */
    public const SOURCE_ID = 'wizard';

    /** @var mcp_execution_service */
    private mcp_execution_service $execution;

    /**
     * Constructor. Builds the shared facade service once for this request.
     */
    public function __construct() {
        $registry = skill_registry::make_default();
        $store = new conversation_store();
        $authz = new authorization_service();
        $this->execution = new mcp_execution_service($registry, $store, $authz);
    }

    /**
     * Source id.
     *
     * @return string
     */
    public function get_source_id(): string {
        return self::SOURCE_ID;
    }

    /**
     * Native MCP tool definitions for every exposed skill this user can run.
     *
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array
     */
    public function list_tools(int $userid, int $contextid): array {
        // Go through the execution service (not the catalog directly) so the mcpaccess gate
        // applies on this path too — the catalog only maps, it does not authorize.
        $tools = [];
        foreach ($this->execution->list_tools($contextid, $userid) as $tool) {
            $tools[] = $this->attach_scope($tool);
        }
        return $tools;
    }

    /**
     * One native tool definition, or null when this source does not own it.
     *
     * @param string $toolname Tool name.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array|null
     */
    public function get_tool(string $toolname, int $userid, int $contextid): ?array {
        foreach ($this->list_tools($userid, $contextid) as $tool) {
            if (($tool['name'] ?? '') === $toolname) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Execute a skill tool call through the shared execution facade.
     *
     * @param string $toolname Tool name (native skill tool name or confirm_pending_action).
     * @param array $args Tool arguments.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @param string $idempotencykey Per-request key for replay protection.
     * @param string $sessionid MCP session id; isolates per-session pending confirmations.
     * @return array MCP-shaped result (content / structuredContent / isError).
     */
    public function call_tool(
        string $toolname,
        array $args,
        int $userid,
        int $contextid,
        string $idempotencykey,
        string $sessionid = ''
    ): array {
        // Note the argument order: the facade takes (contextid, userid).
        return $this->execution->call_tool($toolname, $args, $contextid, $userid, $idempotencykey, $sessionid);
    }

    /**
     * Tag a catalog definition with the tool_oauthmcp scope derived from its read-only hint.
     *
     * @param array $tool Catalog tool definition.
     * @return array
     */
    private function attach_scope(array $tool): array {
        $readonly = !empty($tool['annotations']['readOnlyHint']);
        $tool['scope'] = $readonly ? auth_result::SCOPE_READ : auth_result::SCOPE_WRITE;
        return $tool;
    }
}
