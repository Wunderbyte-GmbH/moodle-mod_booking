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
 * Tests for the read access rules of the bookings tracker option/optiondate scopes.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\bookingstracker\report2_access;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The option and optiondate scopes are readable by everyone who could open
 * the old report.php (teachers of the option, viewreports, readresponses)
 * plus updatebooking (the previous tracker audience). Write actions keep
 * their own capability checks.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report2_access_test extends advanced_testcase {
    /**
     * Cleanup after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Students without any report capability have no access; a non-editing
     * teacher (readresponses via archetype), a teacher of the option and an
     * admin have access - the same audience as the old report.php.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_option_scope_access_matches_report_php_audience(): void {
        global $DB;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$options, $course] = $this->create_options(1);
        $settings = $options[0];
        $cmid = (int)$settings->cmid;
        $optionid = (int)$settings->id;

        // Student: no access.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $this->assertFalse(report2_access::has_option_scope_access($cmid, $optionid));

        // Non-editing teacher: access via mod/booking:readresponses (archetype),
        // exactly like the report.php fallback.
        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));

        // Teacher of the option (student role, booking_teachers record): access.
        $optionteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($optionteacher->id, $course->id, 'student');
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => (int)$settings->bookingid,
            'optionid' => $optionid,
            'userid' => (int)$optionteacher->id,
        ]);
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();
        $this->setUser($optionteacher);
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));

        // Editing teacher: access (readresponses/updatebooking).
        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $this->setUser($editingteacher);
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));

        // Admin: access.
        $this->setAdminUser();
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));
    }

    /**
     * A role carrying ONLY updatebooking grants access to ALL options of the
     * instances inside its assignment scope: assigned in the course, both
     * options of the instance are accessible but not an option of a foreign
     * instance; assigned system-wide, really all options are accessible.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_updatebooking_grants_access_to_all_options_in_scope(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$optionsa, $coursea] = $this->create_options(2);
        [$optionsb] = $this->create_options(1);

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'onlyupdatebooking']);
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, \context_course::instance($coursea->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        // All options of instance A (course-level assignment) are accessible.
        foreach ($optionsa as $settings) {
            $this->assertTrue(
                report2_access::has_option_scope_access((int)$settings->cmid, (int)$settings->id)
            );
        }
        // The option of the foreign instance B is not.
        $this->assertFalse(
            report2_access::has_option_scope_access((int)$optionsb[0]->cmid, (int)$optionsb[0]->id)
        );

        // System-wide assignment: really all options are accessible.
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            report2_access::has_option_scope_access((int)$optionsb[0]->cmid, (int)$optionsb[0]->id)
        );
    }

    /**
     * Teacher access is scoped to the option booking_check_if_teacher()
     * returns true for: the teacher of option A can read A but not option B
     * of the same instance.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_teacher_access_is_scoped_to_their_option(): void {
        global $DB;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$options, $course] = $this->create_options(2);
        [$optiona, $optionb] = $options;

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'student');
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => (int)$optiona->bookingid,
            'optionid' => (int)$optiona->id,
            'userid' => (int)$teacher->id,
        ]);
        booking_option::purge_cache_for_option((int)$optiona->id);
        singleton_service::destroy_instance();
        $this->setUser($teacher);

        $this->assertTrue(booking_check_if_teacher((int)$optiona->id), 'Precondition: teacher of option A.');
        $this->assertFalse(booking_check_if_teacher((int)$optionb->id), 'Precondition: not teacher of option B.');

        $this->assertTrue(report2_access::has_option_scope_access((int)$optiona->cmid, (int)$optiona->id));
        $this->assertFalse(report2_access::has_option_scope_access((int)$optionb->cmid, (int)$optionb->id));
    }

    /**
     * Managebookedusers alone does NOT grant option scope access - not even
     * assigned system-wide. Only adding updatebooking (or being teacher of
     * the option) opens the option scope.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_managebookedusers_alone_grants_no_option_scope_access(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$options] = $this->create_options(1);
        $settings = $options[0];

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'onlymanagebooked']);
        assign_capability('mod/booking:managebookedusers', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $this->assertTrue(
            has_capability('mod/booking:managebookedusers', \context_module::instance((int)$settings->cmid)),
            'Precondition: the user holds managebookedusers system-wide.'
        );
        $this->assertFalse(
            booking_check_if_teacher((int)$settings->id),
            'Precondition: the user is not a teacher of the option.'
        );
        $this->assertFalse(
            report2_access::has_option_scope_access((int)$settings->cmid, (int)$settings->id),
            'managebookedusers alone must not grant option scope access.'
        );

        // Adding updatebooking to the same role opens the option scope.
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            report2_access::has_option_scope_access((int)$settings->cmid, (int)$settings->id)
        );
    }

    /**
     * Responsible contacts count as teachers of the option (and get read
     * access) only when the responsiblecontactcanedit setting is active.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_responsiblecontact_access_depends_on_config(): void {
        global $DB;

        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$options, $course] = $this->create_options(1);
        $settings = $options[0];

        $contact = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($contact->id, $course->id, 'student');
        $DB->set_field('booking_options', 'responsiblecontact', (string)$contact->id, ['id' => $settings->id]);
        booking_option::purge_cache_for_option((int)$settings->id);
        singleton_service::destroy_instance();
        $this->setUser($contact);

        set_config('responsiblecontactcanedit', 0, 'booking');
        $this->assertFalse(report2_access::has_option_scope_access((int)$settings->cmid, (int)$settings->id));

        set_config('responsiblecontactcanedit', 1, 'booking');
        $this->assertTrue(report2_access::has_option_scope_access((int)$settings->cmid, (int)$settings->id));
    }

    /**
     * The aggregated scopes open only for the assignment level of
     * managebookedusers: a module-level assignment opens only the instance
     * scope of that module, a course-level assignment opens course + its
     * instances but not the system scope, and only a global assignment opens
     * the system scope.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_system_scope_access
     * @covers \mod_booking\local\bookingstracker\report2_access::has_course_scope_access
     * @covers \mod_booking\local\bookingstracker\report2_access::has_instance_scope_access
     */
    public function test_aggregated_scope_access_matches_assignment_level(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$options, $course] = $this->create_options(1);
        $cmid = (int)$options[0]->cmid;

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'managebookedonly']);
        assign_capability('mod/booking:managebookedusers', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        // Module-level assignment: only the instance scope of THIS module.
        $moduleuser = $this->getDataGenerator()->create_user();
        role_assign($roleid, $moduleuser->id, \context_module::instance($cmid)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($moduleuser);
        $this->assertTrue(report2_access::has_instance_scope_access($cmid));
        $this->assertFalse(
            report2_access::has_course_scope_access((int)$course->id),
            'A module-level assignment must not open the course scope.'
        );
        $this->assertFalse(
            report2_access::has_system_scope_access(),
            'A module-level assignment must not open the system scope.'
        );

        // Course-level assignment: course scope + the instances of the course,
        // but not the system scope.
        $courseuser = $this->getDataGenerator()->create_user();
        role_assign($roleid, $courseuser->id, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($courseuser);
        $this->assertTrue(report2_access::has_course_scope_access((int)$course->id));
        $this->assertTrue(report2_access::has_instance_scope_access($cmid));
        $this->assertFalse(
            report2_access::has_system_scope_access(),
            'A course-level assignment must not open the system scope.'
        );

        // Global assignment: all three scopes.
        $globaluser = $this->getDataGenerator()->create_user();
        role_assign($roleid, $globaluser->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($globaluser);
        $this->assertTrue(report2_access::has_system_scope_access());
        $this->assertTrue(report2_access::has_course_scope_access((int)$course->id));
        $this->assertTrue(report2_access::has_instance_scope_access($cmid));
    }

    /**
     * Helper: booking instance with one or more options.
     *
     * @param int $count number of options to create
     * @return array{0: \mod_booking\booking_option_settings[], 1: stdClass} option settings and course
     */
    private function create_options(int $count = 1): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Report2 access test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $settingslist = [];
        for ($i = 1; $i <= $count; $i++) {
            $optionrecord = new stdClass();
            $optionrecord->bookingid = $booking->id;
            $optionrecord->text = 'Option ' . $i . ' for access test';
            $option = $plugingenerator->create_option($optionrecord);
            $settingslist[] = singleton_service::get_instance_of_booking_option_settings($option->id);
        }

        return [$settingslist, $course];
    }
}
