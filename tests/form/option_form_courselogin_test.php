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
 * Tests for the dynamic submission of the option form without course enrolment.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\form\option_form;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use require_login_exception;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Every action in the option form after the first render (add date, delete date, save) is an ajax call
 * to core_form_dynamic_form, which validates the context of the form with require_login().
 * If the setting "editoptionsrequirecourselogin" is disabled, a site login has to be enough there, too.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_form_courselogin_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * A teacher of the option who is not enrolled in the course can add a date and submit the form.
     *
     * @covers \mod_booking\form\option_form::get_context_for_dynamic_submission
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_teacher_without_enrolment_can_use_form(): void {
        global $PAGE;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $this->setUser($teacher);

        // No submit button "add date".
        $form = $this->create_ajax_form($settings, ['adddatebutton' => 1]);
        // Like core_form\external\dynamic_form::execute(): the no submit buttons are registered in definition_after_data().
        $form->set_data_for_dynamic_submission();
        $this->assertFalse($form->is_validated());
        // Do not assert no_submit_button_pressed(): core caches its result in a static for the whole process.
        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);

        // Save.
        $PAGE = new \moodle_page();
        $this->create_ajax_form($settings, ['text' => 'Changed title']);
        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);
    }

    /**
     * With the default setting, the course login is still required for the ajax calls of the form.
     *
     * @covers \mod_booking\form\option_form::get_context_for_dynamic_submission
     */
    public function test_course_login_required_by_default(): void {
        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        $this->setUser($teacher);

        $this->expectException(require_login_exception::class);
        $this->create_ajax_form($settings, ['adddatebutton' => 1]);
    }

    /**
     * Without course login, users who may edit their own options can not edit options of others.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_other_user_without_enrolment_can_not_edit_option(): void {
        [$settings, , $roleid] = $this->create_option_with_teacher_without_enrolment();
        set_config('editoptionsrequirecourselogin', 0, 'booking');

        $otheruser = $this->getDataGenerator()->create_user();
        role_assign($roleid, $otheruser->id, context_system::instance()->id);
        $this->setUser($otheruser);

        $this->expectException(required_capability_exception::class);
        $this->create_ajax_form($settings, ['text' => 'Changed title']);
    }

    /**
     * The client must not save another option than the one the access was checked for.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_tampered_id_is_rejected(): void {
        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        $otheroption = $this->create_option((int)$settings->bookingid);
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $this->setUser($teacher);

        // The teacher's own option passes the teacher check, but "id" is the option which would be saved.
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidcontext', 'error'));
        $this->create_ajax_form($settings, ['id' => (int)$otheroption->id, 'text' => 'Changed title']);
    }

    /**
     * Booking instance and copied option sent by the client must belong to the course module.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     * @covers \booking_option_form_ids_match_cm
     */
    public function test_ids_of_other_booking_instance_are_rejected(): void {
        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        $otherbooking = $this->create_booking();
        $otheroption = $this->create_option((int)$otherbooking->id);
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $this->setUser($teacher);

        foreach (
            [
                'bookingid' => ['bookingid' => (int)$otherbooking->id],
                'copyoptionid' => ['copyoptionid' => (int)$otheroption->id],
            ] as $case => $data
        ) {
            try {
                $this->create_ajax_form($settings, $data);
                $this->fail("Tampered $case must be rejected.");
            } catch (moodle_exception $e) {
                $this->assertEquals('invalidcontext', $e->errorcode, "Tampered $case must be rejected.");
            }
        }
    }

    /**
     * Helper: create the option form like core_form\external\dynamic_form::execute() does.
     *
     * @param booking_option_settings $settings
     * @param array $data additional form data
     * @return option_form
     */
    private function create_ajax_form(booking_option_settings $settings, array $data): option_form {
        $formdata = option_form::mock_ajax_submit(array_merge([
            'cmid' => (int)$settings->cmid,
            'id' => (int)$settings->id,
            'optionid' => (int)$settings->id,
            'bookingid' => (int)$settings->bookingid,
        ], $data));
        return new option_form(null, null, 'post', '', [], true, $formdata, true);
    }

    /**
     * Helper: booking option with a teacher who is not enrolled in the course.
     *
     * The teacher gets a system role with mod/booking:addeditownoption and mod/booking:expertoptionform.
     *
     * @return array{0: booking_option_settings, 1: stdClass, 2: int}
     */
    private function create_option_with_teacher_without_enrolment(): array {
        global $DB;

        $this->setAdminUser();

        $option = $this->create_option((int)$this->create_booking()->id);

        $teacher = $this->getDataGenerator()->create_user();
        $systemcontext = context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('mod/booking:expertoptionform', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The teacher created the option, so booking_check_if_teacher() is true for them.
        $DB->set_field('booking_options', 'usercreated', $teacher->id, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);

        $this->assertFalse(is_enrolled(context_module::instance($option->cmid), $teacher), 'Precondition: not enrolled.');

        return [singleton_service::get_instance_of_booking_option_settings($option->id), $teacher, $roleid];
    }

    /**
     * Helper: booking instance in a new course.
     *
     * @return stdClass
     */
    private function create_booking(): stdClass {
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        return $this->getDataGenerator()->create_module('booking', [
            'name' => 'Course login test booking',
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
    }

    /**
     * Helper: booking option in the given booking instance, created as admin.
     *
     * @param int $bookingid
     * @return stdClass
     */
    private function create_option(int $bookingid): stdClass {
        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = 'Option for course login test';
        $record->description = 'Test description';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $option->cmid = singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;
        return $option;
    }
}
