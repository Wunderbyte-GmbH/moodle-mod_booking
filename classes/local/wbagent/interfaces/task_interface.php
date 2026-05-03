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

namespace mod_booking\local\wbagent\interfaces;

use mod_booking\local\wbagent\task_preflight_result;

/**
 * Structured AI task interface.
 *
 * A task encapsulates its schema, validation, execution, and read-only flag.
 *
 * Validation is split into two explicit phases:
 *
 *  1. check_structure() — pure, no DB access; used by interpreter only.
 *  2. preflight()       — DB lookups, entity resolution, conflict detection;
 *                         used by agent_decision_service during routing.
 *
 * execute() receives the prepared_input from task_preflight_result and must
 * NOT repeat heavy resolution logic already done in preflight().
 *
 * The legacy validate() method is kept for backward-compatibility but
 * SHOULD NOT be overridden in new tasks.  It is called only by the
 * executor's stale-state guard and by legacy callers.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface task_interface {
    /**
     * Return the fully-qualified task name, e.g. booking.create_option.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Return the task schema for prompt embedding.
     *
     * @return array
     */
    public function get_schema(): array;

    /**
     * Structural (pure) validation — no DB access, no side-effects.
     *
     * Called by the interpreter immediately after JSON parsing to verify that
     * the required top-level fields are present and have the expected types.
     * MUST NOT perform DB lookups or any I/O.
     *
     * @param  array $input  Raw command input from the LLM.
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array;

    /**
     * Deep preflight validation — DB lookups, entity resolution, conflict detection.
     *
     * Called by agent_decision_service after structural validation passes.
     * MUST NOT perform writes.  Returns a task_preflight_result whose
     * prepared_input carries resolved IDs and normalised values ready for execute().
     *
     * @param  array $input   Input that has already passed check_structure().
     * @param  int   $cmid    Course-module ID.
     * @param  int   $userid  Executing user ID.
     * @return task_preflight_result
     */
    public function preflight(array $input, int $cmid, int $userid): task_preflight_result;

    /**
     * Execute the task.
     *
     * Receives prepared_input from the stored pending intent (i.e. the
     * prepared_input produced by preflight()).  MUST NOT repeat heavy
     * resolution logic already done in preflight().
     *
     * @param  array $preparedinput  Resolved, normalised input from preflight().
     * @param  int   $cmid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array;

    /**
     * Legacy combined validation (deprecated).
     *
     * Kept for backward-compatibility with the executor's stale-state guard
     * and any callers that have not yet migrated to preflight().
     * New tasks SHOULD implement check_structure() + preflight() instead.
     *
     * @param  array $input
     * @param  int   $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,
     *     issues?:array<int,array<string,mixed>>}
     * @deprecated since 2026 — implement check_structure() + preflight() instead.
     */
    public function validate(array $input, int $cmid): array;

    /**
     * Whether the task is read-only and can be auto-executed.
     *
     * @return bool
     */
    public function is_read_only(): bool;
}
