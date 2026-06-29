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
 * Tests for booking option permission checks (creator-based editing).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\teachers_handler;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests that creators of booking options can edit their own options
 * with the mod/booking:addeditownoption capability.
 *
 * @covers \booking_check_if_teacher
 */
final class booking_option_permission_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Helper to create a course, booking instance, and enrol a user.
     *
     * @param object $user
     * @return array [$course, $booking, $context]
     */
    private function create_booking_setup(object $user): array {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $bdata = [
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy',
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $user->username,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $context = context_module::instance($booking->cmid);

        return [$course, $booking, $context];
    }

    /**
     * Helper to create a booking option as admin, then set usercreated to simulate
     * a specific user having created it. This is needed because prepare_save_fields()
     * checks capabilities per field and non-admin users cannot populate all required fields.
     *
     * @param object $creator The user to record as the creator.
     * @param int $bookingid
     * @param int $courseid
     * @return object The created option record.
     */
    private function create_option_as_user(object $creator, int $bookingid, int $courseid): object {
        global $DB;

        // Create option as admin to ensure all required fields are populated.
        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = 'Option by ' . $creator->username;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $courseid;
        $record->description = 'Test description';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Set usercreated and usermodified to the intended creator.
        $DB->set_field('booking_options', 'usercreated', $creator->id, ['id' => $option->id]);
        $DB->set_field('booking_options', 'usermodified', $creator->id, ['id' => $option->id]);

        // Purge Moodle cache and singletons so the new usercreated value is loaded from DB.
        booking_option::purge_cache_for_option($option->id);

        return $option;
    }

    /**
     * Test that a creator with mod/booking:addeditownoption can still edit
     * the booking option after saving (without mod/booking:updatebooking).
     *
     * @covers \booking_check_if_teacher
     */
    public function test_creator_can_edit_own_option_with_addeditownoption(): void {
        global $DB;

        // Create user and setup.
        $creator = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($creator);

        // Give ONLY addeditownoption, explicitly NOT updatebooking.
        $roleid = create_role('OptionCreator', 'optioncreator', 'Can create and edit own options');
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $creator->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Verify user does NOT have updatebooking.
        $this->setUser($creator);
        $this->assertFalse(has_capability('mod/booking:updatebooking', $context));
        $this->assertTrue(has_capability('mod/booking:addeditownoption', $context));

        // Create option as this user.
        $option = $this->create_option_as_user($creator, $booking->id, $course->id);

        // Verify usercreated is set correctly.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals($creator->id, $dbrecord->usercreated);
        $this->assertEquals($creator->id, $dbrecord->usermodified);

        // Verify creator can edit (booking_check_if_teacher returns true for creator).
        $this->setUser($creator);
        $this->assertTrue(booking_check_if_teacher($option->id, $creator->id));
    }

    /**
     * Test that a creator who loses mod/booking:addeditownoption can no
     * longer edit the booking option.
     *
     * @covers \booking_check_if_teacher
     */
    public function test_creator_cannot_edit_after_capability_revoked(): void {
        global $DB;

        // Create user and setup.
        $creator = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($creator);

        // Give addeditownoption.
        $roleid = create_role('OptionCreator', 'optioncreator', 'Can create and edit own options');
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $creator->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Create option as this user.
        $option = $this->create_option_as_user($creator, $booking->id, $course->id);

        // Verify the user can edit (sanity check).
        $this->setUser($creator);
        $this->assertTrue(has_capability('mod/booking:addeditownoption', $context));
        $this->assertTrue(booking_check_if_teacher($option->id, $creator->id));

        // Now revoke the capability.
        unassign_capability('mod/booking:addeditownoption', $roleid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Verify capability is gone.
        $this->assertFalse(has_capability('mod/booking:addeditownoption', $context));

        // The booking_check_if_teacher still returns true (creator check),
        // but the full permission check requires BOTH capability AND teacher/creator check.
        // So the user should NOT be able to edit because capability is missing.
        // Simulate the editoptions.php permission check.
        $canedit = has_capability('mod/booking:updatebooking', $context)
            || (has_capability('mod/booking:addeditownoption', $context)
                && booking_check_if_teacher($option->id, $creator->id));
        $this->assertFalse($canedit);
    }

    /**
     * Test that a user with mod/booking:addeditownoption cannot edit an
     * option they did not create and are not a teacher of.
     *
     * @covers \booking_check_if_teacher
     */
    public function test_user_cannot_edit_option_not_created_and_not_teacher(): void {
        global $DB;

        // Create two users.
        $creator = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        [$course, $booking, $context] = $this->create_booking_setup($creator);

        // Enrol both users.
        $this->getDataGenerator()->enrol_user($otheruser->id, $course->id);

        // Give addeditownoption to BOTH users.
        $roleid = create_role('OptionCreator', 'optioncreator', 'Can create and edit own options');
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $creator->id, $context->id);
        role_assign($roleid, $otheruser->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Create option as "creator" user.
        $option = $this->create_option_as_user($creator, $booking->id, $course->id);

        // Verify usercreated is set to creator.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals($creator->id, $dbrecord->usercreated);

        // Now switch to "otheruser" — has the capability but did NOT create the option
        // and is NOT a teacher of the option.
        $this->setUser($otheruser);
        $this->assertTrue(has_capability('mod/booking:addeditownoption', $context));
        $this->assertFalse(has_capability('mod/booking:updatebooking', $context));

        // The booking_check_if_teacher should return false (not teacher, not creator).
        $this->assertFalse(booking_check_if_teacher($option->id, $otheruser->id));

        // Full permission check should deny access.
        $canedit = has_capability('mod/booking:updatebooking', $context)
            || (has_capability('mod/booking:addeditownoption', $context)
                && booking_check_if_teacher($option->id, $otheruser->id));
        $this->assertFalse($canedit);
    }

    /**
     * Test that a teacher (non-creator) can still edit an option with
     * mod/booking:addeditownoption. Regression test for existing teacher logic.
     *
     * @covers \booking_check_if_teacher
     */
    public function test_teacher_noncreator_can_edit_with_addeditownoption(): void {
        global $DB;

        $admin = get_admin();
        $teacher = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($teacher);

        // Give teacher only addeditownoption.
        $roleid = create_role('OptionTeacher', 'optionteacher', 'Teacher who can edit own options');
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $teacher->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Create option as admin (teacher is NOT the creator).
        $option = $this->create_option_as_user($admin, $booking->id, $course->id);

        // Verify teacher is NOT the creator.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertNotEquals($teacher->id, $dbrecord->usercreated);

        // Assign teacher to this option via booking_teachers table.
        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $teacherhandler = new teachers_handler($option->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $cm->id, $group->id);

        // Purge Moodle cache and singletons so teacherids are refreshed.
        booking_option::purge_cache_for_option($option->id);

        // Teacher (non-creator) should be able to edit.
        $this->setUser($teacher);
        $this->assertTrue(booking_check_if_teacher($option->id, $teacher->id));

        $canedit = has_capability('mod/booking:updatebooking', $context)
            || (has_capability('mod/booking:addeditownoption', $context)
                && booking_check_if_teacher($option->id, $teacher->id));
        $this->assertTrue($canedit);
    }

    /**
     * Test that usermodified is updated on edit but usercreated stays unchanged.
     *
     * @covers \mod_booking\booking_option::update
     */
    public function test_usermodified_updated_on_edit_usercreated_unchanged(): void {
        global $DB;

        $creator = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($creator);

        // Create option as "creator".
        $option = $this->create_option_as_user($creator, $booking->id, $course->id);

        // Verify initial state.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals($creator->id, $dbrecord->usercreated);
        $this->assertEquals($creator->id, $dbrecord->usermodified);

        // Now edit the option as admin (different user than creator).
        $admin = get_admin();
        $this->setAdminUser();
        $updatedata = new stdClass();
        $updatedata->id = $option->id;
        $updatedata->cmid = $option->cmid;
        $updatedata->bookingid = $booking->id;
        $updatedata->text = 'Updated by admin';
        $updatedata->description = 'Updated description';
        $updatedata->importing = true;
        booking_option::update($updatedata, $context);

        // Verify usercreated is unchanged, usermodified is updated to admin.
        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertEquals($creator->id, $dbrecord->usercreated);
        $this->assertEquals($admin->id, $dbrecord->usermodified);
    }

    /**
     * Test that legacy options with usercreated=0 cannot be edited by
     * arbitrary users (guard against matching userid 0).
     *
     * @covers \booking_check_if_teacher
     */
    public function test_legacy_option_without_usercreated_not_editable(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($user);

        // Give user addeditownoption.
        $roleid = create_role('OptionCreator', 'optioncreator', 'Can create and edit own options');
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Create option as admin.
        $option = $this->create_option_as_user(get_admin(), $booking->id, $course->id);

        // Simulate legacy data: set usercreated to 0 (as if from before upgrade).
        $DB->set_field('booking_options', 'usercreated', 0, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);

        // User should NOT be able to edit this legacy option.
        $this->setUser($user);
        $this->assertFalse(booking_check_if_teacher($option->id, $user->id));
    }

    /**
     * Test that a user with mod/booking:updatebooking can edit any option
     * regardless of creator or teacher status.
     *
     * @covers \booking_check_if_teacher
     */
    public function test_user_with_updatebooking_can_edit_any_option(): void {
        global $DB;

        $creator = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_booking_setup($creator);
        $this->getDataGenerator()->enrol_user($manager->id, $course->id);

        // Give manager only updatebooking.
        $managerroleid = create_role('BookingManager', 'bookingmanager', 'Can edit all options');
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $managerroleid, $context->id, true);
        role_assign($managerroleid, $manager->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Create option as "creator" — manager is NOT the creator and NOT a teacher.
        $option = $this->create_option_as_user($creator, $booking->id, $course->id);

        $dbrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        $this->assertNotEquals($manager->id, $dbrecord->usercreated);

        // Manager can edit anyway via updatebooking.
        $this->setUser($manager);
        $this->assertTrue(has_capability('mod/booking:updatebooking', $context));

        $canedit = has_capability('mod/booking:updatebooking', $context)
            || (has_capability('mod/booking:addeditownoption', $context)
                && booking_check_if_teacher($option->id, $manager->id));
        $this->assertTrue($canedit);
    }
}
