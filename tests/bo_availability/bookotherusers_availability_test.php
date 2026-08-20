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
 * Tests for the availability condition check used when booking other users (subscribeusers.php).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for bo_info::get_unmet_availability_conditions, the check behind the global setting
 * "bookotherusersavailability" (warn about/block booking users who do not meet the conditions).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class bookotherusers_availability_test extends booking_advanced_testcase {
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
     * Real availability restrictions (profile field, booking time) are reported as blockers,
     * while flow conditions (bookit button), capacity (fully booked) and the already-booked
     * state of a user are not.
     *
     * @covers \mod_booking\bo_availability\bo_info::get_unmet_availability_conditions
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_get_unmet_availability_conditions(array $bdata): void {
        global $DB, $PAGE;

        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Create a custom profile field: only users of department IT may book option1.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'companydepartment',
            'name' => 'Department',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'IT',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'HR',
        ]);

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Option1: restricted to companydepartment = IT.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Option restricted by custom profile field';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'companydepartment';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'IT';
        $option1 = $plugingenerator->create_option($record);

        // Option2: booking period already over.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Option with closed booking period';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 3 day');
        $record->bookingclosingtime = strtotime('now - 2 day');
        $option2 = $plugingenerator->create_option($record);

        // Option3: no restrictions, but only one place.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Option with one place';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $option3 = $plugingenerator->create_option($record);

        // Student1 books the single place of option3 themselves.
        $this->setUser($student1);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        booking_bookit::bookit('option', $settings3->id, $student1->id);
        booking_bookit::bookit('option', $settings3->id, $student1->id);

        // The admin acts as the agent booking for other users.
        $this->setAdminUser();

        // Student1 meets the profile field condition: nothing blocks. This also proves that
        // pure flow conditions like the bookit button are not reported as blockers.
        $this->assertSame([], bo_info::get_unmet_availability_conditions($option1->id, $student1->id));

        // Student2 does not meet the profile field condition.
        $blocking = bo_info::get_unmet_availability_conditions($option1->id, $student2->id);
        $this->assertArrayHasKey(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $blocking);
        $this->assertNotEmpty($blocking[MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD]);

        // The booking period of option2 is over for everybody.
        $blocking = bo_info::get_unmet_availability_conditions($option2->id, $student1->id);
        $this->assertArrayHasKey(MOD_BOOKING_BO_COND_BOOKING_TIME, $blocking);

        // Option3 is fully booked: capacity is handled by the waiting list logic of the booking
        // process, so it must NOT be reported as a blocking availability condition.
        $this->assertSame([], bo_info::get_unmet_availability_conditions($option3->id, $student2->id));

        // Student1 already holds the place of option3: the already-booked state is no blocker either.
        $this->assertSame([], bo_info::get_unmet_availability_conditions($option3->id, $student1->id));
    }

    /**
     * A user over the "max bookings per user" limit of the instance is reported as a blocker,
     * and user_submit_response can override exactly that limit with $skipuserlimitcheck -
     * used after the agent confirmed the warning on the book other users page.
     *
     * @covers \mod_booking\bo_availability\bo_info::get_unmet_availability_conditions
     * @covers \mod_booking\booking_option::user_submit_response
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_confirmed_warning_overrides_max_bookings_limit(array $bdata): void {
        global $DB, $PAGE;

        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        // Only one booking per user allowed in this instance.
        $bdata['maxperuser'] = 1;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // The options need real dates: get_user_booking_count() only counts answers of options
        // whose courseendtime is 0 or in the future - a NULL courseendtime would not count.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'First option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 2 day');
        $record->courseendtime_0 = strtotime('now + 4 day');
        $option1 = $plugingenerator->create_option($record);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Second option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 2 day');
        $record->courseendtime_0 = strtotime('now + 4 day');
        $option2 = $plugingenerator->create_option($record);

        // Student1 books the first option themselves.
        $this->setUser($student1);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        booking_bookit::bookit('option', $settings1->id, $student1->id);
        booking_bookit::bookit('option', $settings1->id, $student1->id);

        $this->setAdminUser();

        // The limit of the instance is reached: reported as a blocker for the second option.
        $blocking = bo_info::get_unmet_availability_conditions($option2->id, $student1->id);
        $this->assertArrayHasKey(MOD_BOOKING_BO_COND_MAX_NUMBER_OF_BOOKINGS, $blocking);

        $option2obj = singleton_service::get_instance_of_booking_option($booking1->cmid, $option2->id);
        $studentrecord = $DB->get_record('user', ['id' => $student1->id], '*', MUST_EXIST);

        // Without the override the limit blocks the booking (behavior of the modes 0 and 2).
        $this->assertFalse(
            $option2obj->user_submit_response($studentrecord, 0, 0, 0, MOD_BOOKING_VERIFIED)
        );
        $this->assertFalse($DB->record_exists('booking_answers', [
            'optionid' => $option2->id,
            'userid' => $student1->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]));

        // With the override (agent confirmed the warning) the user is booked.
        $this->assertTrue(
            (bool) $option2obj->user_submit_response(
                $studentrecord,
                0,
                0,
                0,
                MOD_BOOKING_VERIFIED,
                skipuserlimitcheck: true,
            )
        );
        $this->assertTrue($DB->record_exists('booking_answers', [
            'optionid' => $option2->id,
            'userid' => $student1->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]));
    }

    /**
     * Data provider for the test.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test booking for others availability',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
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
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
