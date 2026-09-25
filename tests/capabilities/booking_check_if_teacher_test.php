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
 * Tests for booking_check_if_teacher() with the placeholder ids of new options.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\option_edit_access;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Tests for booking_check_if_teacher() with the placeholder ids of new options.
 *
 * The duplicate link opens editoptions.php with optionid -1. The edit gate asks
 * booking_check_if_teacher(-1) for users holding editownoption. -1 is no option:
 * the answer is "no", and the option settings cache must not be asked for it -
 * with debugging on it refuses the key -1 and throws a coding exception
 * (Wunderbyte-GmbH/moodle-mod_booking#1604). The tests run with debugging on,
 * as every PHPUnit test does.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_check_if_teacher_test extends capability_testcase {
    /**
     * A negative id is no option: not a teacher, no exception - also for a user
     * who teaches an option. An empty id keeps its meaning "teaches any option".
     *
     * @covers ::booking_check_if_teacher
     */
    public function test_negative_id_is_no_option(): void {
        $own = $this->create_option();
        $user = $this->user_with([]);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertNull(
            $this->capture_exception(fn() => booking_check_if_teacher(-1)),
            'A negative id must not reach the option settings cache.'
        );
        $this->assertFalse(booking_check_if_teacher(-1), 'A negative id is no option.');
        $this->assertTrue(booking_check_if_teacher((int)$own->id), 'Precondition: teacher of the own option.');
        $this->assertTrue(booking_check_if_teacher(0), 'An empty id still means "teaches any option".');
    }

    /**
     * The duplicate link (optionid -1) for a user with editownoption: the gate
     * answers instead of dying. It refuses without duplicateownoption and opens
     * with duplicateownoption for an option the user teaches.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_duplicate_link_gate_answers_for_editownoption(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:editownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $answer = null;
        $this->assertNull(
            $this->capture_exception(function () use (&$answer, $cmid, $own) {
                $answer = option_edit_access::can_edit_option($cmid, -1, (int)$own->id);
            }),
            'The duplicate link must not die with debugging on.'
        );
        $this->assertFalse($answer, 'editownoption alone does not duplicate.');

        $duplicator = $this->user_with(['mod/booking:editownoption', 'mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$duplicator->id);
        $this->setUser($duplicator);
        $this->assertTrue(
            option_edit_access::can_edit_option($cmid, -1, (int)$own->id),
            'duplicateownoption opens the duplicate of an own option.'
        );
    }
}
