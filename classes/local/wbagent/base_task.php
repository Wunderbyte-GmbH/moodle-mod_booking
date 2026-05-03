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

namespace mod_booking\local\wbagent;

use mod_booking\local\wbagent\interfaces\task_interface;

/**
 * Base class for AI tasks.
 *
 * Provides default (pass-through) implementations of the two new preflight
 * phases so that tasks that have not yet been migrated continue to work via
 * the legacy validate() path.
 *
 * Migration path for subclasses:
 *  1. Override check_structure() for pure structural checks.
 *  2. Override preflight()       for DB-dependent deep validation.
 *  3. Override execute()         to use $preparedinput from preflight().
 *  4. Remove (or keep as a no-op) the old validate() override.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_task implements task_interface {
    /** @var bool */
    protected bool $readonly;

    /**
     * Constructor.
     *
     * @param bool $readonly
     */
    public function __construct(bool $readonly = false) {
        $this->readonly = $readonly;
    }

    /**
     * Return whether the task is read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return $this->readonly;
    }

    /**
     * Default structural validation — always passes.
     *
     * Override in concrete tasks to check required fields without DB access.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Default preflight — delegates to the legacy validate() so unmigrated
     * tasks continue to work transparently.
     *
     * Once a task overrides both check_structure() and preflight() the legacy
     * validate() becomes a no-op and may be removed.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid  (unused by default; available for overrides)
     * @return task_preflight_result
     */
    public function preflight(array $input, int $cmid, int $userid): task_preflight_result {
        $validation = $this->validate($input, $cmid);
        if (!($validation['valid'] ?? true)) {
            $issues = [];
            foreach ((array)($validation['errors'] ?? []) as $error) {
                $issues[] = [
                    'code'     => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message'  => (string)$error,
                ];
            }
            foreach ((array)($validation['ambiguities'] ?? []) as $ambiguity) {
                $issues[] = [
                    'code'     => 'AMBIGUITY',
                    'severity' => 'needs_clarification',
                    'message'  => (string)$ambiguity,
                ];
            }
            // Merge legacy structured issues when present.
            foreach ((array)($validation['issues'] ?? []) as $issue) {
                if (is_array($issue)) {
                    $issues[] = $issue;
                }
            }
            return task_preflight_result::invalid($issues);
        }

        // Legacy validation passed — return prepared_input == input (no enrichment).
        $legacyissues = [];
        foreach ((array)($validation['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $legacyissues[] = $issue;
            }
        }

        $confirmable = array_filter(
            $legacyissues,
            static fn(array $i): bool => ($i['severity'] ?? '') === 'needs_confirmation'
        );
        if (!empty($confirmable)) {
            return task_preflight_result::confirmable($input, array_values($legacyissues));
        }

        return task_preflight_result::ok($input);
    }
}
