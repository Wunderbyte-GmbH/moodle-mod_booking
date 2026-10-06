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
 * Attempt budget DTO shared across runtime, preflight and execution layers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Immutable value object for attempt budgets.
 */
class attempt_budget_dto {
    /** @var int */
    private int $totalattempts;

    /** @var int */
    private int $loopattempts;

    /** @var int */
    private int $preflightretries;

    /** @var int */
    private int $executionretries;

    /** @var int */
    private int $queueretries;

    /** @var int */
    private int $hardlimit;

    /** @var string */
    private string $exhaustedreason;

    /**
     * Constructor.
     *
     * @param int $totalattempts
     * @param int $loopattempts
     * @param int $preflightretries
     * @param int $executionretries
     * @param int $queueretries
     * @param int $hardlimit
     * @param string $exhaustedreason
     */
    public function __construct(
        int $totalattempts,
        int $loopattempts,
        int $preflightretries,
        int $executionretries,
        int $queueretries,
        int $hardlimit,
        string $exhaustedreason = ''
    ) {
        $this->totalattempts = max(0, $totalattempts);
        $this->loopattempts = max(0, $loopattempts);
        $this->preflightretries = max(0, $preflightretries);
        $this->executionretries = max(0, $executionretries);
        $this->queueretries = max(0, $queueretries);
        $this->hardlimit = max(1, $hardlimit);
        $this->exhaustedreason = trim($exhaustedreason);
    }

    /**
     * Build a budget DTO from loop context only.
     *
     * @param int $loopstep
     * @param int $hardlimit
     * @param string $exhaustedreason
     * @return self
     */
    public static function from_loop(int $loopstep, int $hardlimit, string $exhaustedreason = ''): self {
        return new self(
            max(0, $loopstep),
            max(0, $loopstep),
            0,
            0,
            0,
            $hardlimit,
            $exhaustedreason
        );
    }

    /**
     * Build a budget DTO from queue item retry metadata.
     *
     * @param array $queueitem
     * @param int $hardlimit
     * @param string $exhaustedreason
     * @return self
     */
    public static function from_queue_item(array $queueitem, int $hardlimit = 1, string $exhaustedreason = ''): self {
        $preflight = max(0, (int)($queueitem['preflight_retry_count'] ?? 0));
        $queue = max(0, (int)($queueitem['retry_count'] ?? 0));

        return new self(
            $preflight + $queue,
            0,
            $preflight,
            $queue,
            $queue,
            max(1, $hardlimit),
            $exhaustedreason
        );
    }

    /**
     * Export to a stable array payload.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'scope' => 'global_view',
            'total_attempts' => $this->totalattempts,
            'loop_attempts' => $this->loopattempts,
            'preflight_retries' => $this->preflightretries,
            'execution_retries' => $this->executionretries,
            'queue_retries' => $this->queueretries,
            'hard_limit' => $this->hardlimit,
            'exhausted_reason' => $this->exhaustedreason,
            'remaining_llm_calls' => max(0, $this->hardlimit - $this->loopattempts),
        ];
    }
}
