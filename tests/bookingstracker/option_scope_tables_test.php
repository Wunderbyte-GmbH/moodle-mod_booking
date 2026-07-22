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
 * Tests for the option scope tables of the bookings tracker: action button
 * visibility, download button gate and the per-status SQL.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_answers\scopes\option;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Which action buttons the booked-users table of the option scope offers
 * depends on the responsesfields setting and the capabilities of the user;
 * the download button is gated by downloadresponses and points to
 * download_report2.php; the side tables (waiting list, reserved, notify list,
 * deleted) filter by their status param.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_scope_tables_test extends booking_advanced_testcase {
    /**
     * Admins (all capabilities) with fully configured responsesfields get all
     * bulk buttons and the download button (pointing to download_report2.php);
     * without the column-bound fields the matching buttons disappear.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     */
    public function test_admin_sees_all_buttons_with_full_config(): void {
        $this->setAdminUser();
        [$settings] = $this->create_booked_option();

        $buttons = $this->button_identifiers($settings, 'adminfull');
        $this->assertContains('toggle_completion_booking_answers', $buttons);
        $this->assertContains('mod_booking\\form\\optiondates\\modal_change_status', $buttons);
        $this->assertContains('mod_booking\\form\\optiondates\\modal_change_notes', $buttons);
        $this->assertContains('mod_booking\\form\\modal_send_custom_message', $buttons);
        $this->assertContains('mod_booking\\form\\modal_transfer_users', $buttons);
        $this->assertContains('mod_booking\\form\\modal_set_rating', $buttons);
        $this->assertContains('enrol_checked_booking_answers', $buttons);
        $this->assertContains('delete_checked_booking_answers', $buttons);
        // Certificates are not configured (certificateon off), so no button.
        $this->assertNotContains('trigger_certificate_booking_answers', $buttons);

        // Download button: admin has downloadresponses; baseurl targets the
        // dedicated report2 download endpoint.
        $table = $this->build_table($settings, 'admindl');
        $this->assertTrue(!empty($table->showdownloadbutton));
        $this->assertStringContainsString('/mod/booking/download_report2.php', $table->baseurl->out(false));
    }

    /**
     * Column-bound buttons follow the responsesfields setting: without the
     * completed/status/notes/rating columns their buttons disappear, the
     * capability-bound ones stay.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     */
    public function test_buttons_follow_responsesfields(): void {
        global $DB;

        $this->setAdminUser();
        [$settings] = $this->create_booked_option();

        $DB->set_field('booking', 'responsesfields', 'email', ['id' => $settings->bookingid]);
        \cache_helper::purge_by_event('setbackbookinginstances');
        singleton_service::destroy_instance();

        $buttons = $this->button_identifiers($settings, 'reduced');
        $this->assertNotContains('toggle_completion_booking_answers', $buttons);
        $this->assertNotContains('mod_booking\\form\\optiondates\\modal_change_status', $buttons);
        $this->assertNotContains('mod_booking\\form\\optiondates\\modal_change_notes', $buttons);
        $this->assertNotContains('mod_booking\\form\\modal_set_rating', $buttons);
        $this->assertContains('mod_booking\\form\\modal_send_custom_message', $buttons);
        $this->assertContains('mod_booking\\form\\modal_transfer_users', $buttons);
        $this->assertContains('delete_checked_booking_answers', $buttons);
    }

    /**
     * Non-editing teachers (fresh-install defaults) are read-only for booking
     * data: no completion/presence/notes (managebookedusers), no transfer or
     * enrol (subscribeusers), no delete (deleteresponses) - but they keep
     * messaging (communicate), rating (mod/booking:rate) and the download
     * (downloadresponses).
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     */
    public function test_nonediting_teacher_sees_only_readonly_compatible_buttons(): void {
        $this->setAdminUser();
        [$settings, $course] = $this->create_booked_option();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $buttons = $this->button_identifiers($settings, 'teacher');
        $this->assertNotContains('toggle_completion_booking_answers', $buttons);
        $this->assertNotContains('mod_booking\\form\\optiondates\\modal_change_status', $buttons);
        $this->assertNotContains('mod_booking\\form\\optiondates\\modal_change_notes', $buttons);
        $this->assertNotContains('mod_booking\\form\\modal_transfer_users', $buttons);
        $this->assertNotContains('enrol_checked_booking_answers', $buttons);
        $this->assertNotContains('delete_checked_booking_answers', $buttons);
        $this->assertContains('mod_booking\\form\\modal_send_custom_message', $buttons);
        $this->assertContains('mod_booking\\form\\modal_set_rating', $buttons);

        $table = $this->build_table($settings, 'teacherdl');
        $this->assertTrue(!empty($table->showdownloadbutton), 'Teachers keep the export (downloadresponses).');
    }

    /**
     * Students get no bulk buttons and no download button.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     */
    public function test_student_sees_no_buttons_and_no_download(): void {
        $this->setAdminUser();
        [$settings, $course, $students] = $this->create_booked_option();

        $this->setUser($students[0]);

        $buttons = $this->button_identifiers($settings, 'student');
        $this->assertSame([], $buttons);

        $table = $this->build_table($settings, 'studentdl');
        $this->assertTrue(empty($table->showdownloadbutton), 'Students have no downloadresponses.');
    }

    /**
     * The side tables filter by their status param: waiting list, reserved,
     * notify list and deleted answers each return exactly their users.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_sql_for_booked_users
     */
    public function test_scope_sql_filters_by_status(): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $course, $students] = $this->create_booked_option();
        [$booked1, $booked2] = $students;

        // Create the side-table states directly (no booking flow side effects).
        $extras = [];
        foreach (
            [
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'reserved' => MOD_BOOKING_STATUSPARAM_RESERVED,
                'notifyme' => MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                'deleted' => MOD_BOOKING_STATUSPARAM_DELETED,
            ] as $key => $statusparam
        ) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $DB->insert_record('booking_answers', (object)[
                'bookingid' => (int)$settings->bookingid,
                'optionid' => (int)$settings->id,
                'userid' => (int)$user->id,
                'waitinglist' => $statusparam,
                'places' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $extras[$key] = $user;
        }

        $scope = new option();
        $expectations = [
            MOD_BOOKING_STATUSPARAM_BOOKED => [(int)$booked1->id, (int)$booked2->id],
            MOD_BOOKING_STATUSPARAM_WAITINGLIST => [(int)$extras['waitinglist']->id],
            MOD_BOOKING_STATUSPARAM_RESERVED => [(int)$extras['reserved']->id],
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST => [(int)$extras['notifyme']->id],
            MOD_BOOKING_STATUSPARAM_DELETED => [(int)$extras['deleted']->id],
        ];

        foreach ($expectations as $statusparam => $expecteduserids) {
            [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users(
                'option',
                (int)$settings->id,
                $statusparam
            );
            $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
            $actualuserids = array_map(fn($row) => (int)$row->userid, $rows);
            sort($actualuserids);
            sort($expecteduserids);
            $this->assertSame(
                $expecteduserids,
                array_values($actualuserids),
                "Status param $statusparam must return exactly its users."
            );
        }
    }

    /**
     * Helper: identifiers (methodname or formname) of the action buttons of
     * the booked users table.
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param string $prefix unique table name prefix
     * @return array
     */
    private function button_identifiers($settings, string $prefix): array {
        $table = $this->build_table($settings, $prefix);
        return array_map(
            fn($button) => $button['methodname'] ?? $button['formname'],
            $table->actionbuttons ?? []
        );
    }

    /**
     * Helper: build the booked users table of the option scope.
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param string $prefix unique table name prefix
     * @return \local_wunderbyte_table\wunderbyte_table
     */
    private function build_table($settings, string $prefix) {
        $scope = new option();
        return $scope->return_users_table(
            'option',
            (int)$settings->id,
            MOD_BOOKING_STATUSPARAM_BOOKED,
            'testtable' . $prefix,
            ['firstname'],
            [get_string('firstname')]
        );
    }

    /**
     * Helper: booking instance with ratings enabled, full responsesfields, an
     * option with connected course and two booked students.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: stdClass, 2: stdClass[]}
     */
    private function create_booked_option(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Option scope tables test booking',
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
            'assessed' => 1,
            'scale' => 10,
            'autoenrol' => 0,
            'responsesfields' => 'completed,status,notes,rating,email',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for scope tables';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->courseid = $targetcourse->id;
        $record->chooseorcreatecourse = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);

        $this->assertEquals(2, $DB->count_records('booking_answers', ['optionid' => $option->id]));

        return [$settings, $course, [$student1, $student2]];
    }
}
