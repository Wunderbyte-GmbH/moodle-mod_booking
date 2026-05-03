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

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\agent_executor;
use mod_booking\local\wbagent\privacy_anonymizer;

/**
 * Dispatches interpreter-validated commands to the appropriate task.
 *
 * Commands reaching execute_commands() are expected to carry prepared_input
 * (resolved by task->preflight() in agent_decision_service).  The executor
 * therefore performs ONLY lightweight structural checks (check_structure) and
 * does NOT re-run DB-dependent validation.
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
      * Commands are expected to carry prepared_input (resolved IDs, normalised values)
      * produced by task->preflight() in agent_decision_service.
      * The executor MUST NOT repeat DB-resolution logic.
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
            return [['status' => 'skipped', 'detail' => get_string('agent_executor_run_already_executed', 'mod_booking'), 'resultid' => null]];
        }

        $results = [];
        $run = $this->store->get_run($runid);
        $threadid = (int)($run->threadid ?? 0);
        $anonymizer = new privacy_anonymizer($this->store);

        foreach ($commands as $cmd) {
            $taskname = $cmd['task'] ?? '';
            $input    = $cmd['input'] ?? [];
            if ($threadid > 0 && is_array($input)) {
                // Safety-net deanonymization: any remaining ANON tokens not resolved
                // earlier are resolved here (e.g. commands arriving via adhoc tasks
                // that bypassed the decision service preflight).
                $input = $anonymizer->deanonymize_command_input($threadid, $input);
            }

            $task = $this->registry->get_task($taskname);
            if (!$task) {
                $results[] = ['status' => 'error', 'detail' => get_string('agent_executor_task_not_registered', 'mod_booking', $taskname), 'resultid' => null];
                continue;
            }

            // Lightweight structural guard only — no DB access.
            // Deep validation was already performed by task->preflight() in agent_decision_service.
            $structural = $task->check_structure($input);
            if (!($structural['valid'] ?? true)) {
                $detail = implode('; ', (array)($structural['errors'] ?? []));
                $results[] = ['status' => 'error', 'detail' => get_string('agent_executor_structural_failure', 'mod_booking', $detail), 'resultid' => null];
                continue;
            }

            $result = $task->execute($input, $cmid, $userid);
            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                booking_task_support::remember_last_preview_options_for_user_for_execute(
                    $userid,
                    $cmid,
                    array_map('intval', $result['previewoptionids'])
                );
            }
            $results[] = $result;
        }

        return $results;
    }
}
