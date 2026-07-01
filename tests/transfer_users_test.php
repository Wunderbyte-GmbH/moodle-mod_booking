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
 * Tests for transferring booked users to another booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/booking_advanced_testcase.php');

/**
 * Tests for transferring booked users between booking options, including the transfer warnings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::transfer_users_to_otheroption
 * @covers \mod_booking\booking_option::get_transfer_warnings
 */
final class transfer_users_test extends booking_advanced_testcase {
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
     * Base settings for a booking instance.
     *
     * @return array
     */
    private function booking_instance_settings(): array {
        return [
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
    }

    /**
     * Create a booking instance in the given course.
     *
     * @param stdClass $course
     * @param string $name
     * @return stdClass
     */
    private function create_booking(stdClass $course, string $name): stdClass {
        $bdata = $this->booking_instance_settings();
        $bdata['course'] = $course->id;
        $bdata['name'] = $name;
        return $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * Create a default (with dates) booking option in the given booking instance.
     *
     * @param mod_booking_generator $generator
     * @param int $bookingid
     * @param int $courseid
     * @param string $text
     * @return stdClass
     */
    private function create_option(
        mod_booking_generator $generator,
        int $bookingid,
        int $courseid,
        string $text
    ): stdClass {
        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $courseid;
        $record->maxanswers = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        return $generator->create_option($record);
    }

    /**
     * Whether the user has a non-deleted booking answer for the option.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    private function has_active_answer(int $optionid, int $userid): bool {
        global $DB;
        return $DB->record_exists_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist <> :deleted',
            [
                'optionid' => $optionid,
                'userid' => $userid,
                'deleted' => MOD_BOOKING_STATUSPARAM_DELETED,
            ]
        );
    }

    /**
     * Transferring a single user to another option within the same booking instance works.
     */
    public function test_transfer_within_same_instance_works(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking($course, 'Instance 1');

        /** @var mod_booking_generator $generator */
        $generator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option1 = $this->create_option($generator, $booking->id, $course->id, 'Source option');
        $option2 = $this->create_option($generator, $booking->id, $course->id, 'Target option');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Book the student on the source option.
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $student->id]);
        $this->assertTrue($this->has_active_answer($option1->id, $student->id));
        $this->assertFalse($this->has_active_answer($option2->id, $student->id));

        // Transfer to the target option.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $sourceoption = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        $result = $sourceoption->transfer_users_to_otheroption($option2->id, [$student->id]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->yes);
        $this->assertCount(0, $result->no);

        // The student is now booked on the target option and no longer active on the source.
        $this->assertFalse($this->has_active_answer($option1->id, $student->id));
        $this->assertTrue($this->has_active_answer($option2->id, $student->id));
    }

