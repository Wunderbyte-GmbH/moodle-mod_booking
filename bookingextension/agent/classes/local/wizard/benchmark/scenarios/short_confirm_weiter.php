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
 * Scenario short_confirm_weiter.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario short_confirm_weiter.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class short_confirm_weiter extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'short_confirm_weiter';
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
        return '"mach weiter" selects next pending skill (not create_option again)';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'mach weiter';
    }

    /**

     * Get prior messages.

     *

     * @return array

     */
    public function get_prior_messages(): array {
        return [
            ['role' => 'user', 'content' => 'Erstelle EventA und EventB, dann buche Anna Berger fuer EventA.'],
            [
                'role' => 'assistant',
                'content' => "Beide Veranstaltungen wurden erstellt.\n\n" .
                    "Noch ausstehend: Anna Berger fuer EventA buchen.",
            ],
        ];
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
        return 'mod_booking.book_users';
    }

    /**

     * Get the stub selector response.

     *

     * @return string

     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book Anna Berger for EventA",'
            . '"lang":"de","user_lang":"de"}';
    }
}
