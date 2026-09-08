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
 * Tests for the create / edit own / edit other people's matrix of the option
 * editing capabilities (capability table 2).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\option_edit_access;
use mod_booking\tests\capability_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The documented matrix of the option form gate (editoptions.php):
 *
 * |                      | create | edit own | edit other people's |
 * | addoption            | yes    | no       | no                  |
 * | addeditownoption     | no     | yes      | no                  |
 * | updatebooking        | yes    | yes      | yes                 |
 *
 * "own" means: the user is a teacher of the option (booking_check_if_teacher),
 * not: the user has created it.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_edit_matrix_test extends capability_testcase {
    /**
     * One row of the matrix per capability.
     *
     * @return array
     */
    public static function matrix_provider(): array {
        return [
            // Capability, may create, may edit own, may edit other people's.
            'addoption' => ['mod/booking:addoption', true, false, false],
            'addeditownoption' => ['mod/booking:addeditownoption', false, true, false],
            'updatebooking' => ['mod/booking:updatebooking', true, true, true],
        ];
    }

    /**
     * Each capability alone opens exactly the documented cells of the matrix.
     *
     * @param string $capability
     * @param bool $maycreate
     * @param bool $mayeditown
     * @param bool $mayeditother
     * @dataProvider matrix_provider
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_edit_matrix(
        string $capability,
        bool $maycreate,
        bool $mayeditown,
        bool $mayeditother
    ): void {
        $own = $this->create_option();
        $other = $this->add_option();
        $cmid = (int)$own->cmid;

        // A user holding only this capability, teacher of no option at all.
        $user = $this->user_with([$capability]);

        $this->assertSame(
            $maycreate,
            option_edit_access::can_edit_option($cmid, 0),
            "$capability and creating a new option."
        );
        $this->assertSame(
            $mayeditother,
            option_edit_access::can_edit_option($cmid, (int)$other->id),
            "$capability and editing an option of somebody else."
        );

        // The same user, now teacher of the first option: that is what "own" means.
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);
        $this->assertTrue(booking_check_if_teacher((int)$own->id), 'Precondition: teacher of the own option.');

        $this->assertSame(
            $mayeditown,
            option_edit_access::can_edit_option($cmid, (int)$own->id),
            "$capability and editing an option they teach."
        );
        $this->assertSame(
            $mayeditother,
            option_edit_access::can_edit_option($cmid, (int)$other->id),
            "$capability and editing an option of somebody else, while teaching another one."
        );
    }

    /**
     * Users without any of the three capabilities are rejected with the same
     * exception the inline check in editoptions.php threw.
     *
     * @covers \mod_booking\local\option_edit_access::require_edit_option
     */
    public function test_option_form_requires_one_of_the_three_capabilities(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $this->user_with([]);
        try {
            option_edit_access::require_edit_option($cmid, (int)$own->id);
            $this->fail('Users without an editing capability must not open the option form.');
        } catch (moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $this->user_with(['mod/booking:updatebooking']);
        option_edit_access::require_edit_option($cmid, (int)$own->id);
    }
}
