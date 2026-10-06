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
 * Event fired when a mutating (write) agent skill executes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Audit-trail event: a mutating agent skill ran (chat, MCP or API), regardless of outcome.
 *
 * Split from {@see skill_executed} (which stays read-only) so the standard log report's CRUD
 * column separates changes from reads natively: every write raises this event with a write CRUD,
 * every read raises {@see skill_executed} with 'r'. "Show me everything the agent changed" is
 * therefore a plain CRUD filter, no `other`-field query required.
 *
 * The CRUD letter is fixed per event class ({@see init()} runs before per-instance `other`), so
 * this class reports 'u' (the neutral write letter) in the native column; the precise operation
 * — create/update/delete — is always in `other['crud']` (from the skill's get_log_crud()).
 * Emitted once per executed mutating command from
 * {@see \bookingextension_agent\local\wizard\executor::execute_commands()} via
 * {@see \bookingextension_agent\local\wizard\services\telemetry\audit_logger}.
 */
class skill_write_executed extends base {
    /**
     * Initialise static event metadata.
     */
    protected function init(): void {
        // Groups all writes under 'u' in the log CRUD column; other['crud'] keeps c/u/d precisely.
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_skill_write_executed', 'bookingextension_agent');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $skill = (string)($this->other['skill'] ?? '');
        $channel = (string)($this->other['channel'] ?? 'chat');
        $outcome = (string)($this->other['outcome'] ?? '');
        $crud = (string)($this->other['crud'] ?? 'u');
        return "The user with id '{$this->userid}' executed the mutating agent skill '{$skill}' "
            . "(channel: '{$channel}', operation: '{$crud}', outcome: '{$outcome}') "
            . "in the context with id '{$this->contextid}'.";
    }
}
