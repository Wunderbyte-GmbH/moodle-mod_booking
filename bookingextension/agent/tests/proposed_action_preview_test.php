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
use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\proposed_action_preview;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Tests for the engine-agnostic proposed-action (confirmation) preview framework.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\proposed_action_preview
 * @covers     \bookingextension_agent\local\wizard\base_skill::describe_proposed_action
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class proposed_action_preview_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Build a stub skill whose schema declares the given properties.
     *
     * @param array $properties
     * @param string $name
     * @return base_skill
     */
    private function make_stub_skill(array $properties, string $name = 'mod_booking.create_thing'): base_skill {
        return new class ($properties, $name) extends base_skill {
            /** @var array Declared schema properties. */
            private array $properties;
            /** @var string Skill name. */
            private string $skillname;

            /**
             * Build the stub.
             *
             * @param array $properties
             * @param string $skillname
             */
            public function __construct(array $properties, string $skillname) {
                parent::__construct(false, skill_risk_class::R1);
                $this->properties = $properties;
                $this->skillname = $skillname;
            }

            /**
             * Return the skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return $this->skillname;
            }

            /**
             * Return the skill schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['type' => 'object', 'properties' => $this->properties];
            }

            /**
             * No-op execution.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }
        };
    }

    /**
     * Wrap a single skill in a registry that resolves it by any name.
     *
     * @param skill_interface|null $skill
     * @return skill_registry
     */
    private function make_registry(?skill_interface $skill): skill_registry {
        return new class ($skill) extends skill_registry {
            /** @var skill_interface|null Skill returned for any name. */
            private ?skill_interface $stub;

            /**
             * Build the registry.
             *
             * @param skill_interface|null $stub
             */
            public function __construct(?skill_interface $stub) {
                parent::__construct();
                $this->stub = $stub;
            }

            /**
             * Return the stub skill regardless of name.
             *
             * @param string $skillname
             * @return skill_interface|null
             */
            public function get_skill(string $skillname): ?skill_interface {
                return $this->stub;
            }
        };
    }

    /**
     * The generic default surfaces only declared fields, humanizes labels, formats by type and
     * drops framework-internal / empty / falsy entries.
     */
    public function test_generic_default_describe(): void {
        $skill = $this->make_stub_skill([
            'text' => ['type' => 'string'],
            'slot_duration_minutes' => ['type' => 'integer'],
            'slot_day_3' => ['type' => 'boolean'],
            'slot_day_1' => ['type' => 'boolean'],
            'outputlang' => ['type' => 'string'],
            'maxanswers' => ['type' => 'integer', 'label' => 'Seats'],
        ]);

        $descriptor = $skill->describe_proposed_action([
            'text' => 'Sprechstunde',
            'slot_duration_minutes' => 30,
            'slot_day_3' => true,
            'slot_day_1' => false,
            'outputlang' => 'de',
            'maxanswers' => 12,
            'optiontype' => 'slotbooking',
            'emptyfield' => '',
        ]);

        $this->assertNotNull($descriptor);
        $this->assertSame('Create thing', $descriptor['title']);
        $this->assertSame('', $descriptor['summary']);

        $rows = [];
        foreach ($descriptor['rows'] as $row) {
            $rows[$row['label']] = $row['value'];
        }

        // Declared, non-empty, truthy fields are surfaced with humanized or schema labels.
        $this->assertSame('Sprechstunde', $rows['Text']);
        $this->assertSame('30', $rows['Slot duration minutes']);
        $this->assertSame(get_string('yes'), $rows['Slot day 3']);
        $this->assertSame('12', $rows['Seats']);

        // Dropped: falsy boolean, framework-internal outputlang, undeclared key, empty value.
        $this->assertArrayNotHasKey('Slot day 1', $rows);
        $this->assertArrayNotHasKey('Outputlang', $rows);
        $this->assertArrayNotHasKey('Optiontype', $rows);
        $this->assertArrayNotHasKey('Emptyfield', $rows);
    }

    /**
     * A skill with no declared field present in the input yields no preview.
     */
    public function test_generic_default_returns_null_when_nothing_to_show(): void {
        $skill = $this->make_stub_skill(['text' => ['type' => 'string']]);
        $this->assertNull($skill->describe_proposed_action(['undeclared' => 'x']));
        $this->assertNull($skill->describe_proposed_action(['text' => '']));
    }

    /**
     * A tier-3 skill may override the method to return a fully custom descriptor.
     */
    public function test_tier3_override_is_returned_verbatim(): void {
        $skill = new class extends base_skill {
            /**
             * Build the stub.
             */
            public function __construct() {
                parent::__construct(false, skill_risk_class::R1);
            }

            /**
             * Return the skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'mod_booking.create_slotbooking_option';
            }

            /**
             * Return the skill schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['type' => 'object', 'properties' => []];
            }

            /**
             * No-op execution.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }

            /**
             * Custom tier-3 preview.
             *
             * @param array $input
             * @return array|null
             */
            public function describe_proposed_action(array $input): ?array {
                return [
                    'title' => 'Create slot booking option',
                    'summary' => 'Bookable on Wednesdays.',
                    'rows' => [['label' => 'Weekdays', 'value' => 'Wednesday']],
                ];
            }
        };

        $descriptor = $skill->describe_proposed_action(['slot_day_3' => true]);
        $this->assertSame('Create slot booking option', $descriptor['title']);
        $this->assertSame('Bookable on Wednesdays.', $descriptor['summary']);
        $this->assertSame('Weekdays', $descriptor['rows'][0]['label']);
    }

    /**
     * The service wraps skill descriptors into a self-contained 'proposed_action' descriptor.
     */
    public function test_build_preview_json_wraps_descriptor(): void {
        $skill = $this->make_stub_skill(['text' => ['type' => 'string']]);
        $registry = $this->make_registry($skill);

        $json = proposed_action_preview::build_preview_json(
            [['skill' => 'mod_booking.create_thing', 'input' => ['text' => 'Hello']]],
            $registry
        );

        $decoded = json_decode($json, true);
        $this->assertSame('proposed_action', $decoded['type']);
        $this->assertCount(1, $decoded['payload']['actions']);
        $action = $decoded['payload']['actions'][0];
        $this->assertSame('mod_booking.create_thing', $action['skill']);
        $this->assertSame('Create thing', $action['title']);
        $this->assertSame('Text', $action['rows'][0]['label']);
        $this->assertSame('Hello', $action['rows'][0]['value']);
    }

    /**
     * A skill that produces no rows is skipped, yielding an empty preview.
     */
    public function test_build_preview_json_skips_empty(): void {
        $skill = $this->make_stub_skill(['text' => ['type' => 'string']]);
        $registry = $this->make_registry($skill);

        // Input carries only an undeclared field, so the generic default returns null.
        $json = proposed_action_preview::build_preview_json(
            [['skill' => 'mod_booking.create_thing', 'input' => ['undeclared' => 'x']]],
            $registry
        );
        $this->assertSame('', $json);

        // An unknown skill (registry returns null) is also skipped.
        $emptyregistry = $this->make_registry(null);
        $this->assertSame('', proposed_action_preview::build_preview_json(
            [['skill' => 'mod_booking.create_thing', 'input' => ['text' => 'x']]],
            $emptyregistry
        ));
    }
}
