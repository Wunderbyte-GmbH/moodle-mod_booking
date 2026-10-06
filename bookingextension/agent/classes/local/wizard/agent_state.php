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
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

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
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_state {
    /** @var int Current step index (1-based, set by the loop before each internal step). */
    public int $currentstep = 0;

    /** @var int Maximum number of steps for this invocation. */
    public readonly int $maxsteps;

    /**
     * Ordered list of completed step records.
     *
     * Each entry: ['step' => int, 'tool_calls' => array, 'results' => array, 'observation' => string]
     *
     * @var array[]
     */
    private array $steps = [];
    /** @var array<string,bool> Fingerprints of commands handed to execution in this turn (F77). */
    private array $sentfingerprints = [];

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
     * Per-run discovery family/cache payloads keyed by fingerprint.
     *
     * @var array
     */
    private array $familycache = [];

    /**
     * Private constructor — use agent_state::make().
     *
     * @param int $maxsteps
     */
    private function __construct(int $maxsteps) {
        $this->maxsteps = $maxsteps;
    }

    /**
     * Create a fresh agent state for a new loop invocation.
     *
     * @param  int  $maxsteps  Maximum loop steps (enforced by run_loop()).
     * @return self
     */
    public static function make(int $maxsteps): self {
        return new self(max(1, $maxsteps));
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
            'step'       => $this->currentstep,
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
     * Append a framework-authored observation without recording a tool step.
     *
     * @param string $observation
     * @return void
     */
    public function append_observation(string $observation): void {
        $trimmed = trim($observation);
        if ($trimmed === '') {
            return;
        }

        $this->observations[] = $trimmed;
    }

    /**
     * Return all recorded step records (for debugging / testing).
     *
     * @return array[]
     */
    public function get_steps(): array {
        return $this->steps;
    }

    /**
     * Remember the fingerprints of commands this turn handed to execution (F77).
     *
     * Executed commands come back with prepared input (engine-added keys such as outputlang), so the
     * planner's own command is remembered as it was sent - that is what a repeated plan is compared with.
     *
     * @param string[] $fingerprints
     * @return void
     */
    public function remember_sent_commands(array $fingerprints): void {
        foreach ($fingerprints as $fingerprint) {
            $fingerprint = trim((string)$fingerprint);
            if ($fingerprint !== '') {
                $this->sentfingerprints[$fingerprint] = true;
            }
        }
    }

    /**
     * Whether a command with this fingerprint was already handed to execution in this turn.
     *
     * @param string $fingerprint
     * @return bool
     */
    public function was_sent(string $fingerprint): bool {
        return isset($this->sentfingerprints[trim($fingerprint)]);
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

    /**
     * Return cached discovery family payload for the given key.
     *
     * @param string $cachekey
     * @return array|null
     */
    public function get_discovery_family_cache(string $cachekey): ?array {
        return $this->get_cache_entry($this->familycache, $cachekey);
    }

    /**
     * Store discovery family payload for later loop steps.
     *
     * @param string $cachekey
     * @param array $payload
     * @return void
     */
    public function set_discovery_family_cache(string $cachekey, array $payload): void {
        $this->set_cache_entry($this->familycache, $cachekey, $payload);
    }

    /**
     * Read one cache entry from a phase-local cache.
     *
     * @param array $cache
     * @param string $cachekey
     * @return array|null
     */
    private function get_cache_entry(array $cache, string $cachekey): ?array {
        $key = trim($cachekey);
        if ($key === '' || !isset($cache[$key])) {
            return null;
        }

        $cached = $cache[$key];
        return is_array($cached) ? $cached : null;
    }

    /**
     * Write one cache entry into a phase-local cache.
     *
     * @param array $cache
     * @param string $cachekey
     * @param array $payload
     * @return void
     */
    private function set_cache_entry(array &$cache, string $cachekey, array $payload): void {
        $key = trim($cachekey);
        if ($key === '') {
            return;
        }

        $cache[$key] = $payload;
    }
}
