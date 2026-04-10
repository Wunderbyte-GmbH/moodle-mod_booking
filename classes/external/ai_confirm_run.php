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
 * External service: confirm an AI agent run.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use core\task\manager as task_manager;
use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;
use mod_booking\task\execute_ai_run_adhoc;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Confirm a proposed AI run and execute directly or via async task.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_confirm_run extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'Course-module id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'commands' => new external_value(PARAM_RAW, 'JSON-encoded commands to confirm.'),
        ]);
    }

    /**
     * Create a run and execute it (direct by default, queue optional).
     *
     * @param int    $cmid
     * @param int    $threadid
     * @param string $commands JSON-encoded commands array.
     * @return array
     */
    public static function execute(int $cmid, int $threadid, string $commands): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'threadid' => $threadid,
            'commands' => $commands,
        ]);

        require_sesskey();

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, $params['cmid']);

        $cmdsarray = json_decode($params['commands'], true);
        if (!is_array($cmdsarray) || empty($cmdsarray)) {
            return ['success' => false, 'runid' => 0, 'message' => get_string('ai_no_commands', 'mod_booking')];
        }

        $idempotencykey = hash('sha256', $USER->id . ':' . $params['cmid'] . ':' . $params['threadid']
            . ':' . $params['commands'] . ':' . microtime(true));

        $store = new conversation_store();
        $runid = $store->create_run(
            $params['threadid'],
            (int)$USER->id,
            $params['cmid'],
            $idempotencykey,
            $cmdsarray
        );

        $executionmode = (string)(get_config('booking', 'aiexecutionmode') ?? 'direct');
        if ($executionmode === 'adhoc') {
            $store->update_run_status($runid, 'queued');
            $store->add_message((int)$params['threadid'], 'assistant', get_string('ai_run_queued', 'mod_booking'), [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'queued',
                'results' => [],
            ]);

            $task = new execute_ai_run_adhoc();
            $task->set_custom_data([
                'runid'          => $runid,
                'userid'         => (int)$USER->id,
                'cmid'           => $params['cmid'],
                'idempotencykey' => $idempotencykey,
            ]);
            $task->set_userid((int)$USER->id);
            task_manager::queue_adhoc_task($task);

            return [
                'success' => true,
                'runid'   => $runid,
                'message' => get_string('ai_run_queued', 'mod_booking'),
            ];
        }

        $store->update_run_status($runid, 'running');
        try {
            $registry = task_registry::make_default();
            $exec = new executor($registry, $store, $authz);
            $results = $exec->execute_commands(
                $cmdsarray,
                $params['cmid'],
                (int)$USER->id,
                $idempotencykey,
                $runid
            );
            $store->update_run_status($runid, 'completed', $results);
            $store->add_message((int)$params['threadid'], 'assistant', self::summarize_results($results), [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'completed',
                'results' => $results,
            ]);

            return [
                'success' => true,
                'runid'   => $runid,
                'message' => get_string('ai_run_executed', 'mod_booking'),
            ];
        } catch (\Throwable $e) {
            $store->update_run_status($runid, 'failed', [
                ['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null],
            ]);
            $store->add_message((int)$params['threadid'], 'assistant', get_string('ai_provider_error', 'mod_booking'), [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'failed',
                'results' => [['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null]],
            ]);
            return [
                'success' => false,
                'runid'   => $runid,
                'message' => get_string('ai_provider_error', 'mod_booking'),
            ];
        }
    }

    /**
     * Summarize run results into assistant-visible thread text.
     *
     * @param array<int,array<string,mixed>> $results
     * @return string
     */
    private static function summarize_results(array $results): string {
        if (empty($results)) {
            return get_string('ai_run_executed', 'mod_booking');
        }

        $parts = [];
        foreach ($results as $index => $result) {
            $status = (string)($result['status'] ?? 'unknown');
            $detail = trim((string)($result['detail'] ?? ''));
            $resultid = (int)($result['resultid'] ?? 0);

            $line = 'Command #' . ($index + 1) . ': ' . $status;
            if ($resultid > 0) {
                $line .= ' (resultid=' . $resultid . ')';
            }
            if ($detail !== '') {
                $line .= ' - ' . $detail;
            }
            $parts[] = $line;
        }

        return implode("\n", $parts);
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the run was successfully queued.'),
            'runid'   => new external_value(PARAM_INT, 'The id of the created run.'),
            'message' => new external_value(PARAM_TEXT, 'Status message.'),
        ]);
    }
}
