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
 * Event fired when an agent skill is refused at a security gate.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Security event: an agent skill was denied before it could run.
 *
 * Emitted from {@see \bookingextension_agent\local\wizard\executor::execute_commands()} at each
 * refusal gate — governance/licence (incl. DENY_REQUIRES_PRO), the deterministic guard token,
 * and the native-capability backstop — via
 * {@see \bookingextension_agent\local\wizard\services\telemetry\audit_logger}. Successful runs
 * raise {@see skill_executed} instead. This is the trail an admin needs to spot repeated
 * refusals (probing, misconfiguration, licence gaps) that were previously invisible.
 */
class action_denied extends base {
    /**
     * Initialise static event metadata.
     */
    protected function init(): void {
        // A denial performs no mutation; it is a read of the authorisation state.
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_action_denied', 'bookingextension_agent');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $skill = (string)($this->other['skill'] ?? '');
        $gate = (string)($this->other['gate'] ?? '');
        $reason = (string)($this->other['reason'] ?? '');
        $channel = (string)($this->other['channel'] ?? 'chat');
        return "The user with id '{$this->userid}' was denied the agent skill '{$skill}' "
            . "at the '{$gate}' gate (reason: '{$reason}', channel: '{$channel}') "
            . "in the context with id '{$this->contextid}'.";
    }
}
