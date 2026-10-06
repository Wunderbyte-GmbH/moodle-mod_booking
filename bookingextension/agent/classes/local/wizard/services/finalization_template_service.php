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
    /** @var array */
    private const ISSUE_CODE_MESSAGES = [
        'BUDGET_EXCEEDED' =>
            'Execution stopped because the loop budget is exhausted. Please simplify your request and try again.',
        'BLOCKED_TIMEOUT' =>
            'Confirmation timed out. Please run the request again and confirm the action once more.',
        'RETRY_EXHAUSTED' =>
            'Execution failed after multiple retries. Please try again in a moment.',
        'PERMISSION_ERROR' =>
            'This action cannot be executed with your current permissions.',
        'VALIDATION_ERROR' =>
            'Some required input is missing or invalid. Please provide the needed details and try again.',
        'CONTEXT_INVALID' =>
            'This request is not valid in the current context. Please open the target context and try again.',
        'CONTRACT_SELECTION_SKILL_MISSING' =>
            'The request could not continue because no next skill was selected. ' .
            'Please repeat the action or provide the next concrete step.',
        'TRIAL_TOKEN_INVALID' =>
            'AI provider authentication failed (trial token or API key is invalid). ' .
            'Please contact an administrator.',
        'AI_PROVIDER_QUOTA_EXCEEDED' =>
            'AI provider quota exceeded. Please try again later.',
    ];

    /** @var array */
    private const ERROR_CLASS_MESSAGES = [
        'provider_unreachable' =>
            'The AI service is temporarily unreachable. Your request was not processed — ' .
            'please try again in a moment.',
        'provider_timeout' =>
            'The AI provider timed out while processing your request. Please try again.',
        'transient_io' =>
            'A temporary connection problem occurred. Please try again.',
        'auth_failed' =>
            'The AI provider authentication failed. Please contact an administrator.',
        'quota_exceeded' =>
            'The AI provider quota was exceeded. Please try again later.',
        'runtime_disabled' =>
            'AI runtime is currently disabled for this context.',
        // The one class where the old catch-all wording is actually true.
        'provider_error' =>
            'The AI provider returned an error. Please try again later.',
        'internal_contract' =>
            'An internal planning error occurred. Please try again — your request was not executed.',
        'internal_status' =>
            'An internal error occurred while checking the AI status. Please try again or contact an administrator.',
        'skill_exception' =>
            'The requested action failed with an internal error. Please try again or rephrase your request.',
    ];

    /** @var array */
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

    /** @var array */
    private const ISSUE_CODE_LANG_KEYS = [
        'AI_PROVIDER_QUOTA_EXCEEDED' => 'error_ai_provider_quota_exceeded',
        'PERMISSION_ERROR' => 'error_ai_permission_denied',
        'TRIAL_TOKEN_INVALID' => 'error_ai_trial_token_invalid',
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
            if (isset(self::ISSUE_CODE_MESSAGES[$issuecode])) {
                $msg = self::ISSUE_CODE_MESSAGES[$issuecode];
                break;
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
                if ($msg === '' && isset(self::ERROR_CLASS_MESSAGES[$errorclass])) {
                    $msg = self::ERROR_CLASS_MESSAGES[$errorclass];
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
                        $msg .= ' (Details: ' . $rawerror . ')';
                    }
                }
            }
            return $msg;
        }

        return '';
    }
}
