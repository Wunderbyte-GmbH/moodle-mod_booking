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
 * Tests for booking task execute service extraction.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\booking\booking_task_execute_service;

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Ensure execute orchestration can live outside booking_task_support.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 */
final class booking_task_execute_service_test extends abstract_agent_testcase {
    /**
     * Execute service should handle readonly get_current_user task.
     */
    public function test_execute_service_executes_get_current_user(): void {
        $this->setUser($this->teacher);

        $support = new booking_task_support();
        $service = new booking_task_execute_service();

        $result = $service->execute(
            'booking.get_current_user',
            [],
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $support
        );

        $this->assertEquals('executed', $result['status']);
        $this->assertEquals((int)$this->teacher->id, (int)$result['userid']);
    }
}
