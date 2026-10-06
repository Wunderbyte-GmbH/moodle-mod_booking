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
 * Canonical home for issue-code semantics.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

use core_text;

/**
 * The single place that maps issue codes to meaning.
 *
 * Both views over the vocabulary — the display **error_class** (preflight_error_classifier)
 * and the **retry category** (retry_policy_service) — are projections of ONE ordered rule
 * walk ({@see self::RULES} via {@see self::first_match()}). One precedence, one source of
 * truth: the same code can never be "a timeout" for the user and "a terminal domain error"
 * for the retry engine again. That exact disagreement made DOMAIN_CHECK_TIMEOUT non-retryable
 * (thread 549 defect 2: the old retry view matched the DOMAIN substring first, the display
 * view matched TIMEOUT first).
 *
 * Ambiguity doctrine (George, 2026-07-10): when a composite code matches several families,
 * the RETRYABLE interpretation wins — the rules are ordered TECHNICAL → EXTERNAL_DEPENDENCY →
 * DOMAIN. A wrongly-terminal classification silently loses finished work; a wrongly-retryable
 * one costs at most one futile retry.
 */
class issue_code_taxonomy {
    /** @var string Retryable technical failure (timeout, transient IO, parse, guard, …). */
    public const CATEGORY_TECHNICAL = 'TECHNICAL';

    /** @var string Non-retryable domain failure (validation, conflict, permission, …). */
    public const CATEGORY_DOMAIN = 'DOMAIN';

    /** @var string Retryable external-dependency failure (auth, quota, rate-limit, provider). */
    public const CATEGORY_EXTERNAL_DEPENDENCY = 'EXTERNAL_DEPENDENCY';

    /** @var string No category could be resolved. */
    public const CATEGORY_UNDEFINED = 'UNDEFINED';

    /**
     * THE ordered classification table — the single source of truth for both views.
     *
     * Each rule: [substring needles, display error_class ('' = none), retry category].
     * First (code, rule) hit wins: codes are scanned in the order given, and for each code
     * the rules top-to-bottom. Retryable families come first (see class doc), so composite
     * codes like DOMAIN_CHECK_TIMEOUT or PERMISSION_TIMEOUT classify as retryable timeouts
     * in BOTH views instead of terminal domain errors in one of them.
     *
     * @var array<int,array{0:string[],1:string,2:string}>
     */
    private const RULES = [
        [['TIMEOUT'], 'provider_timeout', self::CATEGORY_TECHNICAL],
        [['TRANSIENT_IO', 'IO_TRANSIENT'], 'transient_io', self::CATEGORY_TECHNICAL],
        [['TRANSIENT', 'CONTRACT_', 'PARSE', 'SELECTION', 'RETRY_WAITING', 'EXECUTION_GUARD'],
            '', self::CATEGORY_TECHNICAL],
        [['AUTH', 'QUOTA', 'RATE_LIMIT', 'PROVIDER', 'EXTERNAL'], '', self::CATEGORY_EXTERNAL_DEPENDENCY],
        [['PERMISSION'], 'permission_error', self::CATEGORY_DOMAIN],
        [['CONFLICT'], 'domain_conflict', self::CATEGORY_DOMAIN],
        [['VALIDATION', 'MISSING_'], 'validation_error', self::CATEGORY_DOMAIN],
        // A malformed/unloadable schema is deterministic: without new input it re-fails
        // identically, so a retry without a repair hint is wasted work (audit C4, thread-590
        // Run 214->215). Non-retryable DOMAIN. This sits AFTER the TIMEOUT rule, so a composite
        // SCHEMA_CHECK_TIMEOUT still matches TIMEOUT first and stays a retryable technical
        // timeout. Latent-invariant / defense-in-depth: the once-observed live path (executor
        // check_structure) is already defused by e14118d — this closes the sharp edge at the
        // classification single-source-of-truth so no future execute-time SCHEMA_ERROR can slip
        // back into a useless retry loop. SCHEMA_UNAVAILABLE (schema not loadable) is grouped
        // in deliberately: in practice it is a permanent deployment fault, and like SCHEMA_ERROR
        // it cannot self-heal without an external change, so a hint-less retry is equally wasted.
        [['SCHEMA'], 'schema_error', self::CATEGORY_DOMAIN],
        [['DOMAIN'], '', self::CATEGORY_DOMAIN],
    ];

    /**
     * The single rule walk both views project from.
     *
     * @param array $issuecodes
     * @return array{0:string,1:string}|null [error_class, category] of the first hit, or null.
     */
    private static function first_match(array $issuecodes): ?array {
        foreach ($issuecodes as $code) {
            $upper = core_text::strtoupper(trim((string)$code));
            if ($upper === '') {
                continue;
            }
            foreach (self::RULES as [$needles, $errorclass, $category]) {
                foreach ($needles as $needle) {
                    if (str_contains($upper, $needle)) {
                        return [$errorclass, $category];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Derive the display error_class from issue codes — a projection of {@see self::first_match()}.
     *
     * @param array $issuecodes
     * @return string The error class, or '' when nothing matches (or the deciding rule has none).
     */
    public static function error_class_for(array $issuecodes): string {
        return self::first_match($issuecodes)[0] ?? '';
    }

    /**
     * Derive the retry category from issue codes — the same projection, plus the error-class and
     * layer fallbacks for callers that only carry a pre-computed error class.
     *
     * @param string $errorclass
     * @param array $issuecodes
     * @param string $layer
     * @return string One of the CATEGORY_* constants.
     */
    public static function retry_category_for(string $errorclass, array $issuecodes, string $layer = ''): string {
        $match = self::first_match(array_values(array_unique(array_filter(array_map('strval', $issuecodes)))));
        if ($match !== null) {
            return $match[1];
        }

        $normalizederrorclass = trim(strtolower($errorclass));
        if (in_array($normalizederrorclass, ['preflight_retry', 'provider_timeout', 'transient_io'], true)) {
            return self::CATEGORY_TECHNICAL;
        }
        if (in_array($normalizederrorclass, ['domain_conflict', 'validation_error', 'permission_error'], true)) {
            return self::CATEGORY_DOMAIN;
        }
        if (in_array($normalizederrorclass, ['provider_error', 'auth_error', 'quota_error'], true)) {
            return self::CATEGORY_EXTERNAL_DEPENDENCY;
        }

        // Execution layer without explicit signals defaults to technical fallback.
        if (trim(strtolower($layer)) === 'execution' && $normalizederrorclass !== '') {
            return self::CATEGORY_TECHNICAL;
        }

        return self::CATEGORY_UNDEFINED;
    }
}
