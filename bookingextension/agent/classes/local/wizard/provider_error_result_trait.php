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
 * Shared builders for the standardized provider-error result payloads.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use core_text;

/**
 * The standardized provider-error / empty-provider result payloads.
 *
 * Used by both the orchestrator (synchronizer path) and planner_phase_service (planner
 * path); previously these two methods were byte-for-byte duplicated in both classes
 * (audit 04-F06), so a fix to the error-class ladder had to be made twice.
 */
trait provider_error_result_trait {
    /**
     * Build a standardized provider error payload.
     *
     * @param array $call
     * @return array
     */
    private function build_provider_error_result(array $call): array {
        $errormessage = (string)($call['errormessage'] ?? 'Provider returned an error.');
        $errorcode = (int)($call['errorcode'] ?? 0);
        $errorname = (string)($call['errorname'] ?? '');
        $issuecodes = ai_error_classifier::classify_from_response($errormessage, $errorcode, $errorname);

        $errorclass = 'provider_error';
        if (in_array('TRIAL_TOKEN_INVALID', $issuecodes, true)) {
            $errorclass = 'auth_failed';
        } else if (in_array('AI_PROVIDER_QUOTA_EXCEEDED', $issuecodes, true)) {
            $errorclass = 'quota_exceeded';
        } else {
            $lower = core_text::strtolower($errormessage);
            if (
                strpos($lower, 'bad gateway') !== false
                || strpos($lower, 'service unavailable') !== false
                || strpos($lower, 'gateway time') !== false
                || strpos($lower, 'connection refused') !== false
                || strpos($lower, 'could not resolve host') !== false
                || strpos($lower, 'curl error 6') !== false
                || strpos($lower, 'curl error 7') !== false
                || in_array($errorcode, [502, 503, 504], true)
            ) {
                // Transport-level: the gateway/endpoint itself is down or unreachable. Rephrasing
                // cannot help — the template tells the user the service is temporarily unavailable.
                $errorclass = 'provider_unreachable';
            } else if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false) {
                $errorclass = 'provider_timeout';
            } else if (strpos($lower, 'curl error 28') !== false || strpos($lower, 'connection reset') !== false) {
                $errorclass = 'transient_io';
            }
        }

        return [
            'response_type' => 'error',
            // Deliberately empty: the template fallback resolves the localized
            // class-specific text from error_class (provider classes are routed to the
            // template by finalization_classifier — the provider itself is the failing
            // component, so a synchronizer call would fail too).
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            // F61 (Lauf 8, thread 1512): the raw provider/exception text ("Invalid error code: 0") is an
            // admin diagnostic (provider_detail), never a user cause the synchronizer could relay.
            'errors' => ['The AI service could not be reached or returned an error, so the request was not processed.'],
            'provider_detail' => $errormessage,
            'issue_codes' => $issuecodes,
            'error_class' => $errorclass,
        ];
    }

    /**
     * Build the payload for provider output that stayed cut off after the one retry.
     *
     * The partial content is discarded here on purpose: it is unfinished model reasoning, so it
     * must not reach the parser, retry observations, the synchronizer, storage or the user. The
     * runtime turns this result into an honest clarification (agent_runtime).
     *
     * @return array
     */
    private function build_truncated_provider_result(): array {
        return [
            'response_type' => 'error',
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['The AI service stopped before it could complete an answer, so nothing was processed.'],
            'provider_detail' => 'Provider output was cut off at the token limit (finish_reason length), also after one retry.',
            'issue_codes' => ['PROVIDER_OUTPUT_TRUNCATED'],
            'error_class' => 'provider_output_truncated',
        ];
    }

    /**
     * Build a standardized empty-provider payload.
     *
     * @return array
     */
    private function build_empty_provider_result(): array {
        return [
            'response_type' => 'error',
            // Deliberately empty — the transient_io class template resolves it.
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['The AI service returned no answer, so the request was not processed.'],
            'provider_detail' => 'Provider returned empty content.',
            'issue_codes' => [],
            'error_class' => 'transient_io',
        ];
    }
}
