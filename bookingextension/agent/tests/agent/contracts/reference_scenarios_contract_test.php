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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\preflight_schema_validator;
use bookingextension_agent\local\wizard\services\spawn_contract_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract-level reference scenarios for the example target flows.
 *
 * @covers \bookingextension_agent\local\wizard\services\spawn_contract_service
 * @covers \bookingextension_agent\local\wizard\services\preflight_schema_validator
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_scenarios_contract_test extends TestCase {
    /**
     * Scenario A: ideal readonly skill result remains deterministic without spawn.
     */
    public function test_scenario_a_readonly_result_contract(): void {
        $service = new spawn_contract_service();
        $result = $service->normalize_skill_result('booking.readonly_lookup', [
            'status' => 'executed',
            'results' => [
                ['id' => 1, 'name' => 'Option A'],
            ],
        ]);

        $this->assertSame('executed', (string)$result['status']);
        $this->assertSame([], (array)$result['spawn_commands']);
        $this->assertSame([], (array)$result['produced_outputs']);
    }

    /**
     * Scenario B: ideal multistep command validates depends_on plus base schema fields.
     */
    public function test_scenario_b_multistep_command_schema_contract(): void {
        $validator = new preflight_schema_validator();
        $validation = $validator->validate([
            'skill' => 'booking.readonly_lookup',
            'version' => 1,
            'input' => ['query' => 'Yoga'],
            'depends_on' => ['step-0'],
        ]);

        $this->assertTrue((bool)$validation['valid']);
        $this->assertSame([], (array)$validation['errors']);
    }

    /**
     * Scenario C: ideal spawn contract binds parent outputs into child input.
     */
    public function test_scenario_c_spawn_output_binding_contract(): void {
        $service = new spawn_contract_service();

        $parent = $service->normalize_skill_result('booking.create_parent', [
            'produced_outputs' => [
                'created_course_id' => 42,
            ],
            'spawn_commands' => [[
                'skill' => 'booking.child_followup',
                'version' => 1,
                'input' => ['label' => 'Follow-up'],
                'output_bindings' => ['courseid' => 'parent.created_course_id'],
            ]],
        ]);

        $spawncommand = (array)$parent['spawn_commands'][0];
        $resolved = $service->apply_output_bindings(
            (array)$spawncommand['input'],
            (array)$spawncommand['output_bindings'],
            (array)$parent['produced_outputs']
        );

        $this->assertSame([], (array)$resolved['errors']);
        $this->assertSame(42, (int)$resolved['input']['courseid']);
        $this->assertSame('Follow-up', (string)$resolved['input']['label']);
    }
}
