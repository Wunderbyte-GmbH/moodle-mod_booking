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
 * Agent command executor.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

use mod_booking\local\wbagent\interfaces\agent_executor;

/**
 * Dispatches interpreter-validated commands to the appropriate task.
 *
 * Enforces idempotency, capability checks, and produces structured per-command
 * results.  Partial success is allowed; no rollback is performed.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executor implements agent_executor {
    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry         $registry
     * @param conversation_store    $store
     * @param authorization_service $authz
     */
    public function __construct(
        task_registry $registry,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
    }

     /**
      * Execute a list of validated commands.
      *
      * @param array  $commands
      * @param int    $cmid
      * @param int    $userid
      * @param string $idempotencykey
      * @param int    $runid
      * @return array
      */
    public function execute_commands(array $commands, int $cmid, int $userid, string $idempotencykey, int $runid): array {
        // Re-check authorization (always re-verify in adhoc context).
        $this->authz->require_use_capability($userid, $cmid);
        $this->authz->require_valid_context($cmid);

        // Idempotency guard.
        if ($this->store->run_exists_other_than($idempotencykey, $runid)) {
            return [['status' => 'skipped', 'detail' => 'Run already executed (idempotency key matched).', 'resultid' => null]];
        }

        $results = [];

        foreach ($commands as $cmd) {
            $taskname = $cmd['task'] ?? '';
            $input    = $cmd['input'] ?? [];

            $task = $this->registry->get_task($taskname);
            if (!$task) {
                $results[] = ['status' => 'error', 'detail' => "No task registered for '$taskname'.", 'resultid' => null];
                continue;
            }

            // Re-validate before execution (stale state detection).
            $validation = $task->validate($input, $cmid);
            if (!$validation['valid']) {
                $detail = implode('; ', array_merge($validation['errors'], $validation['ambiguities']));
                $results[] = ['status' => 'error', 'detail' => "Stale validation failure: $detail", 'resultid' => null];
                continue;
            }

            $result = $task->execute($input, $cmid, $userid);
            $results[] = $result;
        }

        return $results;
    }
}
