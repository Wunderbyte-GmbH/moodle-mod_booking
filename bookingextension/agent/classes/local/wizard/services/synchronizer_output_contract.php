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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\config\runtime_feature_flags;

/**
 * Enforces the synchronizer output contract.
 *
 * completed_observations = authoritative observed domain outcome after skill execution.
 * completed_commands     = executed command intent (secondary; no domain verification).
 *
 * Gate enforcement respects CONSISTENCY_GATE_MODE:
 *   observe → log telemetry only, pass sync message through
 *   warn    → log telemetry, append warning to message, pass through
 *   enforce → block + return source with issue_code (default)
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_output_contract {
    /**
     * Merge synchronizer output without allowing structural contract drift.
     *
     * @param array $source
     * @param array $sync
     * @return array
     */
    public function merge(array $source, array $sync): array {
        $mode = runtime_feature_flags::enforcement_mode(runtime_feature_flags::CONSISTENCY_GATE_MODE);
        $syncmessage = trim((string)($sync['message'] ?? ''));
        if ($syncmessage === '') {
            return $this->with_gate_telemetry($source, 'failed', 'SYNC_EMPTY_MESSAGE');
        }

        // F3 envelope sanitization (structural rejections only): on a terminal non-success
        // source (clarification/error) a sync reply whose ENVELOPE is wrong — response_type
        // 'error' instead of 'sufficient', or stray commands — often still carries the better
        // user wording (thread 589: the German relay was discarded for the raw cause). The
        // envelope is sanitized and the MESSAGE runs through the remaining content pipeline
        // (fact conflict, source conflict, contract issues) unchanged; the source's own
        // response_type/commands always win, so semantics cannot drift. On a sufficient
        // source WITH a message a sync error envelope stays a real conflict and rejects as
        // before; an empty-message sufficient source has no wording to protect — rejecting
        // there would only leave the user a placeholder.
        $sanitized = false;
        $sourceresponsetype = trim((string)($source['response_type'] ?? ''));
        $sourcemessageempty = trim((string)($source['message'] ?? '')) === '';
        if (
            in_array($sourceresponsetype, ['clarification', 'error'], true)
            || ($sourceresponsetype === 'sufficient' && $sourcemessageempty)
        ) {
            if (trim((string)($sync['response_type'] ?? '')) === 'error') {
                $sync['response_type'] = 'sufficient';
                $sanitized = true;
            }
            if (!empty($sync['commands'])) {
                $sync['commands'] = [];
                $sanitized = true;
            }
        }

        $rejectreason = $this->reject_reason($sync, $syncmessage);
        if ($rejectreason !== '') {
            if ($mode === runtime_feature_flags::ENFORCEMENT_MODE_OBSERVE) {
                return $this->with_gate_telemetry($this->apply_sync_message($source, $syncmessage), 'observe', $rejectreason);
            }
            return $this->with_issue_code(
                $this->with_gate_telemetry($source, 'failed', $rejectreason),
                $rejectreason
            );
        }

        if ($this->has_fact_conflict_with_source($source, $syncmessage)) {
            if ($mode === runtime_feature_flags::ENFORCEMENT_MODE_OBSERVE) {
                return $this->with_gate_telemetry(
                    $this->apply_sync_message($source, $syncmessage),
                    'observe',
                    'SYNC_FACT_CONFLICT_OBSERVED'
                );
            }
            $merged = $this->with_gate_telemetry($source, 'failed', 'SYNC_FACT_CONFLICT_REJECTED');
            return $this->with_issue_code($merged, 'SYNC_FACT_CONFLICT_REJECTED');
        }

        $sourceconflictreason = $this->source_conflict_reason($source);
        if ($sourceconflictreason !== '') {
            $postcondmode = runtime_feature_flags::enforcement_mode(runtime_feature_flags::POSTCONDITION_ENFORCEMENT_MODE);
            if (
                str_contains($sourceconflictreason, 'POSTCONDITION')
                && $postcondmode === runtime_feature_flags::ENFORCEMENT_MODE_OBSERVE
            ) {
                return $this->with_gate_telemetry(
                    $this->apply_sync_message($source, $syncmessage),
                    'observe',
                    $sourceconflictreason
                );
            }
            return $this->with_issue_code(
                $this->with_gate_telemetry($source, 'failed', $sourceconflictreason),
                $sourceconflictreason
            );
        }

        $synccommands = $sync['commands'] ?? [];
        if (is_array($synccommands) && !empty($synccommands)) {
            return $this->with_issue_code(
                $this->with_gate_telemetry($source, 'failed', 'SYNC_COMMAND_PAYLOAD_REJECTED'),
                'SYNC_COMMAND_PAYLOAD_REJECTED'
            );
        }

        // The synchronizer's reply is model text: resolved for display, never masked again (model_authored_text).
        $merged = model_authored_text::mark(array_merge($source, ['message' => $syncmessage]), $syncmessage);

        $synclang = trim((string)($sync['lang'] ?? ''));
        if ($synclang !== '') {
            $merged['lang'] = $synclang;
        }

        return $this->with_gate_telemetry(
            $merged,
            'passed',
            $sanitized ? 'SYNC_ENVELOPE_SANITIZED' : 'SYNC_MESSAGE_ACCEPTED'
        );
    }

    /**
     * Reject sync message when it conflicts with authoritative source execution facts.
     *
     * Current guardrail: if source has a latest option id evidence and sync message
     * references option ids, the latest source id must be present in sync output.
     *
     * @param array $source
     * @param string $syncmessage
     * @return bool
     */
    private function has_fact_conflict_with_source(array $source, string $syncmessage): bool {
        $latestsourceoptionid = $this->extract_latest_source_option_id($source);
        if ($latestsourceoptionid <= 0) {
            return false;
        }

        $syncoptionids = $this->extract_option_ids($syncmessage);
        if (empty($syncoptionids)) {
            return false;
        }

        return !in_array($latestsourceoptionid, $syncoptionids, true);
    }

    /**
     * Extract the newest option id referenced by source facts.
     *
     * @param array $source
     * @return int
     */
    private function extract_latest_source_option_id(array $source): int {
        $results = (array)($source['results'] ?? []);
        for ($i = count($results) - 1; $i >= 0; $i--) {
            $row = $results[$i];
            if (!is_array($row)) {
                continue;
            }

            $candidates = [
                (string)($row['observation_full'] ?? ''),
                (string)($row['observation'] ?? ''),
                (string)($row['detail'] ?? ''),
            ];

            foreach ($candidates as $text) {
                $ids = $this->extract_option_ids($text);
                if (!empty($ids)) {
                    return (int)end($ids);
                }
            }
        }

        $messageids = $this->extract_option_ids((string)($source['message'] ?? ''));
        if (!empty($messageids)) {
            return (int)end($messageids);
        }

        return 0;
    }

    /**
     * Extract option ids from free text using common skill output patterns.
     *
     * @param string $text
     * @return int[]
     */
    private function extract_option_ids(string $text): array {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        $ids = [];
        if (preg_match_all('/(?:option\s*id\s*=\s*|id\s*=\s*|optionid\s*=\s*)(\d+)/i', $trimmed, $matches)) {
            foreach ((array)($matches[1] ?? []) as $rawid) {
                $id = (int)$rawid;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Reject synchronizer outputs that indicate parse or contract failures.
     *
     * @param array $sync
     * @param string $syncmessage
     * @return bool
     */
    private function reject_reason(array $sync, string $syncmessage): string {
        $responsetype = trim((string)($sync['response_type'] ?? ''));
        if ($responsetype === 'error') {
            return 'SYNC_RESPONSE_TYPE_ERROR_REJECTED';
        }

        $issuecodes = issue_code_normalizer::normalize((array)($sync['issue_codes'] ?? []));
        foreach ($issuecodes as $code) {
            if (str_starts_with($code, 'CONTRACT_')) {
                return 'SYNC_CONTRACT_ISSUE_REJECTED';
            }
        }

        if (str_starts_with($syncmessage, 'Failed to parse LLM response as JSON.')) {
            return 'SYNC_PARSE_FAILURE_REJECTED';
        }

        if (strpos($syncmessage, 'Raw excerpt:') !== false) {
            return 'SYNC_RAW_EXCERPT_REJECTED';
        }

        return '';
    }

    /**
     * Detect source-side states where message replacement must be blocked.
     *
     * @param array $source
     * @return string Empty string when no source conflict exists.
     */
    private function source_conflict_reason(array $source): string {
        // Deliberate error presentation (set by the runtime polish step): the
        // synchronizer was fed the error cause and asked to present it in the
        // user's language. Response type and command semantics stay untouched by
        // merge(), so the sync cannot turn the error into a success — only the
        // wording is composed. Without the flag, error sources keep the strict
        // auto-rejection below.
        $errorpresentation = !empty($source['error_presentation_requested']);

        $sourceresponsetype = trim((string)($source['response_type'] ?? ''));
        if ($sourceresponsetype === 'error' && !$errorpresentation) {
            return 'SYNC_SOURCE_RESPONSE_ERROR_REJECTED';
        }

        if ($this->latest_source_result_is_error($source) && !$errorpresentation) {
            return 'SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED';
        }

        $sourcepostcondition = trim((string)($source['postcondition_status'] ?? ''));
        if ($sourcepostcondition === 'failed') {
            return 'SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED';
        }

        return '';
    }

    /**
     * Check whether the newest source result row carries an error status.
     *
     * @param array $source
     * @return bool
     */
    private function latest_source_result_is_error(array $source): bool {
        $results = (array)($source['results'] ?? []);
        if (empty($results)) {
            return false;
        }

        $latest = $results[count($results) - 1];
        if (!is_array($latest)) {
            return false;
        }

        return trim((string)($latest['status'] ?? '')) === 'error';
    }

    /**
     * Append deterministic issue code to result payload.
     *
     * @param array $payload
     * @param string $issuecode
     * @return array
     */
    /**
     * Apply the sync message to source payload (used in observe-mode to pass message through).
     *
     * @param array $source
     * @param string $message
     * @return array
     */
    private function apply_sync_message(array $source, string $message): array {
        $merged = $source;
        $merged['message'] = $message;
        // The synchronizer's reply is model text: resolved for display, never masked again (model_authored_text).
        return model_authored_text::mark($merged, $message);
    }

    /**
     * Add an issue code to the payload if not already present.
     *
     * @param array  $payload   The payload array.
     * @param string $issuecode The issue code to add.
     * @return array The updated payload.
     */
    private function with_issue_code(array $payload, string $issuecode): array {
        $code = trim($issuecode);
        if ($code === '') {
            return $payload;
        }

        $payload['issue_codes'] = array_values(array_unique(array_merge(
            (array)($payload['issue_codes'] ?? []),
            [$code]
        )));
        return $payload;
    }

    /**
     * Attach lightweight gate telemetry to merged result.
     *
     * @param array $payload
     * @param string $status
     * @param string $reason
     * @return array
     */
    private function with_gate_telemetry(array $payload, string $status, string $reason): array {
        $payload['sync_gate_status'] = trim($status);
        $payload['sync_gate_reason'] = trim($reason);
        return $payload;
    }
}
