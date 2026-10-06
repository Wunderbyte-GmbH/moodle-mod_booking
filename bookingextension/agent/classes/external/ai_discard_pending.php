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
 * External service: discard pending confirmation and clear actionable mutating queue items.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\discard_pending_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Discard pending confirmation intent and skip actionable mutating queue items in the thread.
 */
class ai_discard_pending extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
        ]);
    }

    /**
     * Discard pending confirmation intent and skip actionable mutating queue items in the thread.
     *
     * @param int $contextid
     * @param int $threadid
     * @return array
     */
    public static function execute(int $contextid, int $threadid): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'threadid' => $threadid,
        ]);

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid'])) {
            return ['success' => false, 'discardedcount' => 0, 'threadid' => 0, 'message' => $problem['message']];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);

        $store = new conversation_store();

        // Never discard queue items of a thread the caller does not own.
        if (
            (int)$params['threadid'] > 0
                && !$store->thread_belongs_to_user((int)$params['threadid'], (int)$USER->id, (int)$context->id)
        ) {
            return ['success' => false, 'discardedcount' => 0, 'threadid' => 0, 'message' => ''];
        }

        $result = (new discard_pending_service($store))->discard(
            (int)$params['threadid'],
            (int)$USER->id,
            (int)$context->id
        );

        return [
            'success' => true,
            'discardedcount' => (int)$result['discardedcount'],
            'threadid' => (int)$params['threadid'],
            'message' => (string)$result['message'],
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether discard request was processed.'),
            'discardedcount' => new external_value(PARAM_INT, 'Number of queue items skipped by discard.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'message' => new external_value(PARAM_TEXT, 'Status message.'),
        ]);
    }
}
