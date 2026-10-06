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

use bookingextension_agent\local\wizard\services\spawn_contract_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for spawn command normalization and binding resolution.
 *
 * @covers \bookingextension_agent\local\wizard\services\spawn_contract_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class spawn_contract_service_test extends TestCase {
    /**
     * Verify that produced outputs are normalized with parent/skill aliases.
     */
    public function test_normalize_skill_result_adds_output_aliases(): void {
        $service = new spawn_contract_service();
        $normalized = $service->normalize_skill_result('booking.create', [
            'produced_outputs' => [
                'courseid' => 77,
            ],
            'spawn_commands' => [],
        ]);

        $outputs = (array)$normalized['produced_outputs'];
        $this->assertSame(77, $outputs['courseid']);
        $this->assertSame(77, $outputs['parent.courseid']);
        $this->assertSame(77, $outputs['booking.create.courseid']);
    }

    /**
     * Verify that output bindings resolve from canonical parent aliases.
     */
    public function test_apply_output_bindings_resolves_parent_aliases(): void {
        $service = new spawn_contract_service();

        $resolved = $service->apply_output_bindings(
            ['title' => 'X'],
            ['courseid' => 'parent.courseid'],
            ['parent.courseid' => 123]
        );

        $this->assertSame([], $resolved['errors']);
        $this->assertSame(123, $resolved['input']['courseid']);
        $this->assertSame('X', $resolved['input']['title']);
    }

    /**
     * Verify that missing output binding references are rejected.
     */
    public function test_apply_output_bindings_reports_missing_reference(): void {
        $service = new spawn_contract_service();

        $resolved = $service->apply_output_bindings(
            [],
            ['courseid' => 'parent.courseid'],
            []
        );

        $this->assertNotEmpty($resolved['errors']);
        $this->assertStringContainsString('not found', (string)$resolved['errors'][0]);
    }

    /**
     * Verify spawn command normalization drops invalid command entries.
     */
    public function test_normalize_spawn_commands_filters_invalid_entries(): void {
        $service = new spawn_contract_service();
        $commands = $service->normalize_spawn_commands([
            'invalid-string-entry',
            [
                'skill' => '',
                'input' => ['x' => 1],
            ],
            [
                'skill' => 'booking.child',
                'version' => 2,
                'input' => ['x' => 1],
                'output_bindings' => ['courseid' => 'parent.courseid'],
                'depends_on' => ['parent-1', 'parent-1', ''],
            ],
        ]);

        $this->assertCount(1, $commands);
        $this->assertSame('booking.child', (string)$commands[0]['skill']);
        $this->assertSame(2, (int)$commands[0]['version']);
        $this->assertSame(['x' => 1], (array)$commands[0]['input']);
        $this->assertSame(['courseid' => 'parent.courseid'], (array)$commands[0]['output_bindings']);
        $this->assertSame(['parent-1'], array_values((array)$commands[0]['depends_on']));
    }
}
