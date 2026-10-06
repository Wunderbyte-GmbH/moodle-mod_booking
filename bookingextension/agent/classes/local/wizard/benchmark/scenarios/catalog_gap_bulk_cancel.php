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
 * Scenario catalog_gap_bulk_cancel.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario: "delete all bookings for a course" — no bulk-cancel skill exists in the catalog.
 *
 * Product decision §6.3 (no matching skill -> route to wizard.search_skills, the deliberate RAG
 * fallback from thread 203): the correct response is skill_call -> wizard.search_skills, NOT a
 * hallucinated bulk-cancel skill, and NOT a bare error. Kept consistent with
 * skill_not_in_catalog_no_hallucination so both no-skill cases share one contract.
 *
 * @package bookingextension_agent
 */
class catalog_gap_bulk_cancel extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'catalog_gap_bulk_cancel';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'catalog_gap';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'No bulk-cancel skill in catalog — agent must route to wizard.search_skills (RAG fallback), '
            . 'not hallucinate a skill and not bare-error (product decision §6.3)';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Loesche alle Buchungen fuer den Kurs "Yoga Intensiv".';
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
     * No-skill must resolve via the catalog lookup: any concrete (non-search_skills) skill is a
     * hallucinated capability and fails.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $skills = array_map(
            static fn($c): string => (string)($c['skill'] ?? ''),
            (array)($result['commands'] ?? [])
        );
        $hallucinated = array_filter(
            $skills,
            static fn(string $s): bool => $s !== '' && $s !== 'wizard.search_skills'
        );
        return [
            [
                'label'  => 'no hallucinated skill — only wizard.search_skills is valid here',
                'passed' => empty($hallucinated),
                'detail' => 'skills: ' . implode(',', $skills),
            ],
        ];
    }

    /**
     * Get the stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call",'
            . '"commands":[{"skill":"wizard.search_skills","version":1,"input":{"query":"alle Buchungen stornieren"}}],'
            . '"planned_steps":[],"next_step_intent":"","message":"Ich suche im Skill-Katalog nach einer passenden Aktion.",'
            . '"lang":"de","user_lang":"de"}';
    }
}
