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

namespace mod_booking\output;

use advanced_testcase;
use cache_helper;
use context_module;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for booking option showdates rendering with user timezones.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_timezone_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
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
     * Ensure cached showdates output respects each user's timezone.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_showdates
     * @covers \mod_booking\output\col_coursestarttime
     */
    public function test_showdates_cache_respects_user_timezone(): void {
        global $PAGE;

        set_config('timezone', 'UTC');
        set_config('forcetimezone', '99');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user([
            'username' => 'teacher1',
            'firstname' => 'Teacher',
            'lastname' => '1',
            'email' => 'teacher1@example.com',
            'timezone' => 'UTC',
            'lang' => 'en',
        ]);
        $student1 = $this->getDataGenerator()->create_user([
            'username' => 'student1',
            'firstname' => 'Student',
            'lastname' => '1',
            'email' => 'student1@example.com',
            'timezone' => 'Europe/Vienna',
            'lang' => 'en',
        ]);
        $student2 = $this->getDataGenerator()->create_user([
            'username' => 'student2',
            'firstname' => 'Student',
            'lastname' => '2',
            'email' => 'student2@example.com',
            'timezone' => 'Asia/Tehran',
            'lang' => 'en',
        ]);
        $student3 = $this->getDataGenerator()->create_user([
            'username' => 'student3',
            'firstname' => 'Student',
            'lastname' => '3',
            'email' => 'student3@example.com',
            'timezone' => 'America/Chicago',
            'lang' => 'en',
        ]);
        $student4 = $this->getDataGenerator()->create_user([
            'username' => 'student4',
            'firstname' => 'Student',
            'lastname' => '4',
            'email' => 'student4@example.com',
            'timezone' => 'Europe/Vienna',
            'lang' => 'en',
        ]);


        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course->id, 'student');

        $bookingdata = [
            'course' => $course->id,
            'name' => 'BookingTZ',
            'intro' => 'Booking TZ descr',
            'bookingmanager' => $teacher->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bookingdata);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'TZ-Option-01';
        $record->description = 'TZ Option';
        $record->maxanswers = 5;
        $record->availability = 1;
        $record->restrictanswerperiodopening = 1;
        $record->bookingopeningtime = 2373012000; // 2045-03-13 10:00 UTC.
        $record->restrictanswerperiodclosing = 1;
        $record->bookingclosingtime = 2373033600; // 2045-03-13 16:00 UTC.
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = 2373019200; // 2045-03-13 12:00 UTC.
        $record->courseendtime_0 = 2373026400; // 2045-03-13 14:00 UTC.
        $option = $plugingenerator->create_option($record);

        $cm = get_coursemodule_from_instance('booking', $booking->id, $course->id, false, MUST_EXIST);
        $PAGE->set_context(context_module::instance($cm->id));

        cache_helper::purge_all();

        $table = new bookingoptions_wbtable("cmid_{$cm->id} allbookingoptionstable");
        $table->define_cache('mod_booking', 'bookingoptionstable');
        $timevalues = (object)[
            'id' => $option->id,
            'bookingopeningtime' => $record->bookingopeningtime,
            'bookingclosingtime' => $record->bookingclosingtime,
        ];

        $this->setUser($student1);
        $output1 = $table->col_showdates((object)['id' => $option->id]);
        $this->assertStringContainsString('13 March 2045, 1:00 PM', $output1);
        $this->assertStringContainsString('3:00 PM', $output1);
        $opening1 = $table->col_bookingopeningtime($timevalues);
        $closing1 = $table->col_bookingclosingtime($timevalues);
        $this->assertStringContainsString('Bookable from: 13 March 2045, 11:00 AM', $opening1);
        $this->assertStringContainsString('Bookable until: 13 March 2045, 5:00 PM', $closing1);
        $lang = current_language();
        $timezone = \core_date::get_user_timezone($student1);
        $timezonetoken = str_replace('/', '_', $timezone);
        $cachekey = "sessiondates{$option->id}{$lang}{$timezonetoken}";
        $cache = \cache::make('mod_booking', 'bookingoptionstable');
        $this->assertSame($output1, $cache->get($cachekey));

        $this->setUser($student2);
        $output2 = $table->col_showdates((object)['id' => $option->id]);
        $this->assertStringContainsString('13 March 2045, 3:30 PM', $output2);
        $this->assertStringContainsString('5:30 PM', $output2);
        $this->assertStringNotContainsString('13 March 2045, 1:00 PM', $output2);
        $opening2 = $table->col_bookingopeningtime($timevalues);
        $closing2 = $table->col_bookingclosingtime($timevalues);
        $this->assertStringContainsString('Bookable from: 13 March 2045, 1:30 PM', $opening2);
        $this->assertStringContainsString('Bookable until: 13 March 2045, 7:30 PM', $closing2);

        $this->setUser($student3);
        $output3 = $table->col_showdates((object)['id' => $option->id]);
        $this->assertStringContainsString('13 March 2045, 7:00 AM', $output3);
        $this->assertStringContainsString('9:00 AM', $output3);
        $this->assertStringNotContainsString('13 March 2045, 1:00 PM', $output3);
        $opening3 = $table->col_bookingopeningtime($timevalues);
        $closing3 = $table->col_bookingclosingtime($timevalues);
        $this->assertStringContainsString('Bookable from: 13 March 2045, 5:00 AM', $opening3);
        $this->assertStringContainsString('Bookable until: 13 March 2045, 11:00 AM', $closing3);

        $this->setUser($student4);
        $output4 = $table->col_showdates((object)['id' => $option->id]);
        $this->assertStringContainsString('13 March 2045, 1:00 PM', $output4);
        $this->assertStringContainsString('3:00 PM', $output4);
        $opening4 = $table->col_bookingopeningtime($timevalues);
        $closing4 = $table->col_bookingclosingtime($timevalues);
        $this->assertSame($opening1, $opening4);
        $this->assertSame($closing1, $closing4);
        $this->assertSame($output4, $cache->get($cachekey));
    }
}
