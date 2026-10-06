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
 * Event fired when an agent skill actually executes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Audit-trail event: a read-only agent skill ran (chat, MCP or API), regardless of outcome.
 *
 * Reads raise this event (CRUD 'r'); mutating skills raise {@see skill_write_executed} (CRUD 'u')
 * — the split keeps the standard log report's CRUD column meaningful, so "reads" and "changes"
 * separate natively without an `other`-field query. Commands refused at a gate before running
 * instead raise {@see action_denied}.
 *
 * Emitted once per executed read-only command from
 * {@see \bookingextension_agent\local\wizard\executor::execute_commands()} — the single chokepoint
 * every entrypoint funnels through — via
 * {@see \bookingextension_agent\local\wizard\services\telemetry\audit_logger}. The precise
 * operation is also mirrored in `other['crud']`.
 */
class skill_executed extends base {
    /**
     * Initialise static event metadata.
     */
    protected function init(): void {
        // Static per class (see class docblock): the precise operation is in other['crud'].
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_skill_executed', 'bookingextension_agent');
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
        $crud = (string)($this->other['crud'] ?? 'r');
        return "The user with id '{$this->userid}' executed the agent skill '{$skill}' "
            . "(channel: '{$channel}', operation: '{$crud}', outcome: '{$outcome}') "
            . "in the context with id '{$this->contextid}'.";
    }
}
