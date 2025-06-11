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
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class rules_selflearningcourse_test extends advanced_testcase {
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
     * Test of booking option with price as well as cancellation by user.
     *
     * @param array $coursedata
     * @param array $pricecategories
     * @param array $rules
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \mod_booking\booking_bookit::bookit
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_with_price_and_cancellation(
        array $coursedata,
        $pricecategories,
        $rules,
        $expected
    ): void {
        global $DB, $CFG;

        // Clean up singletons.
        self::tearDown();

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

        foreach ($rules as $rule) {
            $plugingenerator->create_rule($rule);
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
                        $teachers[$teacher->username] = $teacher;
                        $users[$teacher->username] = $teacher;
                        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
                        break;
                    case 'bookingmanager':
                        $bookingmanager = $this->getDataGenerator()->create_user($user);
                        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);
                        $users[$bookingmanager->username] = $bookingmanager;
                        break;
                    default:
                        $student = $this->getDataGenerator()->create_user($user);
                        $students[$student->username] = $student;
                        $this->getDataGenerator()->enrol_user($student->id, $course->id);
                        $users[$student->username] = $student;
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

                    if (!empty($option['skipbookingrules'])) {
                        $rulename = $option['skipbookingrules'];
                        $sql = "SELECT id
                                FROM {booking_rules}
                                WHERE rulejson LIKE '%$rulename%'";
                        $ruleid = $DB->get_field_sql($sql);
                        $option['skipbookingrules'] = $ruleid;
                    }

                    $option = $plugingenerator->create_option((object)$option);

                    $bookingoptions[$option->identifier] = $option;
                }
            }
        }

        foreach ($expected as $expecteddata) {
            if (isset($expecteddata['config'])) {
                foreach ($expecteddata['config'] as $key => $value) {
                    set_config($key, $value, 'booking');
                }
            }

            $option = $bookingoptions[$expecteddata['boookingoption']];
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

            // Book the first user without any problem.
            $boinfo = new bo_info($settings);

            $user = $users[$expecteddata['user']];
            $this->setUser($user);

            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user->id, false);
            $this->assertEquals($expecteddata['bo_cond'], $id);

            // We can also check how the button actually looks which will be displayed to this user.
            [$templates, $datas] = booking_bookit::render_bookit_template_data($settings, $user->id);

            if ($expecteddata['label'] ?? false) {
                // Check the label of the button.
                $label = $datas[0]->data["main"]["label"];
                $this->assertEquals($expecteddata['label'], $label);
            }

            if ($expecteddata['showprice']) {
                $price = price::get_price('option', $settings->id, $user);

                // We check the price which is stored.
                $this->assertEquals($expecteddata['price'], (float)$price['price']);

                // Here we check the price which is shown on the button.

                if (!is_array($datas[0]->data["price"])) {
                    $price = $datas[0]->data["price"] ?? null;
                    $this->assertEquals($expecteddata['price'], (float)$price);
                } else {
                    $price = $datas[0]->data["price"]["price"] ?? 0;
                    $this->assertEquals($expecteddata['price'], (float)$price);
                }
            }

            if (isset($expecteddata['undoconfig'])) {
                foreach ($expecteddata['undoconfig'] as $key => $value) {
                    set_config($key, $value, 'booking');
                }
            }

            // We actually book the user now.
            $result = booking_bookit::bookit('option', $settings->id, $user->id);
            // We confirm our booking.
            $result = booking_bookit::bookit('option', $settings->id, $user->id);

            if (!empty($settings->selflearningcourse)) {
                rules_info::execute_rules_for_option($settings->id);

                // Fetch records from the database.
                $records = $DB->get_records('task_adhoc');

                // Assert that there is exactly one record.
                $this->assertCount($expecteddata['numberoftasks'], $records, 'Expected exactly one record in task_adhoc table.');

                foreach ($expecteddata['taskexpected'] as $taskexpected) {
                    $record = array_shift($records);
                    // Assert that customdata exists and is a valid JSON string.
                    $this->assertNotEmpty($record->customdata, 'Customdata field is empty.');
                    $this->assertJson($record->customdata, 'Customdata is not a valid JSON string.');

                    // Decode the customdata JSON for further validation.
                    $customdata = json_decode($record->customdata, true);

                    // Check the runtime.
                    $deviation = 10800; // We allow for three hours deviation.

                    $this->assertThat(
                        $record->nextruntime,
                        $this->logicalAnd(
                            $this->greaterThanOrEqual($taskexpected['nextruntime'] - $deviation),
                            $this->lessThanOrEqual($taskexpected['nextruntime'] + $deviation)
                        ),
                        "Duedate value {$record->nextruntime} is not within the expected range
                        of {$taskexpected['nextruntime']} ± {$deviation}."
                    );

                    // Assert non-integer fields in the customdata.
                    $this->assertSame('rule_daysbefore', $customdata['rulename'], 'Incorrect rulename value.');
                    $this->assertIsString($customdata['rulejson'], 'Rulejson should be a string.');
                    $this->assertIsString($customdata['customsubject'], 'Customsubject should be a string.');
                    $this->assertSame($taskexpected['subject'], $customdata['customsubject'], 'Incorrect customsubject value.');

                    // Decode the nested rulejson field to test its values.
                    $rulejson = json_decode($customdata['rulejson'], true);
                    $this->assertNotEmpty($rulejson, 'Rulejson is empty or invalid JSON.');

                    // Assert specific non-integer values within the rulejson field.
                    $this->assertSame('select_student_in_bo', $rulejson['conditionname'], 'Incorrect conditionname value.');
                    $this->assertSame('0', $rulejson['conditiondata']['borole'], 'Incorrect borole value.');
                    $this->assertSame('send_mail', $rulejson['actionname'], 'Incorrect actionname value.');
                    $this->assertSame('rule_daysbefore', $rulejson['rulename'], 'Incorrect rulename value in rulejson.');
                    $this->assertSame($taskexpected['days'], $rulejson['ruledata']['days'], 'Incorrect days value in ruledata.');
                    $this->assertSame(
                        'selflearningcourseenddate',
                        $rulejson['ruledata']['datefield'],
                        'Incorrect datefield value.'
                    );
                }
            }
        }
        // Clean up singletons.
        self::tearDown();
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
                'defaultvalue' => 111,
                'pricecatsortorder' => 1,
            ],
            [
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 222,
                'pricecatsortorder' => 2,
            ],
            [
                'ordernum' => 3,
                'name' => 'staff',
                'identifier' => 'staff',
                'defaultvalue' => 333,
                'pricecatsortorder' => 3,
            ],
        ];

        $standardbookingoptions = [
            [
                'text' => 'Test Booking Option without price',
                'description' => 'Test Booking Option',
                'identifier' => 'noprice',
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
                'identifier' => 'withprice',
                'maxanswers' => 1,
                'useprice' => 1,
                'default' => 20,
                'student' => 10,
                'staff' => 30,
                'importing' => 1,
            ],
            [
                'text' => 'Disalbed Test Booking Option',
                'description' => 'Test Booking Option',
                'identifier' => 'disabledoption',
                'maxanswers' => 1,
                'useprice' => 1,
                'default' => 20,
                'student' => 10,
                'staff' => 30,
                'importing' => 1,
                'disablebookingusers' => 1,
            ],
            [
                'text' => 'Wait for confirmation Booking Option, no price',
                'description' => 'Test Booking Option',
                'identifier' => 'waitforconfirmationnoprice',
                'maxanswers' => 1,
                'useprice' => 0,
                'default' => 20,
                'student' => 10,
                'staff' => 30,
                'importing' => 1,
                'waitforconfirmation' => 1,
            ],
            [
                'text' => 'Wait for confirmation Booking Option, price',
                'description' => 'Test Booking Option',
                'identifier' => 'waitforconfirmationwithprice',
                'maxanswers' => 1,
                'useprice' => 1,
                'default' => 20,
                'student' => 10,
                'staff' => 0,
                'importing' => 1,
                'waitforconfirmation' => 1,
            ],
            [
                'text' => 'self learning course, rules test',
                'description' => 'self learning course, rules test',
                'identifier' => 'selflearningcoursenotifyendtest',
                'maxanswers' => 1,
                'useprice' => 0,
                'importing' => 1,
                'selflearningcourse' => 1,
                'duration' => 84400 * 4, // 4 days.
            ],
            [
                'text' => 'self learning course, rules test',
                'description' => 'self learning course, rules test',
                'identifier' => 'selflearningcoursenotifypreviousdaytest',
                'maxanswers' => 1,
                'useprice' => 0,
                'importing' => 1,
                'selflearningcourse' => 1,
                'duration' => 84400 * 1, // 4 days.
                'skipbookingrulesmode' => 0, // 0 is opt out, 1 is opt in.
                'skipbookingrules' => '1dayafter', // We use name here and fetch id in test.
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
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
                'bookingoptions' => $standardbookingoptions,
                'sendmail' => 0,
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
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
                'bookingoptions' => $standardbookingoptions,
                'sendmail' => 0,
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
            [
                // User 1 in tests.
                'username' => 'teacher1',
                'firstname' => "Teacher",
                'lastname' => "Tester",
                'email' => 'teacher.tester1@example.com',
                'role' => 'teacher',
                'profile_field_pricecat' => 'default',
            ],
            [
                // User 2 in tests.
                'username' => 'bookingmanager',
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

        $icalstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actionstr1 = '"subject":"1 day after","template":"was ended yesterday","templateformat":"1"}';
        $actionstr2 = '"subject":"1 day before","template":"will end tomorrow","templateformat":"1"}';
        $actionstr3 = '"subject":"10 days before","template":"will end in 10 days","templateformat":"1"}';
        $standardrules = [
            [
                'name' => '1dayafter',
                'conditionname' => 'select_student_in_bo',
                'conditiondata' => '{"borole":"0"}',
                'actionname' => 'send_mail',
                'actiondata' => $icalstr . $actionstr1,
                'rulename' => 'rule_daysbefore',
                'ruledata' => '{"days":"-1","datefield":"selflearningcourseenddate","cancelrules":[]}',
            ],
            [
                'name' => '1daybefore',
                'conditionname' => 'select_student_in_bo',
                'conditiondata' => '{"borole":"0"}',
                'actionname' => 'send_mail',
                'actiondata' => $icalstr . $actionstr2,
                'rulename' => 'rule_daysbefore',
                'ruledata' => '{"days":"1","datefield":"selflearningcourseenddate","cancelrules":[]}',
            ],
            [
                'name' => '10daybefore',
                'conditionname' => 'select_student_in_bo',
                'conditiondata' => '{"borole":"0"}',
                'actionname' => 'send_mail',
                'actiondata' => $icalstr . $actionstr3,
                'rulename' => 'rule_daysbefore',
                'ruledata' => '{"days":"10","datefield":"selflearningcourseenddate","cancelrules":[]}',
            ],
        ];

        $returnarray = [];

        // First we add the standards, we can change them here and for each test.
        $courses = $standardcourses;

        $rules = $standardrules;

        // Test 1: Standard booking instance.
        // Booking should be possible, no price.
        $returnarray[] = [
            'courses' => $courses,
            'pricecategories' => $standardpricecategories,
            'rules' => $rules,
            'expected' => [
                [
                    'user' => 'student1',
                    'boookingoption' => 'noprice',
                    'bo_cond' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    'showprice' => false,
                    'price' => 0,
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'withprice',
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 10,
                ],
                [
                    'user' => 'teacher1',
                    'boookingoption' => 'withprice',
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 20,
                ],
                [
                    'user' => 'bookingmanager',
                    'boookingoption' => 'withprice',
                    'bo_cond' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'showprice' => true,
                    'price' => 30,
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'disabledoption', // Booking disabled.
                    'bo_cond' => MOD_BOOKING_BO_COND_ISBOOKABLE,
                    'showprice' => false,
                    'price' => 30,
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'waitforconfirmationnoprice', // Ask for confirmation, no price.
                    'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                    'showprice' => false,
                    'price' => 30,
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'waitforconfirmationwithprice', // Ask for confirmation, price.
                    'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                    'showprice' => true,
                    'price' => 10,
                ],
                [
                    'user' => 'bookingmanager',
                    'boookingoption' => 'waitforconfirmationwithprice', // Ask for confirmation, price.
                    'bo_cond' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION,
                    'showprice' => true,
                    'price' => 0,
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'selflearningcoursenotifyendtest', // Ask for confirmation, price.
                    'bo_cond' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    'showprice' => false,
                    'price' => 10,
                    'numberoftasks' => 2,
                    'taskexpected' => [
                        [
                            'subject' => '1 day after',
                            'nextruntime' => strtotime('+ 5 days'),
                            'days' => '-1',
                        ],
                        [
                            'subject' => '1 day before',
                            'nextruntime' => strtotime('+ 3 days'),
                            'days' => '1',
                        ],
                    ],
                ],
                [
                    'user' => 'student1',
                    'boookingoption' => 'selflearningcoursenotifypreviousdaytest', // Ask for confirmation, price.
                    'bo_cond' => MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    'showprice' => false,
                    'price' => 10,
                    'numberoftasks' => 3, // 2 from previous test.
                    'taskexpected' => [
                        [
                            'subject' => '1 day after', // From previous test.
                            'nextruntime' => strtotime('+ 5 days'),
                            'days' => '-1',
                        ],
                        [
                            'subject' => '1 day before', // From previous test.
                            'nextruntime' => strtotime('+ 3 days'),
                            'days' => '1',
                        ],
                        [
                            'subject' => '1 day before',
                            'nextruntime' => strtotime('now'),
                            'days' => '1',
                        ],
                    ],
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
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
    }
}
