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
 * Tests for what mod/booking:limitededitownoption actually does
 * (capability table 3).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\editteachersforoptiondate_form;
use mod_booking\local\option_edit_access;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The capability is evaluated in exactly four places:
 *
 * - optiondates_teachers_report.php:52 - the "teachers per session" report of
 *   the whole instance. It repeats the same expression as the form below, but
 *   renders an access denied page instead of throwing, so it has no seam and
 *   is represented here by the form.
 * - editteachersforoptiondate_form::check_access_for_dynamic_submission() -
 *   teachers, substitution reason and deductions of a single session.
 * - report.php:1061 - the mailto button, inline in the page script and
 *   additionally gated by the config teachersallowmailtobookedusers and by
 *   teacher status.
 * - permissions::has_any_booking_editing_capability() - the collective gate
 *   of the form webservices.
 *
 * It is NOT queried in editoptions.php, and none of its call sites calls
 * booking_check_if_teacher(), so the "own" in its name is not implemented.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class limitededitownoption_test extends capability_testcase {
    /**
     * It opens the session teachers form - for EVERY option of the instance,
     * including options the user does not teach.
     *
     * @covers \mod_booking\form\editteachersforoptiondate_form::check_access_for_dynamic_submission
     */
    public function test_it_opens_the_session_teachers_form_for_any_option(): void {
        $this->create_option();
        $foreign = $this->add_option('Option of somebody else');
        $cmid = (int)$foreign->cmid;

        $this->user_with([]);
        $this->assertNotNull(
            $this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]),
            'Without any editing capability the form stays closed.'
        );

        $user = $this->user_with(['mod/booking:limitededitownoption']);
        $this->assertFalse(booking_check_if_teacher((int)$foreign->id), 'Precondition: not a teacher of the option.');
        $this->assertFalse(booking_check_if_teacher(0), 'Precondition: teacher of no option at all.');
        $this->assertNull(
            $this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]),
            'The capability opens the session teachers form instance wide, without any ownership check.'
        );
        $this->assertNotEmpty($user->id);
    }

    /**
     * It is not a reduced variant of the option form: editoptions.php does not
     * query it at all, so it stays closed even for the option the user teaches.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_it_does_not_open_the_option_form(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:limitededitownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(booking_check_if_teacher((int)$own->id), 'Precondition: teacher of the option.');
        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, (int)$own->id),
            'limitededitownoption grants no access to the booking option form.'
        );
        $this->assertFalse(option_edit_access::can_edit_option($cmid, 0));
    }

    /**
     * It is accepted by the collective gate of the form webservices.
     *
     * @covers \mod_booking\permissions::has_any_booking_editing_capability
     */
    public function test_it_is_accepted_by_the_collective_webservice_gate(): void {
        $this->create_option();

        $this->user_with([]);
        $this->assertFalse(permissions::has_any_booking_editing_capability());

        $this->user_with(['mod/booking:limitededitownoption']);
        $this->assertTrue(permissions::has_any_booking_editing_capability());
    }

    /**
     * With the standard roles the capability is inert: all its call sites OR
     * in addeditownoption (and updatebooking), which editingteacher and
     * manager hold by default anyway. It only becomes relevant for roles
     * without those capabilities.
     *
     * @covers \mod_booking\form\editteachersforoptiondate_form::check_access_for_dynamic_submission
     * @covers \mod_booking\permissions::has_any_booking_editing_capability
     */
    public function test_it_adds_nothing_to_roles_holding_addeditownoption(): void {
        $this->create_option();
        $cmid = (int)$this->settings->cmid;

        // The capability addeditownoption alone already opens both call sites.
        $this->user_with(['mod/booking:addeditownoption']);
        $this->assertNull($this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]));
        $this->assertTrue(permissions::has_any_booking_editing_capability());

        // Adding limitededitownoption changes nothing for such a role.
        $this->user_with(['mod/booking:addeditownoption', 'mod/booking:limitededitownoption']);
        $this->assertNull($this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]));
        $this->assertTrue(permissions::has_any_booking_editing_capability());
    }
}
