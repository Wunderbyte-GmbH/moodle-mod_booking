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
 * Adhoc task to execute a confirmed AI agent run asynchronously.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use core\task\adhoc_task;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\execution_feedback_service;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;

/**
 * Executes a confirmed AI run: re-validates, enforces idempotency, dispatches commands.
 *
 * Custom data shape:
 * {
 *   "runid":          int,
 *   "userid":         int,
 *   "cmid":           int,
 *   "idempotencykey": string
 * }
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execute_ai_run_adhoc extends adhoc_task {
    /**
     * Return a human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_execute_ai_run', 'mod_booking');
    }

    /**
     * Execute the AI run.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $runid         = (int)($data->runid ?? 0);
        $userid        = (int)($data->userid ?? 0);
        $cmid          = (int)($data->cmid ?? 0);
        $idempotency   = (string)($data->idempotencykey ?? '');

        if (!$runid || !$userid || !$cmid || !$idempotency) {
            mtrace('execute_ai_run_adhoc: invalid task data, aborting.');
            return;
        }

        mtrace("execute_ai_run_adhoc: processing run id={$runid} cmid={$cmid} userid={$userid}");

        $store   = new conversation_store();
        $authz   = new authorization_service();
        $registry = task_registry::make_default();
        $exec    = new executor($registry, $store, $authz);

        $run = $store->get_run($runid);
        if (!$run) {
            mtrace("execute_ai_run_adhoc: run {$runid} not found.");
            return;
        }

        if ($run->status === 'completed') {
            mtrace("execute_ai_run_adhoc: run {$runid} already completed, skipping.");
            return;
        }

        $store->update_run_status($runid, 'running');

        $commands = json_decode($run->commandsjson ?? '[]', true) ?? [];

        try {
            $rawresults = $exec->execute_commands($commands, $cmid, $userid, $idempotency, $runid);
            $feedbackservice = new execution_feedback_service($store);
            $feedback = $feedbackservice->build_completion_feedback(
                (int)$run->threadid,
                $cmid,
                $userid,
                is_array($commands) ? $commands : [],
                $rawresults,
                current_language()
            );
            $store->update_run_status($runid, 'completed', $feedback['results']);
            $store->add_message((int)$run->threadid, 'assistant', (string)$feedback['message'], [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'completed',
                'results' => $feedback['results'],
            ]);
            mtrace("execute_ai_run_adhoc: run {$runid} completed with " . count($rawresults) . " result(s).");
        } catch (\Throwable $e) {
            $rawresults = [['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null]];
            $feedbackservice = new execution_feedback_service($store);
            $feedback = $feedbackservice->build_completion_feedback(
                (int)$run->threadid,
                $cmid,
                $userid,
                is_array($commands) ? $commands : [],
                $rawresults,
                current_language()
            );
            $store->update_run_status($runid, 'failed', $feedback['results']);
            $store->add_message((int)$run->threadid, 'assistant', (string)$feedback['message'], [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'failed',
                'results' => $feedback['results'],
            ]);
            mtrace("execute_ai_run_adhoc: run {$runid} failed: " . $e->getMessage());
        }
    }
}
