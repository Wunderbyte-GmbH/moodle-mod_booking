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
 * Tests for the call sites of mod/booking:addeditownoption and whether they
 * check ownership of the option (capability table 2).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\customfield\booking_handler;
use mod_booking\form\dynamicoptiondateform;
use mod_booking\form\option_form;
use mod_booking\local\option_edit_access;
use mod_booking\output\bookingoption_description;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * addeditownoption is documented as "edit your own options", but only some of
 * its call sites pair it with booking_check_if_teacher(). Every test here uses
 * the SAME user - holding addeditownoption, teacher of NO option - against an
 * option they do not teach, so a passing "no ownership" test means the
 * restriction does not apply at that call site.
 *
 * Call sites without a class seam are not covered here: report.php:1060 (the
 * mailto button, inline in the page script), lib.php:1550/1737 (navigation,
 * display only), the instance wide reports and template pages
 * (teachers_instance_report.php, teacher_performed_units_report.php,
 * instancetemplatessettings.php, bookinginstancetemplatessettings.php,
 * edit_optiontemplates.php, optiondates_teachers_report.php) and the wizard
 * skills, which resolve their context via resolve_option_operating_context().
 * moveoption.php:148 checks the capability per TARGET context, which is the
 * correct behaviour for choosing a move target.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class addeditownoption_callsites_test extends capability_testcase {
    /**
     * editoptions.php: ownership IS checked - the form opens for the option
     * the user teaches and stays closed for the other one.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_option_form_gate_checks_ownership(): void {
        [$own, $other, $user] = $this->create_owner_and_foreign_option();
        $cmid = (int)$own->cmid;

        $this->assertTrue(option_edit_access::can_edit_option($cmid, (int)$own->id));
        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, (int)$other->id),
            'editoptions.php pairs addeditownoption with booking_check_if_teacher().'
        );
        $this->assertNotEmpty($user->id);
    }

    /**
     * The edit menu of the options list: ownership IS checked - only the own
     * option offers the link to the option form.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_options_list_edit_menu_checks_ownership(): void {
        [$own, $other] = $this->create_owner_and_foreign_option();

        $table = new bookingoptions_wbtable('capcolaction');
        $this->assertStringContainsString(
            'editoptions.php',
            $table->col_action((object)['id' => (int)$own->id])
        );
        $this->assertStringNotContainsString(
            'editoptions.php',
            $table->col_action((object)['id' => (int)$other->id]),
            'The edit menu pairs addeditownoption with booking_check_if_teacher().'
        );
    }

    /**
     * The detail view of an option: ownership IS checked for both the edit
     * link and the "manage bookings" link.
     *
     * @covers \mod_booking\output\bookingoption_description::export_for_template
     */
    public function test_option_detail_view_checks_ownership(): void {
        global $PAGE;

        [$own, $other] = $this->create_owner_and_foreign_option();
        $output = $PAGE->get_renderer('mod_booking');

        $owndata = (new bookingoption_description((int)$own->id))->export_for_template($output);
        $this->assertNotEmpty($owndata['editurl']);

        $otherdata = (new bookingoption_description((int)$other->id))->export_for_template($output);
        $this->assertEmpty(
            $otherdata['editurl'],
            'The detail view pairs addeditownoption with booking_check_if_teacher().'
        );
    }

    /**
     * The dynamic option form: ownership is NOT checked. The AJAX endpoint
     * behind the very same form accepts the option the user does not teach,
     * so the "own options only" restriction of editoptions.php can be
     * circumvented through it.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_dynamic_option_form_does_not_check_ownership(): void {
        [$own, $other] = $this->create_owner_and_foreign_option();
        $cmid = (int)$own->cmid;

        $this->assertNull(
            $this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$other->id]),
            'option_form only checks the capability, never booking_check_if_teacher().'
        );
        $this->assertNull(
            $this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$own->id])
        );

        // Without the capability the form is closed for everybody.
        $this->user_with([]);
        $this->assertNotNull(
            $this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$own->id])
        );
    }

    /**
     * The session dates form: ownership is NOT checked either.
     *
     * @covers \mod_booking\form\dynamicoptiondateform::check_access_for_dynamic_submission
     */
    public function test_dynamic_optiondate_form_does_not_check_ownership(): void {
        [$own, $other] = $this->create_owner_and_foreign_option();
        $cmid = (int)$own->cmid;

        $this->assertNull(
            $this->run_form_access_check(dynamicoptiondateform::class, [
                'cmid' => $cmid,
                'optionid' => (int)$other->id,
            ]),
            'dynamicoptiondateform only requires the capability.'
        );

        $this->user_with([]);
        $this->assertNotNull(
            $this->run_form_access_check(dynamicoptiondateform::class, [
                'cmid' => $cmid,
                'optionid' => (int)$other->id,
            ])
        );
    }

    /**
     * Configuring the custom field categories of the instance: ownership is
     * NOT checked - and the check runs in the system context, so the
     * capability opens an instance wide (in fact site wide) feature.
     *
     * @covers \mod_booking\customfield\booking_handler::can_configure
     */
    public function test_customfield_configuration_does_not_check_ownership(): void {
        $this->create_option();

        $this->user_with([]);
        $this->assertFalse(booking_handler::create()->can_configure());

        // Teacher of no option at all, capability assigned globally.
        $this->user_with(['mod/booking:addeditownoption'], \context_system::instance());
        $this->assertFalse(booking_check_if_teacher(0), 'Precondition: teacher of no option.');
        $this->assertTrue(
            booking_handler::create()->can_configure(),
            'Custom field configuration needs no ownership relation at all.'
        );
    }

    /**
     * The collective webservice gate: no ownership check by design - it only
     * answers whether the user may edit options ANYWHERE, because the context
     * of the form element being loaded is unknown.
     *
     * @covers \mod_booking\permissions::has_capability_anywhere
     */
    public function test_webservice_gate_does_not_check_ownership(): void {
        $this->create_option();

        $this->user_with([]);
        $this->assertFalse(permissions::has_capability_anywhere('mod/booking:addeditownoption'));

        $this->user_with(['mod/booking:addeditownoption']);
        $this->assertTrue(
            permissions::has_capability_anywhere('mod/booking:addeditownoption'),
            'The gate accepts the capability in any module context, ownership aside.'
        );
    }

    /**
     * Helper: an option the user teaches, an option they do not teach, and
     * the logged in user holding only addeditownoption.
     *
     * @return array{0: booking_option_settings, 1: booking_option_settings, 2: \stdClass}
     */
    private function create_owner_and_foreign_option(): array {
        $own = $this->create_option();
        $other = $this->add_option('Option of somebody else');

        $user = $this->user_with(['mod/booking:addeditownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        return [$own, $other, $user];
    }
}
