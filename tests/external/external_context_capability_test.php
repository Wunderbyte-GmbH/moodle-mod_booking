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
 * Tests for the context validation and capability checks of the external services.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\external\allow_add_item_to_cart;
use mod_booking\external\bookings;
use mod_booking\external\bookit;
use mod_booking\external\get_booking_option_description;
use mod_booking\external\get_performance_chart;
use mod_booking\external\get_submission_mobile;
use mod_booking\external\load_pre_booking_page;
use mod_booking\form\condition\customform_form;
use mod_booking\local\mobile\customformstore;
use mod_booking\external\optiontemplate;
use mod_booking\external\rate_option;
use mod_booking\external\search_booking_options;
use mod_booking\external\search_courses;
use mod_booking\external\search_teachers;
use mod_booking\external\search_templates;
use mod_booking\external\search_users;
use mod_booking\external\toggle_notify_user;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for the security hardening of the external services: every service
 * has to validate its execution context (which includes the login and course access
 * check) and enforce the required capabilities before processing the request.
 *
 * @covers \mod_booking\external\allow_add_item_to_cart
 * @covers \mod_booking\external\bookings
 * @covers \mod_booking\external\bookit
 * @covers \mod_booking\external\get_booking_option_description
 * @covers \mod_booking\external\get_performance_chart
 * @covers \mod_booking\external\get_submission_mobile
 * @covers \mod_booking\external\load_pre_booking_page
 * @covers \mod_booking\external\optiontemplate
 * @covers \mod_booking\external\rate_option
 * @covers \mod_booking\external\search_booking_options
 * @covers \mod_booking\external\search_courses
 * @covers \mod_booking\external\search_teachers
 * @covers \mod_booking\external\search_templates
 * @covers \mod_booking\external\search_users
 * @covers \mod_booking\external\toggle_notify_user
 * @covers \mod_booking\permissions
 */
