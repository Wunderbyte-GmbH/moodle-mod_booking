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
 * Centralised AI-provider error code classifier.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

use context_module;
use core_text;

/**
 * Classifies AI provider errors into structured issue codes.
 *
 * Merges two previously duplicated error-detection paths that existed in:
 *  - orchestrator::detect_token_issue_codes()  (inline string matching)
 *  - agent_runtime::infer_issue_codes_from_recent_core_ai_failure()  (DB lookup)
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_error_classifier {

    /**
     * Classify an AI provider error from response metadata.
     *
     * Returns issue codes derived from the HTTP error code and error message.
     *
     * @param  string $errormessage  Human-readable error from the provider.
     * @param  int    $errorcode     HTTP-like numeric error code (e.g. 401, 429).
     * @param  string $errorname     Short error name/type string from the provider.
     * @return string[]  Issue code strings, or empty array when no match.
     */
    public static function classify_from_response(
        string $errormessage,
        int $errorcode = 0,
        string $errorname = ''
    ): array {
        if ($errorcode === 401) {
            return ['TRIAL_TOKEN_INVALID'];
        }

        if ($errorcode === 429) {
            return ['AI_PROVIDER_QUOTA_EXCEEDED'];
        }

        $lower = core_text::strtolower($errormessage);
        $lowername = core_text::strtolower($errorname);

        if ($lowername !== '' && strpos($lowername, 'unauthorized') !== false) {
            return ['TRIAL_TOKEN_INVALID'];
        }

        if ($lowername !== '' && strpos($lowername, 'rate limit') !== false) {
            return ['AI_PROVIDER_QUOTA_EXCEEDED'];
        }

        $tokenmarkers = [
            'invalid token',
            'token is invalid',
            'token expired',
            'expired token',
            'invalid api key',
            'incorrect api key',
            'authenticationerror',
            'authentication_error',
            'unauthorized',
            '401',
        ];

        $quotamarkers = [
            'rate limit exceeded',
            'limit type: tokens',
            'current limit: 0',
            'remaining: 0',
            'insufficient_quota',
            'insufficient quota',
            'insufficient credits',
            'max budget',
            'budget exceeded',
            'credit balance is too low',
            '429',
        ];

        foreach ($tokenmarkers as $marker) {
            if (strpos($lower, $marker) !== false) {
                return ['TRIAL_TOKEN_INVALID'];
            }
        }

        foreach ($quotamarkers as $marker) {
            if (strpos($lower, $marker) !== false) {
                return ['AI_PROVIDER_QUOTA_EXCEEDED'];
            }
        }

        return [];
    }

    /**
     * Infer issue codes by inspecting recent failed ai_action_register records.
     *
     * Used as a fallback when the orchestrator returned a generic error without
     * structured issue codes — typically when a low-level network or quota failure
     * prevented the model from returning a structured JSON response.
     *
     * @param  int $userid
     * @param  int $cmid
     * @return string[]
     */
    public static function classify_from_db(int $userid, int $cmid): array {
        global $DB;

        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists('ai_action_register')) {
                return [];
            }

            $contextid = (int)context_module::instance($cmid)->id;
            $records = $DB->get_records_select(
                'ai_action_register',
                'userid = :userid AND contextid = :contextid AND actionname = :actionname
                 AND success = :success AND timecompleted >= :since',
                [
                    'userid'     => $userid,
                    'contextid'  => $contextid,
                    'actionname' => 'generate_text',
                    'success'    => 0,
                    'since'      => time() - 600,
                ],
                'timecompleted DESC, id DESC',
                'id, errorcode, errormessage',
                0,
                20
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($records)) {
            return [];
        }

        foreach ($records as $record) {
            $codes = self::classify_from_response(
                (string)($record->errormessage ?? ''),
                (int)($record->errorcode ?? 0)
            );
            if (!empty($codes)) {
                return $codes;
            }
        }

        return [];
    }
}
