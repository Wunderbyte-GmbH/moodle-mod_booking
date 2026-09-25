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
 * booking_check_if_teacher() with an empty option id, and the gates that pass one.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use mod_booking\form\modal_confirmcancel;
use mod_booking\form\modal_set_rating;
use mod_booking\form\modal_signinsheet_download;
use mod_booking\local\option_edit_access;
use mod_booking\local\report_access;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * booking_check_if_teacher() with an empty option id, and the gates that pass one.
 *
 * Only a call WITHOUT argument asks "is the user teacher of ANY option?" (the
 * "my options" tab, local_urise meinekurse.php). A given id that is empty or
 * not positive is no option. Before, an empty id silently meant "any option",
 * so every gate that passes an option id from the request opened for a teacher
 * of some other option (Wunderbyte-GmbH/moodle-mod_booking#1608).
 *
 * Every gate test uses a user who teaches an option but lacks the regular
 * capability of the gate, and checks the real option id as counter-check.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teacher_check_empty_optionid_test extends capability_testcase {
    /**
     * No argument: teacher of any option. A given empty or non-positive id:
     * no option, and no exception (the option settings cache is not asked).
     * A real id and an option object keep working.
     *
     * @covers ::booking_check_if_teacher
     */
    public function test_only_the_call_without_argument_means_any_option(): void {
        $own = $this->create_option();
        $user = $this->user_with([]);
        $this->assertFalse(booking_check_if_teacher(), 'Precondition: teaches nothing yet.');

        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(booking_check_if_teacher(), 'Without argument: teacher of any option.');
        $this->assertTrue(booking_check_if_teacher((int)$own->id), 'The own option by id.');
        $this->assertTrue(booking_check_if_teacher((object)['id' => (int)$own->id]), 'The own option as object.');

        foreach (['0' => 0, 'null' => null, 'empty string' => '', 'string 0' => '0', '-1' => -1] as $label => $empty) {
            $answer = null;
            $this->assertNull(
                $this->capture_exception(function () use (&$answer, $empty) {
                    $answer = booking_check_if_teacher($empty);
                }),
                "$label must not reach the option settings cache."
            );
            $this->assertFalse($answer, "$label is no option.");
        }
    }

    /**
     * report.php: the entry check of the report for option 0 is refused for a
     * teacher of another option.
     *
     * @covers \mod_booking\local\report_access::has_report_access
     */
    public function test_report_access_refuses_empty_optionid(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;
        $user = $this->user_with([]);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(report_access::has_report_access($cmid, (int)$own->id), 'Counter-check: the own option.');
        $this->assertFalse(report_access::has_report_access($cmid, 0), 'Option 0 is nobody\'s option.');
    }

    /**
     * The sign-in sheet modal: option 0 needs readresponses like any foreign
     * option, the teacher of another option does not pass.
     *
     * @covers \mod_booking\form\modal_signinsheet_download::check_access_for_dynamic_submission
     */
    public function test_signinsheet_modal_refuses_empty_optionid(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;
        $user = $this->user_with([]);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertNull(
            $this->run_form_access_check(modal_signinsheet_download::class, ['cmid' => $cmid, 'optionid' => (int)$own->id]),
            'Counter-check: the own option.'
        );
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(modal_signinsheet_download::class, ['cmid' => $cmid, 'optionid' => 0]),
            'mod/booking:readresponses'
        );
    }

    /**
     * The cancel modal without option id (it falls back to the system context):
     * cancelownoption does not open it for a teacher of another option.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     */
    public function test_cancel_modal_refuses_missing_optionid(): void {
        $own = $this->create_option();
        $user = $this->user_with(['mod/booking:cancelownoption'], context_system::instance());
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertNull(
            $this->run_form_access_check(modal_confirmcancel::class, ['optionid' => (int)$own->id]),
            'Counter-check: the own option.'
        );
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(modal_confirmcancel::class, []),
            'mod/booking:updatebooking'
        );
    }

    /**
     * The rating modal: for option 0 the teacher of another option needs
     * moodle/rating:rate like everybody else.
     *
     * @covers \mod_booking\form\modal_set_rating::check_access_for_dynamic_submission
     */
    public function test_rating_modal_refuses_empty_optionid(): void {
        $own = $this->create_option(['assessed' => 1, 'scale' => 10]);
        $cmid = (int)$own->cmid;
        $user = $this->user_with([], null, ['moodle/rating:rate']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertNull(
            $this->run_form_access_check(modal_set_rating::class, ['cmid' => $cmid, 'optionid' => (int)$own->id]),
            'Counter-check: the own option.'
        );
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(modal_set_rating::class, ['cmid' => $cmid, 'optionid' => 0]),
            'moodle/rating:rate'
        );
    }

    /**
     * The option form: editownoption does not open a new option (id 0) for a
     * teacher of another option - the root cause of #1603.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_option_form_refuses_new_option_for_editownoption(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;
        $user = $this->user_with(['mod/booking:editownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(option_edit_access::can_edit_option($cmid, (int)$own->id), 'Counter-check: the own option.');
        $this->assertFalse(option_edit_access::can_edit_option($cmid, 0), 'A new option is nobody\'s option.');
    }
}
