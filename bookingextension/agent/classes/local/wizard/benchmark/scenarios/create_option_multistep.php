<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Scenario create_option_multistep.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario: 4-step multi-step request (create x2, trainer, book).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_multistep extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'create_option_multistep';
    }
    /**
     * Get the scenario class.
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
        return '4-step: create two options, set trainer, book user';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Erstelle zwei Veranstaltungen, TestA am Dienstag und TestB am Mittwoch, '
            . 'jeweils 10-12 Uhr, 9 Plaetze. Mach dann User1 zum Trainer von TestA und buche ihn fuer TestB.';
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
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'mod_booking.create_option';
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
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.create_option","input":{}}],'
            . '"planned_steps":[{"intent":"Create TestB for Wednesday"},'
            . '{"intent":"Set trainer for TestA"},{"intent":"Book user for TestB"}],'
            . '"next_step_intent":"Create TestA first","lang":"de","user_lang":"de"}';
    }

    /**

     * Perform additional assertions.

     *

     * @param array $result

     * @return array

     */
    public function assert_additional(array $result): array {
        $steps = $result['planned_steps'] ?? null;
        return [
            [
                'label'  => 'planned_steps has >=3 future steps',
                'passed' => is_array($steps) && count($steps) >= 3,
                'detail' => 'planned_steps count: ' . (is_array($steps) ? count($steps) : 'missing'),
            ],
        ];
    }
}
