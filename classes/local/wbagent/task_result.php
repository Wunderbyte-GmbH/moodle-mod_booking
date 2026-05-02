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
 * Structured task result value object.
 *
 * Tasks MUST return structured results.  This value object enforces the
 * contract: a success flag, domain data on success, or a structured error
 * (code + message + metadata) on failure.
 *
 * Backward-compatible conversion to the legacy array format used by the
 * executor is provided via {@see task_result::to_legacy_array()}.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

/**
 * Immutable value object returned by all agent tasks.
 *
 * Tasks MUST NOT:
 * - Call LLMs.
 * - Retry internally.
 * - Ask the user directly.
 * - Instantiate infrastructure (DB anonymizer, etc.) in validate().
 *
 * All decision-making after a task returns is handled by AgentRuntime.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_result {
    /** @var bool */
    private bool $success;

    /** @var array */
    private array $data;

    /** @var array|null  ['code' => string, 'message' => string, 'metadata' => array] */
    private ?array $error;

    /**
     * Private constructor – use static factories instead.
     *
     * @param bool       $success
     * @param array      $data
     * @param array|null $error
     */
    private function __construct(bool $success, array $data, ?array $error) {
        $this->success = $success;
        $this->data    = $data;
        $this->error   = $error;
    }

    /**
     * Create a successful result with domain data.
     *
     * @param  array $data
     * @return self
     */
    public static function ok(array $data = []): self {
        return new self(true, $data, null);
    }

    /**
     * Create a failure result with a structured error descriptor.
     *
     * @param  string $code     Machine-readable error code (e.g. 'OPTION_NOT_FOUND').
     * @param  string $message  Human-readable error message.
     * @param  array  $metadata Optional extra context (e.g. ['optionquery' => 'Yoga']).
     * @return self
     */
    public static function failure(string $code, string $message, array $metadata = []): self {
        return new self(false, [], ['code' => $code, 'message' => $message, 'metadata' => $metadata]);
    }

    /**
     * Whether the task succeeded.
     *
     * @return bool
     */
    public function is_success(): bool {
        return $this->success;
    }

    /**
     * Return the domain data on success.
     *
     * @return array
     */
    public function get_data(): array {
        return $this->data;
    }

    /**
     * Return the full error descriptor, or null on success.
     *
     * @return array|null
     */
    public function get_error(): ?array {
        return $this->error;
    }

    /**
     * Convenience: return the error code string, or '' on success.
     *
     * @return string
     */
    public function get_error_code(): string {
        return (string)($this->error['code'] ?? '');
    }

    /**
     * Convenience: return the error message string, or '' on success.
     *
     * @return string
     */
    public function get_error_message(): string {
        return (string)($this->error['message'] ?? '');
    }

    /**
     * Convert to the legacy task-result array format expected by the executor.
     *
     * Merges $this->data so that task-specific fields like 'resultid', 'options',
     * 'users', etc. are preserved.
     *
     * @return array
     */
    public function to_legacy_array(): array {
        if ($this->success) {
            return array_merge(['status' => 'executed', 'resultid' => null], $this->data);
        }

        return [
            'status'   => 'error',
            'detail'   => $this->get_error_message(),
            'resultid' => null,
        ];
    }
}
