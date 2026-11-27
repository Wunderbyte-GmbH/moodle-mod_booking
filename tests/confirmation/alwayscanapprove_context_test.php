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

use advanced_testcase;
use context_coursecat;
use context_module;
use context_system;
use mod_booking\local\confirmationworkflow\confirmation;
use mod_booking_generator;

/**
 * The capability mod/booking:alwayscanapprove is checked in the module context of the option.
 *
 * A role assigned on a course category must be able to confirm the answers of the options in this
 * category - and only of these. Without an option the check falls back to the system context.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class alwayscanapprove_context_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Creates a course in the given category with a booking instance and an option which needs
     * confirmation, but uses no confirmation workflow.
     *
     * @param int $categoryid
     * @return \stdClass the booking option
     */
    private function create_option_in_category(int $categoryid): \stdClass {
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        return $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option in category ' . $categoryid,
            'importing' => 1,
            'waitforconfirmation' => 1,
        ]);
    }

    /**
     * A role with alwayscanapprove assigned on a course category confirms the answers of the
     * options in this category, but not those of other categories.
     *
     * @covers \mod_booking\local\confirmationworkflow\confirmation::can_always_approve
     * @covers \mod_booking\local\confirmationworkflow\confirmation::get_context_for_option
     * @covers \mod_booking\local\confirmationworkflow\confirmation::check_confirm_capability
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking::has_capability_to_confirm_booking
     */
    public function test_category_role_can_approve_only_in_its_category(): void {
        $this->setAdminUser();

        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');
        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            set_config('confirmationsupervisorenabled', 0, 'bookingextension_confirmation_supervisor');
        }

        $owncategory = $this->getDataGenerator()->create_category();
        $othercategory = $this->getDataGenerator()->create_category();

        $ownoption = $this->create_option_in_category((int)$owncategory->id);
        $otheroption = $this->create_option_in_category((int)$othercategory->id);

        $student = $this->getDataGenerator()->create_user();

        $approver = $this->getDataGenerator()->create_user();
        $roleid = create_role('Category approver', 'categoryapprover', 'May always approve in a category');
        assign_capability('mod/booking:alwayscanapprove', CAP_ALLOW, $roleid, SYSCONTEXTID, true);
        role_assign($roleid, $approver->id, context_coursecat::instance($owncategory->id)->id);

        $this->setUser($approver);

        $ownsettings = singleton_service::get_instance_of_booking_option_settings($ownoption->id);
        $this->assertSame(
            context_module::instance($ownsettings->cmid)->id,
            confirmation::get_context_for_option($ownoption->id)->id,
            'The context of an option must be its module context.'
        );

        $this->assertTrue(
            confirmation::can_always_approve($ownoption->id),
            'A category role must be able to approve the options of its category.'
        );
        [$allowed] = confirmation::check_confirm_capability($ownoption->id, $approver->id, $student->id);
        $this->assertTrue($allowed, 'The confirm action must be granted in the own category.');

        $this->assertFalse(
            confirmation::can_always_approve($otheroption->id),
            'A category role must not approve the options of another category.'
        );
        [$allowed] = confirmation::check_confirm_capability($otheroption->id, $approver->id, $student->id);
        $this->assertFalse($allowed, 'The confirm action must be refused in another category.');
    }

    /**
     * Without an option (or with an option which does not exist) the check falls back to the
     * system context: a category role is refused, a system role is accepted everywhere.
     *
     * @covers \mod_booking\local\confirmationworkflow\confirmation::can_always_approve
     * @covers \mod_booking\local\confirmationworkflow\confirmation::get_context_for_option
     */
    public function test_fallback_to_system_context(): void {
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $option = $this->create_option_in_category((int)$category->id);

        $roleid = create_role('Approver', 'alwaysapprover', 'May always approve');
        assign_capability('mod/booking:alwayscanapprove', CAP_ALLOW, $roleid, SYSCONTEXTID, true);

        $categoryapprover = $this->getDataGenerator()->create_user();
        role_assign($roleid, $categoryapprover->id, context_coursecat::instance($category->id)->id);

        $systemapprover = $this->getDataGenerator()->create_user();
        role_assign($roleid, $systemapprover->id, context_system::instance()->id);

        $this->assertSame(
            context_system::instance()->id,
            confirmation::get_context_for_option(0)->id,
            'Without an option the system context must be used.'
        );
        $this->assertSame(
            context_system::instance()->id,
            confirmation::get_context_for_option(999999999)->id,
            'An option which does not exist must fall back to the system context.'
        );

        $this->setUser($categoryapprover);
        $this->assertFalse(
            confirmation::can_always_approve(),
            'A category role must not pass the check without an option.'
        );

        $this->setUser($systemapprover);
        $this->assertTrue(
            confirmation::can_always_approve(),
            'A system role must pass the check without an option.'
        );
        $this->assertTrue(
            confirmation::can_always_approve($option->id),
            'A system role must still approve every option.'
        );
    }
}
