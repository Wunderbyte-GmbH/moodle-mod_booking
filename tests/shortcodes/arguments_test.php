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
 * Tests for the recommendedin shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use context_course;
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * This test tests the functionality of some arguments.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class arguments_test extends advanced_testcase {
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
     * Test creation and display of shortcode recommendedin.
     *
     * @covers \mod_booking\shortcodes::recommendedin
     *
     * @throws \coding_exception
     * @throws \dml_exception
     *
     */
    public function test_infinitescrollpage(): void {
        global $DB, $CFG;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course1']);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        // Crerate booking module.
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        // Create an initial booking option.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            /** @var mod_booking_generator $plugingenerator */
            $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
            $option1 = $plugingenerator->create_option($record);
        }

        // Get cmid id from last intance. It is same for all of options.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $cmid = $settings->cmid;

        // Now we have multiple options in multiple bookings and multiple courses.
        $records = $DB->get_records('booking_options');
        $this->assertCount(count($bdata['standardbookingoptions']), $records, 'Booking options were not created correctly');

        // Now we can start testing the shortcode.
        $env = new stdClass();
        $next = function () {
        };
        // Prepare the args.
        $args = [
            'all' => 1,
            'cmid' => $cmid,
            'infinitescrollpage' => 10,
            'sort' => 1,
            'pageable' => 0,
        ];

        global $PAGE;
        $context = context_course::instance($course->id);
        $PAGE->set_context($context);
        $PAGE->set_course($course);
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/recommendedin_test.php'));

        // Use courselist shortcode for this test.
        $shortcode = shortcodes::courselist('courselist', $args, null, $env, $next);
        $this->assertNotEmpty($shortcode);
        preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $shortcode, $matches);
        $encodedtable = $matches[1];

        // Call external api to check the result.
        $result = \local_wunderbyte_table\external\load_data::execute($encodedtable);
        $template = $result['template'];
        $content = $result['content'];
        $filterjson = $result['filterjson'];


        // $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        // $tableobject = $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        // $this->assertEquals(0, $table->totalrows);
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        $standardbookingoptions = [
            ['text' => 'Badminton'],
            ['text' => 'Badminton Beginner'],
            ['text' => 'Badminton Advanced'],
            ['text' => 'Badminton Intermediate'],
            ['text' => 'Badminton Mixed Level'],
            ['text' => 'Badminton Recreational'],
            ['text' => 'Badminton Open Training'],
            ['text' => 'Badminton Free Play'],
            ['text' => 'Badminton Technique'],
            ['text' => 'Badminton Skills Clinic'],
            ['text' => 'Badminton Team Training'],
            ['text' => 'Badminton Club Session'],
            ['text' => 'Badminton Conditioning'],
            ['text' => 'Badminton Endurance'],
            ['text' => 'Badminton Intro Course'],
            ['text' => 'Badminton Workshop'],
            ['text' => 'Badminton Pickup Games'],
            ['text' => 'Badminton Evening Session'],
            ['text' => 'Badminton Morning Session'],
            ['text' => 'Badminton Lunchtime Session'],
            ['text' => 'Basketball Beginner'],
            ['text' => 'Basketball Advanced'],
            ['text' => 'Basketball Intermediate'],
            ['text' => 'Basketball Mixed Level'],
            ['text' => 'Basketball Recreational'],
            ['text' => 'Basketball Open Training'],
            ['text' => 'Basketball Free Play'],
            ['text' => 'Basketball Technique'],
            ['text' => 'Basketball Skills Clinic'],
            ['text' => 'Basketball Team Training'],
            ['text' => 'Basketball Club Session'],
            ['text' => 'Basketball Conditioning'],
            ['text' => 'Basketball Endurance'],
            ['text' => 'Basketball Intro Course'],
            ['text' => 'Basketball Workshop'],
            ['text' => 'Basketball Pickup Games'],
            ['text' => 'Basketball Evening Session'],
            ['text' => 'Basketball Morning Session'],
            ['text' => 'Basketball Lunchtime Session'],
            ['text' => 'Volleyball Beginner'],
            ['text' => 'Volleyball Advanced'],
            ['text' => 'Volleyball Intermediate'],
            ['text' => 'Volleyball Mixed Level'],
            ['text' => 'Volleyball Recreational'],
            ['text' => 'Volleyball Open Training'],
            ['text' => 'Volleyball Free Play'],
            ['text' => 'Volleyball Technique'],
            ['text' => 'Volleyball Skills Clinic'],
            ['text' => 'Volleyball Team Training'],
            ['text' => 'Volleyball Club Session'],
            ['text' => 'Volleyball Conditioning'],
            ['text' => 'Volleyball Endurance'],
            ['text' => 'Volleyball Intro Course'],
            ['text' => 'Volleyball Workshop'],
            ['text' => 'Volleyball Pickup Games'],
            ['text' => 'Volleyball Evening Session'],
            ['text' => 'Volleyball Morning Session'],
            ['text' => 'Volleyball Lunchtime Session'],
            ['text' => 'Table Tennis Beginner'],
            ['text' => 'Table Tennis Advanced'],
            ['text' => 'Table Tennis Intermediate'],
            ['text' => 'Table Tennis Mixed Level'],
            ['text' => 'Table Tennis Recreational'],
            ['text' => 'Table Tennis Open Training'],
            ['text' => 'Table Tennis Free Play'],
            ['text' => 'Table Tennis Technique'],
            ['text' => 'Table Tennis Skills Clinic'],
            ['text' => 'Table Tennis Team Training'],
            ['text' => 'Table Tennis Club Session'],
            ['text' => 'Table Tennis Conditioning'],
            ['text' => 'Table Tennis Endurance'],
            ['text' => 'Table Tennis Intro Course'],
            ['text' => 'Table Tennis Workshop'],
            ['text' => 'Table Tennis Pickup Games'],
            ['text' => 'Table Tennis Evening Session'],
            ['text' => 'Table Tennis Morning Session'],
            ['text' => 'Table Tennis Lunchtime Session'],
            ['text' => 'Tennis Beginner'],
            ['text' => 'Tennis Advanced'],
            ['text' => 'Tennis Intermediate'],
            ['text' => 'Tennis Mixed Level'],
            ['text' => 'Tennis Recreational'],
            ['text' => 'Tennis Open Training'],
            ['text' => 'Tennis Free Play'],
            ['text' => 'Tennis Technique'],
            ['text' => 'Tennis Skills Clinic'],
            ['text' => 'Tennis Team Training'],
            ['text' => 'Tennis Club Session'],
            ['text' => 'Tennis Conditioning'],
            ['text' => 'Tennis Endurance'],
            ['text' => 'Tennis Intro Course'],
            ['text' => 'Tennis Workshop'],
            ['text' => 'Tennis Pickup Games'],
            ['text' => 'Tennis Evening Session'],
            ['text' => 'Tennis Morning Session'],
            ['text' => 'Tennis Lunchtime Session'],
            ['text' => 'Squash Beginner'],
            ['text' => 'Squash Advanced'],
            ['text' => 'Squash Intermediate'],
            ['text' => 'Squash Mixed Level'],
            ['text' => 'Squash Recreational'],
            ['text' => 'Squash Open Training'],
            ['text' => 'Squash Free Play'],
            ['text' => 'Squash Technique'],
            ['text' => 'Squash Skills Clinic'],
            ['text' => 'Squash Team Training'],
            ['text' => 'Squash Club Session'],
            ['text' => 'Squash Conditioning'],
            ['text' => 'Squash Endurance'],
            ['text' => 'Squash Intro Course'],
            ['text' => 'Squash Workshop'],
            ['text' => 'Squash Pickup Games'],
            ['text' => 'Squash Evening Session'],
            ['text' => 'Squash Morning Session'],
            ['text' => 'Squash Lunchtime Session'],
            ['text' => 'Handball Beginner'],
            ['text' => 'Handball Advanced'],
            ['text' => 'Handball Intermediate'],
            ['text' => 'Handball Mixed Level'],
            ['text' => 'Handball Recreational'],
            ['text' => 'Handball Open Training'],
            ['text' => 'Handball Free Play'],
            ['text' => 'Handball Technique'],
            ['text' => 'Handball Skills Clinic'],
            ['text' => 'Handball Team Training'],
            ['text' => 'Handball Club Session'],
            ['text' => 'Handball Conditioning'],
            ['text' => 'Handball Endurance'],
            ['text' => 'Handball Intro Course'],
            ['text' => 'Handball Workshop'],
            ['text' => 'Handball Pickup Games'],
            ['text' => 'Handball Evening Session'],
            ['text' => 'Handball Morning Session'],
            ['text' => 'Handball Lunchtime Session'],
            ['text' => 'Rugby Beginner'],
            ['text' => 'Rugby Advanced'],
            ['text' => 'Rugby Intermediate'],
            ['text' => 'Rugby Mixed Level'],
            ['text' => 'Rugby Recreational'],
            ['text' => 'Rugby Open Training'],
            ['text' => 'Rugby Free Play'],
            ['text' => 'Rugby Technique'],
            ['text' => 'Rugby Skills Clinic'],
            ['text' => 'Rugby Team Training'],
            ['text' => 'Rugby Club Session'],
            ['text' => 'Rugby Conditioning'],
            ['text' => 'Rugby Endurance'],
            ['text' => 'Rugby Intro Course'],
            ['text' => 'Rugby Workshop'],
            ['text' => 'Rugby Pickup Games'],
            ['text' => 'Rugby Evening Session'],
            ['text' => 'Rugby Morning Session'],
            ['text' => 'Rugby Lunchtime Session'],
            ['text' => 'Football Beginner'],
            ['text' => 'Football Advanced'],
            ['text' => 'Football Intermediate'],
            ['text' => 'Football Mixed Level'],
            ['text' => 'Football Recreational'],
            ['text' => 'Football Open Training'],
            ['text' => 'Football Free Play'],
            ['text' => 'Football Technique'],
            ['text' => 'Football Skills Clinic'],
            ['text' => 'Football Team Training'],
            ['text' => 'Football Club Session'],
            ['text' => 'Football Conditioning'],
            ['text' => 'Football Endurance'],
            ['text' => 'Football Intro Course'],
            ['text' => 'Football Workshop'],
            ['text' => 'Football Pickup Games'],
            ['text' => 'Football Evening Session'],
            ['text' => 'Football Morning Session'],
            ['text' => 'Football Lunchtime Session'],
            ['text' => 'American Football Beginner'],
            ['text' => 'American Football Advanced'],
            ['text' => 'American Football Intermediate'],
            ['text' => 'American Football Mixed Level'],
            ['text' => 'American Football Recreational'],
            ['text' => 'American Football Open Training'],
            ['text' => 'American Football Free Play'],
            ['text' => 'American Football Technique'],
            ['text' => 'American Football Skills Clinic'],
            ['text' => 'American Football Team Training'],
            ['text' => 'American Football Club Session'],
            ['text' => 'American Football Conditioning'],
            ['text' => 'American Football Endurance'],
            ['text' => 'American Football Intro Course'],
            ['text' => 'American Football Workshop'],
            ['text' => 'American Football Pickup Games'],
            ['text' => 'American Football Evening Session'],
            ['text' => 'American Football Morning Session'],
            ['text' => 'American Football Lunchtime Session'],
            ['text' => 'Ultimate Frisbee Beginner'],
            ['text' => 'Ultimate Frisbee Advanced'],
            ['text' => 'Ultimate Frisbee Intermediate'],
            ['text' => 'Ultimate Frisbee Mixed Level'],
            ['text' => 'Ultimate Frisbee Recreational'],
            ['text' => 'Ultimate Frisbee Open Training'],
            ['text' => 'Ultimate Frisbee Free Play'],
            ['text' => 'Ultimate Frisbee Technique'],
            ['text' => 'Ultimate Frisbee Skills Clinic'],
            ['text' => 'Ultimate Frisbee Team Training'],
            ['text' => 'Ultimate Frisbee Club Session'],
            ['text' => 'Ultimate Frisbee Conditioning'],
            ['text' => 'Ultimate Frisbee Endurance'],
            ['text' => 'Ultimate Frisbee Intro Course'],
            ['text' => 'Ultimate Frisbee Workshop']
        ];

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
            'standardbookingoptions' => $standardbookingoptions,
        ];
    }
}
