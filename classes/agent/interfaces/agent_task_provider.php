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
 * Agent task provider interface.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\agent\interfaces;

/**
 * Interface that every domain task provider must implement.
 *
 * A task provider registers the set of structured tasks it supports
 * (e.g. booking.create_option, booking.update_option) and exposes
 * the JSON schema for each one so the orchestrator can embed them
 * in the system prompt.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_task_provider {

    /**
     * Return an array of task names this provider handles.
     *
     * Names must be namespaced, e.g. 'booking.create_option'.
     *
     * @return string[]
     */
    public function get_task_names(): array;

    /**
     * Return the JSON schema (as a PHP array) for the given task name.
     *
     * @param string $taskname Namespaced task name.
     * @return array Associative array describing the task's input schema.
     */
    public function get_task_schema(string $taskname): array;

    /**
     * Validate task input against the schema and domain rules.
     *
     * @param string $taskname  Namespaced task name.
     * @param array  $input     Task input payload.
     * @param int    $cmid      Course-module id for context scoping.
     * @return array ['valid' => bool, 'errors' => string[], 'ambiguities' => string[]]
     */
    public function validate(string $taskname, array $input, int $cmid): array;

    /**
     * Execute a single validated command.
     *
     * Must only be called by the executor after all validations pass.
     *
     * @param string $taskname  Namespaced task name.
     * @param array  $input     Validated, normalised task input.
     * @param int    $cmid      Course-module id for context scoping.
     * @param int    $userid    User id performing the action.
     * @return array ['status' => 'executed'|'skipped'|'error', 'detail' => string, 'resultid' => int|null]
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid): array;
}
