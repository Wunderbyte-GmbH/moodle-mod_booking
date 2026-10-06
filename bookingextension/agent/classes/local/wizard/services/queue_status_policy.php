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
 * Queue status policy.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Central policy for queue status semantics.
 */
class queue_status_policy {
    /** Canonical status: queued for later processing. */
    private const STATUS_QUEUED = 'queued';

    /** Canonical status: execution can start immediately. */
    private const STATUS_READY = 'ready';

    /** Canonical status: execution finished successfully. */
    private const STATUS_SUCCEEDED = 'succeeded';

    /** Canonical status: execution ended with failure. */
    private const STATUS_FAILED = 'failed';

    /** Canonical status: execution intentionally skipped. */
    private const STATUS_SKIPPED = 'skipped';

    /** Canonical status: blocked confirmation pending user input. */
    private const STATUS_BLOCKED_CONFIRMATION = 'blocked_confirmation';

    /** Canonical status: retry is scheduled but not yet due. */
    private const STATUS_RETRY_WAITING = 'retry_waiting';

    /** Canonical status: placeholder for a future multi-step skill (intent only, no skill/params yet). */
    public const STATUS_PLANNED = 'planned';

    /**
     * Canonical status: a placeholder whose real command is in flight (staged, awaiting
     * confirmation or executing). Placeholder-only. Not planned (invisible to the pending-step
     * lists), not terminal: it settles to succeeded/failed with the real item, or reverts to
     * planned when the real item is blocked by a preflight clarification (F5, threads 544/589 —
     * a placeholder must never claim success before its step actually executed).
     */
    public const STATUS_REALIZING = 'realizing';

    /** Actionable mutating statuses. */
    private const ACTIONABLE_MUTATING_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_BLOCKED_CONFIRMATION,
        self::STATUS_READY,
        self::STATUS_RETRY_WAITING,
    ];

    /** Statuses that may be picked up for execution when time/dependencies allow. */
    private const PICKUP_READY_STATUSES = [self::STATUS_READY, self::STATUS_RETRY_WAITING];

    /** Terminal statuses after which an item is considered finalized. */
    private const TERMINAL_STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_SKIPPED];

    /** Statuses that satisfy dependency completion checks. */
    private const DEPENDENCY_SATISFIED_STATUSES = [self::STATUS_SUCCEEDED];

    /**
     * Canonical ready status value.
     *
     * @return string
     */
    public static function ready_status(): string {
        return self::STATUS_READY;
    }

    /**
     * Canonical failed status value.
     *
     * @return string
     */
    public static function failed_status(): string {
        return self::STATUS_FAILED;
    }

    /**
     * Canonical succeeded status value.
     *
     * @return string
     */
    public static function succeeded_status(): string {
        return self::STATUS_SUCCEEDED;
    }

    /**
     * Canonical skipped status value.
     *
     * @return string
     */
    public static function skipped_status(): string {
        return self::STATUS_SKIPPED;
    }

    /**
     * Return actionable mutating statuses.
     *
     * @return string[]
     */
    public static function actionable_mutating_statuses(): array {
        return self::ACTIONABLE_MUTATING_STATUSES;
    }

    /**
     * Return execution-pickup eligible statuses.
     *
     * @return string[]
     */
    public static function pickup_ready_statuses(): array {
        return self::PICKUP_READY_STATUSES;
    }

    /**
     * Check whether mutating queue item status is still actionable.
     *
     * @param string $status
     * @return bool
     */
    public static function is_actionable_mutating_status(string $status): bool {
        return in_array(trim($status), self::ACTIONABLE_MUTATING_STATUSES, true);
    }

    /**
     * Check whether a queue item status can be picked up for execution.
     *
     * @param string $status
     * @return bool
     */
    public static function is_pickup_ready_status(string $status): bool {
        return in_array(trim($status), self::PICKUP_READY_STATUSES, true);
    }

    /**
     * Check whether a queue item status is terminal/finalized.
     *
     * @param string $status
     * @return bool
     */
    public static function is_terminal_status(string $status): bool {
        return in_array(trim($status), self::TERMINAL_STATUSES, true);
    }

    /**
     * Check whether a queue item status is considered successful.
     *
     * @param string $status
     * @return bool
     */
    public static function is_succeeded_status(string $status): bool {
        return trim($status) === self::STATUS_SUCCEEDED;
    }

    /**
     * Check whether a queue item status is failed.
     *
     * @param string $status
     * @return bool
     */
    public static function is_failed_status(string $status): bool {
        return trim($status) === self::STATUS_FAILED;
    }

    /**
     * Check whether a queue item status is ready.
     *
     * @param string $status
     * @return bool
     */
    public static function is_ready_status(string $status): bool {
        return trim($status) === self::STATUS_READY;
    }

    /**
     * Check whether a dependency status satisfies downstream execution.
     *
     * @param string $status
     * @return bool
     */
    public static function is_dependency_satisfied_status(string $status): bool {
        return in_array(trim($status), self::DEPENDENCY_SATISFIED_STATUSES, true);
    }

    /**
     * Check whether status is blocked_confirmation.
     *
     * @param string $status
     * @return bool
     */
    public static function is_blocked_confirmation_status(string $status): bool {
        return trim($status) === self::STATUS_BLOCKED_CONFIRMATION;
    }

    /**
     * Check whether status is retry_waiting.
     *
     * @param string $status
     * @return bool
     */
    public static function is_retry_waiting_status(string $status): bool {
        return trim($status) === self::STATUS_RETRY_WAITING;
    }

    /**
     * Canonical planned status value.
     *
     * @return string
     */
    public static function planned_status(): string {
        return self::STATUS_PLANNED;
    }

    /**
     * Check whether a queue item is a planned placeholder.
     *
     * @param string $status
     * @return bool
     */
    public static function is_planned_status(string $status): bool {
        return trim($status) === self::STATUS_PLANNED;
    }

    /**
     * Canonical realizing status value (placeholder whose real command is in flight).
     *
     * @return string
     */
    public static function realizing_status(): string {
        return self::STATUS_REALIZING;
    }

    /**
     * Check whether a placeholder is being realized by an in-flight real command.
     *
     * @param string $status
     * @return bool
     */
    public static function is_realizing_status(string $status): bool {
        return trim($status) === self::STATUS_REALIZING;
    }
}
