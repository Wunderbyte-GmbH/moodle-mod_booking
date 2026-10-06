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
 * Agent authorization service interface.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Interface for agent authorization checks.
 *
 * All capability and context checks must go through this service so that
 * the same rules are applied in the web-service layer, the async skill, and
 * any future extraction into a standalone plugin.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_authorization_service {
    /**
     * Assert that the given user may use the AI instructions feature for this context.
     *
     * Throws \required_capability_exception on failure.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    public function require_use_capability(int $userid, int $contextid): void;

    /**
     * Return true if the user has permission to use AI instructions.
     *
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function can_use(int $userid, int $contextid): bool;

    /**
     * Graceful readiness check for the AI webservice entry points: never throws.
     *
     * Returns null when the agent is ready for this user/context, otherwise a structured problem
     * ['code' => agent_unavailable|context_invalid|permission_denied, 'message' => user-facing string].
     * "agent_unavailable" covers the plugin being not installed / mid-upgrade — distinct from a real
     * permission denial, so callers never surface a misleading "no permission" error.
     *
     * @param int $userid
     * @param int $contextid
     * @return array{code:string,message:string}|null
     */
    public function check_use_readiness(int $userid, int $contextid): ?array;

    /**
     * Assert that the context belongs to an active booking module.
     *
     * Throws \moodle_exception on failure.
     *
     * @param int $contextid
     * @return void
     */
    public function require_valid_context(int $contextid): void;

    /**
     * Assert that the user holds a specific native Moodle capability at a given context.
     *
     * Used for the runtime context switch (Gate 2): re-check the user's own right at the
     * resolved operating context. This is an authorization check, never an escalation.
     * Throws \required_capability_exception on failure.
     *
     * @param int $userid
     * @param \context $operatingcontext
     * @param string $capability
     * @return void
     */
    public function require_capability_at(int $userid, \context $operatingcontext, string $capability): void;
}
