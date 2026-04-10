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

/**
 * Structured AI task interface.
 *
 * A task encapsulates its schema, validation, execution, and read-only flag.
 *
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
     * @return array<string,mixed>
     */
    public function get_schema(): array;

    /**
     * Validate the task input against domain rules.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array;

    /**
     * Execute the task.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $input, int $cmid, int $userid): array;

    /**
     * Whether the task is read-only and can be auto-executed.
     *
     * @return bool
     */
    public function is_read_only(): bool;
}
