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
 * Hook listener that registers the agent as an MCP tool provider.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\hook;

use bookingextension_agent\local\wizard\services\mcp\mcp_hook_tool_provider;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use tool_oauthmcp\hook\collect_tool_providers;

/**
 * Contributes the wizard skill catalog to tool_oauthmcp's MCP surface.
 *
 * The callback only fires when tool_oauthmcp is installed and dispatches the hook,
 * so referencing its types here is safe. It stays quiet unless the engine is the
 * active one (the local_wizard coexistence chokepoint), so the extracted engine
 * and the bundled one never both publish.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_provider_listener {
    /**
     * Add the agent's tool provider to the collection hook.
     *
     * @param collect_tool_providers $hook The provider-collection hook.
     * @return void
     */
    public static function collect(collect_tool_providers $hook): void {
        // Only the active engine publishes; if a higher-priority engine (local_wizard)
        // has taken over, this bundled engine stays silent — mirrors check_use_readiness.
        if (!authorization_service::is_agent_engine_active()) {
            return;
        }
        $hook->add_provider(new mcp_hook_tool_provider());
    }
}
