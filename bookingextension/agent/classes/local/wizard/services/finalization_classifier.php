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

use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Deterministic classifier for runtime finalization strategy.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalization_classifier {
    /** @var string */
    public const STRATEGY_DIRECT_FINAL = 'direct_final';

    /** @var string */
    public const STRATEGY_TEMPLATE_ONLY = 'template_only';

    /** @var string */
    public const STRATEGY_LLM_POLISH = 'llm_polish';

    /** @var string[] */
    private const DIRECT_RESPONSE_TYPES = [
        'confirmation_request',
        'confirm_pending',
        'skill_call',
    ];

    /** @var string[] */
    private const DIRECT_ISSUE_CODES = [
        'SCHEMA_ERROR',
        'SCHEMA_UNAVAILABLE',
        'DEPENDENCY_CYCLE',
        'CONTRACT_INVALID_RESPONSE_TYPE',
        'CONTRACT_COMMANDS_REQUIRED',
        // The CONTRACT_PHASE_* family was removed here (N-591a, George 2026-07-14): direct_final
        // rendered the interpreter's raw "CONTRACT_VIOLATION: …" string verbatim to the user
        // (thread 591 msg 1601). These errors now fall through to llm_polish — the interpreter
        // splits them two-channel (plain user_cause in errors/message, technical detail in
        // repair_hints), so the synchronizer formulates the reply in the user's language and
        // planner vocabulary never leaks. Sync failure guards (error faithfulness, envelope
        // sanitization) stay in force on this path.
    ];

    /** @var string[] */
    private const TEMPLATE_ISSUE_CODES = [
        'BUDGET_EXCEEDED',
        'BLOCKED_TIMEOUT',
        'RETRY_EXHAUSTED',
        'PERMISSION_ERROR',
        'VALIDATION_ERROR',
        'CONTEXT_INVALID',
        'CONTRACT_SELECTION_SKILL_MISSING',
        // Synchronizer consistency gate rejections are terminal — never retry via llm_polish.
        'SYNC_FACT_CONFLICT_REJECTED',
        'SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED',
        'SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED',
        'SYNC_SOURCE_RESPONSE_ERROR_REJECTED',
        'SYNC_COMMAND_PAYLOAD_REJECTED',
        'SYNC_EMPTY_MESSAGE',
        'SYNC_RAW_EXCERPT_REJECTED',
    ];

    /** @var string[] */
    private const TEMPLATE_ERROR_CLASSES = [
        'provider_timeout',
        'transient_io',
        'auth_failed',
        'quota_exceeded',
        'runtime_disabled',
        // Provider classes never route to the synchronizer: the provider itself is
        // the failing component, so an extra LLM call would fail (or lie) too.
        'provider_error',
        // HTTP 502/503/504 or an unreachable endpoint: polishing would call the same dead
        // service again and add another gateway wait before the user sees anything (#2395).
        'provider_unreachable',
        // Internal status failures are deterministic facts, not conversation.
        'internal_status',
    ];

    /**
     * Classify finalization strategy from normalized result metadata.
     *
     * @param array $result
     * @return string One of STRATEGY_* constants.
     */
    public function classify(array $result): string {
        $responsetype = trim((string)($result['response_type'] ?? ''));
        $hascommands = $this->has_commands($result);
        $issuecodes = issue_code_normalizer::from_result($result);
        $errorclass = trim((string)($result['error_class'] ?? ''));
        $errorclass = strtolower($errorclass);
        $structuralfailure = !empty($result['structural_failure']);

        if ($hascommands) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if (in_array($responsetype, self::DIRECT_RESPONSE_TYPES, true)) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if ($this->contains_any($issuecodes, self::DIRECT_ISSUE_CODES)) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if ($this->contains_any($issuecodes, self::TEMPLATE_ISSUE_CODES)) {
            return self::STRATEGY_TEMPLATE_ONLY;
        }

        if ($errorclass !== '' && in_array($errorclass, self::TEMPLATE_ERROR_CLASSES, true)) {
            return self::STRATEGY_TEMPLATE_ONLY;
        }

        if ($responsetype === 'sufficient' || $responsetype === 'clarification') {
            // Clarifications and sufficient results are always finalized by the synchronizer so the answer
            // is composed in the user's language (independent of risk class). The synchronizer is made
            // FAITHFUL to a blocking clarification (relay the question + options, never fabricate closure)
            // via its prompt/contract, not by bypassing it here.
            return self::STRATEGY_LLM_POLISH;
        }

        if ($responsetype === 'error' && !$structuralfailure) {
            return self::STRATEGY_LLM_POLISH;
        }

        // Safe fallback: preserve deterministic and structural behavior.
        return self::STRATEGY_DIRECT_FINAL;
    }

    /**
     * Check whether the synchronizer output must include an irreversibility notice.
     *
     * @param array $result
     * @return bool
     */
    public function requires_irreversibility_notice(array $result): bool {
        if (trim((string)($result['response_type'] ?? '')) !== 'sufficient') {
            return false;
        }

        return $this->resolve_explicit_risk_class($result) === skill_risk_class::R3;
    }

    /**
     * Check whether the synchronizer output must include an affected-scope summary.
     *
     * @param array $result
     * @return bool
     */
    public function requires_affected_scope_summary(array $result): bool {
        if (trim((string)($result['response_type'] ?? '')) !== 'sufficient') {
            return false;
        }

        return $this->resolve_explicit_risk_class($result) === skill_risk_class::R2;
    }

    /**
     * Resolve explicit risk_class from the top-level payload.
     *
     * Synchronizer guard requirements should only trigger when risk_class
     * is declared explicitly by runtime output, not inferred implicitly.
     *
     * @param array $result
     * @return string
     */
    private function resolve_explicit_risk_class(array $result): string {
        $riskclass = trim((string)($result['risk_class'] ?? ''));
        if (skill_risk_class::is_valid($riskclass)) {
            return $riskclass;
        }

        return '';
    }

    /**
     * Determine whether the result currently carries executable commands.
     *
     * @param array $result
     * @return bool
     */
    private function has_commands(array $result): bool {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands)) {
            return false;
        }

        if (empty($commands)) {
            return false;
        }

        // Accept both list and single-associative command payloads.
        if (!array_is_list($commands) && isset($commands['skill'])) {
            return true;
        }

        return array_is_list($commands) && !empty($commands);
    }

    /**
     * Returns true when at least one needle exists in haystack.
     *
     * @param string[] $haystack
     * @param string[] $needles
     * @return bool
     */
    private function contains_any(array $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (in_array($needle, $haystack, true)) {
                return true;
            }
        }

        return false;
    }
}
