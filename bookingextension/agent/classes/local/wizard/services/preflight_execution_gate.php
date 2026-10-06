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

use bookingextension_agent\local\wizard\dto\preflight_result_v2;

/**
 * Layer-3 execution gate for retry/backoff decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_execution_gate {
    /** @var int */
    private const BASE_MS = 500;
    /** @var int */
    private const JITTER_MS = 200;
    /** @var int */
    private const MAX_RETRIES = 4;
    /** @var int */
    private const MAX_BACKOFF_MS = 4000;
    /** @var int Upper bound to keep 2^n exponentiation safe and bounded. */
    private const MAX_EXPONENT = 30;

    /**
     * Evaluate retry policy for an error class.
     *
     * @param string $errorclass
     * @param int $retrycount
     * @param string[] $issuecodes
     * @return preflight_result_v2
     */
    public function evaluate(string $errorclass, int $retrycount, array $issuecodes = []): preflight_result_v2 {
        $errorclass = trim(strtolower($errorclass));
        $retrycount = max(0, $retrycount);
        $issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));

        $retrypolicy = new retry_policy_service();
        $category = $retrypolicy->resolve_retry_hint_category($errorclass, $issuecodes, 'execution');
        if ($category === retry_policy_service::CATEGORY_UNDEFINED) {
            return new preflight_result_v2(
                'hard_block',
                array_values(array_unique(array_merge($issuecodes, [
                    retry_policy_service::ISSUE_RETRY_CATEGORY_UNDEFINED,
                ]))),
                'execution_gate',
                0,
                $retrycount,
                0
            );
        }

        if (!$retrypolicy->is_retryable_category($category)) {
            return new preflight_result_v2(
                'hard_block',
                array_values(array_unique(array_merge($issuecodes, [
                    retry_policy_service::ISSUE_RETRY_CATEGORY_NOT_ALLOWED,
                ]))),
                'execution_gate',
                0,
                $retrycount,
                0
            );
        }

        $circuit = $retrypolicy->evaluate_provider_circuit_breaker($errorclass, $issuecodes);
        if (!$circuit['allow']) {
            return new preflight_result_v2(
                'hard_block',
                array_values(array_unique(array_merge($issuecodes, (array)($circuit['issue_codes'] ?? [])))),
                'execution_gate',
                0,
                $retrycount,
                0
            );
        }

        if (!preflight_error_classifier::is_retryable_error_class($errorclass)) {
            return new preflight_result_v2('hard_block', $issuecodes, 'execution_gate', 0, $retrycount, 0);
        }

        if ($retrycount >= self::MAX_RETRIES) {
            $issuecodes[] = 'MAX_RETRIES_EXCEEDED';
            return new preflight_result_v2(
                'hard_block',
                array_values(array_unique($issuecodes)),
                'execution_gate',
                0,
                $retrycount,
                0
            );
        }

        $exponent = min($retrycount, self::MAX_EXPONENT);
        $multiplier = 2 ** $exponent;
        $backoffms = (self::BASE_MS * $multiplier) + random_int(0, self::JITTER_MS);
        $backoffms = min($backoffms, self::MAX_BACKOFF_MS);
        return new preflight_result_v2(
            'retry_hint',
            array_values(array_unique(array_merge($issuecodes, [
                'RETRY_DECISION_LAYER_EXECUTION',
                'RETRY_CATEGORY_' . $category,
            ]))),
            'execution_gate',
            $backoffms,
            $retrycount,
            0
        );
    }

    /**
     * Build a deterministic guard token for prepared mutating input.
     *
     * @param string $skillname
     * @param int $contextid
     * @param array $preparedinput
     * @return string
     */
    public static function build_guard_token(string $skillname, int $contextid, array $preparedinput): string {
        $normalized = self::normalize_for_guard($preparedinput);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', trim($skillname) . ':' . max(0, $contextid) . ':' . (string)$json);
    }

    /**
     * Verify that a guard token still matches skill, context and prepared input.
     *
     * @param string $guardtoken
     * @param string $skillname
     * @param int $contextid
     * @param array $preparedinput
     * @return bool
     */
    public static function verify_guard_token(
        string $guardtoken,
        string $skillname,
        int $contextid,
        array $preparedinput
    ): bool {
        $guardtoken = trim($guardtoken);
        if ($guardtoken === '') {
            return false;
        }

        return hash_equals($guardtoken, self::build_guard_token($skillname, $contextid, $preparedinput));
    }

    /**
     * Normalize input recursively for stable guard hashing.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_for_guard($value) {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn($entry) => self::normalize_for_guard($entry), $value);
            }
            ksort($value);
            foreach ($value as $key => $entry) {
                $value[$key] = self::normalize_for_guard($entry);
            }
            return $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }
}
