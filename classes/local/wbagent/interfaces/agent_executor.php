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
 * Agent executor interface.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent\interfaces;

/**
 * Interface for the agent executor.
 *
 * The executor receives a list of interpreter-validated commands and
 * dispatches each one to the appropriate task provider.  It enforces
 * idempotency and produces a structured result per command.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_executor {
    /**
     * Execute a list of validated commands and return per-command results.
     *
     * Partial success is allowed – the executor will attempt every command
     * and report individual outcomes.  No rollback is performed.
     *
     * @param array  $commands  Validated command objects, each with 'task', 'version', 'input'.
     * @param int    $cmid      Course-module id for context scoping.
     * @param int    $userid    User id performing the actions.
     * @param string $idempotencykey Unique key that prevents re-execution of the same run.
     * @param int    $runid          Current run id to exclude from duplicate checks.
     * @return array Array of per-command result arrays:
     *               ['status' => 'executed'|'skipped'|'error', 'detail' => string, 'resultid' => int|null]
     */
    public function execute_commands(array $commands, int $cmid, int $userid, string $idempotencykey, int $runid): array;
}
