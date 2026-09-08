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
 * Tests for the mod/booking:cancelownoption capability on the cancel-booking-option
 * confirm modal.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\modal_confirmcancel;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\teachers_handler;
use mod_booking_generator;
use context_course;
use context_module;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * MUSI-898: mod/booking:cancelownoption lets a teacher, responsible contact, or
 * creator cancel a booking option they are assigned to, following the same
 * pattern as mod/booking:addeditownoption (has_capability + booking_check_if_teacher()).
 *
 * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
 */
final class modal_confirmcancel_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Helper: course + booking instance + a role granting ONLY cancelownoption
     * (explicitly NOT updatebooking), assigned to $user.
     *
     * @param object $user
     * @return array [$course, $booking, $context]
     */
    private function create_setup_with_cancelownoption(object $user): array {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Cancel permission test booking',
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
            'course' => $course->id,
            'bookingmanager' => $user->username,
        ]);
        $context = context_module::instance($booking->cmid);

        $roleid = create_role('OptionCancelOwn', 'optioncancelown', 'Can cancel own booking options');
        assign_capability('mod/booking:cancelownoption', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return [$course, $booking, $context];
    }

    /**
     * Helper: create a plain booking option as admin.
     *
     * @param int $bookingid
     * @return object
     */
    private function create_option(int $bookingid): object {
        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = 'Option to cancel';
        $record->useprice = 0;
        $record->maxanswers = 5;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        return $plugingenerator->create_option($record);
    }

    /**
     * Helper: instantiate the confirm-cancel dynamic form as an ajax submission,
     * exactly like a real modal submit. The dynamic_form constructor runs the
     * access check for ajax submissions.
     *
     * @param int $optionid
     * @param int $status
     * @return modal_confirmcancel
     */
    private function build_form(int $optionid, int $status = 0): modal_confirmcancel {
        $ajaxargs = [
            'optionid' => $optionid,
            'status' => $status,
            'cancelreason' => 'Test cancel reason',
        ];
        $submitdata = modal_confirmcancel::mock_ajax_submit($ajaxargs);
        return new modal_confirmcancel(null, null, 'post', '', [], true, $submitdata, true);
    }

    /**
     * A user with only mod/booking:cancelownoption, who is neither teacher,
     * responsible contact, nor creator of the option, cannot cancel it.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     */
    public function test_non_teacher_with_cancelownoption_cannot_cancel(): void {
        $user = $this->getDataGenerator()->create_user();
        [, $booking] = $this->create_setup_with_cancelownoption($user);

        $option = $this->create_option($booking->id);

        $this->setUser($user);
        $this->assertFalse(booking_check_if_teacher($option->id, $user->id));

        $this->expectException(required_capability_exception::class);
        $this->build_form($option->id);
    }

    /**
     * Once the same user is assigned as teacher of the option (booking_teachers),
     * the very same action succeeds and actually cancels the option. This is the
     * acceptance scenario from MUSI-898: try to cancel -> fails -> assign as
     * teacher -> succeeds.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     * @covers \mod_booking\form\modal_confirmcancel::process_dynamic_submission
     */
    public function test_teacher_with_cancelownoption_can_cancel(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user();
        [$course, $booking] = $this->create_setup_with_cancelownoption($teacher);

        $option = $this->create_option($booking->id);

        // Confirm cancelling still fails before the teacher assignment.
        $this->setUser($teacher);
        $this->assertFalse(booking_check_if_teacher($option->id, $teacher->id));
        try {
            $this->build_form($option->id);
            $this->fail('Cancelling must fail before the user is assigned as teacher.');
        } catch (required_capability_exception $e) {
            $this->assertInstanceOf(required_capability_exception::class, $e);
        }

        // Now assign the user as teacher of this specific option.
        $this->setAdminUser();
        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacherhandler = new teachers_handler($option->id);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $cm->id, $group->id);
        booking_option::purge_cache_for_option($option->id);

        $this->setUser($teacher);
        $this->assertTrue(booking_check_if_teacher($option->id, $teacher->id));

        $form = $this->build_form($option->id, 0);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();

        $this->assertEquals(1, $DB->get_field('booking_options', 'status', ['id' => $option->id]));
    }

    /**
     * The option's creator (usercreated) counts as "own option" too, same as
     * for mod/booking:addeditownoption.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     */
    public function test_creator_with_cancelownoption_can_cancel(): void {
        global $DB;

        $creator = $this->getDataGenerator()->create_user();
        [, $booking] = $this->create_setup_with_cancelownoption($creator);

        $option = $this->create_option($booking->id);
        $DB->set_field('booking_options', 'usercreated', $creator->id, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);

        $this->setUser($creator);
        $this->assertTrue(booking_check_if_teacher($option->id, $creator->id));

        $form = $this->build_form($option->id, 0);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();

        $this->assertEquals(1, $DB->get_field('booking_options', 'status', ['id' => $option->id]));
    }

    /**
     * A user with mod/booking:updatebooking can cancel any option regardless
     * of teacher/creator status - unchanged existing behaviour.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     */
    public function test_user_with_updatebooking_can_cancel_any_option(): void {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Cancel permission test booking 2',
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
            'course' => $course->id,
            'bookingmanager' => $manager->username,
        ]);
        $context = context_module::instance($booking->cmid);

        $roleid = create_role('BookingManager2', 'bookingmanager2', 'Can cancel any booking option');
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $manager->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $option = $this->create_option($booking->id);

        $this->setUser($manager);
        $this->assertFalse(booking_check_if_teacher($option->id, $manager->id));

        $form = $this->build_form($option->id, 0);
        $this->assertTrue($form->is_validated());
    }

    /**
     * The realistic minimal-role scenario: a role holding nothing but
     * moodle/course:view, mod/booking:view and mod/booking:cancelownoption -
     * in particular NEITHER mod/booking:updatebooking NOR
     * mod/booking:addeditownoption - is enough to cancel an option, both for
     * the user assigned as teacher and for the user assigned as responsible
     * contact of that option.
     *
     * @covers \mod_booking\form\modal_confirmcancel::check_access_for_dynamic_submission
     * @covers \mod_booking\form\modal_confirmcancel::process_dynamic_submission
     */
    public function test_teacher_and_responsiblecontact_with_minimal_role_can_cancel(): void {
        global $DB;

        // Responsible contacts only count as "own option" when this setting is on.
        set_config('responsiblecontactcanedit', 1, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $bookingmanager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Minimal role cancel test booking',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);
        $context = context_module::instance($booking->cmid);

        // The role holds exactly these three capabilities and nothing else.
        $roleid = create_role('teacher or responsible', 'teacherorresponsible', 'May cancel own booking options');
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('mod/booking:view', CAP_ALLOW, $roleid, $coursecontext->id, true);
        assign_capability('mod/booking:cancelownoption', CAP_ALLOW, $roleid, $coursecontext->id, true);

        // Two separate users holding that role. They are deliberately NOT enrolled,
        // moodle/course:view is what lets them see the course.
        $teacher = $this->getDataGenerator()->create_user();
        $responsible = $this->getDataGenerator()->create_user();
        role_assign($roleid, $teacher->id, $coursecontext->id);
        role_assign($roleid, $responsible->id, $coursecontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        $option = $this->create_option($booking->id);

        // One user is teacher of the option, the other its responsible contact.
        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacherhandler = new teachers_handler($option->id);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $cm->id, $group->id);
        $DB->set_field('booking_options', 'responsiblecontact', (string)$responsible->id, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();

        // The teacher cancels the option with nothing but the minimal role.
        $this->setUser($teacher);
        $this->assertTrue(has_capability('mod/booking:cancelownoption', $context));
        $this->assertFalse(has_capability('mod/booking:updatebooking', $context));
        $this->assertFalse(has_capability('mod/booking:addeditownoption', $context));
        $this->assertTrue(booking_check_if_teacher($option->id, $teacher->id));

        $form = $this->build_form($option->id, 0);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();
        $this->assertEquals(1, $DB->get_field('booking_options', 'status', ['id' => $option->id]));

        // Undo the cancellation, so the responsible contact can cancel it as well.
        $DB->set_field('booking_options', 'status', 0, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();

        // The responsible contact cancels the same option with the same role.
        $this->setUser($responsible);
        $this->assertTrue(has_capability('mod/booking:cancelownoption', $context));
        $this->assertFalse(has_capability('mod/booking:updatebooking', $context));
        $this->assertFalse(has_capability('mod/booking:addeditownoption', $context));
        $this->assertTrue(booking_check_if_teacher($option->id, $responsible->id));

        $form = $this->build_form($option->id, 0);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();
        $this->assertEquals(1, $DB->get_field('booking_options', 'status', ['id' => $option->id]));
    }

    /**
     * The context menu entry itself: mod/booking:cancelownoption is independent
     * of mod/booking:addeditownoption, so a teacher of the option holding only
     * cancelownoption gets the "cancel this booking option" entry rendered -
     * while the edit entry and the destructive delete entry stay hidden.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_cancel_menu_entry_is_independent_of_addeditownoption(): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');

        $teacher = $this->getDataGenerator()->create_user();
        [$course, $booking, $context] = $this->create_setup_with_cancelownoption($teacher);

        $option = $this->create_option($booking->id);

        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $teacherhandler = new teachers_handler($option->id);
        $teacherhandler->subscribe_teacher_to_booking_option($teacher->id, $option->id, $cm->id, $group->id);
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();

        $this->setUser($teacher);
        $this->assertFalse(has_capability('mod/booking:addeditownoption', $context));
        $this->assertFalse(has_capability('mod/booking:updatebooking', $context));

        $table = new bookingoptions_wbtable('cancelownoption_menu');
        $result = $table->col_action((object)['id' => $option->id, 'status' => 0]);

        $this->assertStringContainsString(
            get_string('cancelthisbookingoption', 'mod_booking'),
            $result,
            'The cancel entry must be shown even without addeditownoption.'
        );
        $this->assertStringNotContainsString(
            get_string('deletethisbookingoption', 'mod_booking'),
            $result,
            'The destructive delete entry stays reserved for updatebooking.'
        );
        $this->assertStringNotContainsString(
            get_string('editbookingoption', 'mod_booking'),
            $result,
            'Without addeditownoption there must be no edit entry.'
        );
    }

    /**
     * Counter-check for the menu entry: holding cancelownoption without being
     * teacher, responsible contact or creator of the option shows no cancel entry.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_cancel_menu_entry_hidden_for_non_teacher(): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');

        $user = $this->getDataGenerator()->create_user();
        [, $booking] = $this->create_setup_with_cancelownoption($user);

        $option = $this->create_option($booking->id);

        $this->setUser($user);
        $this->assertFalse(booking_check_if_teacher($option->id, $user->id));

        $table = new bookingoptions_wbtable('cancelownoption_menu_negative');
        $result = $table->col_action((object)['id' => $option->id, 'status' => 0]);

        $this->assertStringNotContainsString(
            get_string('cancelthisbookingoption', 'mod_booking'),
            $result,
            'Without being assigned to the option there must be no cancel entry.'
        );
    }
}
