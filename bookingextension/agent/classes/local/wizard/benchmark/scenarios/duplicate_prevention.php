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
 * Scenario duplicate_prevention.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario duplicate_prevention.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duplicate_prevention extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'duplicate_prevention_completed_action';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'mutation_r1';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Re-requesting already-executed create -> sufficient, no duplicate';
    }
    /**
     * Contract rule (completed-action -> sufficient, no duplicate): deterministic, belongs in
     * PHPUnit/stub. Excluded from the noisy live LLM benchmark (Tier 2). Note: the "again => no-op"
     * product decision is encoded here. See docs/Blueprints/BENCHMARK_REDESIGN.md.
     *
     * @return string
     */
    public function get_tier(): string {
        return 'deterministic';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Erstelle nochmal die Veranstaltung "Jahresmeeting".';
    }

    /**

     * Get prior messages.

     *

     * @return array

     */
    public function get_prior_messages(): array {
        return [
            ['role' => 'user', 'content' => 'Erstelle die Veranstaltung "Jahresmeeting" am Montag 9-11 Uhr.'],
            ['role' => 'assistant', 'content' => 'Jahresmeeting wurde erfolgreich erstellt (ID 42, Montag 9-11 Uhr).'],
        ];
    }

    /**

     * Get the expected response type.

     *

     * @return string

     */
    public function get_expected_response_type(): string {
        return 'sufficient';
    }
    /**
     * Get the expected skill.
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
        return '{"response_type":"sufficient","message":"Die Veranstaltung Jahresmeeting wurde bereits erstellt.",'
            . '"commands":[],"planned_steps":[],"next_step_intent":"","lang":"de","user_lang":"de"}';
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
                'label'  => 'No new create_option command emitted',
                'passed' => empty($result['commands']),
                'detail' => 'commands: ' . json_encode($result['commands'] ?? []),
            ],
        ];
    }
}
