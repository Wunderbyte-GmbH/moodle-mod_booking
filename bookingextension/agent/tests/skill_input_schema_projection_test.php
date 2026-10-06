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
 * The constructor sees the input fields of the skill it is building for.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\skill_input_schema_projection;

/**
 * Tests for skill_input_schema_projection.
 *
 * Background (F29, agent baseline run 12): the construction prompt stubbed the schema placeholder to an
 * empty list, so the constructor only ever saw a 240-character description window, an example and the
 * guidance lines. Missing field knowledge is the largest single cause of prompts that end in a question
 * instead of a command: 16 of 34 unreached booking prompts carried CONSTRUCTION_INPUT_REQUIRED.
 *
 * @covers \bookingextension_agent\local\wizard\services\skill_input_schema_projection
 */
final class skill_input_schema_projection_test extends \advanced_testcase {
    /**
     * A schema with required and optional fields of several types.
     *
     * @return array
     */
    private function sample_schema(): array {
        return [
            'version' => 1,
            'description' => 'Change one booking option.',
            'readonly' => false,
            'example_utterances' => ['Change the price of the pottery class'],
            'properties' => [
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Booking option id to change. Use it when the id is known.',
                    'required' => true,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => "Pass the user's wording verbatim. Used when optionid is unknown.",
                    'required' => false,
                ],
                'invisible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the option is hidden from participants.',
                    'required' => false,
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Publication state.',
                    'required' => false,
                    'enum' => ['draft', 'published'],
                ],
            ],
            'prompt_meta' => ['intent' => 'change one option', 'input_fields_for_prompt' => ['optionid']],
        ];
    }

    /**
     * Every declared field reaches the constructor with its type and whether it is required.
     */
    public function test_projection_lists_fields_with_type_and_requiredness(): void {
        $this->resetAfterTest();

        $lines = skill_input_schema_projection::project($this->sample_schema());
        $text = implode("\n", $lines);

        foreach (['optionid', 'optionquery', 'invisible', 'status'] as $field) {
            $this->assertStringContainsString($field, $text, "field $field is missing from the projection");
        }
        $this->assertStringContainsString('integer', $text);
        $this->assertStringContainsString('boolean', $text);
        $this->assertMatchesRegularExpression('/optionid[^\n]*required/i', $text);
        $this->assertMatchesRegularExpression('/optionquery[^\n]*optional/i', $text);
    }

    /**
     * Allowed values are part of the field line: without them the constructor invents states.
     */
    public function test_projection_carries_allowed_values(): void {
        $this->resetAfterTest();

        $text = implode("\n", skill_input_schema_projection::project($this->sample_schema()));

        $this->assertStringContainsString('draft', $text);
        $this->assertStringContainsString('published', $text);
    }

    /**
     * Only input fields travel. Schema internals are noise for the constructor and must stay out.
     */
    public function test_projection_omits_schema_internals(): void {
        $this->resetAfterTest();

        $text = implode("\n", skill_input_schema_projection::project($this->sample_schema()));

        foreach (['prompt_meta', 'example_utterances', 'input_fields_for_prompt', 'readonly', 'version'] as $internal) {
            $this->assertStringNotContainsString($internal, $text, "schema internal $internal leaked into the projection");
        }
    }

    /**
     * A wide schema (update_option has 103 fields) stays inside the budget, required fields survive,
     * and the constructor is told that the list was shortened instead of silently seeing a partial list.
     */
    public function test_wide_schema_is_capped_and_keeps_required_fields(): void {
        $this->resetAfterTest();

        $schema = ['properties' => [
            'mandatory' => ['type' => 'integer', 'description' => 'Required one.', 'required' => true],
        ]];
        for ($i = 1; $i <= 120; $i++) {
            $schema['properties']["field$i"] = [
                'type' => 'string',
                'description' => 'An optional field with a reasonably long description so the budget is reached.',
                'required' => false,
            ];
        }

        $lines = skill_input_schema_projection::project($schema);
        $text = implode("\n", $lines);

        $this->assertLessThanOrEqual(
            skill_input_schema_projection::MAX_CHARS,
            strlen($text),
            'the projection must respect its character budget'
        );
        $this->assertMatchesRegularExpression('/mandatory[^\n]*required/i', $text);
        $note = (string)end($lines);
        $this->assertMatchesRegularExpression('/\b\d+\b/', $note, 'the cut-off note must name the omitted count');
    }

    /**
     * A schema without properties produces nothing at all, not an empty block.
     */
    public function test_schema_without_properties_projects_nothing(): void {
        $this->resetAfterTest();

        $this->assertSame([], skill_input_schema_projection::project([]));
        $this->assertSame([], skill_input_schema_projection::project(['properties' => []]));
    }

    /**
     * The projection reads the schema off a skill object, and tolerates one that has none.
     */
    public function test_projection_for_a_skill_object(): void {
        $this->resetAfterTest();

        $schema = $this->sample_schema();
        $skill = new class ($schema) {
            /** @var array */
            private array $schema;

            /**
             * Constructor.
             *
             * @param array $schema
             */
            public function __construct(array $schema) {
                $this->schema = $schema;
            }

            /**
             * Return the schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return $this->schema;
            }
        };

        $this->assertNotEmpty(skill_input_schema_projection::for_skill($skill));
        $this->assertSame([], skill_input_schema_projection::for_skill(new \stdClass()));
    }

    /**
     * The projection is engine-agnostic: it names no skill and no plugin, it only describes fields.
     */
    public function test_projection_names_no_skill(): void {
        $this->resetAfterTest();

        $text = implode("\n", skill_input_schema_projection::project($this->sample_schema()));

        $this->assertStringNotContainsString('mod_booking.', $text);
        $this->assertStringNotContainsString('wizard.', $text);
    }
}
