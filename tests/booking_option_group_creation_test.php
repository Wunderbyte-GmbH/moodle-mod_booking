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
 * PHPUnit test for automatic group creation on booking option save.
 *
 * When a booking instance has both "Automatically enrol users in connected course"
 * (autoenrol) and "Automatically enrol users in group of linked course" (addtogroup)
 * enabled, creating a booking option that is connected to a Moodle course must
 * automatically create a group in that connected course and store its id in the
 * groupid column of booking_options.
 *
 * The generated group name follows the pattern:
 *   <booking instance name> - <booking option name> (<booking option id>)
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use cache_helper;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests that a group is automatically created when a booking option with a
 * connected course is saved inside a booking instance that has autoenrol and
 * addtogroup activated.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_option_group_creation_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
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
     * Test that a group is automatically created and linked when a booking option
     * with a connected course is saved.
     *
     * Scenario:
     *  - A Moodle course named "Course 1" is created.
     *  - A booking instance named "Booking instance 1" is created inside "Course 1"
     *    with both autoenrol and addtogroup set to 1.
     *  - A booking option named "Booking option 1" is created inside the booking
     *    instance and connected to "Course 1" via the courseid field.
     *
     * Expected outcome:
     *  - The groupid column in booking_options is set (non-zero).
     *  - The group exists and its name matches the pattern:
     *    "Booking instance 1 - Booking option 1 (<optionid>)"
     *    where <optionid> is replaced by the actual id of the booking option.
     *
     * @covers \mod_booking\option\fields\addtogroup::save_data
     * @covers \mod_booking\booking_option::create_group
     * @covers \mod_booking\booking_option::generate_group_data
     */
    public function test_booking_option_creates_group_in_connected_course(): void {
        global $DB;

        // Create the Moodle course that the booking option will be connected to.
        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'Course 1']);

        /*
         * Create the booking instance named "Booking instance 1".
         * autoenrol = 1 activates "Automatically enrol users in connected course".
         * addtogroup = 1 activates "Automatically enrol users in group of linked course".
         * The booking module itself lives inside "Course 1".
         */
        $bdata = [
            'name' => 'Booking instance 1',
            'course' => $course1->id,
            'autoenrol' => 1,
            'addtogroup' => 1,
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Create a booking option named "Booking option 1" connected to "Course 1" via courseid.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Booking option 1';
        $record->chooseorcreatecourse = 1; // Choose an existing Moodle course.
        $record->courseid = $course1->id;
        $record->resetgroupid = 0; // Required by the groupid field handler; 0 means do not reset.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Fetch the fresh booking option record from the database.
        $optionrecord = $DB->get_record('booking_options', ['id' => $option->id], '*', MUST_EXIST);

        // Assert that the groupid column in booking_options is set (non-zero).
        $this->assertNotEmpty(
            $optionrecord->groupid,
            'The groupid column in booking_options must be set (non-zero) after the '
            . 'booking option is created when addtogroup is active.'
        );

        /*
         * Assert that the linked group exists and its name matches the expected pattern
         * "Booking instance 1 - Booking option 1 (<optionid>)".
         * The group is created in the connected course ("Course 1").
         */
        $group = groups_get_group($optionrecord->groupid);

        $this->assertNotFalse(
            $group,
            'A Moodle group with the id stored in booking_options.groupid must exist.'
        );

        $expectedgroupname = "Booking instance 1 - Booking option 1 ({$option->id})";

        $this->assertEquals(
            $expectedgroupname,
            $group->name,
            'The automatically created group name must follow the pattern '
            . '"<booking instance name> - <booking option name> (<booking option id>)".'
        );
    }

    /**
     * Test that after duplicating a booking instance:
     *  - The duplicated booking option retains the same group as the original.
     *  - Saving the duplicated booking option with resetgroupid=1 creates a new group
     *    named after the duplicated instance and the option's own id.
     *
     * @covers \mod_booking\option\fields\groupid::save_data
     * @covers \mod_booking\booking_option::create_group
     * @covers \mod_booking\booking_option::generate_group_data
     * @covers \mod_booking\booking_option::update
     */
    public function test_duplicate_booking_instance_resets_group(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        // Create the Moodle course that the booking option will be connected to.
        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'Course 1']);

        // Create "Booking instance 1" with autoenrol and addtogroup active.
        $bdata = [
            'name' => 'Booking instance 1',
            'course' => $course1->id,
            'autoenrol' => 1,
            'addtogroup' => 1,
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Create "Booking option 1" inside "Booking instance 1", connected to "Course 1".
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Booking option 1';
        $record->chooseorcreatecourse = 1; // Choose an existing Moodle course.
        $record->courseid = $course1->id;
        $record->resetgroupid = 0; // Required by the groupid field handler; 0 means do not reset.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // Fetch the original option record and confirm group was created.
        $option1record = $DB->get_record('booking_options', ['id' => $option1->id], '*', MUST_EXIST);
        $this->assertNotEmpty($option1record->groupid, 'Original option must have a groupid after creation.');
        $originalgroupid = $option1record->groupid;

        // Duplicate "Booking instance 1" using the standard Moodle module duplication.
        $cm1 = get_fast_modinfo($course1)->get_cm($booking1->cmid);
        $newcm = duplicate_module($course1, $cm1);
        $this->assertNotNull($newcm, 'Module duplication must return a valid cm_info object.');

        // Rename the duplicated booking instance to "Booking instance 2".
        $DB->set_field('booking', 'name', 'Booking instance 2', ['id' => $newcm->instance]);

        /*
         * Explicitly delete the MUC cache entry for the duplicated booking instance so the
         * next booking_settings read for this cmid goes back to DB and picks up the new name.
         */
        $bookinginstancecache = \cache::make('mod_booking', 'cachedbookinginstances');
        $bookinginstancecache->delete($newcm->id);

        // Reset the singleton service so all further singleton fetches are fresh.
        cache_helper::purge_all();
        singleton_service::destroy_instance();
        get_fast_modinfo($course1, 0, true);

        // Find the booking option inside the duplicated instance.
        $option2record = $DB->get_record(
            'booking_options',
            ['bookingid' => $newcm->instance],
            '*',
            MUST_EXIST
        );

        // Assert the duplicated booking option still has the same group as the original.
        $this->assertEquals(
            $originalgroupid,
            $option2record->groupid,
            'After duplication the booking option must still reference the same group as the original.'
        );

        // Save the duplicated booking option with resetgroupid=1 to trigger group regeneration.
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2record->id);

        $updaterecord = new stdClass();
        $updaterecord->id = $option2record->id;
        $updaterecord->cmid = $settings2->cmid;
        $updaterecord->bookingid = $newcm->instance;
        $updaterecord->text = 'Booking option 1';
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course1->id;
        $updaterecord->resetgroupid = 1; // Trigger group reset and recreation.

        /*
         * Destroy singletons one final time so booking_option::update fetches the
         * booking settings exclusively from DB (which now has "Booking instance 2").
         */
        singleton_service::destroy_instance();

        booking_option::update($updaterecord);

        // Destroy singleton to force fresh DB read.
        singleton_service::destroy_booking_option_singleton($option2record->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings2->cmid);

        // Fetch the updated option2 record from the database.
        $option2updated = $DB->get_record('booking_options', ['id' => $option2record->id], '*', MUST_EXIST);

        // Assert a groupid is still set after the reset.
        $this->assertNotEmpty(
            $option2updated->groupid,
            'After resetgroupid=1, the duplicated booking option must still have a groupid.'
        );

        // Assert a new group was created, so the groupid must differ from the original.
        $this->assertNotEquals(
            $originalgroupid,
            $option2updated->groupid,
            'After resetgroupid=1, a new group must be created; the groupid must differ from the original.'
        );

        /*
         * Assert the group name now matches "Booking instance 2 - Booking option 1 (<option2id>)".
         */
        $newgroup = groups_get_group($option2updated->groupid);
        $this->assertNotFalse(
            $newgroup,
            'A Moodle group with the groupid stored in booking_options must exist.'
        );

        $expectedname = "Booking instance 2 - Booking option 1 ({$option2record->id})";
        $this->assertEquals(
            $expectedname,
            $newgroup->name,
            'The regenerated group name must follow the pattern '
            . '"<booking instance name> - <booking option name> (<booking option id>)".'
        );
    }
}
