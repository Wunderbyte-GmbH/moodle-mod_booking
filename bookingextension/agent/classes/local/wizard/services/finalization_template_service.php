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

/**
 * Deterministic template resolver for template-only finalization.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalization_template_service {
    /** @var array Error class => language string shown to the user. */
    private const ERROR_CLASS_LANG_KEYS = [
        'auth_failed' => 'error_ai_trial_token_invalid',
        'quota_exceeded' => 'error_ai_provider_quota_exceeded',
        'runtime_disabled' => 'error_ai_context_disabled',
        'provider_timeout' => 'error_ai_provider_timeout',
        'provider_unreachable' => 'error_ai_provider_unreachable',
        'transient_io' => 'error_ai_transient_io',
        'provider_error' => 'ai_provider_error',
        'internal_contract' => 'error_ai_internal_planning',
        'internal_status' => 'error_ai_internal_status',
        'skill_exception' => 'error_ai_skill_exception',
    ];

    /** @var array Issue code => language string shown to the user. */
    private const ISSUE_CODE_LANG_KEYS = [
        'AI_PROVIDER_QUOTA_EXCEEDED' => 'error_ai_provider_quota_exceeded',
        'BLOCKED_TIMEOUT' => 'error_ai_confirmation_timeout',
        'BUDGET_EXCEEDED' => 'error_ai_budget_exceeded',
        'CONTEXT_INVALID' => 'error_ai_request_context_invalid',
        'CONTRACT_SELECTION_SKILL_MISSING' => 'error_ai_no_next_skill',
        'PERMISSION_ERROR' => 'error_ai_permission_denied',
        'RETRY_EXHAUSTED' => 'error_ai_retry_exhausted',
        'TRIAL_TOKEN_INVALID' => 'error_ai_trial_token_invalid',
        'VALIDATION_ERROR' => 'error_ai_invalid_input',
    ];

    /**
     * Resolve a deterministic template-only message.
     *
     * Priority: issue_codes first, then error_class.
     *
     * @param array $result
     * @return string
     */
    public function resolve_message(array $result): string {
        $msg = '';

        foreach (issue_code_normalizer::from_result($result) as $issuecode) {
            if (isset(self::ISSUE_CODE_LANG_KEYS[$issuecode])) {
                $localized = get_string(self::ISSUE_CODE_LANG_KEYS[$issuecode], 'bookingextension_agent');
                if ($localized !== '' && !str_starts_with($localized, '[[')) {
                    $msg = $localized;
                    break;
                }
            }
        }

        if ($msg === '') {
            $errorclass = strtolower(trim((string)($result['error_class'] ?? '')));
            if ($errorclass !== '') {
                if (isset(self::ERROR_CLASS_LANG_KEYS[$errorclass])) {
                    $localized = get_string(self::ERROR_CLASS_LANG_KEYS[$errorclass], 'bookingextension_agent');
                    if ($localized !== '' && !str_starts_with($localized, '[[')) {
                        $msg = $localized;
                    }
                }
            }
        }

        if ($msg !== '') {
            // Raw error details are an ADMIN diagnostic channel — regular users get
            // the localized class text only (raw provider/internal strings are noise
            // for them and may be English-only).
            if (is_siteadmin()) {
                $rawerrors = array_merge(
                    (array)($result['errors'] ?? []),
                    trim((string)($result['provider_detail'] ?? '')) !== '' ? [(string)$result['provider_detail']] : []
                );
                if (!empty($rawerrors) && is_array($rawerrors)) {
                    $rawerror = trim(implode(' ', $rawerrors));
                    if ($rawerror !== '') {
                        $msg .= ' ' . get_string('error_ai_admin_details', 'bookingextension_agent', $rawerror);
                    }
                }
            }
            return $msg;
        }

        return '';
    }
}
