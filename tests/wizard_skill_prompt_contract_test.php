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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\skill_provider;
use ReflectionMethod;

/**
 * Constructor contract of every mod_booking wizard skill.
 *
 * The constructor never sees the property schema — only the description, `minimal_input`
 * (prompt_meta input_fields_for_prompt) and the example keys/values. A minimal_input entry that
 * is a sentence ("optionquery (or optionid)") renders as ONE required token, an example key that
 * is not a property teaches a field that does not exist (#2411 limitanswers). Runs 8/9 (#2418).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\skill_provider
 */
final class wizard_skill_prompt_contract_test extends advanced_testcase {
    /**
     * Setup: engine aliases for the skill base classes.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * The prompt contract payload of a skill (protected in the engine base class).
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
        $violations = [];
        $seen = 0;
        foreach ((new skill_provider())->get_skills() as $skill) {
            $seen++;
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
        $this->assertGreaterThan(10, $seen, 'skill discovery must list the booking skills');
        $this->assertSame(
            [],
            $violations,
            "prompt contract entries that are not schema properties:\n" . implode("\n", $violations)
        );
    }

    /**
     * Skills whose targets are named by the user show name-based targeting in their example VALUES and
     * never an id-only key in minimal_input (run 10, #2423: UOT-2/3 asked for option and user ids, DUB-2
     * was not built at all without an example).
     */
    public function test_name_targeted_skills_advertise_queries_not_ids(): void {
        $expected = [
            'mod_booking.update_option_trainer' => ['optionquery', 'teacherquery'],
            'mod_booking.diagnose_user_booking' => ['userquery', 'optionquery'],
        ];
        $idonly = ['optionid', 'optionids', 'teacherids', 'userid'];
        $found = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $name = $skill->get_name();
            if (!isset($expected[$name])) {
                continue;
            }
            $found[] = $name;
            $example = (array)$skill->get_example_input();
            foreach ($expected[$name] as $key) {
                $this->assertNotEmpty(
                    $example[$key] ?? null,
                    $name . ' example must carry ' . $key . ': ' . json_encode($example)
                );
            }
            $minimal = (array)($this->contract($skill)['minimal_input'] ?? []);
            $this->assertSame(
                [],
                array_values(array_intersect($minimal, $idonly)),
                $name . ' minimal_input: ' . json_encode($minimal)
            );
        }
        sort($found);
        $this->assertSame(['mod_booking.diagnose_user_booking', 'mod_booking.update_option_trainer'], $found);
    }

    /**
     * A skill that resolves its target by name must tell the constructor to pass the user's wording
     * verbatim instead of asking back for a name or id.
     *
     * Run 11 (Wunderbyte-GmbH/Wunderbyte-GmbH#2423, F29 group A): GOD-1 ("that autumn hiking thing"),
     * UO-4 ("der Wanderkurs") and DWL-3 ("l'atelier photo") ended as constructor clarifications asking for
     * "the exact name or ID", although the skills resolve a fuzzy query themselves and report candidates.
     *
     * @return void
     */
    public function test_query_targeted_skills_instruct_verbatim_pass_through(): void {
        $this->resetAfterTest();
        $skills = [
            new \mod_booking\local\wizard\options\skills\get_option_details_skill(),
            new \mod_booking\local\wizard\options\skills\update_option_skill(),
            new \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill(),
        ];
        foreach ($skills as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('optionquery', $properties, $skill->get_name());
            $description = (string)($properties['optionquery']['description'] ?? '');
            $this->assertStringContainsStringIgnoringCase(
                'verbatim',
                $description,
                $skill->get_name() . ': optionquery must instruct the constructor to pass the wording verbatim'
            );
            $this->assertFalse(
                (bool)($properties['optionquery']['required'] ?? false),
                $skill->get_name() . ': optionquery stays optional'
            );
        }
    }
}
