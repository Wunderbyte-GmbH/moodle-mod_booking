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
 * Central classifier for preflight/execution error classes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_error_classifier {
    /** Retryable execution error classes. */
    private const RETRYABLE_ERROR_CLASSES = ['provider_timeout', 'transient_io'];

    /**
     * Infer error class from structured issue codes.
     *
     * @param string[] $issuecodes
     * @return string
     */
    public static function infer_from_issue_codes(array $issuecodes): string {
        // Canonical rules live in issue_code_taxonomy (audit C3-F02); this stays as the
        // existing entry point for the preflight/confirm callers.
        return issue_code_taxonomy::error_class_for($issuecodes);
    }

    /**
     * Check whether an error class is retryable via execution gate.
     *
     * @param string $errorclass
     * @return bool
     */
    public static function is_retryable_error_class(string $errorclass): bool {
        return in_array(trim(strtolower($errorclass)), self::RETRYABLE_ERROR_CLASSES, true);
    }
}
