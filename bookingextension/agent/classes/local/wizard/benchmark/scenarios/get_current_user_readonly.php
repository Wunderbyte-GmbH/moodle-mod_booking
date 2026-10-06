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
 * Scenario get_current_user_readonly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario get_current_user_readonly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_readonly extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'get_current_user_readonly';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'readonly';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'R0 read-only: get_current_user selected, no confirmation_request';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Wer bin ich? Zeig mir mein Profil.';
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
        return 'core.get_current_user';
    }

    /**

     * Get the stub selector response.

     *

     * @return string

     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"core.get_current_user","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Show user profile",'
            . '"lang":"de","user_lang":"de"}';
    }
    /**
     * Perform additional assertions.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'R0 skill: response_type is skill_call (not confirmation_request)',
                'passed' => ($result['response_type'] ?? '') === 'skill_call',
                'detail' => 'response_type: ' . ($result['response_type'] ?? ''),
            ],
        ];
    }
}
