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
 * Shared row/glyph/error builders for the diagnose_* skills.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\diagnostics;

use moodle_url;

/**
 * Builds the checklist rows, status glyphs and error results that were previously copied verbatim
 * across every diagnose_* skill (access, enrolment, grades, notifications, permissions).
 */
class diagnostic_result_builder {
    /**
     * Build one diagnostic checklist row.
     *
     * @param string          $status 'ok' | 'fail' | 'warn'
     * @param string          $check  Short check name.
     * @param string          $finding Human-readable finding.
     * @param moodle_url|null $url    Optional action/help link.
     * @return array{status:string,check:string,finding:string,url:?string}
     */
    public static function row(string $status, string $check, string $finding, ?moodle_url $url = null): array {
        return [
            'status' => $status,
            'check' => $check,
            'finding' => $finding,
            'url' => $url instanceof moodle_url ? $url->out(false) : null,
        ];
    }

    /**
     * Map a row status to its observation glyph.
     *
     * @param string $status
     * @return string
     */
    public static function glyph(string $status): string {
        return ['ok' => '[OK]', 'fail' => '[X]', 'warn' => '[!]'][$status] ?? '[!]';
    }

    /**
     * Build a uniform error result for a diagnose skill that could not run.
     *
     * @param string $message
     * @param string $errorclass
     * @param string $observationprefix e.g. "Access diagnosis could not run: " — skill-specific.
     * @return array
     */
    public static function error_result(string $message, string $errorclass, string $observationprefix): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'error_class' => $errorclass,
            'observation_full' => $observationprefix . $message,
        ];
    }
}
