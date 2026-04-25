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
 * External service: run strict privacy precheck before LLM processing.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
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
 * Precheck endpoint for user message privacy anonymization.
 */
class ai_privacy_precheck extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module id of the booking instance.'),
            'message' => new external_value(PARAM_RAW, 'Raw user message to precheck and sanitize.'),
            'forcenewthread' => new external_value(
                PARAM_INT,
                'If 1, starts a fresh AI thread for this page session.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Execute privacy precheck.
     *
     * @param int $cmid
     * @param string $message
     * @param int $forcenewthread
     * @return array
     */
    public static function execute(int $cmid, string $message, int $forcenewthread = 0): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'message' => $message,
            'forcenewthread' => $forcenewthread,
        ]);
        $cmid = (int)$params['cmid'];
        $message = trim((string)$params['message']);
        $forcenewthread = (int)$params['forcenewthread'];

        $authz = new authorization_service();
        $authz->require_valid_context($cmid);
        $context = context_module::instance($cmid);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, $cmid);

        if ($message === '') {
            return [
                'status' => 'blocked',
                'message' => get_string('ai_empty_message', 'mod_booking'),
                'sanitizedmessage' => '',
                'anonymizedcount' => 0,
                'anonymizedemails' => 0,
                'anonymizednames' => 0,
                'elapsedms' => 0,
                'threadid' => 0,
                'strictmode' => 0,
            ];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $store = new conversation_store();
        if ($forcenewthread === 1) {
            $thread = $store->create_fresh_thread((int)$USER->id, $cmid, (int)$cm->instance);
        } else {
            $thread = $store->get_or_create_thread((int)$USER->id, $cmid, (int)$cm->instance);
        }
        $threadid = (int)$thread->id;

        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, $message);

        $count = (int)($precheck['anonymizedcount'] ?? 0);
        $a = (object)[
            'count' => $count,
            'emails' => (int)($precheck['anonymizedemails'] ?? 0),
            'names' => (int)($precheck['anonymizednames'] ?? 0),
        ];

        $summary = $count > 0
            ? get_string('ai_privacy_precheck_summary', 'mod_booking', $a)
            : get_string('ai_privacy_precheck_summary_none', 'mod_booking');

        return [
            'status' => 'ok',
            'message' => $summary,
            'sanitizedmessage' => (string)($precheck['sanitizedmessage'] ?? $message),
            'anonymizedcount' => $count,
            'anonymizedemails' => (int)($precheck['anonymizedemails'] ?? 0),
            'anonymizednames' => (int)($precheck['anonymizednames'] ?? 0),
            'elapsedms' => (int)($precheck['elapsedms'] ?? 0),
            'threadid' => $threadid,
            'strictmode' => $anonymizer->should_anonymize_user_input() ? 1 : 0,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'ok or blocked.'),
            'message' => new external_value(PARAM_RAW, 'Privacy precheck status message.'),
            'sanitizedmessage' => new external_value(PARAM_RAW, 'Sanitized message for downstream LLM call.'),
            'anonymizedcount' => new external_value(PARAM_INT, 'Total anonymized entries.'),
            'anonymizedemails' => new external_value(PARAM_INT, 'Anonymized email occurrences.'),
            'anonymizednames' => new external_value(PARAM_INT, 'Anonymized name occurrences.'),
            'elapsedms' => new external_value(PARAM_INT, 'Precheck duration in milliseconds.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'strictmode' => new external_value(PARAM_INT, '1 when strict pre-LLM mode is active, otherwise 0.'),
        ]);
    }
}
