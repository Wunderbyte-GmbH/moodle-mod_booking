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
 * Preflight validation result value object.
 *
 * Returned by task_interface::preflight().  Carries:
 *   - is_valid          — whether execution may proceed
 *   - prepared_input    — input with IDs resolved, values normalised; ready for execute()
 *   - issues            — structured issue descriptors (same shape as the legacy issues array)
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

/**
 * Immutable result of task preflight validation.
 *
 * Rules:
 *  - is_valid === true  → prepared_input is safe to pass directly to execute().
 *  - is_valid === false → execution must NOT proceed; issues explain why.
 *  - issues with severity === 'needs_confirmation' → may trigger a confirmation_request flow.
 *  - issues with severity === 'needs_clarification' → must be resolved before confirmation.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_preflight_result {
    /** @var bool Whether execution may proceed. */
    public readonly bool $is_valid;

    /**
     * Input with IDs resolved and values normalised.
     * This is what execute() receives — no further resolution is needed.
     *
     * @var array<string,mixed>
     */
    public readonly array $prepared_input;

    /**
     * Structured issue descriptors.
     *
     * Each entry is an array with at least:
     *   'code'     => string  (machine-readable, e.g. 'OPTION_NOT_FOUND')
     *   'severity' => string  ('needs_clarification' | 'needs_confirmation')
     *   'message'  => string  (human-readable, may be empty)
     *
     * Optional fields: 'user_question', 'remedy_options'.
     *
     * @var array<int,array<string,mixed>>
     */
    public readonly array $issues;

    /**
     * Private constructor — use static factories.
     *
     * @param bool  $isvalid
     * @param array $preparedinput
     * @param array $issues
     */
    private function __construct(bool $isvalid, array $preparedinput, array $issues) {
        $this->is_valid       = $isvalid;
        $this->prepared_input = $preparedinput;
        $this->issues         = $issues;
    }

    /**
     * Successful preflight with no issues: execution may proceed immediately.
     *
     * @param  array $preparedinput  Resolved, normalised input ready for execute().
     * @return self
     */
    public static function ok(array $preparedinput): self {
        return new self(true, $preparedinput, []);
    }

    /**
     * Preflight passed but with confirmable soft issues.
     *
     * Execution is allowed only after the user has confirmed via the
     * confirmation_request flow.  The pending intent MUST store prepared_input
     * so that execute() never has to re-resolve anything.
     *
     * @param  array $preparedinput  Resolved, normalised input ready for execute().
     * @param  array $issues         One or more issues with severity 'needs_confirmation'.
     * @return self
     */
    public static function confirmable(array $preparedinput, array $issues): self {
        return new self(true, $preparedinput, $issues);
    }

    /**
     * Preflight failed: clarification is required before any further step.
     *
     * @param  array $issues  One or more issues with severity 'needs_clarification'.
     * @return self
     */
    public static function invalid(array $issues): self {
        return new self(false, [], $issues);
    }

    /**
     * Convenience: return all issues with a specific severity.
     *
     * @param  string $severity  'needs_clarification' | 'needs_confirmation'
     * @return array<int,array<string,mixed>>
     */
    public function get_issues_by_severity(string $severity): array {
        return array_values(array_filter(
            $this->issues,
            static fn(array $issue): bool => ($issue['severity'] ?? '') === $severity
        ));
    }

    /**
     * Convenience: return all issue codes as a flat array.
     *
     * @return array<int,string>
     */
    public function get_issue_codes(): array {
        return array_values(array_filter(array_map(
            static fn(array $issue): string => trim((string)($issue['code'] ?? '')),
            $this->issues
        )));
    }

    /**
     * Convenience: whether there are any confirmable (soft) issues.
     *
     * @return bool
     */
    public function has_confirmable_issues(): bool {
        return !empty($this->get_issues_by_severity('needs_confirmation'));
    }
}
