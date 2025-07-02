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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use cache_helper;
use coding_exception;
use context_system;
use mod_booking\bo_availability\conditions\maxoptionsfromcategory;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;
use tool_mocktesttime\time_mock;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class condition_maxoptionsfromcategory_test extends advanced_testcase {
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
     * Test of booking options with max options from category.
     *
     * @covers \mod_booking\booking_bookit
     *
     * @param array $coursedata
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_max_options_from_category(array $coursedata, $expected): void {
        $this->tearDown();
        global $DB, $CFG;

        $users = [];
        $bookingoptions = [];
        $boinstance = [];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create the custom field and select it as the field to use for max options from category.
        // Create custom booking field.
        $categorydata = [
            'name' => 'BookCustomCat1',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ];
        $bookingcat = $this->getDataGenerator()->create_custom_field_category($categorydata);
        $bookingcat->save();
        $catid = $bookingcat->get('id');

        $fielddata = [
            'categoryid' => $catid,
            'name' => 'Sport',
            'shortname' => 'sport',
            'type' => 'text',
            'configdata' => "",
        ];
        $bookingfield = $this->getDataGenerator()->create_custom_field($fielddata);
        $bookingfield->save();

        $this->setAdminUser();

        // Create the courses, depending on data provider.
        foreach ($coursedata as $coursearray) {
            $course = $this->getDataGenerator()->create_course((object)$coursearray);
            $courses[$course->id] = $course;

            // Create users.
            if (empty($users)) {
                foreach ($coursearray['users'] as $user) {
                    $student = $this->getDataGenerator()->create_user($user);
                    $this->getDataGenerator()->enrol_user($student->id, $course->id);
                    $users[$student->username] = $student;
                }
            } else {
                foreach ($users as $user) {
                    $this->getDataGenerator()->enrol_user($user->id, $course->id);
                }
            }
            // Create Booking instances.
            foreach ($coursearray['bdata'] as $bdata) {
                $bdata['course'] = $course->id;
                $booking = $this->getDataGenerator()->create_module('booking', (object)$bdata);
                $boinstance[] = $booking;
                // Create booking options.
                foreach ($bdata['bookingoptions'] as $option) {
                    $option['bookingid'] = $booking->id;
                    $option = $plugingenerator->create_option((object)$option);
                    $bookingoptions[$option->identifier] = $option;
                }
            }
        }
        foreach ($expected as $expecteddata) {
            foreach ($boinstance as $key => $booking) {
                $jsondata = $expecteddata['bookinginstancesettings'][$key];
                $DB->update_record('booking', (object)['id' => $booking->id, 'json' => $jsondata]);
            }

            booking::purge_cache_for_booking_instance_by_cmid($option->cmid);
            $booking = singleton_service::get_instance_of_booking_by_bookingid($booking->id);
            foreach ($expecteddata['bookingconfig'] as $config) {
                set_config($config['name'], $config['value'], 'booking');
            }

            $firstenrollbos = [];
            $bos = [];
            foreach ($bookingoptions as $option) {
                // Use the same bookingoptions in both booking instances.
                $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
                $boinfo = new bo_info($settings);
                if ($option->identifier == "aerialsilksecondinstance") {
                    $firstenrollbos[] = [
                        'settings' => $settings,
                        'boinfo' => $boinfo,
                        'option' => $option,
                        'identifier' => $option->identifier,
                    ];
                } else {
                    $bos[] = [
                        'settings' => $settings,
                        'boinfo' => $boinfo,
                        'option' => $option,
                        'identifier' => $option->identifier,
                    ];
                }
            }

            $user = $users[$expecteddata['user']];
            $this->setUser($user);

            // First make sure to enrol into bo of first instance.
            foreach ($firstenrollbos as $bo) {
                $settings = $bo['settings'];
                $result = booking_bookit::bookit('option', $settings->id, $user->id);
                $result = booking_bookit::bookit('option', $settings->id, $user->id);
                maxoptionsfromcategory::reset_instance();
                singleton_service::destroy_instance();
                cache_helper::invalidate_by_event('setbackoptionsanswers', [$bo['option']->id]);
            }

            // Then we proceed with the actual test for bookingoptions in the second instance.
            foreach ($bos as $bo) {
                $settings = $bo['settings'];
                $boinfo = $bo['boinfo'];
                $option = $bo['option'];
                $identifier = $bo['identifier'];
                if (isset($expecteddata['results'][$identifier])) {
                    [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, false);
                    $this->assertEquals($expecteddata['results'][$identifier][0], $id);
                    $result = booking_bookit::bookit('option', $settings->id, $user->id);
                    [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
                    $this->assertEquals($expecteddata['results'][$identifier][1], $id);
                    $result = booking_bookit::bookit('option', $settings->id, $user->id);
                    [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, true);
                    $this->assertEquals($expecteddata['results'][$identifier][2], $id);
                    maxoptionsfromcategory::reset_instance();
                    singleton_service::destroy_instance();
                    cache_helper::invalidate_by_event('setbackoptionsanswers', [$bo['option']->id]);
                }
            }

            $DB->delete_records('booking_answers');
            foreach ($bos as $bo) {
                cache_helper::invalidate_by_event('setbackoptionsanswers', [$bo['option']->id]);
            }
            $this->tearDown();
        }
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        $bookingoptions = [
            [
                'text' => 'Test bookingoption with customfield AERIAL SILK 1',
                'description' => 'Test Booking Option',
                'identifier' => 'aerialsilk1',
                'maxanswers' => 10,
                'importing' => 1,
                'sport' => 'AERIAL SILK',
            ],
            [
                'text' => 'Test bookingoption with customfield AERIAL SILK 2',
                'description' => 'Test Booking Option',
                'identifier' => 'aerialsilk2',
                'maxanswers' => 10,
                'importing' => 1,
                'sport' => 'AERIAL SILK',
            ],
            [
                'text' => 'Test bookingoption with customfield OTHER VALUE',
                'description' => 'Test Booking Option',
                'identifier' => 'othervalue',
                'maxanswers' => 10,
                'importing' => 1,
                'sport' => 'OTHER VALUE',
            ],
            [
                'text' => 'Test bookingoption without customfield',
                'description' => 'Test Booking Option',
                'identifier' => 'withoutcustomfield',
                'maxanswers' => 10,
                'importing' => 1,
                'sport' => 'OTHER VALUE',
            ],
        ];

        $standardbookinginstances =
        [
            [
                // Booking instance 0 in tests.
                'name' => 'Presetting instance',
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
                'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
                'bookingoptions' => [
                    0 => [
                        'text' => 'Test bookingoption with customfield AERIAL SILK second instance',
                        'description' => 'Test Booking Option',
                        'identifier' => 'aerialsilksecondinstance',
                        'maxanswers' => 10,
                        'importing' => 1,
                        'sport' => 'AERIAL SILK',
                        ],
                    ],
            ],
            [
                // Booking instance 1 in tests.
                'name' => 'Second booking instance',
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
                'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
                'bookingoptions' => $bookingoptions,
            ],
        ];

        $standardusers = [
            [ // User 0 in tests.
                'username'  => 'student1',
                'firstname' => "Student1",
                'lastname' => "Tester1",
                'email' => 'student.tester1@example.com',
                'role' => 'student',
                'profile_field_pricecat' => 'student',
            ],
            [ // User 1 in tests.
                'username'  => 'student2',
                'firstname' => "Student2",
                'lastname' => "Tester2",
                'email' => 'student.tester2@example.com',
                'role' => 'student',
                'profile_field_pricecat' => 'student',
            ],
        ];

        $standardcourses = [
            [
                'fullname' => 'Test Course',
                'bdata' => $standardbookinginstances,
                'users' => $standardusers,
            ],
        ];
        $bookingconfig = [
            'off' => [],
            'on' => [
                [
                    'value' => 1,
                    'name' => 'maxoptionsfromcategory',
                ],
                [
                    'value' => 'sport',
                    'name' => 'maxoptionsfromcategoryfield',
                ],
            ],
        ];

        $conditionresult = [
            'bookable' => [
                MOD_BOOKING_BO_COND_BOOKITBUTTON,
                MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
            ],
            'blocking' => [
                MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
            ],
        ];

        $returnarray = [];

        // First we add the standards, we can change them here and for each test.
        $courses = $standardcourses;

        // Test 1: Standard booking instance.
        // Booking should be possible, no price.
        $returnarray[] = [
            'courses' => $courses,
            'expected' => [
                'maxoneoptionblock' =>
                    [   'bookingconfig' => $bookingconfig['on'],
                        'bookinginstancesettings' => [
                            '{}', // First instance doesn't contain any restrictions.
                            '{"maxoptionsfromcategory":' .
                                '"{\"1\":{\"count\":1,\"localizedstring\":\"AERIAL SILK\"},' .
                                '\"2\":' .
                                '{\"count\":1,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"}}",' .
                                '"maxoptionsfrominstance":"1"}',
                        ],
                        'user' => 'student1',
                        'bookingoptions' => $bookingoptions,
                        'results' => [
                            // With these settings, first option is supposed to be bookable and second to block.
                            'aerialsilk1' => $conditionresult['bookable'],
                            'aerialsilk2' => $conditionresult['blocking'], // This one is blocking.
                            'othervalue' => $conditionresult['bookable'],
                            'withoutcustomfield' => $conditionresult['bookable'],
                        ],
                    ],
                'maxtwooptionblock' =>
                    [   'bookingconfig' => $bookingconfig['on'],
                        'bookinginstancesettings' => [
                            '{}', // First instance doesn't contain any restrictions.
                            '{"maxoptionsfromcategory":' . // Count is set to two.
                                '"{\"1\":{\"count\":2,\"localizedstring\":\"AERIAL SILK\"},' .
                                '\"aerialstrengthflexibility\":' .
                                '{\"count\":2,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"}}",' .
                                '"maxoptionsfrominstance":"1"}',
                        ],
                        'user' => 'student2',
                        'bookingoptions' => $bookingoptions,
                        'results' => [
                            // With these settings, first option is supposed to be bookable and second to block.
                            'aerialsilk1' => $conditionresult['bookable'],
                            'aerialsilk2' => $conditionresult['bookable'],
                            'othervalue' => $conditionresult['bookable'],
                            'withoutcustomfield' => $conditionresult['bookable'],
                        ],
                    ],
                'secondinstanceblock' =>
                    [   'bookingconfig' => $bookingconfig['on'],
                        'bookinginstancesettings' => [
                            '{}', // First instance doesn't contain any restrictions.
                            '{"maxoptionsfromcategory":' . // Count is set to two.
                                '"{\"1\":{\"count\":2,\"localizedstring\":\"AERIAL SILK\"}}",' .
                                '"maxoptionsfrominstance":"0"}',
                        ], // Instance is disabled ("maxoptionsfrominstance":"0") & count is set to two.
                        'user' => 'student2',
                        'bookingoptions' => $bookingoptions,
                        'results' => [
                            // Setting has slightly changed...
                            // Now booking answers from other instaces are counted for the blocking limit.
                            // So with one booking from first instance, and limit count 2, ...
                            // ... the first aerialsilk option is bookable and second is blocking.
                            'aerialsilk1' => $conditionresult['bookable'],
                            'aerialsilk2' => $conditionresult['blocking'],
                            'othervalue' => $conditionresult['bookable'],
                            'withoutcustomfield' => $conditionresult['bookable'],
                        ],
                    ],
                'settingsoff' =>
                    [   'bookingconfig' => $bookingconfig['off'],
                        'bookinginstancesettings' => [
                            '{}', // First instance doesn't contain any restrictions.
                            '{"maxoptionsfromcategory":"{\"1\":{\"count\":2,\"localizedstring\":\"AERIAL SILK\"},' .
                            '\"2\":{\"count\":2,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"}}",' .
                            '"maxoptionsfrominstance":"1"}',
                        ],
                        'user' => 'student2',
                        'bookingoptions' => $bookingoptions,
                        'results' => [
                            // With settings in plugin disabled, everything should be bookable.
                            'aerialsilk1' => $conditionresult['bookable'],
                            'aerialsilk2' => $conditionresult['bookable'],
                            'othervalue' => $conditionresult['bookable'],
                            'withoutcustomfield' => $conditionresult['bookable'],
                        ],
                    ],
                'nolimitset' =>
                    [   'bookingconfig' => $bookingconfig['on'],
                        'bookinginstancesettings' => [
                            '{}', // First instance doesn't contain any restrictions.
                            '{"maxoptionsfromcategory":"{\"1\":{\"count\":0,\"localizedstring\":\"AERIAL SILK\"},' .
                            '\"2\":{\"count\":0,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"}}",' . '
                            "maxoptionsfrominstance":"1"}',
                        ],
                        'user' => 'student2',
                        'bookingoptions' => $bookingoptions,
                        'results' => [
                            // With settings in plugin disabled, everything should be bookable.
                            'aerialsilk1' => $conditionresult['bookable'],
                            'aerialsilk2' => $conditionresult['bookable'],
                            'othervalue' => $conditionresult['bookable'],
                            'withoutcustomfield' => $conditionresult['bookable'],
                        ],
                    ],
            ],
        ];

        return $returnarray;
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        cache_helper::purge_all();
        maxoptionsfromcategory::reset_instance();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
