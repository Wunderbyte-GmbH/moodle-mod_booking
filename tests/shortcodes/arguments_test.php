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
use local_wunderbyte_table\external\load_data;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

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
     * This test creates around 200 booking options, then instantiates a bookingoptions_wbtable
     * that returns the booking options sorted by option name. It also sorts the option names
     * using PHP. The test then compares the options from the booking table with
     * the PHP-sorted options. They must match at the same positions in the array.
     *
     * @covers \mod_booking\shortcodes::allbookingoptions
     * @covers \local_wunderbyte_table\external\load_data::execute
     *
     * @param string $shortcodename
     * @param string $shortcode
     * @param array $args
     * @param array $config
     * @return void
     *
     * @dataProvider shortcode_provider
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_infinitescrollpage(
        string $shortcodename,
        string $shortcode,
        array $args,
        array $config
    ): void {
        global $DB, $PAGE;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course1']);

        // Create users.
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        // Crerate booking module.
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        if ($config['hascustomfields']) {
            // Create custom booking field.
            $categorydata = new stdClass();
            $categorydata->name = 'BookCustomCat1';
            $categorydata->component = 'mod_booking';
            $categorydata->area = 'booking';
            $categorydata->itemid = 0;
            $categorydata->contextid = context_system::instance()->id;
            $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
            $bookingcat->save();
            foreach ($config['customfields'] as $cfkey => $cfvalues) {
                $fielddata = new stdClass();
                $fielddata->categoryid = $bookingcat->get('id');
                $fielddata->name = $cfkey;
                $fielddata->shortname = $cfkey;
                $fielddata->type = 'text';
                $fielddata->configdata = "";
                $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
                $bookingfield->save();
            }
        }

        // Create booking options.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            // Adding custom fields dynamically.
            if ($config['hascustomfields']) {
                foreach ($config['customfields'] as $cfkey => $cfvalue) {
                    $customfieldname = "customfield_{$cfkey}";
                    $record->{$customfieldname} = $cfvalue;
                }
            }
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

        $context = context_course::instance($course->id);
        $PAGE->set_context($context);
        $PAGE->set_course($course);
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/arguments_test.php'));

        // Use the courselist shortcode for this test.
        // We create a table instance and then get the encodedtable string.
        // We use encodedtable to fetch pages in an AJAX-like way.
        $args[$config['paramforcmid']] = $cmid; // Add the param which is used to inject the cmid to the $args.
        $shortcoderenderedtable = call_user_func($shortcode, $shortcodename, $args, null, $env, $next);
        $this->assertNotEmpty($shortcoderenderedtable);
        preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $shortcoderenderedtable, $matches);
        $errormessage = 'Unsuccessful to extract encodedtable string from table rendering. The table is probably not instantiated.';
        $this->assertNotEmpty($matches, $errormessage);
        $encodedtable = $matches[1];

        // Get the option names sorted via PHP.
        $sortedoptionnames = self::get_sorted_option_names();

        // Since we have around 200 records, we request 10 items per page and check the option names.
        for ($page = 0; $page < 20; $page++) {
            // Call external api to check the result.
            $result = load_data::execute($encodedtable, $page);
            // The $content variable now contains the rows in rendered HTML.
            // We need to make sure that the returned data contains the expected content for page 1.
            $content = json_decode($result['content']);
            // Now we extract the option names from each row of the returned data
            // and compare them with the option names sorted by PHP.
            // We repeat this comparison for each page.
            for ($i = 0; $i < count($content->table->rows); $i++) {
                $row = $content->table->rows[$i];
                // The content is in one of items in leftside property.
                $columns = array_filter($row->leftside, fn($i): bool => $i->key === 'text');
                $coursename = empty($columns) ? '' : $this->extract_link_text((reset($columns))->value);
                $itemnumber = ($args['infinitescrollpage'] * $page) + $i;
                $expectedoptionname = $sortedoptionnames[$itemnumber]['text'];
                // The sorted items in bookingoption_wbtable should match the items in the array sorted by PHP.
                $this->assertSame($expectedoptionname, $coursename);
            }
        }
    }

    /**
     * Extract the link text from an HTML <a> tag.
     *
     * Example:
     *   Given:  $html = "<div><a href='https://www.example.com/'>American Football</a></div>"
     *   Return: "American Football"
     *
     * @param string $html An HTML string containing an <a> tag.
     * @return string The link text.
     */
    public static function extract_link_text($html): string {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);  // Quites warnings for fragments.
        $dom->loadHTML($html);
        $link = $dom->getElementsByTagName('a')->item(0);
        return $link->textContent;
    }

    /**
     * Returns a sorted array of option names.
     *
     * @return array{text: string[]}
     */
    public static function get_sorted_option_names(): array {
        $options = self::option_names_provider();

        usort($options, function ($a, $b) {
            return strcasecmp($a['text'], $b['text']);
        });

        return $options;
    }

    /**
     * short code provider.
     * @return array
     */
    public static function shortcode_provider(): array {
        return [
            'allbookingoptions' => [
                'shortcodename' => 'allbookingoptions',
                'shortcode' => '\mod_booking\shortcodes::allbookingoptions',
                'args' => [
                    'all' => 1,
                    'infinitescrollpage' => 10,
                    'sort' => 1,
                    'pageable' => 0,
                ],
                'config' => [
                    'paramforcmid' => 'cmid',
                    'hascustomfields' => false,
                ],
            ],
        ];
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
            'standardbookingoptions' => self::option_names_provider(),
        ];
    }

    /**
     * Sample options names.
     * @return array{text: string[]}
     */
    public static function option_names_provider(): array {
        return [
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
            ['text' => 'Ultimate Frisbee Workshop'],
        ];
    }
}
