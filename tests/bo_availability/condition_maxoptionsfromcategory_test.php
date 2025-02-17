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
use coding_exception;
use context_system;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;


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
    }

    /**
     * Test of booking options with max options from category.
     *
     * @covers \booking_bookit
     *
     * @param array $coursedata
     * @param array $pricecategories
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_max_options_from_category(array $coursedata, $expected): void {
        global $DB, $CFG;

        $users = [];
        $bookingoptions = [];

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
            foreach ($coursearray['users'] as $user) {
                $student = $this->getDataGenerator()->create_user($user);
                $this->getDataGenerator()->enrol_user($student->id, $course->id);
                $users[$student->username] = $student;
                break;
            }

            // Create Booking instances.
            foreach ($coursearray['bdata'] as $bdata) {
                $bdata['course'] = $course->id;
                $booking = $this->getDataGenerator()->create_module('booking', (object)$bdata);

                // Create booking options.
                foreach ($bdata['bookingoptions'] as $option) {
                    $option['bookingid'] = $booking->id;

                    $option = $plugingenerator->create_option((object)$option);

                    $bookingoptions[$option->identifier] = $option;
                }
            }
        }
        // Set this config for the entire test.
        set_config('maxoptionsfromcategory', 1, 'booking');
        set_config('maxoptionsfromcategoryfield', 'sport', 'booking');
        foreach ($expected as $expecteddata) {

            $option1 = $bookingoptions[$expecteddata['boookingoption_1']];
            $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $option2 = $bookingoptions[$expecteddata['boookingoption_2']];
            $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);

            // Book the first user without any problem.
            $boinfo1 = new bo_info($settings1);
            $boinfo2 = new bo_info($settings2);

            $user = $users[$expecteddata['user']];
            $this->setUser($user);

            // Book the first option to simulate booking answers.
            [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $user->id, false);
            $this->assertEquals($expecteddata['bo_cond_1'], $id);
            $result = booking_bookit::bookit('option', $settings1->id, $user->id);
            [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $user->id, true);
            $this->assertEquals($expecteddata['bo_cond_2'], $id);
            $result = booking_bookit::bookit('option', $settings1->id, $user->id);
            [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $user->id, true);
            $this->assertEquals($expecteddata['bo_cond_3'], $id);

            [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $user->id, false);
            $this->assertEquals($expecteddata['bo_cond_block'], $id);

            // Now try to book the second option.
        }
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        $standardbookingoptions = [
            [
                'text' => 'Test Booking Option without price',
                'description' => 'Test Booking Option',
                'identifier' => 'noprice',
                'maxanswers' => 1,
                'importing' => 1,
                'sport' => 'AERIAL SILK',
            ],
            [
                'text' => 'Test Booking Option with price',
                'description' => 'Test Booking Option',
                'identifier' => 'withprice',
                'maxanswers' => 1,
                'importing' => 1,
                'sport' => 'AERIAL SILK',
            ],
            [
                'text' => 'Disalbed Test Booking Option',
                'description' => 'Test Booking Option',
                'identifier' => 'disabledoption',
                'maxanswers' => 1,
                'importing' => 1,
                'disablebookingusers' => 1,
                'sport' => 'AERIAL SILK',
            ],
            [
                'text' => 'Wait for confirmation Booking Option, no price',
                'description' => 'Test Booking Option',
                'identifier' => 'waitforconfirmationnoprice',
                'maxanswers' => 1,
                'importing' => 1,
                'waitforconfirmation' => 1,
                'sport' => 'AERIAL SILK',
            ],
        ];

        $standardbookinginstances =
        [
            [
                // Booking instance 0 in tests.
                'name' => 'Restricting aerialsilk limit 3 in instance',
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
                'json' => '{"cancelrelativedate":"2","viewparam":0,"maxoptionsfromcategory":"{\"aerialhammockacrobatics\":{\"count\":1,\"localizedstring\":\"AERIAL HAMMOCK ACROBATICS\"},\"aerialhoop\":{\"count\":1,\"localizedstring\":\"AERIAL HOOP\"},\"aerialpilates\":{\"count\":1,\"localizedstring\":\"AERIAL PILATES\"},\"aerialsilk\":{\"count\":1,\"localizedstring\":\"AERIAL SILK\"},\"aerialstrengthflexibility\":{\"count\":1,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"},\"aerialtrapez\":{\"count\":1,\"localizedstring\":\"AERIAL TRAPEZ\"}}","maxoptionsfrominstance":"1"}',
                'bookingoptions' => $standardbookingoptions,
            ],
            [
                // Booking instance 1 in tests.
                'name' => 'Restricting aerialsilk limit 1 in instance',
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
                'json' => '{"cancelrelativedate":"2","viewparam":0,"maxoptionsfromcategory":"{\"aerialhammockacrobatics\":{\"count\":1,\"localizedstring\":\"AERIAL HAMMOCK ACROBATICS\"},\"aerialhoop\":{\"count\":1,\"localizedstring\":\"AERIAL HOOP\"},\"aerialpilates\":{\"count\":1,\"localizedstring\":\"AERIAL PILATES\"},\"aerialsilk\":{\"count\":1,\"localizedstring\":\"AERIAL SILK\"},\"aerialstrengthflexibility\":{\"count\":1,\"localizedstring\":\"AERIAL STRENGTH&FLEXIBILITY\"},\"aerialtrapez\":{\"count\":1,\"localizedstring\":\"AERIAL TRAPEZ\"}}","maxoptionsfrominstance":"1"}',
                'bookingoptions' => $standardbookingoptions,
                        ],
        ];

        $standardusers = [
            [ // User 0 in tests.
                'username'  => 'student1',
                'firstname' => "Student",
                'lastname' => "Tester",
                'email' => 'student.tester1@example.com',
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

        $returnarray = [];

        // First we add the standards, we can change them here and for each test.
        $courses = $standardcourses;

        // Test 1: Standard booking instance.
        // Booking should be possible, no price.
        $returnarray[] = [
            'courses' => $courses,
            'expected' => [
                [
                    'user' => 'student1',
                    'boookingoption_1' => 'noprice',
                    'boookingoption_2' => 'withprice',
                    'bo_cond_1' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    'bo_cond_2' => MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                    'bo_cond_3' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    'bo_cond_block' => MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                    'showprice' => false,
                    'price' => 0,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
                // [
                //     'user' => 'student1',
                //     'boookingoption' => 'withprice',
                //     'bo_cond_1' => MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                //     'bo_cond_2' => MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                //     'bo_cond_3' => MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY,
                //     'showprice' => true,
                //     'price' => 10,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'teacher1',
                //     'boookingoption' => 'withprice',
                //     'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                //     'showprice' => true,
                //     'price' => 20,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'bookingmanager',
                //     'boookingoption' => 'withprice',
                //     'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                //     'showprice' => true,
                //     'price' => 30,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'student1',
                //     'boookingoption' => 'disabledoption', // Booking disabled.
                //     'bo_cond' => MOD_BOOKING_BO_COND_ISBOOKABLE,
                //     'showprice' => false,
                //     'price' => 30,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'student1',
                //     'boookingoption' => 'waitforconfirmationnoprice', // Ask for confirmation, no price.
                //     'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                //     'showprice' => false,
                //     'price' => 30,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'student1',
                //     'boookingoption' => 'waitforconfirmationwithprice', // Ask for confirmation, price.
                //     'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                //     'showprice' => true,
                //     'price' => 10,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
                // [
                //     'user' => 'bookingmanager',
                //     'boookingoption' => 'waitforconfirmationwithprice', // Ask for confirmation, price.
                //     'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                //     'showprice' => true,
                //     'price' => 0,
                //     'cancancelbook' => 0,
                //     'canbook' => 1,
                // ],
            ],

        ];

        // Test 2: Standard booking instance.
        // Price should be shown.

        return $returnarray;
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
