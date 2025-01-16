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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class bookitbutton_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test of booking option with price as well as cancellation by user.
     *
     * @covers \booking_bookit
     *
     * @param array $bdata
     * @param array $users
     * @param array $courses
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_with_price_and_cancellation(array $coursedata, $pricecategories, $expected): void {
        global $DB, $CFG;

        $users = [];
        $bookingoptions = [];

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        foreach ($pricecategories as $pricecategory) {
            $plugingenerator->create_pricecategory($pricecategory);
        }

        $this->setAdminUser();

        // Create the courses, depending on data provider.
        foreach ($coursedata as $coursearray) {
            $course = $this->getDataGenerator()->create_course((object)$coursearray);
            $courses[$course->id] = $course;

            // Create users.
            foreach ($coursearray['users'] as $user) {
                switch ($user['role']) {
                    case 'teacher':
                        $teacher = $this->getDataGenerator()->create_user($user);
                        $teachers[$teacher->id] = $teacher;
                        $users[] = $teacher;
                        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
                        break;
                    case 'bookingmanager':
                        $bookingmanager = $this->getDataGenerator()->create_user($user);
                        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);
                        $users[] = $bookingmanager;
                        break;
                    default:
                        $student = $this->getDataGenerator()->create_user($user);
                        $students[$student->id] = $student;
                        $this->getDataGenerator()->enrol_user($student->id, $course->id);
                        $users[] = $student;
                        break;
                }
            }

            // Create Booking instances.
            foreach ($coursearray['bdata'] as $bdata) {
                $bdata['course'] = $course->id;
                $booking = $this->getDataGenerator()->create_module('booking', (object)$bdata);

                // Create booking options.
                foreach ($bdata['bookingoptions'] as $option) {
                    $option['bookingid'] = $booking->id;
                    $option = $plugingenerator->create_option((object)$option);

                    $bookingoptions[] = $option;
                }
            }
        }

        foreach ($expected as $expecteddata) {
            $option = $bookingoptions[$expecteddata['boookingoption']];
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

            // Book the first user without any problem.
            $boinfo = new bo_info($settings);

            $user = $users[$expecteddata['user']];
            $this->setUser($user);

            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, false);
            $this->assertEquals($expecteddata['bo_cond'], $id);

            if ($expecteddata['showprice']) {
                $price = price::get_price('option', $settings->id, $user);

                $this->assertEquals($expecteddata['price'], (float)$price['price']);
            }
        }
    }


    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        $standardpricecategories = [
            [
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            [
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 100,
                'pricecatsortorder' => 2,
            ],
            [
                'ordernum' => 3,
                'name' => 'staff',
                'identifier' => 'staff',
                'defaultvalue' => 100,
                'pricecatsortorder' => 3,
            ],
        ];

        $standardbookingoptions = [
            [
                'text' => 'Test Booking Option without price',
                'description' => 'Test Booking Option',
                'maxanswers' => 1,
                'useprice' => 0,
                'price' => 0,
                'student' => 0,
                'staff' => 0,
                'importing' => 1,
            ],
            [
                'text' => 'Test Booking Option with price',
                'description' => 'Test Booking Option',
                'maxanswers' => 1,
                'useprice' => 1,
                'default' => 20,
                'student' => 10,
                'staff' => 30,
                'importing' => 1,
            ],
            [
                'text' => 'Test Booking Option with price',
                'description' => 'Test Booking Option',
                'maxanswers' => 1,
                'useprice' => 1,
                'default' => 20,
                'student' => 10,
                'staff' => 30,
                'importing' => 1,
                'disablebookingusers' => 1,
            ],
        ];

        $standardbookinginstances =
        [
            [
                // Booking instance 0 in tests.
                'name' => 'Test Booking Instance 0',
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
                'bookingoptions' => $standardbookingoptions,
            ],
            [
                // Booking instance 1 in tests.
                'name' => 'Test Booking Instance 1',
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
                'bookingoptions' => $standardbookingoptions,
            ],
        ];

        $standardusers = [
            [ // User 0 in tests.
                'firstname' => "Student",
                'lastname' => "Tester",
                'email' => 'student.tester1@example.com',
                'role' => 'student',
                'profile_field_pricecat' => 'student',
            ],
            [
                // User 1 in tests.
                'firstname' => "Teacher",
                'lastname' => "Tester",
                'email' => 'teacher.tester1@example.com',
                'role' => 'teacher',
                'profile_field_pricecat' => 'default',
            ],
            [
                // User 2 in tests.
                'firstname' => "Booking",
                'lastname' => "Manager",
                'email' => 'booking.manager@example.com',
                'role' => 'bookingmanager',
                'profile_field_pricecat' => 'staff',
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
            'pricecategories' => $standardpricecategories,
            'expected' => [
                [
                    'user' => 0,
                    'boookingoption' => 0,
                    'bo_cond' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    'showprice' => false,
                    'price' => 0,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
                [
                    'user' => 0,
                    'boookingoption' => 1,
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 10,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
                [
                    'user' => 1,
                    'boookingoption' => 1,
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 20,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
                [
                    'user' => 2,
                    'boookingoption' => 1,
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 30,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
                [
                    'user' => 0,
                    'boookingoption' => 2,
                    'bo_cond' => MOD_BOOKING_BO_COND_ISBOOKABLE,
                    'showprice' => false,
                    'price' => 30,
                    'cancancelbook' => 0,
                    'canbook' => 1,
                ],
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
