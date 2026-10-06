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
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\option_schema_definition;
use mod_booking\local\wizard\options\skills\update_option_skill;

/**
 * The fields a user changes most must be inside the constructor's field window.
 *
 * The constructor prompt lists the schema fields in declared order until a character budget is
 * spent. update_option declares over a hundred fields; price, visibility and course link were
 * behind the budget, so the model guessed their names (#2510).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\option_schema_definition
 */
final class wizard_option_fields_in_constructor_window_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var string[] The fields of the skill's own example utterances. */
    private const EVERYDAY_FIELDS = ['prices', 'invisible', 'visibility', 'coursequery'];

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        engine_component::ensure_engine_aliases();
    }

    /**
     * Field names the constructor gets to see for a skill.
     *
     * @param object $skill
     * @return string[]
     */
    private function listed_fields(object $skill): array {
        $class = '\\bookingextension_agent\\local\\wizard\\services\\skill_input_schema_projection';
        $names = [];
        foreach ($class::for_skill($skill) as $line) {
            if (preg_match('/^\W*([a-z_0-9]+)\s*\(/', (string)$line, $m)) {
                $names[] = $m[1];
            }
        }
        return $names;
    }

    /**
     * update_option shows price, visibility and course link to the constructor.
     */
    public function test_update_option_lists_the_everyday_fields(): void {
        $listed = $this->listed_fields(new update_option_skill());

        foreach (self::EVERYDAY_FIELDS as $field) {
            $this->assertContains($field, $listed, "$field is outside the constructor's field window");
        }
        $this->assertContains('optiontype', $listed);
        $this->assertContains('slot_enabled', $listed, 'the slot block must stay reachable');
    }

    /**
     * bulk_update_options shares the field list and the problem.
     */
    public function test_bulk_update_options_lists_the_everyday_fields(): void {
        $listed = $this->listed_fields(new bulk_update_options_skill());

        foreach (self::EVERYDAY_FIELDS as $field) {
            $this->assertContains($field, $listed, "$field is outside the constructor's field window");
        }
    }

    /**
     * create_option lists all of its fields, as before; the engine's yes/no requester companion of teacherquery
     * (#2569) is no schema field and follows its field directly.
     */
    public function test_create_option_window_is_complete(): void {
        $skill = new create_option_skill();
        $listed = $this->listed_fields($skill);
        $fields = array_values(array_filter($listed, static fn(string $name): bool => !str_ends_with($name, '_is_requester')));

        $this->assertSame(array_keys((array)$skill->get_schema()['properties']), $fields);
        $this->assertContains('prices', $listed);
        $companion = array_search('teacherquery_is_requester', $listed, true);
        $this->assertNotFalse($companion);
        $this->assertSame('teacherquery', $listed[$companion - 1]);
    }

    /**
     * Only the order changes: every shared field keeps its name, type and description.
     */
    public function test_shared_fields_keep_their_definitions(): void {
        $properties = option_schema_definition::common_properties();

        foreach (self::EVERYDAY_FIELDS as $field) {
            $this->assertArrayHasKey($field, $properties);
        }
        $this->assertSame('object', $properties['prices']['type']);
        $this->assertStringContainsString('{"default": 10, "student": 20}', $properties['prices']['description']);
        $this->assertSame('integer', $properties['invisible']['type']);
        $this->assertSame('string', $properties['coursequery']['type']);
        foreach ($properties as $name => $definition) {
            $this->assertArrayHasKey('type', $definition, $name);
            $this->assertNotSame('', trim((string)($definition['description'] ?? '')), "$name has no description");
        }
    }
}
