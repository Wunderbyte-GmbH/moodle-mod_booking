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
use moodle_url;
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
     * A teacher of the option who is not enrolled in the course can save a changed date.
     *
     * This test has to run before any test that presses a no submit button (add, apply, delete date):
     * moodleform::no_submit_button_pressed() caches its result in a static for the whole process.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     * @covers \mod_booking\form\option_form::process_dynamic_submission
     * @covers \mod_booking\dates::save_optiondates_from_form
     */
    public function test_teacher_without_enrolment_can_change_date(): void {
        global $DB, $PAGE;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment(true);
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $optiondateid = (int)array_key_first($settings->sessions);
        $this->assertNotEmpty($optiondateid, 'Precondition: the option has a stored date.');
        $this->setUser($teacher);

        $newstart = make_timestamp(2050, 6, 10, 10, 0);
        $newend = make_timestamp(2050, 6, 10, 12, 0);
        $sink = $this->redirectEvents();

        // The identifier has a "required" rule and the autocompletes (teachers, entities) do not export
        // their stored values when they are not submitted, so these fields are submitted like the browser does.
        $submitted = [
            'identifier' => $settings->identifier,
            'teachersforoption' => [(int)$teacher->id],
        ];
        if (class_exists('local_entities\\entitiesrelation_handler')) {
            $submitted[LOCAL_ENTITIES_FORM_ENTITYID . '0'] = '';
            $submitted[LOCAL_ENTITIES_FORM_ENTITYID . '1'] = '';
        }
        $form = $this->create_ajax_form(
            $settings,
            $this->date_form_data($optiondateid, $newstart, $newend, $submitted)
        );
        $form->set_data_for_dynamic_submission();
        $this->assertTrue($form->is_validated(), 'The form with the changed date must validate.');
        $form->process_dynamic_submission();

        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);

        $dates = $DB->get_records('booking_optiondates', ['optionid' => (int)$settings->id]);
        $this->assertCount(1, $dates);
        $date = reset($dates);
        $this->assertEquals($newstart, (int)$date->coursestarttime);
        $this->assertEquals($newend, (int)$date->courseendtime);

        // The teacher stays subscribed to the (changed) date.
        $this->assertTrue($DB->record_exists(
            'booking_optiondates_teachers',
            ['optiondateid' => (int)$date->id, 'userid' => (int)$teacher->id]
        ));

        // The option updated event is triggered in the context of the course module.
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_booking\event\bookingoption_updated);
        $this->assertNotEmpty($events, 'bookingoption_updated must be triggered.');
        $this->assertEquals((int)$settings->cmid, (int)reset($events)->contextinstanceid);
        $sink->close();

        // Still not enrolled: saving the date must not enrol the teacher.
        $this->assertFalse(is_enrolled(context_module::instance($settings->cmid), $teacher));
    }

    /**
     * A teacher who is not enrolled in the course can apply a changed date (no submit button) in the form.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     * @covers \mod_booking\dates::definition_after_data
     */
    public function test_teacher_without_enrolment_can_apply_changed_date(): void {
        global $DB, $PAGE;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment(true);
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $optiondateid = (int)array_key_first($settings->sessions);
        $oldstart = (int)$settings->sessions[$optiondateid]->coursestarttime;
        $this->setUser($teacher);

        $newstart = make_timestamp(2050, 6, 10, 10, 0);
        $newend = make_timestamp(2050, 6, 10, 12, 0);

        $form = $this->create_ajax_form(
            $settings,
            $this->date_form_data($optiondateid, $newstart, $newend, ['applydate_1' => 1])
        );
        $form->set_data_for_dynamic_submission();
        $this->assertFalse($form->is_validated());
        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);

        // The form is rendered again with the date row.
        $html = $form->render();
        $this->assertStringContainsString('coursestarttime_1[', $html);

        // A no submit button saves nothing.
        $this->assertEquals($oldstart, (int)$DB->get_field('booking_optiondates', 'coursestarttime', ['id' => $optiondateid]));
    }

    /**
     * A teacher who is not enrolled in the course can delete a date (no submit button) in the form.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     * @covers \mod_booking\dates::set_data
     */
    public function test_teacher_without_enrolment_can_delete_date(): void {
        global $DB, $PAGE;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment(true);
        set_config('editoptionsrequirecourselogin', 0, 'booking');
        $optiondateid = (int)array_key_first($settings->sessions);
        $session = $settings->sessions[$optiondateid];
        $this->setUser($teacher);

        $form = $this->create_ajax_form(
            $settings,
            $this->date_form_data(
                $optiondateid,
                (int)$session->coursestarttime,
                (int)$session->courseendtime,
                [MOD_BOOKING_FORM_DELETEDATE . '1' => 1]
            )
        );
        $form->set_data_for_dynamic_submission();
        $this->assertFalse($form->is_validated());
        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);

        // The form is rendered again without the date row.
        $html = $form->render();
        $this->assertStringNotContainsString('coursestarttime_1[', $html);

        // A no submit button deletes nothing yet.
        $this->assertTrue($DB->record_exists('booking_optiondates', ['id' => $optiondateid]));
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
     * Without enrolment, editoptions.php must not use the module context as page context.
     *
     * The javascript of the page sends the page context to web services like core_get_user_dates
     * (calendar of the date selectors), which validate it with require_login().
     *
     * @covers \booking_require_editoptions_login
     */
    public function test_page_context_without_enrolment(): void {
        global $PAGE;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        [$course, $cm] = get_course_and_cm_from_cmid((int)$settings->cmid, 'booking');
        $this->setUser($teacher);

        set_config('editoptionsrequirecourselogin', 0, 'booking');

        // Not enrolled: a context the user may access, the course is still set.
        $PAGE = new \moodle_page();
        booking_require_editoptions_login($course, $cm);
        $this->assertEquals(context_system::instance()->id, $PAGE->context->id);
        $this->assertEquals($course->id, $PAGE->course->id);
        // The web service validation of the page context passes for the teacher.
        \core_external\external_api::validate_context($PAGE->context);

        // Enrolled users keep the course module as page context.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $PAGE = new \moodle_page();
        booking_require_editoptions_login($course, $cm);
        $this->assertEquals(context_module::instance($settings->cmid)->id, $PAGE->context->id);
        $this->assertEquals($cm->id, $PAGE->cm->id);
    }

    /**
     * Without course login, the fallback return url of the form must not need a course login either.
     *
     * @covers \booking_editoptions_returnurl
     */
    public function test_returnurl_fallback_without_enrolment(): void {
        global $DB;

        [$settings, $teacher] = $this->create_option_with_teacher_without_enrolment();
        // The course of the booking instance, not the course linked to the option.
        [$course] = get_course_and_cm_from_cmid((int)$settings->cmid, 'booking');
        $viewurl = new moodle_url('/mod/booking/view.php', ['id' => (int)$settings->cmid]);

        // Default setting: always the booking instance, the course login is checked before.
        $this->setUser($teacher);
        $this->assertEquals($viewurl->out(), booking_editoptions_returnurl($course, (int)$settings->cmid)->out());

        set_config('editoptionsrequirecourselogin', 0, 'booking');

        // Not enrolled and not in the teachers table: the dashboard.
        $this->assertEquals(
            (new moodle_url('/my/'))->out(),
            booking_editoptions_returnurl($course, (int)$settings->cmid)->out()
        );

        // Not enrolled, but a teacher: the teacher's own page.
        $DB->insert_record('booking_teachers', [
            'bookingid' => (int)$settings->bookingid,
            'optionid' => (int)$settings->id,
            'userid' => (int)$teacher->id,
        ]);
        $this->assertEquals(
            (new moodle_url('/mod/booking/teacher.php', ['teacherid' => (int)$teacher->id]))->out(),
            booking_editoptions_returnurl($course, (int)$settings->cmid)->out()
        );

        // Enrolled users keep the booking instance as fallback.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->assertEquals($viewurl->out(), booking_editoptions_returnurl($course, (int)$settings->cmid)->out());
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
     * Helper: the fields of the date section as the browser submits them for one stored date.
     *
     * The date_time_selector elements submit arrays; "datesmarker" tells dates::set_data that the
     * form was already rendered, so the submitted values are used instead of the stored dates.
     *
     * @param int $optiondateid
     * @param int $start
     * @param int $end
     * @param array $extra e.g. a no submit button
     * @return array
     */
    private function date_form_data(int $optiondateid, int $start, int $end, array $extra = []): array {
        $toarray = function (int $timestamp): array {
            $date = usergetdate($timestamp);
            return [
                'year' => $date['year'],
                'month' => $date['mon'],
                'day' => $date['mday'],
                'hour' => $date['hours'],
                'minute' => $date['minutes'],
            ];
        };
        return array_merge([
            'datesmarker' => 0,
            'datescounter' => 1,
            MOD_BOOKING_FORM_OPTIONDATEID . '1' => $optiondateid,
            MOD_BOOKING_FORM_COURSESTARTTIME . '1' => $toarray($start),
            MOD_BOOKING_FORM_COURSEENDTIME . '1' => $toarray($end),
            MOD_BOOKING_FORM_DAYSTONOTIFY . '1' => 0,
        ], $extra);
    }

    /**
     * Helper: booking option with a teacher who is not enrolled in the course.
     *
     * The teacher gets a system role with mod/booking:editownoption and mod/booking:expertoptionform.
     *
     * @param bool $withdate also store one date (optiondate) and add the teacher to the teachers of the option
     * @return array{0: booking_option_settings, 1: stdClass, 2: int}
     */
    private function create_option_with_teacher_without_enrolment(bool $withdate = false): array {
        global $DB;

        $this->setAdminUser();

        $dateseed = $withdate ? [
            'optiondateid_1' => 0,
            'daystonotify_1' => 0,
            'coursestarttime_1' => make_timestamp(2050, 5, 20, 10, 0),
            'courseendtime_1' => make_timestamp(2050, 5, 20, 12, 0),
        ] : [];
        $option = $this->create_option((int)$this->create_booking()->id, $dateseed);

        $teacher = $this->getDataGenerator()->create_user();
        $systemcontext = context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:editownoption', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('mod/booking:expertoptionform', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The teacher created the option, so booking_check_if_teacher() is true for them.
        $DB->set_field('booking_options', 'usercreated', $teacher->id, ['id' => $option->id]);

        if ($withdate) {
            // Teacher of the option and of its date, inserted directly so no enrolment is triggered.
            $DB->insert_record('booking_teachers', [
                'bookingid' => (int)$option->bookingid,
                'optionid' => (int)$option->id,
                'userid' => (int)$teacher->id,
            ]);
            teachers_handler::subscribe_teacher_to_all_optiondates((int)$option->id, (int)$teacher->id);
        }
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
     * @param array $extra additional fields of the option record, e.g. a date seed
     * @return stdClass
     */
    private function create_option(int $bookingid, array $extra = []): stdClass {
        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = 'Option for course login test';
        $record->description = 'Test description';
        foreach ($extra as $key => $value) {
            $record->{$key} = $value;
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $option->cmid = singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;
        return $option;
    }
}
