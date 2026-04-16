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
 * External service: poll AI run status.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\privacy_anonymizer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Return the current status and results of an AI execution run.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_poll_run_status extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'Course-module id.'),
            'runid' => new external_value(PARAM_INT, 'Run id.'),
        ]);
    }

    /**
     * Return run status and results.
     *
     * @param int $cmid
     * @param int $runid
     * @return array
     */
    public static function execute(int $cmid, int $runid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'runid' => $runid]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, $params['cmid']);

        $store = new conversation_store();
        $run   = $store->get_run($params['runid']);

        if (!$run || (int)$run->userid !== (int)$USER->id || (int)$run->cmid !== $params['cmid']) {
            return [
                'runid'      => $params['runid'],
                'status'     => 'notfound',
                'message'    => '',
                'displaymessage' => '',
                'privacyapplied' => 0,
                'resultsjson' => '[]',
            ];
        }

        $message = '';
        $displaymessage = '';
        $privacyapplied = 0;
        $executionmessage = $store->get_latest_execution_result_message_for_run((int)$run->threadid, (int)$run->id);
        if ($executionmessage) {
            $message = (string)($executionmessage->content ?? '');
            $displaymessage = $message;
            $anonymizer = new privacy_anonymizer($store);
            $display = $anonymizer->deanonymize_message_for_display((int)$run->threadid, $message);
            $displaymessage = (string)($display['message'] ?? $message);
            $privacyapplied = (int)(!empty($display['replacedcount']));
        }

        return [
            'runid'       => (int)$run->id,
            'status'      => $run->status,
            'message'     => $message,
            'displaymessage' => $displaymessage,
            'privacyapplied' => $privacyapplied,
            'resultsjson' => $run->resultsjson ?? '[]',
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'runid'       => new external_value(PARAM_INT, 'Run id.'),
            'status'      => new external_value(PARAM_TEXT, 'Run status.'),
            'message'     => new external_value(PARAM_RAW, 'Assistant message stored for this run.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for this run.'),
            'privacyapplied' => new external_value(PARAM_INT, 'Whether de-masking was applied for display.'),
            'resultsjson' => new external_value(PARAM_RAW, 'JSON-encoded per-command results.'),
        ]);
    }
}