final class external_context_capability_test extends booking_advanced_testcase {
    /**
     * Creates a course with a booking instance and one option, an enrolled student1
     * (booked into the option), an enrolled student2, an enrolled editingteacher
     * and one user who is not enrolled at all.
     *
     * @return array [$course, $booking, $option, $student1, $student2, $teacher, $outsider]
     */
    private function create_environment(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'showinapi' => 1,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Secured option',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 10,
        ]);

        singleton_service::destroy_instance();

        // Book student1 directly (as a trainer would), forcing a verified booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_booking_answers($option->id);

        return [$course, $booking, $option, $student1, $student2, $teacher, $outsider];
    }

    /**
     * The raw option record is only handed out to users who may manage option templates.
     */
    public function test_optiontemplate_requires_manageoptiontemplates(): void {
        [, , $option, $student1] = $this->create_environment();

        $this->setAdminUser();
        $result = optiontemplate::execute($option->id);
        $this->assertSame('Secured option', $result['name']);

        $this->setUser($student1);
        $this->expectException(required_capability_exception::class);
        optiontemplate::execute($option->id);
    }

    /**
     * Without access to the course of the booking instance, the notification list cannot be toggled.
     */
    public function test_toggle_notify_user_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        toggle_notify_user::execute((int)$outsider->id, (int)$option->id);
    }

    /**
     * Enrolled users can toggle the notification list for themselves, but not for other users.
     */
    public function test_toggle_notify_user_self_allowed_others_denied(): void {
        [, , $option, , $student2, , ] = $this->create_environment();

        $this->setUser($student2);

        // Toggling for yourself puts you on the notification list.
        $result = toggle_notify_user::execute((int)$student2->id, (int)$option->id);
        $this->assertSame(1, $result['status']);

        // Toggling for somebody else needs mod/booking:subscribeusers.
        $other = $this->getDataGenerator()->create_user();
        $result = toggle_notify_user::execute((int)$other->id, (int)$option->id);
        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * The bookings service requires course access and only exposes the booked users
     * (personal data incl. e-mail addresses) to users with mod/booking:readresponses.
     */
    public function test_bookings_requires_course_access_and_gates_user_data(): void {
        [$course, , , $student1, , , $outsider] = $this->create_environment();

        // An enrolled student gets the option list, but never the booked users.
        $this->setUser($student1);
        singleton_service::destroy_instance();
        $result = bookings::execute((string)$course->id, '1', '0');
        $this->assertCount(1, $result);
        $this->assertSame('Secured option', $result[0]['options'][0]['text']);
        $this->assertSame([], $result[0]['options'][0]['users']);

        // A user with readresponses (admin) gets the booked users.
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $result = bookings::execute((string)$course->id, '1', '0');
        $userids = array_column($result[0]['options'][0]['users'], 'id');
        $this->assertContains((int)$student1->id, array_map('intval', $userids));

        // A user without access to the course is rejected.
        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        bookings::execute((string)$course->id, '1', '0');
    }

    /**
     * The autocomplete search backends are restricted to users
     * who may edit booking options somewhere in the system.
     */
    public function test_search_backends_require_option_editing_capability(): void {
        [, , , $student1, , $teacher, ] = $this->create_environment();

        $this->setUser($student1);
        $backends = [
            fn() => search_users::execute('someone'),
            fn() => search_teachers::execute('someone'),
            fn() => search_courses::execute('somecourse'),
            fn() => search_templates::execute('sometemplate'),
            fn() => search_booking_options::execute('someoption'),
        ];
        foreach ($backends as $backend) {
            try {
                $backend();
                $this->fail('A student must not be able to use the search backends.');
            } catch (moodle_exception $e) {
                $this->assertStringContainsString('nopermissions', $e->errorcode);
            }
        }

        // An editingteacher (mod/booking:addeditownoption in the module context) may search.
        $this->setUser($teacher);
        $result = search_teachers::execute('someone');
        $this->assertArrayHasKey('list', $result);
    }

    /**
     * The option description rendered for another user (incl. their booking status)
     * is only available with the book for others rights.
     */
    public function test_get_booking_option_description_gates_foreign_user(): void {
        [, , $option, $student1, $student2, , ] = $this->create_environment();

        $this->setUser($student1);
        $result = get_booking_option_description::execute((int)$option->id, (int)$student1->id);
        $this->assertNotEmpty($result['content']);

        $this->expectException(required_capability_exception::class);
        get_booking_option_description::execute((int)$option->id, (int)$student2->id);
    }

    /**
     * The performance chart is part of the performance tool and needs its view capability.
     */
    public function test_get_performance_chart_requires_viewperformance(): void {
        [, , , $student1] = $this->create_environment();

        $this->setUser($student1);
        $this->expectException(required_capability_exception::class);
        get_performance_chart::execute('somevalue');
    }

    /**
     * Booking via the webservice requires access to the course of the booking instance.
     */
    public function test_bookit_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        bookit::execute('option', (int)$option->id, (int)$outsider->id, '');
    }

    /**
     * Users holding mod/booking:choose may book without being enrolled in the course
     * of the booking instance (booking options are regularly presented outside of
     * their course, e.g. via shortcode lists): the webservices of the booking chain
     * accept them, and the booking itself works — as do the option description modal
     * of the lists and the "notify me" toggle of the booking button. Users without
     * the capability are still rejected (see test_bookit_requires_course_access
     * and test_toggle_notify_user_requires_course_access).
     */
    public function test_booking_chain_allows_unenrolled_user_with_choose(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        // Typical shortcode setup: users hold mod/booking:choose via a system level role.
        $this->setAdminUser();
        $systemcontext = \context_system::instance();
        $roleid = create_role('Booking user', 'bookinguser', '');
        assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $outsider->id, $systemcontext->id);

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        // The description modal of the lists opens for the unenrolled user.
        $result = get_booking_option_description::execute((int)$option->id, (int)$outsider->id);
        $this->assertNotEmpty($result['content']);

        // The "notify me" toggle of the booking button works for the unenrolled user.
        $result = toggle_notify_user::execute((int)$outsider->id, (int)$option->id);
        $this->assertSame(1, $result['status']);
        $result = toggle_notify_user::execute((int)$outsider->id, (int)$option->id);
        $this->assertSame(0, $result['status']);

        // The pre booking check of the booking button passes...
        $result = allow_add_item_to_cart::execute((int)$option->id, (int)$outsider->id);
        $this->assertSame(1, $result['success']);

        // ...and booking the option works (bookit is called twice to confirm).
        bookit::execute('option', (int)$option->id, (int)$outsider->id, '');
        bookit::execute('option', (int)$option->id, (int)$outsider->id, '');

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, (int)$outsider->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Rating follows the same rule as the booking chain: booked users are not
     * necessarily enrolled in the course of the booking instance (e.g. booked via
     * shortcode lists, option without connected course), so a booked user holding
     * mod/booking:choose via a system level role may rate without course access.
     */
    public function test_rate_option_allows_unenrolled_booked_user_with_choose(): void {
        global $DB;

        [$course, $booking, , , , , $outsider] = $this->create_environment();

        $this->setAdminUser();

        // Restrict ratings to users who are booked (ratings = 2).
        $DB->set_field('booking', 'ratings', 2, ['id' => $booking->id]);
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($booking->cmid);

        // An option without connected course: booking it does not enrol anybody.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Rated option',
            'maxanswers' => 10,
        ]);
        singleton_service::destroy_instance();

        // Book the outsider directly (as a trainer would), forcing a verified booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $this->assertTrue($bookingoption->user_submit_response($outsider, 0, 0, 0, MOD_BOOKING_VERIFIED));
        singleton_service::destroy_booking_answers($option->id);

        // The premise of this test: rating restricted to booked users, booked, but still not enrolled.
        $this->assertTrue(
            $DB->record_exists('booking_answers', ['optionid' => $option->id, 'userid' => $outsider->id])
        );
        $this->assertSame(2, (int)$DB->get_field('booking', 'ratings', ['id' => $booking->id]));
        $this->assertFalse(is_enrolled(\context_course::instance($course->id), $outsider));

        // Typical shortcode setup: users hold mod/booking:choose via a system level role.
        $systemcontext = \context_system::instance();
        $roleid = create_role('Booking user', 'bookinguser', '');
        assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $outsider->id, $systemcontext->id);

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        $result = rate_option::execute((int)$settings->cmid, (int)$option->id, 5);
        $this->assertSame(5, $result['rate']);
        $this->assertFalse($result['duplicate']);
        $this->assertTrue(
            $DB->record_exists('booking_ratings', ['userid' => $outsider->id, 'optionid' => $option->id])
        );
    }

    /**
     * Without mod/booking:choose, a user who is not enrolled in the course of the
     * booking instance keeps being rejected when rating.
     */
    public function test_rate_option_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        $this->expectException(\require_login_exception::class);
        rate_option::execute((int)$settings->cmid, (int)$option->id, 5);
    }

    /**
     * The mobile counterpart of the customform submission (get_submission_mobile, used
     * by the Moodle app) follows the same rule as the booking chain: a user who is not
     * enrolled in the course of the booking instance but holds mod/booking:choose
     * (e.g. via a system level role) may submit and reset the custom form data.
     */
    public function test_get_submission_mobile_allows_unenrolled_user_with_choose(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        // Typical shortcode setup: users hold mod/booking:choose via a system level role.
        $this->setAdminUser();
        $systemcontext = \context_system::instance();
        $roleid = create_role('Booking user', 'bookinguser', '');
        assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $outsider->id, $systemcontext->id);

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        $result = get_submission_mobile::execute(
            (int)$option->id,
            (int)$outsider->id,
            sesskey(),
            false,
            [['name' => 'customform_shorttext_1', 'value' => 'Ada Lovelace']]
        );
        $this->assertSame(1, $result['submitted']);
        $json = json_decode($result['json'], true);
        $this->assertSame('Ada Lovelace', $json['customform_shorttext_1'] ?? null);
    }

    /**
     * Without mod/booking:choose, a user who is not enrolled in the course of the
     * booking instance keeps being rejected by the mobile customform submission.
     */
    public function test_get_submission_mobile_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        $this->expectException(\require_login_exception::class);
        get_submission_mobile::execute((int)$option->id, (int)$outsider->id, sesskey(), false, []);
    }

    /**
     * For an option with a customform condition, the customform prepage modal opens
     * for a user who is NOT enrolled in the course of the booking instance (authenticated
     * user holding mod/booking:choose via a system level role, e.g. shortcode setups):
     * the pre booking check passes, the customform page is among the prepages, loading it
     * through the webservice returns the customform template (the call which failed with
     * requireloginerror since the 9.7.0 hardening), and after submitting the form the
     * confirmation page load books the user, carrying the customform data in the answer.
     */
    public function test_customform_prepage_opens_and_books_for_unenrolled_user_with_choose(): void {
        global $DB;

        [$course, $booking, , , , , $outsider] = $this->create_environment();

        // Add an option with a customform (shorttext) condition to the instance.
        $this->setAdminUser();
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Customform option',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 10,
            'importing' => 1,
            'bo_cond_customform_restrict' => 1,
            'bo_cond_customform_select_1_1' => 'shorttext',
            'bo_cond_customform_label_1_1' => 'Your name',
        ]);

        // Typical shortcode setup: users hold mod/booking:choose via a system level role.
        $systemcontext = \context_system::instance();
        $roleid = create_role('Booking user', 'bookinguser', '');
        assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $outsider->id, $systemcontext->id);

        $this->setUser($outsider);
        singleton_service::destroy_instance();

        // The submission of the customform itself (core dynamic form webservice) is gated
        // by mod/booking:conditionforms on system context, which every authenticated user
        // holds by default - so the whole flow works without any enrolment.
        $this->assertTrue(has_capability('mod/booking:conditionforms', $systemcontext));

        // The pre booking check of the booking button passes.
        $result = allow_add_item_to_cart::execute((int)$option->id, (int)$outsider->id);
        $this->assertSame(1, $result['success']);

        // The customform page is among the prepages of the modal for the unenrolled user.
        $conditionresults = bo_info::get_condition_results($option->id, (int)$outsider->id);
        usort($conditionresults, fn ($a, $b) => $a['id'] < $b['id'] ? 1 : -1);
        $pages = bo_info::return_sorted_conditions($conditionresults);
        $customformpage = null;
        $confirmationpage = null;
        foreach ($pages as $index => $page) {
            if ((int)$page['id'] === MOD_BOOKING_BO_COND_JSON_CUSTOMFORM) {
                $customformpage = $index;
            }
            if ((int)$page['id'] === MOD_BOOKING_BO_COND_CONFIRMATION) {
                $confirmationpage = $index;
            }
        }
        $this->assertNotNull($customformpage, 'The customform prepage must be shown to the unenrolled user.');
        $this->assertNotNull($confirmationpage, 'The confirmation prepage must be offered to the unenrolled user.');

        // Loading the customform page through the webservice returns the customform template.
        $result = load_pre_booking_page::execute((int)$option->id, (int)$outsider->id, $customformpage);
        $this->assertStringContainsString('mod_booking/condition/customform', $result['template']);

        // Submit the customform (as the dynamic form webservice would).
        $_POST = [
            'id' => (string)$option->id,
            'userid' => (string)$outsider->id,
            'customform_shorttext_1' => 'Ada Lovelace',
            'sesskey' => sesskey(),
            '_qf__mod_booking_form_condition_customform_form' => '1',
        ];
        $form = new customform_form(null, null, 'post', '', [], true, $_POST, true);
        $this->assertTrue($form->is_validated(), 'Customform submission should validate for the unenrolled user.');
        $form->process_dynamic_submission();
        $_POST = [];

        $customformstore = new customformstore((int)$outsider->id, (int)$option->id);
        $storeddata = (array)$customformstore->get_customform_data();
        $this->assertSame('Ada Lovelace', $storeddata['customform_shorttext_1'] ?? null);

        // The browser's "Continue" ends on the confirmation page, whose load books the option.
        singleton_service::destroy_instance();
        load_pre_booking_page::execute((int)$option->id, (int)$outsider->id, $confirmationpage);

        $answers = $DB->get_records('booking_answers', ['optionid' => $option->id, 'userid' => $outsider->id]);
        $this->assertCount(1, $answers, 'The unenrolled user with mod/booking:choose must end up booked.');
        $answer = reset($answers);
        $this->assertSame((int)MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $this->assertStringContainsString('Ada Lovelace', (string)$answer->json);
    }
}
