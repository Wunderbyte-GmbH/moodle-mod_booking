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
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

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
            'contextid' => new external_value(PARAM_INT, 'Module context id of the booking instance.'),
            'message' => new external_value(PARAM_RAW, 'Raw user message to precheck and sanitize.'),
            'forcenewthread' => new external_value(
                PARAM_INT,
                'If 1, starts a fresh AI thread for this page session.',
                VALUE_DEFAULT,
                0
            ),
            'anonworddecision' => new external_value(
                PARAM_RAW,
                'Optional JSON object {"word":"...","decision":"person"|"word"} recording the '
                    . 'user\'s structured answer to an anonymizer collision clarification (#2226). '
                    . 'Recorded BEFORE the precheck so a "word" decision unmasks immediately.',
                VALUE_DEFAULT,
                '{}'
            ),
        ]);
    }

    /**
     * Execute privacy precheck.
     *
     * @param int $contextid
     * @param string $message
     * @param int $forcenewthread
     * @param string $anonworddecision
     * @return array
     */
    public static function execute(
        int $contextid,
        string $message,
        int $forcenewthread = 0,
        string $anonworddecision = '{}'
    ): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'message' => $message,
            'forcenewthread' => $forcenewthread,
            'anonworddecision' => $anonworddecision,
        ]);
        $contextid = (int)$params['contextid'];
        $message = trim((string)$params['message']);
        $forcenewthread = (int)$params['forcenewthread'];
        $anonworddecision = (string)($params['anonworddecision'] ?? '{}');

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, $contextid)) {
            return [
                'status' => 'blocked',
                'message' => $problem['message'],
                'sanitizedmessage' => '',
                'anonymizedcount' => 0,
                'anonymizedemails' => 0,
                'anonymizednames' => 0,
                'elapsedms' => 0,
                'threadid' => 0,
                'strictmode' => 0,
            ];
        }
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $contextid = (int)$context->id;
        self::validate_context($context);

        if ($message === '') {
            return [
                'status' => 'blocked',
                'message' => get_string('ai_empty_message', 'bookingextension_agent'),
                'sanitizedmessage' => '',
                'anonymizedcount' => 0,
                'anonymizedemails' => 0,
                'anonymizednames' => 0,
                'elapsedms' => 0,
                'threadid' => 0,
                'strictmode' => 0,
            ];
        }

        $store = new conversation_store();
        if ($forcenewthread === 1) {
            $thread = $store->create_fresh_thread((int)$USER->id, $contextid);
        } else {
            $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        }
        $threadid = (int)$thread->id;

        $anonymizer = new privacy_anonymizer($store);

        // Structured collision decision from the clarification chips (#2226): recorded before
        // the precheck runs, so a "word" decision stops masking that word in THIS message
        // already. The decision comes from a button, never from parsing the reply text.
        $decisionpayload = json_decode($anonworddecision, true);
        if (is_array($decisionpayload)) {
            $anonymizer->record_anon_word_decision(
                (int)$USER->id,
                (string)($decisionpayload['word'] ?? ''),
                (string)($decisionpayload['decision'] ?? '')
            );
        }

        $precheck = $anonymizer->precheck_user_message($threadid, $message);

        $count = (int)($precheck['anonymizedcount'] ?? 0);
        $a = (object)[
            'count' => $count,
            'emails' => (int)($precheck['anonymizedemails'] ?? 0),
            'names' => (int)($precheck['anonymizednames'] ?? 0),
        ];

        $summary = $count > 0
            ? get_string('ai_privacy_precheck_summary', 'bookingextension_agent', $a)
            : get_string('ai_privacy_precheck_summary_none', 'bookingextension_agent');

        // The chip UI (the designed low-confidence tiebreaker) can only render when the
        // response names WHICH words are suspects - counting alone degrades silently.
        $suspects = [];
        $sanitized = (string)($precheck['sanitizedmessage'] ?? '');
        foreach ($anonymizer->get_low_confidence_suspects($threadid, (int)$USER->id) as $token => $word) {
            if ($sanitized !== '' && strpos($sanitized, (string)$token) !== false) {
                $suspects[] = ['token' => (string)$token, 'word' => (string)$word];
            }
        }

        // An undecided suspect blocks the turn (thread 22045): offering the chips while the turn was
        // already running let the decision arrive as a second, overlapping turn. The client repeats
        // this call with the decision and starts the turn only after the status is ok. The message is
        // shown in the interface only, so it may name the word; nothing is stored here.
        $needsdecision = !empty($suspects);
        if ($needsdecision) {
            $summary = get_string(
                'agent_anon_decision_required',
                'bookingextension_agent',
                (string)$suspects[0]['word']
            );
        }

        return [
            'status' => $needsdecision ? 'needs_decision' : 'ok',
            'message' => $summary,
            'sanitizedmessage' => (string)($precheck['sanitizedmessage'] ?? $message),
            'anonymizedcount' => $count,
            'anonymizedemails' => (int)($precheck['anonymizedemails'] ?? 0),
            'anonymizednames' => (int)($precheck['anonymizednames'] ?? 0),
            'elapsedms' => (int)($precheck['elapsedms'] ?? 0),
            'threadid' => $threadid,
            'suspects' => $suspects,
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
            'status' => new external_value(
                PARAM_TEXT,
                'ok, blocked, or needs_decision while a low-confidence suspect of this message is undecided.'
            ),
            'message' => new external_value(PARAM_RAW, 'Privacy precheck status message.'),
            'sanitizedmessage' => new external_value(PARAM_RAW, 'Sanitized message for downstream LLM call.'),
            'anonymizedcount' => new external_value(PARAM_INT, 'Total anonymized entries.'),
            'anonymizedemails' => new external_value(PARAM_INT, 'Anonymized email occurrences.'),
            'anonymizednames' => new external_value(PARAM_INT, 'Anonymized name occurrences.'),
            'elapsedms' => new external_value(PARAM_INT, 'Precheck duration in milliseconds.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'strictmode' => new external_value(PARAM_INT, '1 when strict pre-LLM mode is active, otherwise 0.'),
            'suspects' => new external_multiple_structure(
                new external_single_structure([
                    'token' => new external_value(PARAM_RAW, 'Anonymization token in the sanitized message.'),
                    'word' => new external_value(PARAM_RAW, 'Original single word the token replaced (low confidence).'),
                ]),
                'Low-confidence single-word suspects of THIS message for the chip UI.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
