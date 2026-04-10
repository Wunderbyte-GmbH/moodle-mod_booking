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
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\agent\authorization_service;
use mod_booking\agent\conversation_store;
use mod_booking\task\execute_ai_run_adhoc;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Confirm a proposed AI run and enqueue the async execution task.
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
     * Create a run and enqueue async execution.
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
        $authz->require_use_capability($USER->id, $params['cmid']);

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

        $store->update_run_status($runid, 'queued');

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
