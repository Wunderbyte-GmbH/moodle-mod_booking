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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use advanced_testcase;

/**
 * Tests for phase-based planner prompt-profile helpers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class orchestrator_prompt_profile_service_test extends advanced_testcase {
    /**
     * Verifies that phase-based prompt-profile keys remain stable.
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::get_planner_initial_prompt_config_key_for_phase
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::get_history_limit_for_phase
     */
    public function test_phase_profiles_use_expected_config_keys(): void {
        $service = new orchestrator_prompt_profile_service();

        $this->assertSame(
            'aiinitialprompt_selection',
            $service->get_planner_initial_prompt_config_key_for_phase('discovery')
        );
        $this->assertSame(
            'aiinitialprompt_selection',
            $service->get_planner_initial_prompt_config_key_for_phase('selection')
        );
        $this->assertSame(
            'aiinitialprompt_parameter_construction',
            $service->get_planner_initial_prompt_config_key_for_phase('parameter_construction')
        );
        $limit = orchestrator_prompt_profile_service::HISTORY_TAIL_LIMIT;
        $this->assertSame($limit, $service->get_history_limit_for_phase('discovery'));
        $this->assertSame($limit, $service->get_history_limit_for_phase('selection'));
        $this->assertSame($limit, $service->get_history_limit_for_phase('parameter_construction'));
    }

    /**
     * Short threads pass through unchanged.
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::select_history_messages
     */
    public function test_select_history_returns_all_for_short_thread(): void {
        $service = new orchestrator_prompt_profile_service();
        $messages = $this->build_messages(['user', 'assistant', 'user']);

        $selected = $service->select_history_messages($messages, 'selection');

        $this->assertSame($messages, $selected);
    }

    /**
     * Long threads keep the original request plus the most-recent tail (max N + 1).
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::select_history_messages
     */
    public function test_select_history_preserves_first_user_message(): void {
        $service = new orchestrator_prompt_profile_service();
        $limit = orchestrator_prompt_profile_service::HISTORY_TAIL_LIMIT;

        // First message is the original user request; followed by many later turns.
        $roles = array_merge(['user'], array_fill(0, $limit + 6, 'assistant'));
        $messages = $this->build_messages($roles);

        $selected = $service->select_history_messages($messages, 'selection');

        $this->assertCount($limit + 1, $selected);
        $this->assertSame($messages[0], $selected[0], 'Original request must be kept on top.');
        $this->assertSame($messages[count($messages) - 1], $selected[count($selected) - 1], 'Tail must end at the newest message.');
    }

    /**
     * When the first user message already sits inside the tail window, no duplicate is prepended.
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::select_history_messages
     */
    public function test_select_history_no_duplicate_when_first_user_in_tail(): void {
        $service = new orchestrator_prompt_profile_service();
        $limit = orchestrator_prompt_profile_service::HISTORY_TAIL_LIMIT;

        // Leading assistant rows push the first user message close to the end, inside the tail.
        $roles = array_merge(['assistant', 'assistant'], array_fill(0, $limit, 'user'));
        $messages = $this->build_messages($roles);

        $selected = $service->select_history_messages($messages, 'selection');

        $this->assertCount($limit, $selected);
    }

    /**
     * Threads without any user message fall back to the plain tail.
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service::select_history_messages
     */
    public function test_select_history_tail_only_without_user_message(): void {
        $service = new orchestrator_prompt_profile_service();
        $limit = orchestrator_prompt_profile_service::HISTORY_TAIL_LIMIT;

        $messages = $this->build_messages(array_fill(0, $limit + 5, 'assistant'));

        $selected = $service->select_history_messages($messages, 'selection');

        $this->assertCount($limit, $selected);
        $this->assertSame($messages[count($messages) - 1], $selected[count($selected) - 1]);
    }

    /**
     * Build a list of stdClass messages with sequential content for the given roles.
     *
     * @param string[] $roles
     * @return \stdClass[]
     */
    private function build_messages(array $roles): array {
        $messages = [];
        foreach (array_values($roles) as $index => $role) {
            $msg = new \stdClass();
            $msg->role = $role;
            $msg->content = 'm' . $index;
            $messages[] = $msg;
        }
        return $messages;
    }
}
