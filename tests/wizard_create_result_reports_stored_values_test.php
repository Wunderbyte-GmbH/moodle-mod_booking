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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\create_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The create result must report the STORED core values, not just id and link.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_mutation_execute_service
 */
final class wizard_create_result_reports_stored_values_test extends booking_advanced_testcase {
    /**
     * A created option's result detail carries the stored start (with weekday), end and
     * seat count — the reply-writer must have real facts to quote instead of the wish.
     */
    public function test_create_detail_carries_stored_start_end_and_seats(): void {
        $this->resetAfterTest();
        engine_component::ensure_engine_aliases();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Result Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $contextid = (int)context_module::instance((int)$booking->cmid)->id;

        // A Saturday at least a week ahead: a fixed date turns into "start in the past" once it passes.
        $saturday = strtotime('next saturday 14:00') + WEEKSECS;
        $skill = new create_option_skill();
        $dto = $skill->preflight([
            'text' => 'Truth Seminar',
            'coursestarttime' => $saturday,
            'courseendtime' => $saturday + (3 * HOURSECS),
            'maxanswers' => 12,
        ], $contextid, (int)get_admin()->id);
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));

        $result = $skill->execute((array)$dto->preparedinput, $contextid, (int)get_admin()->id);

        $this->assertSame('executed', (string)($result['status'] ?? ''), (string)($result['detail'] ?? ''));
        $detail = (string)($result['detail'] ?? '');
        $this->assertStringContainsString('Saturday', $detail, 'stored start must be reported with its weekday');
        $this->assertStringContainsString(userdate($saturday, '%B %Y'), $detail);
        $this->assertStringContainsString('12', $detail, 'stored seat count must be reported');
    }
}
