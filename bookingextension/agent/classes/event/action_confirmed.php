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
 * Event fired when a user confirms a pending mutating agent action.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Audit-trail event: a user approved a pending mutating action at the confirm gate.
 *
 * Records the human *consent* to a change, distinct from the change itself (which raises
 * {@see skill_write_executed} when it runs). Emitted from both confirm paths — the chat
 * {@see \bookingextension_agent\local\wizard\services\confirm_run_service} and the MCP
 * {@see \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service::confirm_tool()} —
 * via {@see \bookingextension_agent\local\wizard\services\telemetry\audit_logger}, so "who
 * authorised this change?" is answerable independently of whether execution then succeeded.
 */
class action_confirmed extends base {
    /**
     * Initialise static event metadata.
     */
    protected function init(): void {
        // A confirmation authorises a change; group it with writes in the log CRUD column.
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_action_confirmed', 'bookingextension_agent');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $skill = (string)($this->other['skill'] ?? '');
        $channel = (string)($this->other['channel'] ?? 'chat');
        return "The user with id '{$this->userid}' confirmed the pending agent action '{$skill}' "
            . "(channel: '{$channel}') in the context with id '{$this->contextid}'.";
    }
}
