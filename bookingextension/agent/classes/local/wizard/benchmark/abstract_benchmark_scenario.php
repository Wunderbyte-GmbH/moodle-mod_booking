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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\benchmark;

/**
 * Base scenario with sensible defaults.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_benchmark_scenario implements benchmark_scenario_interface {
    /**
     * Get prior messages for the scenario.
     *
     * @return array
     */
    public function get_prior_messages(): array {
        return [];
    }

    /**
     * Whether the scenario expects planned steps.
     *
     * @return bool
     */
    public function expects_planned_steps(): bool {
        return false;
    }

    /**
     * Whether the scenario requires a live LLM execution.
     *
     * @return bool
     */
    public function requires_live_llm(): bool {
        return false;
    }

    /**
     * Get stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '';
    }

    /**
     * Perform additional assertions on results.
     *
     * @param array $result Results to assert.
     * @return array Validation errors/issues if any.
     */
    public function assert_additional(array $result): array {
        return [];
    }

    /**
     * Benchmark tier (BENCHMARK_REDESIGN.md):
     *  - 'deterministic' : contract/decision behaviour verified WITHOUT the live LLM (stub selector +
     *                      seeded state). Must pass 100%; runs in CI.
     *  - 'probabilistic' : model-dependent routing/selection quality. Scored over N live runs by the
     *                      stable-fail set.
     *
     * @return string
     */
    public function get_tier(): string {
        return 'probabilistic';
    }

    /**
     * Query language of this scenario (for cross-language coverage/reporting).
     *
     * @return string ISO 639-1 (e.g. 'de', 'en').
     */
    public function get_language(): string {
        return 'de';
    }

    /**
     * The set of response_types that count as a PASS (unambiguous-but-multiple acceptance).
     * Empty array = fall back to the single get_expected_response_type().
     *
     * @return string[]
     */
    public function get_acceptable_response_types(): array {
        $expected = $this->get_expected_response_type();
        return $expected === '' ? [] : [$expected];
    }

    /**
     * Seed REAL thread state via production setters BEFORE the turn under test runs (e.g. a queued
     * pending-confirmation, a completed action). Required when the expected behaviour is state-driven
     * and would otherwise be unreachable from prior message text alone. Default: no-op.
     *
     * @param \bookingextension_agent\local\wizard\conversation_store $store
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return void
     */
    public function setup_state(
        \bookingextension_agent\local\wizard\conversation_store $store,
        int $threadid,
        int $contextid,
        int $userid
    ): void {
        // No state to seed by default.
        unset($store, $threadid, $contextid, $userid);
    }
}
