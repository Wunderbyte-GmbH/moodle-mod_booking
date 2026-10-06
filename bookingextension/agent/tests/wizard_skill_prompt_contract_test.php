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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\skill_provider;
use ReflectionMethod;

/**
 * Prompt contract of the skills shipped with the agent (course.*, core.*, question.*, wizard.*).
 *
 * The constructor never sees the property schema — only the description window, minimal_input,
 * the example keys and values and guidance (planner_catalog_service). An entry that is not a schema
 * property is therefore either a required-looking token that makes the constructor ask for
 * something it cannot send (F29) or an example key the model copies into an invalid call.
 * Baseline run 9 (2026-09-16, Wunderbyte-GmbH#2419): AA-1/AQ-2 constructor clarifications.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\skill_provider
 */
final class wizard_skill_prompt_contract_test extends advanced_testcase {
    /**
     * The prompt contract payload of a skill (protected in the base class).
     *
     * @param object $skill Skill instance.
     * @return array
     */
    private function contract(object $skill): array {
        $method = new ReflectionMethod($skill, 'prompt_contract_payload');
        $method->setAccessible(true);
        return (array)$method->invoke($skill);
    }

    /**
     * Every minimal_input entry and every example key is a real schema property.
     */
    public function test_minimal_input_and_example_keys_are_schema_properties(): void {
        $this->resetAfterTest();
        $violations = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $name = $skill->get_name();
            $properties = array_keys((array)($skill->get_schema()['properties'] ?? []));
            foreach ((array)($this->contract($skill)['minimal_input'] ?? []) as $entry) {
                if (!in_array($entry, $properties, true)) {
                    $violations[] = $name . ' minimal_input "' . $entry . '"';
                }
            }
            foreach (array_keys((array)$skill->get_example_input()) as $key) {
                if (!in_array($key, $properties, true)) {
                    $violations[] = $name . ' example key "' . $key . '"';
                }
            }
        }
        $this->assertSame(
            [],
            $violations,
            "prompt contract entries that are not schema properties:\n" . implode("\n", $violations)
        );
    }

    /**
     * minimal_input lists only what the constructor genuinely needs: the optional shaping fields of
     * the authoring skills (intro, section, settings, category, count, ...) are not required tokens.
     *
     * Run 9 AA-1 ("Ordner für die Vorlagen im Einführungsabschnitt") and AQ-2 ended as constructor
     * clarifications because every listed field reads as REQUIRED in the card.
     */
    public function test_authoring_skills_keep_minimal_input_to_the_target_fields(): void {
        $this->resetAfterTest();
        $expected = [
            'course.add_activity' => ['modname', 'name'],
            'course.add_quiz' => ['name'],
            'question.generate_questions' => [],
        ];
        foreach ((new skill_provider())->get_skills() as $skill) {
            if (!isset($expected[$skill->get_name()])) {
                continue;
            }
            $this->assertSame(
                $expected[$skill->get_name()],
                (array)($this->contract($skill)['minimal_input'] ?? []),
                $skill->get_name() . ' minimal_input'
            );
        }
    }
}
