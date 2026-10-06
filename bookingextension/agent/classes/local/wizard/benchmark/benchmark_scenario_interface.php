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
 * Contract every benchmark scenario must implement.
 *
 * A scenario is a single reproducible test case: one user_message, one set of
 * expected outcomes, and optional stub responses for offline runs.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface benchmark_scenario_interface {
    /**
     * Unique machine-readable key for this scenario.
     * Example: "create_option_basic", "multistep_trainer_booking"
     */
    public function get_key(): string;

    /**
     * Scenario class for grouping and filtering.
     * One of: readonly, mutation_r1, mutation_r2, mutation_r3, error_retry, multistep, clarification
     */
    public function get_class(): string;

    /**
     * Human-readable description shown in reports.
     */
    public function get_description(): string;

    /**
     * The user message to send as input to the selector.
     */
    public function get_user_message(): string;

    /**
     * Previous conversation messages to inject (for follow-up scenarios).
     * Each element: ['role' => 'user'|'assistant', 'content' => '...']
     *
     * @return array[]
     */
    public function get_prior_messages(): array;

    /**
     * Expected response_type from the selector.
     */
    public function get_expected_response_type(): string;

    /**
     * Expected skill name selected by the selector. Empty string = any skill acceptable.
     */
    public function get_expected_skill(): string;

    /**
     * Whether planned_steps[] is expected in the selector output.
     */
    public function expects_planned_steps(): bool;

    /**
     * Whether this scenario requires live LLM calls (false = can use stub).
     */
    public function requires_live_llm(): bool;

    /**
     * Optional stub selector response for offline runs (JSON string).
     * Return empty string to skip stub.
     */
    public function get_stub_selector_response(): string;

    /**
     * Additional assertions beyond response_type and skill.
     * Return array of ['label' => '...', 'passed' => bool, 'detail' => '...'].
     *
     * @param array $result Normalized selector result
     * @return array[]
     */
    public function assert_additional(array $result): array;
}
