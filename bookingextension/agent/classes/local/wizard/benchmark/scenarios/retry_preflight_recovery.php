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
 * Scenario retry_preflight_recovery.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario retry_preflight_recovery.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class retry_preflight_recovery extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'retry_preflight_recovery';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'error_retry';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Selector picks mutation skill after simulated preflight transient error context';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Buche Peter Mayer fuer den Kurs "Notfallkurs", der Kurs hatte gestern einen Fehler.';
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
     * Empty on purpose: the check lives in assert_additional, which also accepts
     * the legitimate find-then-book pattern — but deliberately NOT diagnose/search
     * detours triggered by the error wording (that distraction is exactly what
     * this scenario measures).
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return '';
    }

    /**

     * Get the stub selector response.

     *

     * @return string

     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book Peter Mayer for Notfallkurs",'
            . '"lang":"de","user_lang":"de"}';
    }
    /**
     * Perform additional assertions.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $skill = trim((string)($result['commands'][0]['skill'] ?? ''));
        $hasfollowup = !empty($result['planned_steps']) || trim((string)($result['next_step_intent'] ?? '')) !== '';
        $direct = $skill === 'mod_booking.book_users';
        $findthenbook = $skill === 'mod_booking.search_options' && $hasfollowup;

        return [
            [
                'label'  => 'Booking intent survives error context (book_users, or search_options with follow-up; '
                    . 'diagnose/course detours fail)',
                'passed' => $direct || $findthenbook,
                'detail' => "skill: {$skill}; followup: " . ($hasfollowup ? 'yes' : 'no'),
            ],
        ];
    }
}
