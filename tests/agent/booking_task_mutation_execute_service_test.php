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
 * Tests for booking task mutation execute service extraction.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

use mod_booking\local\wbagent\booking\booking_task_mutation_execute_service;
use mod_booking\local\wbagent\booking\booking_task_support;

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Ensure mutating execute orchestration can live outside booking_task_support.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 */
final class booking_task_mutation_execute_service_test extends abstract_agent_testcase {
    /**
     * Mutation execute service should create a booking option.
     */
    public function test_mutation_service_executes_create_option(): void {
        $this->setUser($this->teacher);

        $support = new booking_task_support();
        $service = new booking_task_mutation_execute_service();

        $result = $service->execute(
            'booking.create_option',
            [
                'text' => 'Mutation Service Option',
                'maxanswers' => 10,
                'teacherquery' => 'current',
                'optiondates' => [
                    [
                        'coursestarttime' => '2045-03-15T09:00:00',
                        'courseendtime' => '2045-03-15T17:00:00',
                    ],
                ],
            ],
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $support
        );

        $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
        $this->assertNotEmpty($result['resultid']);

        $option = $this->get_option_from_db((int)$result['resultid']);
        $this->assertEquals('Mutation Service Option', (string)$option->text);
    }
}
