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
 * External service: poll thread messages.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use mod_booking\agent\authorization_service;
use mod_booking\agent\conversation_store;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Return all messages in a conversation thread for the current user.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_poll_thread extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT, 'Course-module id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id (0 = auto-resolve for current user).'),
        ]);
    }

    /**
     * Return thread messages.
     *
     * @param int $cmid
     * @param int $threadid
     * @return array
     */
    public static function execute(int $cmid, int $threadid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'threadid' => $threadid]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $authz->require_use_capability($USER->id, $params['cmid']);

        $store = new conversation_store();

        if ($params['threadid'] > 0) {
            $tid = $params['threadid'];
        } else {
            $cm     = get_coursemodule_from_id('booking', $params['cmid'], 0, false, MUST_EXIST);
            $thread = $store->get_or_create_thread((int)$USER->id, $params['cmid'], (int)$cm->instance);
            $tid    = $thread->id;
        }

        $messages = $store->get_messages($tid);
        $result   = [];

        foreach ($messages as $msg) {
            $result[] = [
                'id'             => (int)$msg->id,
                'role'           => $msg->role,
                'content'        => $msg->content ?? '',
                'structuredjson' => $msg->structuredjson ?? '',
                'timecreated'    => (int)$msg->timecreated,
            ];
        }

        return ['threadid' => $tid, 'messages' => $result];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id'             => new external_value(PARAM_INT, 'Message id.'),
                    'role'           => new external_value(PARAM_TEXT, 'Message role.'),
                    'content'        => new external_value(PARAM_RAW, 'Message content.'),
                    'structuredjson' => new external_value(PARAM_RAW, 'Structured JSON state.', VALUE_OPTIONAL),
                    'timecreated'    => new external_value(PARAM_INT, 'Creation timestamp.'),
                ])
            ),
        ]);
    }
}
