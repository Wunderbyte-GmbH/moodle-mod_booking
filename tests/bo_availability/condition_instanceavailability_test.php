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
 * Tests for instanceavailability booking availability condition.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use advanced_testcase;
use coding_exception;
use context_module;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for instanceavailability booking availability condition.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class condition_instanceavailability_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test of booking option availability by cohorts and bookingtime.
     *
     * @covers \mod_booking\bo_availability\conditions\instanceavailability::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_cohorts(array $bdata): void {
        global $DB, $CFG, $PAGE;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user(); // Maybe we'll add another test for admin later.
        $student1 = $this->getDataGenerator()->create_user([
            'firstname' => 'Donald',
            'lastname' => 'Duck',
        ]);
        $student2 = $this->getDataGenerator()->create_user([
            'firstname' => 'Gustav',
            'lastname' => 'Gans',
        ]);

        $bdata['course'] = $course1->id;

        $bdata['availability'] = json_encode([
            "op" => "&",
            "c" => [
                [
                    "op" => "&",
                    "c" => [
                        [
                            "type" => "profile",
                            "sf" => "firstname",
                            "op" => "isequalto",
                            "v" => "Gustav",
                        ],
                    ],
                ],
                [
                    "type" => "profile",
                    "sf" => "lastname",
                    "op" => "isequalto",
                    "v" => "Gans",
                ],
            ],
            "showc" => [
                true,
                true,
            ],
        ]);
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        set_config('restrictavailabilityforinstance', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option for instanceavailability condition';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        $boinfo = new bo_info($settings);

        // Student 1 should be blocked by instanceavailability condition.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        // Blocked by access restrictions of booking instance.
        $this->assertEquals(MOD_BOOKING_BO_COND_INSTANCEAVAILABILITY, $id);

        // Student 2 should be able to book.
        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        // Not blocked, user can book.
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // With config setting disabled, restriction should not apply.
        set_config('restrictavailabilityforinstance', 0, 'booking');
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Data provider for condition_instanceavailability_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking instance access restrictions',
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

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
