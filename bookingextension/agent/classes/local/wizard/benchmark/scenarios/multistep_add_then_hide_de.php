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

namespace bookingextension_agent\local\wizard\benchmark\scenarios;

use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Multi-step, booking-agnostic scenario: add a course page and then hide it. Turn 1 must route to
 * course.add_activity AND carry the "hide it" as a planned second step (not collapse to one action,
 * not drop the second intent). Uses only agent-native course skills, so it survives the standalone
 * extraction (no mod_booking dependency).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multistep_add_then_hide_de extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'multistep_add_then_hide_de';
    }

    /**
     * Get the scenario class (grouping label).
     *
     * @return string
     */
    public function get_class(): string {
        return 'multistep';
    }

    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Two-step course edit (agent-native): add a page, then hide it -> route add_activity + plan the hide';
    }

    /**
     * Get the query language.
     *
     * @return string
     */
    public function get_language(): string {
        return 'de';
    }

    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Fuege dem Kurs "Biologie 101" eine Seite "Kursinfo" hinzu und blende sie danach aus.';
    }

    /**
     * Get the expected response type.
     *
     * @return string
     */
    public function get_expected_response_type(): string {
        return 'skill_call';
    }

    /**
     * A mutating first step may route as a direct skill_call or a confirmation_request; both are correct.
     *
     * @return string[]
     */
    public function get_acceptable_response_types(): array {
        return ['skill_call', 'confirmation_request'];
    }

    /**
     * Get the expected skill (the first step).
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'course.add_activity';
    }

    /**
     * Whether planned steps are expected.
     *
     * @return bool
     */
    public function expects_planned_steps(): bool {
        return true;
    }

    /**
     * Get the stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"course.add_activity","input":{}}],'
            . '"planned_steps":[{"intent":"Hide the Kursinfo page"}],'
            . '"next_step_intent":"Add the Kursinfo page first","lang":"de","user_lang":"de"}';
    }

    /**
     * Perform additional assertions: the hide must be carried as a planned step.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $steps = $result['planned_steps'] ?? null;
        return [
            [
                'label'  => 'planned_steps carries the second (hide) step',
                'passed' => is_array($steps) && count($steps) >= 1,
                'detail' => 'planned_steps count: ' . (is_array($steps) ? count($steps) : 'missing'),
            ],
        ];
    }
}
