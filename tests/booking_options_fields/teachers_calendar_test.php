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
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->dirroot/mod/booking/classes/price.php");

/**
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teachers_calendar_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test creation and update of recurring options.
     *
     * @covers \mod_booking\option\fields\teachers::changes_collected_action
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_create_teacher_calendar_events(): void {
        global $DB;
        $bdata = self::provide_bdata();

        // Course is needed for module generator.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;

        // Create users.
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $teacher3 = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create an initial booking option.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Testoption';
        $record->importing = 1;
        $record->coursestarttime = '2025-01-01 10:00:00';
        $record->courseendtime = '2025-12-31 12:00:00';
        $record->useprice = 0;
        $record->default = 0;

        // Teachers.
        $record->teachersforoption = $teacher1->username;

        // Optiondate 1.
        $record->optiondateid_1 = 1;
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');
        $record->daystonotify_1 = 0;

        // Optiondate 2.
        $record->optiondateid_2 = 2;
        $record->coursestarttime_2 = strtotime('21 May 2050 15:00');
        $record->courseendtime_2 = strtotime('21 May 2050 16:00');
        $record->daystonotify_2 = 0;


        // Create a booking option.
        $option1 = $plugingenerator->create_option($record);

        $calendarevents = $DB->get_records('event', [
            'name' => 'Testoption',
            'userid' => $teacher1->id,
            'component' => 'mod_booking',
            'eventtype' => 'user',
        ]);

        $this->assertCount(2, $calendarevents, 'There should be 2 calendar events for the teacher.');

        // Update option to trigger recurrence.
        /*$record->id = $option1->id;
        $record->cmid = $settings->cmid;
        $record->importing = 1;

        $record->repeatthisbooking = $data['repeatthisbooking'];
        $record->howmanytimestorepeat = $data['howmanytimestorepeat'];
        $record->howoftentorepeat = $data['howoftentorepeat'];
        $record->requirepreviousoptionstobebooked = $data['requirepreviousoptionstobebooked'];*/

        /*booking_option::update($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);*/

        // TearDown at the very end.
        self::teardown();
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Policy 1',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
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
