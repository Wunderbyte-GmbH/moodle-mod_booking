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
 * Scenario skill_not_in_catalog.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario skill_not_in_catalog.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_not_in_catalog extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'skill_not_in_catalog_no_hallucination';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'recovery';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Genuinely uncovered capability (no export/report skill exists) -> wizard.search_skills '
            . 'catalog lookup (RAG fallback, §6.3), no hallucinated skill. Replaces the former '
            . '"create a Zoom link" utterance, which course.add_activity now legitimately serves '
            . '(URL/link resource) — that made it a false no-skill case (run 44).';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Exportiere alle Teilnehmer von "Erste Hilfe Grundkurs" als Excel-Datei.';
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
        return 'wizard.search_skills';
    }

    /**
     * Get the stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","message":"Ich suche im Skill-Katalog nach einer passenden Aktion.",'
            . '"commands":[{"skill":"wizard.search_skills","version":1,"input":{"query":"Teilnehmer als Excel exportieren"}}],'
            . '"planned_steps":[],"next_step_intent":"","lang":"de","user_lang":"de"}';
    }

    /**
     * Perform additional assertions.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $commands = is_array($result['commands'] ?? null) ? $result['commands'] : [];
        $skills = array_map(
            static fn($c): string => is_array($c) ? trim((string)($c['skill'] ?? '')) : '',
            $commands
        );
        $nonsearch = array_filter($skills, static fn(string $s): bool => $s !== '' && $s !== 'wizard.search_skills');

        return [
            [
                'label'  => 'Only the catalog lookup is emitted for the unavailable action',
                'passed' => empty($nonsearch),
                'detail' => 'commands: ' . json_encode($commands),
            ],
        ];
    }
}
