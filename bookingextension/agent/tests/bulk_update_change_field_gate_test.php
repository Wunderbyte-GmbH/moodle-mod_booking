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
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;

/**
 * The bulk update must never silently succeed without a known change field.
 *
 * Full run 2026-07-14 (#11, queue-dump evidence): for "make this option visible" the model
 * staged bulk_update_options with the invented field "available: 1" — no schema field, so the
 * run changed nothing and still reported success. check_structure now gates: a bulk command
 * needs at least one schema-known mutation field (or the legacy "visible" alias) besides its
 * selection/control keys, otherwise it clarifies (RECOVERABLE_INPUT_ERROR) and names the
 * unsupported keys on the planner repair channel.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\bulk_update_options_skill::check_structure
 */
final class bulk_update_change_field_gate_test extends advanced_testcase {
    /**
     * An invented change key (the observed "available") clarifies instead of passing.
     */
    public function test_unknown_only_change_key_clarifies(): void {
        $result = (new bulk_update_options_skill())->check_structure([
            'optionquery' => 'Multistep Real LLM',
            'available' => 1,
            'outputlang' => 'en',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $repair = implode(' ', (array)($result['repair'] ?? []));
        $this->assertStringContainsString('available', $repair, 'The repair channel must name the unsupported key.');
        $this->assertStringContainsString('invisible', $repair, 'The repair channel must name canonical fields.');
    }

    /**
     * Selection without any change field clarifies too (nothing to apply).
     */
    public function test_selection_without_change_field_clarifies(): void {
        $result = (new bulk_update_options_skill())->check_structure([
            'optionquery' => 'Yoga',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Canonical and alias visibility fields (and other schema fields) pass the gate.
     */
    public function test_known_change_fields_pass(): void {
        $skill = new bulk_update_options_skill();

        $this->assertTrue($skill->check_structure(['optionquery' => 'Yoga', 'invisible' => 0])['valid']);
        $this->assertTrue(
            $skill->check_structure(['optionquery' => 'Yoga', 'visible' => 1])['valid'],
            'The documented legacy "visible" alias must count as a change field.'
        );
        $this->assertTrue($skill->check_structure(['optionquery' => 'Yoga', 'maxanswers' => 25])['valid']);
        $this->assertTrue($skill->check_structure(['optionquery' => 'Yoga', 'headerimage_token' => 'tok_x'])['valid']);
    }

    /**
     * The missing-target gate stays first: no selection mechanism is still the primary error.
     */
    public function test_missing_target_still_fires_first(): void {
        $result = (new bulk_update_options_skill())->check_structure(['available' => 1]);

        $this->assertFalse($result['valid']);
        $this->assertNotContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
    }
}
