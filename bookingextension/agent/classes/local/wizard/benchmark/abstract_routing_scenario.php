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
 * Base for a Tier-2 routing scenario inside a confusable cluster.
 *
 * The whole point of Tier 2 (per docs/Blueprints/BENCHMARK_REDESIGN.md) is disambiguation between
 * near-duplicate sibling skills — recall is ~100%, the loss is in selection. A routing scenario
 * therefore PINS the expected skill and additionally asserts the selector did NOT fall back to a
 * named confusable sibling, so a failure reads as "picked sibling X" rather than a generic miss.
 *
 * A subclass only has to provide get_key(), get_user_message(), get_expected_skill() and (for the
 * cluster guard) get_forbidden_siblings(); language defaults to 'de' and is overridden for the
 * cross-language variants that measure the Weg-B bridge directly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_routing_scenario extends abstract_benchmark_scenario {
    /**
     * The confusable sibling skills the selector must NOT route to for this query.
     *
     * @return string[] fully-qualified skill keys (e.g. mod_booking.create_option)
     */
    public function get_forbidden_siblings(): array {
        return [];
    }

    /**
     * Whether the routed skill is a mutating action (create/update/add/book/remember/forget/…).
     *
     * Derived from the skill name so cluster scenarios need no per-file flag. Mutating intents may be
     * routed either as a direct skill_call or as a confirmation_request (the planner asks before it
     * executes) — both are CORRECT routing, so both count (see get_acceptable_response_types()).
     *
     * @return bool
     */
    public function is_mutating(): bool {
        $skill = $this->get_expected_skill();
        foreach (
            ['create_', 'update_', 'add_', 'book_users', '.remember', '.forget',
            'set_', 'configure_', 'bulk_', 'delete_', 'remove_'] as $needle
        ) {
            if (strpos($skill, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Routing is judged on the selected SKILL, not on whether the planner decided to confirm first.
     * Per the product decision (BENCHMARK_REDESIGN.md §8.2 / TEST_SUITE_AUDIT §6.2): for a mutating
     * skill, accept skill_call OR confirmation_request — the pinned skill (carried in commands[0].skill
     * in both cases) still has to match, so a wrong sibling still fails.
     *
     * @return string[]
     */
    public function get_acceptable_response_types(): array {
        return $this->is_mutating()
            ? ['skill_call', 'confirmation_request']
            : ['skill_call'];
    }

    /**
     * Get the scenario class (grouping label).
     *
     * @return string
     */
    public function get_class(): string {
        return 'routing';
    }

    /**
     * Routing is judged on the selected skill; the canonical successful response_type is skill_call.
     *
     * @return string
     */
    public function get_expected_response_type(): string {
        return 'skill_call';
    }

    /**
     * Canonical, contract-valid stub for harness-determinism checks (no LLM).
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        $lang = $this->get_language();
        return json_encode([
            'response_type' => 'skill_call',
            'commands' => [['skill' => $this->get_expected_skill(), 'input' => new \stdClass()]],
            'planned_steps' => [],
            'next_step_intent' => '',
            'lang' => $lang,
            'user_lang' => $lang,
        ]);
    }

    /**
     * Assert the selector did not route to a confusable sibling.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $forbidden = $this->get_forbidden_siblings();
        $rt = (string)($result['response_type'] ?? '');
        // The selected skill is carried in commands[0].skill for both skill_call and confirmation_request.
        if (empty($forbidden) || !in_array($rt, ['skill_call', 'confirmation_request'], true)) {
            return [];
        }
        $skill = (string)($result['commands'][0]['skill'] ?? '');
        return [
            [
                'label'  => 'must not route to a confusable sibling skill',
                'passed' => !in_array($skill, $forbidden, true),
                'detail' => 'selected: ' . $skill . ' | forbidden: ' . implode(',', $forbidden),
            ],
        ];
    }
}
