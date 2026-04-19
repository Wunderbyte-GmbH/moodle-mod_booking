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
 * End-to-end scenario tests for booking agent task flows.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

/**
 * E2E scenario tests over multiple tasks.
 *
 * @group mod_booking
 * @group mod_booking_agent
 */
final class agent_e2e_scenarios_test extends abstract_agent_testcase {

    /**
     * Build a create_option payload that satisfies normal booking type validation.
     *
     * @param string $text
     * @param array $overrides
     * @return array
     */
    private function create_payload(string $text, array $overrides = []): array {
        return array_merge([
            'text' => $text,
            'maxanswers' => 10,
            'coursestarttime' => '2045-03-15T09:00:00',
            'duration' => 8,
            'teacherquery' => 'current',
        ], $overrides);
    }

    /**
     * Scenario: create -> search -> update in one logical workflow.
     */
    public function test_create_search_update_flow(): void {
        $create = $this->exec_command('booking.create_option', $this->create_payload('Flow Yoga Option', [
            'maxanswers' => 7,
        ]));
        $this->assertEquals('executed', $create['status'], $create['detail'] ?? '');

        $optionid = (int)$create['resultid'];
        $this->assertGreaterThan(0, $optionid);

        $search = $this->exec_command('booking.search_options', [
            'query' => 'Flow Yoga',
        ]);
        $this->assertEquals('executed', $search['status'], $search['detail'] ?? '');
        $this->assertArrayHasKey('previewoptionids', $search);
        $this->assertContains($optionid, $search['previewoptionids']);

        $update = $this->exec_command('booking.update_option', [
            'optionid' => $optionid,
            'maxanswers' => 21,
        ]);
        $this->assertEquals('executed', $update['status'], $update['detail'] ?? '');

        $updated = $this->get_option_from_db($optionid);
        $this->assertEquals(21, (int)$updated->maxanswers);
    }

    /**
     * Scenario: filtered bulk update only mutates the matching subset.
     */
    public function test_filtered_bulk_update_flow(): void {
        $yoga1 = $this->exec_command('booking.create_option', $this->create_payload('Flow Yoga Morning', ['maxanswers' => 2]));
        $yoga2 = $this->exec_command('booking.create_option', $this->create_payload('Flow Yoga Evening', ['maxanswers' => 2]));
        $pilates = $this->exec_command('booking.create_option', $this->create_payload('Flow Pilates', ['maxanswers' => 2]));

        $this->assertEquals('executed', $yoga1['status'], $yoga1['detail'] ?? '');
        $this->assertEquals('executed', $yoga2['status'], $yoga2['detail'] ?? '');
        $this->assertEquals('executed', $pilates['status'], $pilates['detail'] ?? '');

        $bulk = $this->exec_command('booking.bulk_update_options', [
            'optionquery' => 'Flow Yoga',
            'maxanswers' => 11,
        ]);
        $this->assertEquals('executed', $bulk['status'], $bulk['detail'] ?? '');

        $opt1 = $this->get_option_from_db((int)$yoga1['resultid']);
        $opt2 = $this->get_option_from_db((int)$yoga2['resultid']);
        $opt3 = $this->get_option_from_db((int)$pilates['resultid']);

        $this->assertEquals(11, (int)$opt1->maxanswers);
        $this->assertEquals(11, (int)$opt2->maxanswers);
        $this->assertEquals(2, (int)$opt3->maxanswers);
    }

    /**
     * Scenario: read-only task does not mutate option count.
     */
    public function test_read_only_task_keeps_state_unchanged(): void {
        $c1 = $this->exec_command('booking.create_option', $this->create_payload('Readonly A'));
        $c2 = $this->exec_command('booking.create_option', $this->create_payload('Readonly B'));
        $this->assertEquals('executed', $c1['status'], $c1['detail'] ?? '');
        $this->assertEquals('executed', $c2['status'], $c2['detail'] ?? '');

        $before = count($this->get_all_options());

        $list = $this->exec_command('booking.list_actions', ['scope' => 'readonly']);
        $this->assertEquals('executed', $list['status'], $list['detail'] ?? '');

        $search = $this->exec_command('booking.search_options', ['query' => 'Readonly']);
        $this->assertEquals('executed', $search['status'], $search['detail'] ?? '');

        $after = count($this->get_all_options());
        $this->assertEquals($before, $after);
    }

    /**
     * Scenario: student access to agent tasks is denied by capability checks.
     */
    public function test_student_agent_access_denied_flow(): void {
        $create = $this->exec_command('booking.create_option', $this->create_payload('Role Option'));
        $this->assertEquals('executed', $create['status'], $create['detail'] ?? '');

        $this->expectException(\core\exception\required_capability_exception::class);

        $search = $this->exec_command(
            'booking.search_options',
            ['query' => 'Role'],
            (int)$this->booking->cmid,
            (int)$this->student->id
        );
    }

    /**
     * Scenario: invalid update request followed by a valid recovery request.
     */
    public function test_error_then_recovery_flow(): void {
        $created = $this->exec_command('booking.create_option', $this->create_payload('Recoverable Option', ['maxanswers' => 3]));
        $this->assertEquals('executed', $created['status'], $created['detail'] ?? '');
        $optionid = (int)$created['resultid'];

        $invalid = $this->exec_command('booking.update_option', [
            'maxanswers' => 10,
        ]);
        $this->assertEquals('error', $invalid['status']);

        $valid = $this->exec_command('booking.update_option', [
            'optionid' => $optionid,
            'maxanswers' => 10,
        ]);
        $this->assertEquals('executed', $valid['status'], $valid['detail'] ?? '');

        $updated = $this->get_option_from_db($optionid);
        $this->assertEquals(10, (int)$updated->maxanswers);
    }
}
