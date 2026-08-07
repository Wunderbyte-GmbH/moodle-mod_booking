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

use mod_booking\tests\booking_advanced_testcase;
use backup_controller;
use restore_controller;
use backup;
use context_system;
use core_customfield\api;
use core_customfield\category_controller;
use mod_booking\customfield\booking_handler;
use mod_booking\output\view;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test that a booking instance referencing customfields that do not exist on this platform
 * (e.g. after restoring a course backup from another Moodle site) shows a clear message
 * instead of a technical error.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\customfield\booking_handler::get_missing_customfields
 * @covers \mod_booking\customfield\booking_handler::check_for_missing_customfields_and_return_warning
 *
 * @runTestsInSeparateProcesses
 */
final class restore_missing_customfields_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        singleton_service::destroy_instance();
    }

    /**
     * Restore a booking referencing customfields on a platform where these fields are missing:
     * the restore completes, the view renders without a technical error and a clear message
     * lists the missing fields - with the link to create them only for users allowed to do so.
     * Once the fields have been created, the message disappears.
     *
     * @return void
     */
    public function test_restored_booking_with_missing_customfields_shows_clear_message(): void {
        global $DB, $PAGE, $USER;

        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course(['enablecompletion' => 1]);
        $course2 = $generator->create_course(['enablecompletion' => 1]);
        $teacher = $generator->create_user(['username' => 'teacher1']);
        $manager = $generator->create_user(['username' => 'manager1']);
        $student = $generator->create_user(['username' => 'student1']);
        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $generator->enrol_user($manager->id, $course2->id, 'manager');
        $generator->enrol_user($student->id, $course2->id, 'student');

        // Create the booking customfields on "platform A".
        $categoryid = $this->create_booking_customfields();

        // Booking instance referencing the customfields for the filter and the options overview.
        $booking1 = $generator->create_module('booking', [
            'course' => $course1->id,
            'name' => 'BookingWithCustomfields',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
            'customfieldsforfilter' => ['spt1', 'lng1'],
            'customfieldsforview' => ['spt1', 'lng1'],
        ]);
        // A second instance without any customfield references must stay unaffected.
        $generator->create_module('booking', [
            'course' => $course1->id,
            'name' => 'BookingWithoutCustomfields',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_option((object) [
            'bookingid' => $booking1->id,
            'text' => 'Option01-t',
            'course' => $course1->id,
            'importing' => 1,
            'maxanswers' => 3,
            'spt1' => 'tenis',
            'lng1' => 'french',
        ]);

        // Backup the course on "platform A".
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course1->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->finish_ui();
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Simulate "platform B": delete the booking customfields (incl. their category).
        $category = category_controller::create($categoryid);
        api::delete_category($category);
        \cache::make('mod_booking', 'customfields')->purge();
        singleton_service::destroy_instance();
        $this->assertSame([], booking_handler::get_customfields());

        // Restore the backup into the second course. The restore completes as before.
        $rc = new restore_controller(
            $backupid,
            $course2->id,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_CURRENT_ADDING
        );
        $rc->finish_ui();
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restored = [];
        foreach (get_fast_modinfo($course2->id)->get_instances_of('booking') as $cm) {
            $restored[$cm->name] = $cm;
        }
        $this->assertArrayHasKey('BookingWithCustomfields', $restored);
        $this->assertArrayHasKey('BookingWithoutCustomfields', $restored);
        $cmid1 = $restored['BookingWithCustomfields']->id;
        $cmid2 = $restored['BookingWithoutCustomfields']->id;

        singleton_service::destroy_instance();
        $bookingsettings1 = singleton_service::get_instance_of_booking_settings_by_cmid($cmid1);
        $bookingsettings2 = singleton_service::get_instance_of_booking_settings_by_cmid($cmid2);

        // The missing fields are detected with the names stored on the source platform.
        $missing = booking_handler::get_missing_customfields($bookingsettings1);
        $this->assertEquals(['spt1' => 'Sport1', 'lng1' => 'Language1'], $missing);
        $this->assertSame([], booking_handler::get_missing_customfields($bookingsettings2));

        // Opening the restored activity does not throw a technical error.
        $PAGE->set_url('/mod/booking/view.php', ['id' => $cmid1]);
        $viewoutput = new view($cmid1, 'showall');
        $html = $viewoutput->get_rendered_all_options_table();
        $this->assertStringContainsString('wunderbyte_table_container', $html);

        $handler = booking_handler::create();
        $context1 = \context_module::instance($cmid1);
        $context2 = \context_module::instance($cmid2);

        // Site admins can create the fields: message lists the fields and links to the admin page.
        $warning = $handler->check_for_missing_customfields_and_return_warning($bookingsettings1, $context1);
        $this->assertStringContainsString(get_string('warningmissingcustomfields', 'mod_booking'), $warning);
        $this->assertStringContainsString('Sport1 (spt1)', $warning);
        $this->assertStringContainsString('Language1 (lng1)', $warning);
        $this->assertStringContainsString('/mod/booking/customfield.php', $warning);
        // Booking instances without missing customfields do not show the message.
        $this->assertSame('', $handler->check_for_missing_customfields_and_return_warning($bookingsettings2, $context2));

        // Users who can manage the instance but cannot create the fields get the message without the link.
        $this->setUser($manager);
        $warning = $handler->check_for_missing_customfields_and_return_warning($bookingsettings1, $context1);
        $this->assertStringContainsString(get_string('warningmissingcustomfields', 'mod_booking'), $warning);
        $this->assertStringContainsString('Sport1 (spt1)', $warning);
        $this->assertStringNotContainsString('/mod/booking/customfield.php', $warning);

        // Students do not see the message at all.
        $this->setUser($student);
        $this->assertSame('', $handler->check_for_missing_customfields_and_return_warning($bookingsettings1, $context1));

        // Once the missing customfields have been created, the message is no longer displayed.
        $this->setAdminUser();
        $this->create_booking_customfields();
        $this->assertSame([], booking_handler::get_missing_customfields($bookingsettings1));
        $this->assertSame('', $handler->check_for_missing_customfields_and_return_warning($bookingsettings1, $context1));
    }

    /**
     * Create the booking customfields used by this test and return the category id.
     *
     * @return int
     */
    private function create_booking_customfields(): int {
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'BookCustomCat1',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $category->save();

        foreach (['spt1' => 'Sport1', 'lng1' => 'Language1'] as $shortname => $name) {
            $this->getDataGenerator()->create_custom_field([
                'categoryid' => $category->get('id'),
                'name' => $name,
                'shortname' => $shortname,
                'type' => 'text',
                'configdata' => '{"required":"0","uniquevalues":"0","defaultvalue":"",'
                    . '"displaysize":50,"maxlength":1333,"ispassword":"0","link":"",'
                    . '"locked":"0","visibility":"2"}',
            ])->save();
        }

        return (int)$category->get('id');
    }
}
