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
use mod_booking\local\confirmationworkflow\confirmation;
use mod_booking_generator;

/**
 * The trainer confirmation workflow of an option can be enabled by an import.
 *
 * An import (CSV, web service, the test generators with importing=1) only runs the field classes
 * whose class name or alternative import identifier is a column. The trainer workflow governs an
 * option only when confirmationtrainerenabled is stored in its json, so the import must be able
 * to set it through that key.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirmation_trainer_import_test extends advanced_testcase {
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
     * An imported option with the column confirmationtrainerenabled stores it in its json and a
     * bookforothers holder may then confirm its answers; without the column nothing is stored and
     * no workflow is responsible for the option, so the same user is refused.
     *
     * @covers \bookingextension_confirmation_trainer\option\fields\confirmation_trainer
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking::has_capability_to_confirm_booking
     * @covers \mod_booking\local\confirmationworkflow\confirmation::check_confirm_capability
     */
    public function test_import_enables_trainer_workflow(): void {
        $this->setAdminUser();

        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');
        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            set_config('confirmationsupervisorenabled', 0, 'bookingextension_confirmation_supervisor');
        }

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // The approver may confirm (bookforothers) but is no admin (no alwayscanapprove).
        $approver = $this->getDataGenerator()->create_user();
        $approverroleid = create_role('Approver', 'approver', 'Approver with booking capabilities');
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        role_assign($approverroleid, $approver->id, \context_system::instance()->id);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        $imported = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Imported with trainer workflow',
            'importing' => 1,
            'waitforconfirmation' => 1,
            'confirmationtrainerenabled' => 1,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($imported->id);
        $this->assertSame(
            1,
            (int)($settings->jsonobject->confirmationtrainerenabled ?? 0),
            'The import must store the trainer workflow flag in the option json.'
        );
        $this->setUser($approver);
        [$allowed] = confirmation::check_confirm_capability($imported->id, $approver->id, $student->id);
        $this->assertTrue($allowed, 'The trainer workflow must govern the imported option.');
        $this->setAdminUser();

        $plain = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Imported without trainer workflow',
            'importing' => 1,
            'waitforconfirmation' => 1,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($plain->id);
        $this->assertObjectNotHasProperty(
            'confirmationtrainerenabled',
            $settings->jsonobject,
            'Without the column the import must not touch the trainer workflow flag.'
        );
        $this->setUser($approver);
        [$allowed] = confirmation::check_confirm_capability($plain->id, $approver->id, $student->id);
        $this->assertFalse($allowed, 'Without the flag no workflow is responsible for the option.');
    }
}
