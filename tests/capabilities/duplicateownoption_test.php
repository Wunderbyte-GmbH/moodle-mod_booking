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
 * Tests for mod/booking:duplicateownoption (MUSI-897).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\option_form;
use mod_booking\local\option_edit_access;
use mod_booking\settings\optionformconfig\optionformconfig_info;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\capability_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * MUSI-897: teachers and responsible contacts may duplicate the options they
 * are assigned to. Modelled on mod/booking:addeditownoption - the capability
 * only takes effect together with booking_check_if_teacher() on the option
 * that is copied.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class duplicateownoption_test extends capability_testcase {
    /**
     * The capability is defined on the module level and allowed for the same
     * archetypes as addeditownoption.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_capability_is_defined_like_addeditownoption(): void {
        $this->create_option();

        $this->assert_capability_default(
            'mod/booking:duplicateownoption',
            CONTEXT_MODULE,
            ['editingteacher', 'manager']
        );
    }

    /**
     * The acceptance scenario: a user holding duplicateownoption cannot
     * duplicate an option they are not assigned to, and can duplicate it as
     * soon as they are entered as teacher of that option.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_duplicate_gate_checks_ownership(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:duplicateownoption']);

        // Not a teacher of the option yet - duplicating is refused.
        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, -1, (int)$own->id),
            'Without being assigned to the option duplicating must be refused.'
        );

        // Now the user is entered as teacher of the option - duplicating works.
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(
            option_edit_access::can_edit_option($cmid, -1, (int)$own->id),
            'The teacher of the option must be able to duplicate it.'
        );
    }

    /**
     * Ownership is checked on the copied option, so being teacher of one
     * option does not open duplicating a foreign one.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_duplicate_gate_is_bound_to_the_copied_option(): void {
        $own = $this->create_option();
        $other = $this->add_option('Option of somebody else');
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(option_edit_access::can_edit_option($cmid, -1, (int)$own->id));
        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, -1, (int)$other->id),
            'duplicateownoption must not open an option the user does not teach.'
        );
    }

    /**
     * The capability does not turn into an editing capability: opening the
     * form on an EXISTING option stays refused for the teacher of that option.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_duplicate_capability_does_not_allow_editing(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, (int)$own->id),
            'duplicateownoption must not grant editing of the existing option.'
        );
    }

    /**
     * Saving the duplicate: the form gate accepts duplicateownoption for a NEW
     * option and keeps refusing an existing one, so the capability cannot be
     * used to submit changes to somebody elses option.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_form_gate_accepts_new_option_only(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        $user = $this->user_with(['mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assert_capability_gate_passed($this->run_form_access_check(
            option_form::class,
            ['cmid' => $cmid, 'optionid' => 0]
        ));

        $this->assert_blocked_by_capability(
            $this->run_form_access_check(
                option_form::class,
                ['cmid' => $cmid, 'optionid' => (int)$own->id]
            ),
            'mod/booking:addeditownoption'
        );
    }

    /**
     * The context menu of the options list: the duplicate entry shows up for
     * the teacher of the option holding only duplicateownoption, while the
     * edit and delete entries stay hidden.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_duplicate_menu_entry_checks_ownership(): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');

        $own = $this->create_option();
        $other = $this->add_option('Option of somebody else');

        $user = $this->user_with(['mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $table = new bookingoptions_wbtable('duplicateownoption_menu');

        $ownaction = $table->col_action((object)['id' => (int)$own->id, 'status' => 0]);
        $this->assertStringContainsString(
            get_string('duplicatebookingoption', 'mod_booking'),
            $ownaction,
            'The teacher of the option must be offered the duplicate entry.'
        );
        $this->assertStringNotContainsString(
            get_string('deletethisbookingoption', 'mod_booking'),
            $ownaction,
            'The destructive delete entry stays reserved for updatebooking.'
        );
        $this->assertStringNotContainsString(
            get_string('editbookingoption', 'mod_booking'),
            $ownaction,
            'Without addeditownoption there must be no edit entry.'
        );

        $otheraction = $table->col_action((object)['id' => (int)$other->id, 'status' => 0]);
        $this->assertStringNotContainsString(
            get_string('duplicatebookingoption', 'mod_booking'),
            $otheraction,
            'A foreign option must not offer the duplicate entry.'
        );
    }

    /**
     * Passing the duplicate gate is not enough to get a usable form: the option
     * form only renders fields for users holding one of the option form profile
     * capabilities (expertoptionform / reducedoptionform1-5). Without one the
     * form comes up empty with error:formcapabilitymissing - this is the same
     * requirement editing has and is independent of duplicateownoption.
     *
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info::return_capability_for_user
     */
    public function test_duplicating_needs_an_option_form_profile(): void {
        $own = $this->create_option();
        $contextid = (int)\context_module::instance((int)$own->cmid)->id;

        // Only duplicateownoption: the gate opens, but no form profile is found.
        $user = $this->user_with(['mod/booking:duplicateownoption']);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $this->assertTrue(option_edit_access::can_edit_option((int)$own->cmid, -1, (int)$own->id));
        $this->assertSame(
            '',
            optionformconfig_info::return_capability_for_user($contextid),
            'Without a form profile capability the option form renders no fields.'
        );

        // With a form profile capability the form has a profile to render.
        $user2 = $this->user_with(['mod/booking:duplicateownoption', 'mod/booking:expertoptionform']);
        $this->make_teacher_of((int)$own->id, (int)$user2->id);
        $this->setUser($user2);

        $this->assertTrue(option_edit_access::can_edit_option((int)$own->cmid, -1, (int)$own->id));
        $this->assertSame(
            'mod/booking:expertoptionform',
            optionformconfig_info::return_capability_for_user($contextid),
            'expertoptionform is what makes the duplicate form usable.'
        );
    }
}
