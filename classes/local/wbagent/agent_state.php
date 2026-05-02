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
 * Internal agent loop state value object.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

/**
 * Tracks the state of a single run_loop() invocation.
 *
 * State is purely in-memory and is NEVER persisted to the database.
 * It lives only for the duration of run_loop() and is discarded once
 * the final user-visible response is returned.
 *
 * Design contract:
 * - One agent_state per run_loop() call.
 * - current_step is 1-based (set by the loop before each run_internal()).
 * - Observations are plain-text summaries of completed tool executions,
 *   passed back to the orchestrator so the LLM can reason about results.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_state {
    /** @var int Current step index (1-based, set by the loop before each internal step). */
    public int $current_step = 0;

    /** @var int Maximum number of steps for this invocation. */
    public readonly int $max_steps;

    /**
     * Ordered list of completed step records.
     *
     * Each entry: ['step' => int, 'tool_calls' => array, 'results' => array, 'observation' => string]
     *
     * @var array<int,array>
     */
    private array $steps = [];

    /**
     * Ordered list of structured observation strings, one per completed step.
     *
     * These are injected into the next LLM prompt as context so the model can
     * reason about what the tools returned.
     *
     * @var string[]
     */
    private array $observations = [];

    /**
     * Private constructor — use agent_state::make().
     *
     * @param int $max_steps
     */
    private function __construct(int $max_steps) {
        $this->max_steps = $max_steps;
    }

    /**
     * Create a fresh agent state for a new loop invocation.
     *
     * @param  int  $max_steps  Maximum loop steps (enforced by run_loop()).
     * @return self
     */
    public static function make(int $max_steps): self {
        return new self(max(1, $max_steps));
    }

    /**
     * Record a completed tool-execution step together with its observation.
     *
     * Called once per loop iteration where read-only tools were executed and
     * produced an execution_result.  The observation is the human-readable
     * summary injected into the next LLM call.
     *
     * @param  array  $toolcalls   The commands that were executed (may be empty for auto-executed readonly).
     * @param  array  $results     The sanitized result payloads returned by the executor.
     * @param  string $observation Structured observation string (e.g. "Step 1: Found 3 options: Yoga, Pilates, Swim.").
     * @return void
     */
    public function record_step(array $toolcalls, array $results, string $observation): void {
        $this->steps[] = [
            'step'       => $this->current_step,
            'tool_calls' => $toolcalls,
            'results'    => $results,
            'observation' => trim($observation),
        ];

        $trimmed = trim($observation);
        if ($trimmed !== '') {
            $this->observations[] = $trimmed;
        }
    }

    /**
     * Return accumulated observation strings (one per completed step).
     *
     * These are passed to orchestrator::process() on the next iteration so the
     * LLM can incorporate tool results into its next decision.
     *
     * @return string[]
     */
    public function get_observations(): array {
        return $this->observations;
    }

    /**
     * Return all recorded step records (for debugging / testing).
     *
     * @return array<int,array>
     */
    public function get_steps(): array {
        return $this->steps;
    }

    /**
     * Number of completed internal steps recorded so far.
     *
     * @return int
     */
    public function step_count(): int {
        return count($this->steps);
    }

    /**
     * Whether any observations have been accumulated so far.
     *
     * @return bool
     */
    public function has_observations(): bool {
        return !empty($this->observations);
    }
}
