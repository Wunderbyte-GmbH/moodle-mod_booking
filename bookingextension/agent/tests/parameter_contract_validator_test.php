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
 * Engine-level input key contract of the parameter contract validator (#2364).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Taskflow baseline threads 1114/1180/1183: the planner sent `name`, `unit_id`, `active` for a
 * skill declaring `query`, `unitid`, `isactive`; the engine marked the command structurally valid
 * (base_skill::check_structure() passes everything) and the skill dropped the filters silently.
 * The validator now canonicalizes spelling variants of declared keys and rejects unknown keys
 * with a repair hint, for every skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\construction\parameter_contract_validator
 */
final class parameter_contract_validator_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Spelling variants of a declared key are renamed to the declared key; declared keys win.
     */
    public function test_spelling_variants_are_canonicalized(): void {
        $skill = skill_registry::make_default()->get_skill('wizard.search_skills');
        $this->assertNotNull($skill);
        $validator = new parameter_contract_validator();

        $result = $validator->validate($skill, ['Query' => 'download certificate'], 'Command #1');
        $this->assertTrue($result->valid, json_encode($result->errors));
        $this->assertSame(['query' => 'download certificate'], $result->input);

        $result = $validator->validate($skill, ['QUERY' => 'ignored', 'query' => 'kept', 'outputlang' => 'de'], 'Command #1');
        $this->assertTrue($result->valid);
        $this->assertSame('kept', $result->input['query']);
        $this->assertArrayNotHasKey('QUERY', $result->input);
        $this->assertSame('de', $result->input['outputlang'], 'engine-managed keys pass untouched');
    }

    /**
     * Keys the schema does not declare are rejected with the supported names as repair hint.
     */
    public function test_unknown_keys_are_rejected_with_repair_hint(): void {
        $skill = skill_registry::make_default()->get_skill('wizard.search_skills');
        $validator = new parameter_contract_validator();

        $result = $validator->validate($skill, ['search_text' => 'x', 'query' => 'y'], 'Command #2');
        $this->assertFalse($result->valid);
        $this->assertContains(parameter_contract_validator::ISSUE_UNKNOWN_INPUT_PROPERTY, $result->issuecodes);
        // F3 two-channel contract (Lauf 8, CBI-2/BU-3): key names are planner vocabulary — the
        // unknown key and the supported list travel on the repair channel only, never in the
        // user-cause errors the synchronizer relays.
        $this->assertStringContainsString('search_text', (string)$result->repair[0]);
        $this->assertStringContainsString('query', (string)$result->repair[0]);
        $this->assertStringStartsWith('Command #2:', (string)$result->repair[0]);
        $this->assertNotEmpty($result->errors);
        $schema = (array)$skill->get_schema();
        $names = array_merge(['search_text'], array_map('strval', array_keys((array)($schema['properties'] ?? []))));
        foreach ($result->errors as $error) {
            foreach ($names as $name) {
                $this->assertStringNotContainsString($name, (string)$error, 'user-cause error must not name keys');
            }
            $this->assertStringNotContainsString('Command #', (string)$error, 'user cause carries no planner label');
        }
    }

    /**
     * A schema without declared properties (or with additionalProperties = true) is not key-checked.
     */
    public function test_schemas_without_properties_are_not_checked(): void {
        $skill = skill_registry::make_default()->get_skill('wizard.list_memories');
        $this->assertNotNull($skill);
        $this->assertSame([], (array)($skill->get_schema()['properties'] ?? []));

        $result = (new parameter_contract_validator())->validate($skill, ['anything' => 1], 'Command #3');
        $this->assertTrue($result->valid);
        $this->assertSame(['anything' => 1], $result->input);
    }

    /**
     * The static helper reports canonicalized input, unknown keys and the supported list.
     */
    public function test_check_input_keys_reports_all_three_parts(): void {
        $skill = skill_registry::make_default()->get_skill('wizard.search_skills');
        $check = parameter_contract_validator::check_input_keys($skill, ['query-' => 'a', 'foo' => 'b']);
        $this->assertEquals(['query' => 'a', 'foo' => 'b'], $check['input']);
        $this->assertSame(['foo'], $check['unknown']);
        $this->assertContains('query', $check['supported']);
    }
}
