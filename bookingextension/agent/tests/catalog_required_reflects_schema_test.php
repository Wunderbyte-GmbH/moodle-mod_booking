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

/**
 * The selector catalogue calls a field required only when the schema does.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\planner_catalog_service;

/**
 * Tests for the REQUIRED line of the compact selection catalogue.
 *
 * Found in the forensics of baseline run 15 (2026-09-19): the line was built from `minimal_input`, i.e. from
 * prompt_meta['input_fields_for_prompt'] — the list of fields worth showing — and not from the schema's
 * 'required' flag. `core.diagnose_permissions` therefore advertised `capability` as REQUIRED although the field
 * is 'required' => false and its own description starts with "OPTIONAL". Together with the selector rule
 * "missing required input for the selected skill -> response_type=clarification" this made the selector ask
 * deterministically for data the skill never needed (threads 4773, 4779, 4780, 4800).
 *
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service
 */
final class catalog_required_reflects_schema_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Build the compact catalogue text for one prepared entry.
     *
     * @param array $entry
     * @return string
     */
    private function catalog_text(array $entry): string {
        $service = new planner_catalog_service(
            new \bookingextension_agent\local\wizard\services\assistant_state_guidance_service()
        );

        return $service->render_catalog_as_text([$entry]);
    }

    /**
     * A field that the schema does not require must not appear behind REQUIRED.
     */
    public function test_optional_field_is_not_advertised_as_required(): void {
        $this->resetAfterTest();

        $text = $this->catalog_text([
            'skill' => 'demo.skill',
            'description' => 'A demo skill.',
            'readonly' => true,
            'minimal_input' => ['userquery', 'coursequery', 'capability'],
            'required_input' => ['userquery'],
        ]);

        $this->assertStringContainsString('REQUIRED: userquery', $text);
        $this->assertStringNotContainsString('capability', $text, 'an optional field was advertised as required');
        $this->assertStringNotContainsString('coursequery', $text, 'an optional field was advertised as required');
    }

    /**
     * A skill whose schema requires nothing says "REQUIRED: none" — and still advertises no optional field.
     *
     * This assertion was inverted on 2026-09-19. It used to demand the ABSENCE of the line, which is how the
     * original defect (optional fields printed as REQUIRED) was fixed. Baseline run 17 showed the absence is
     * not neutral: decision rule 3 of the selector prompt ("missing required input -> clarification") is
     * stated in every prompt, so the model read the missing line as ignorance rather than freedom and
     * invented a mandatory field (DMD-4 called assignmentid mandatory although the schema marks it optional).
     * The part that must not regress — an optional field never appearing under REQUIRED — is still pinned.
     */
    public function test_skill_without_required_fields_says_none(): void {
        $this->resetAfterTest();

        $text = $this->catalog_text([
            'skill' => 'demo.skill',
            'description' => 'A demo skill.',
            'readonly' => false,
            'minimal_input' => ['activityquery', 'name', 'intro', 'visible', 'settings', 'section'],
            'required_input' => [],
            // The claim is only printed when it is true, so the fixture must state that this demo skill
            // really does accept an empty input (2026-09-20).
            'accepts_empty_input' => true,
        ]);

        $this->assertStringContainsString('REQUIRED: none', $text);
        foreach (['activityquery', 'name', 'intro', 'visible', 'settings', 'section'] as $optional) {
            $this->assertStringNotContainsString($optional, $text, 'an optional field was advertised as required');
        }
    }

    /**
     * Every registered skill agrees with its own schema: what the catalogue calls required, the schema requires.
     */
    public function test_registered_skills_agree_with_their_schema(): void {
        $this->resetAfterTest();

        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        foreach ($registry->get_skills() as $skill) {
            $schema = (array)$skill->get_schema();
            $properties = (array)($schema['properties'] ?? []);
            $required = [];
            foreach ($properties as $field => $definition) {
                if (is_array($definition) && !empty($definition['required'])) {
                    $required[] = (string)$field;
                }
            }

            $method = new \ReflectionMethod($skill, 'prompt_contract_payload');
            $method->setAccessible(true);
            $contract = (array)$method->invoke($skill);
            $advertised = (array)($contract['required_input'] ?? []);
            sort($required);
            sort($advertised);
            $this->assertSame($required, $advertised, 'catalogue and schema disagree for ' . $skill->get_name());
        }
    }
}