    /**
     * Transferring two users to an option in another booking instance (different cmid) works.
     */
    public function test_transfer_two_users_to_other_instance_works(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking1 = $this->create_booking($course, 'Instance 1');
        $booking2 = $this->create_booking($course, 'Instance 2');

        // The two options live in different booking instances (different cmid).
        $this->assertNotEquals($booking1->cmid, $booking2->cmid);

        /** @var mod_booking_generator $generator */
        $generator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option1 = $this->create_option($generator, $booking1->id, $course->id, 'Source option instance 1');
        $option2 = $this->create_option($generator, $booking2->id, $course->id, 'Target option instance 2');

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        // Book both students on the source option in instance 1.
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $student1->id]);
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $student2->id]);

        // Transfer both to the target option in instance 2.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $sourceoption = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        $result = $sourceoption->transfer_users_to_otheroption($option2->id, [$student1->id, $student2->id]);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->yes);
        $this->assertCount(0, $result->no);

        // Both students are booked on the target option in the other instance and gone from the source.
        foreach ([$student1, $student2] as $student) {
            $this->assertFalse($this->has_active_answer($option1->id, $student->id));
            $this->assertTrue($this->has_active_answer($option2->id, $student->id));
        }
    }

    /**
     * A warning is raised when transferring to an option of a different type.
     */
    public function test_warning_on_option_type_mismatch(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking($course, 'Instance 1');

        /** @var mod_booking_generator $generator */
        $generator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option1 = $this->create_option($generator, $booking->id, $course->id, 'Default type option');
        $option2 = $this->create_option($generator, $booking->id, $course->id, 'Slot booking option');

        // Turn the target into a slot booking option.
        $DB->set_field('booking_options', 'type', MOD_BOOKING_OPTIONTYPE_SLOTBOOKING, ['id' => $option2->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($option2->id);
        singleton_service::destroy_booking_option_singleton($option2->id);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $student->id]);

        $warnings = booking_option::get_transfer_warnings($option1->id, $option2->id, [$student->id]);

        // The only difference is the type, so exactly one warning is expected.
        $this->assertCount(1, $warnings);
        $expected = get_string('transferwarningtype', 'mod_booking', (object) [
            'sourcetype' => booking_option::get_optiontype_label(MOD_BOOKING_OPTIONTYPE_DEFAULT),
            'targettype' => booking_option::get_optiontype_label(MOD_BOOKING_OPTIONTYPE_SLOTBOOKING),
        ]);
        $this->assertContains($expected, $warnings);

        // No warning when transferring to an option of the same type.
        $option3 = $this->create_option($generator, $booking->id, $course->id, 'Another default option');
        $this->assertSame([], booking_option::get_transfer_warnings($option1->id, $option3->id, [$student->id]));
    }

    /**
     * A warning is raised when a selected user filled out a custom form (data would be lost).
     */
    public function test_warning_on_customform_data_loss(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking($course, 'Instance 1');

        /** @var mod_booking_generator $generator */
        $generator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option1 = $this->create_option($generator, $booking->id, $course->id, 'Option with custom form');
        $option2 = $this->create_option($generator, $booking->id, $course->id, 'Plain option');

        $withform = $this->getDataGenerator()->create_user();
        $withoutform = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($withform->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($withoutform->id, $course->id, 'student');
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $withform->id]);
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $withoutform->id]);

        // Simulate that the user submitted custom form data on the source option.
        $json = json_encode(['condition_customform' => ['customform_shorttext_1' => 'Some answer']]);
        $DB->set_field('booking_answers', 'json', $json, ['optionid' => $option1->id, 'userid' => $withform->id]);

        // User who filled out the form: one warning (only the custom form data loss).
        $warnings = booking_option::get_transfer_warnings($option1->id, $option2->id, [$withform->id]);
        $this->assertCount(1, $warnings);
        $this->assertContains(get_string('transferwarningcustomform', 'mod_booking'), $warnings);

        // User who did not fill out the form: no warning.
        $this->assertSame([], booking_option::get_transfer_warnings($option1->id, $option2->id, [$withoutform->id]));
    }

    /**
     * A warning is raised when the target option has a different price.
     */
    public function test_warning_on_different_price(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking($course, 'Instance 1');

        /** @var mod_booking_generator $generator */
        $generator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // A price category is required for prices to resolve.
        $generator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);

        $option1 = $this->create_option($generator, $booking->id, $course->id, 'Free option');
        $option2 = $this->create_option($generator, $booking->id, $course->id, 'Paid option');

        // Only the target option has a price.
        $generator->create_price((object) [
            'area' => 'option',
            'itemname' => 'Paid option',
            'pricecategoryidentifier' => 'default',
            'price' => 20,
            'currency' => 'EUR',
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $generator->create_answer(['optionid' => $option1->id, 'userid' => $student->id]);

        singleton_service::destroy_instance();

        // Same type, no custom form, only the price differs: exactly one warning.
        $warnings = booking_option::get_transfer_warnings($option1->id, $option2->id, [$student->id]);
        $this->assertCount(1, $warnings);

        // And no warning when the target has no (different) price.
        $option3 = $this->create_option($generator, $booking->id, $course->id, 'Another free option');
        $this->assertSame([], booking_option::get_transfer_warnings($option1->id, $option3->id, [$student->id]));
    }
}
