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
 * Tests for booking rules on specific time.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use local_entities_generator;
use mod_booking\booking_rules\rules_info;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rule on specific time.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_specifictime_test extends advanced_testcase {
    /**
     * String that is displayed in the mtask log when mail was send successfully.
     *
     * @var string
     */
    public const MAIL_SUCCES_TRACE = 'send_mail_by_rule_adhoc task: mail successfully sent';
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
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
     * Testing of a new booking rule type rule_specifictime.
     * It allows users to choose time in seconds, minutes, hours, days and weeks (duration field)
     * and choose between "before" and "after".
     * Checks whether:
     * Session reminders: Remind users 5 minutes before every session
     * Reminder one year after a booking option has ended (courseendtime)
     * Reminder one week before start of booking option (coursestarttime)
     *
     * @covers \mod_booking\booking_rules\rules\rule_specifictime::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     *
     * @dataProvider rule_multiple_dates_override_provider
     *
     * @param array $data describes the type of change to the option
     * @param array $expected expected traces for messages sent vs prevented
     * @throws \coding_exception
     */
    public function test_rule_on_specific_time(array $data, array $expected): void {
        global $DB;

        $this->resetAfterTest(true);

        $this->setAdminUser();
        $bdata = self::booking_common_settings_provider();

        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

        // Setup course, users, booking instance.
        $courses = [];
        for ($i = 0; $i <= 1; $i++) {
            $courses[$i] = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        }
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();

        $bdata['booking']['course'] = $courses[0]->id;
        $bdata['booking']['bookingmanager'] = $teacher1->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $courses[0]->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher2->id, $courses[0]->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $courses[0]->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $courses[0]->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $courses[0]->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create rule(s).
        if (isset($data['rulessettings'])) {
            foreach ($data['rulessettings'] as $rulesettings) {
                $plugingenerator->create_rule($rulesettings);
            }
        } else {
            return;
        }

        // Create booking option with 3 session dates in the remote future.
        $record = $bdata['options'][$data['useoption']];
        $record['bookingid'] = $booking->id;
        $record['courseid'] = $courses[$data['usecourse']]->id;
        $record['importing'] = 1;
        $record['teachersforoption'] = $teacher1->username . ',' . $teacher2->username;
        $record['maxanswers'] = 2;
        $record['maxoverbooking'] = 1; // Enable waitinglist.
        // Override settings for option from dataprovider.
        if (isset($data['optionsettings'])) {
            foreach ($data['optionsettings'] as $setting) {
                foreach ($setting as $key => $value) {
                    $record[$key] = $value;
                }
            }
        }
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $result);
        singleton_service::destroy_booking_answers($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Initial number of scheduled tasks.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['initialnumberoftasks'], $tasks, 'wrong number of tasks');

        if (isset($expected['tasksperoptiondates'])) {
            $time = time_mock::get_mock_time();
            unset_config('noemailever');
            foreach ($expected['tasksperoptiondates'] as $tasksperoptiondates) {
                if (!empty($data['mock_timestamp'])) {
                    time_mock::set_mock_time($tasksperoptiondates['mock_time']);
                } else {
                    time_mock::set_mock_time(strtotime($tasksperoptiondates['mock_time'], $time));
                }
                ob_start();
                $messagesink = $this->redirectMessages();
                $plugingenerator->runtaskswithintime(time_mock::get_mock_time());
                $messages = $messagesink->get_messages();
                $trace = ob_get_clean();
                $messagesink->close();

                // Assertions.
                $this->assertCount($tasksperoptiondates['messages_sent'], $messages);
                // Check the log contains "mail successfully sent".
                if (isset($tasksperoptiondates['contains_success'])) {
                    $this->assertTrue(
                        substr_count($trace, $tasksperoptiondates['contains_success']) >= $tasksperoptiondates['messages_sent']
                    );
                }
            }
        }
    }

    /**
     * Data provider for test_rule_on_multiple_optiondates_override.
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function rule_multiple_dates_override_provider(): array {
        return [
            'Reminder to manager two hours before booking opening time (bookingopeningtime)' => [
                [
                    'rulessettings' => [
                        0 => [
                            'name' => 'Reminder to manager two hours before booking opening time (bookingopeningtime)',
                            'useastemplate' => 0,
                            'conditionname' => 'select_booking_manager',
                            'conditiondata' => '',
                            'actionname' => 'send_mail',
                            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                                "subject":"A new session of {Title} will start in 2 hours",
                                "template":"Hi {firstname}. Booking will be allowed in 2 hours:<br>{bookingdetails}",
                                "templateformat":"1"}',
                            'rulename' => 'rule_specifictime',
                            'ruledata' => '{"seconds":7200,"datefield":"bookingopeningtime"}',
                        ],
                    ],
                    'useoption' => 3,
                    'usecourse' => 0,
                    'optionsettings' => [
                        [
                            'restrictanswerperiodopening' => 1,
                            'bookingopeningtime' => '4 June 2050 18:00', // Required in case of importing!
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 1,
                    'tasksperoptiondates' => [
                        [
                            'mock_time' => '4 June 2050 15:00',
                            'messages_sent' => 0, // More than 2 hours before bookingopeningtime.
                        ],
                        [
                            'mock_time' => '4 June 2050 16:30',
                            'messages_sent' => 1, // Less than 2 hours before bookingopeningtime.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                        [
                            'mock_time' => '5 June 2050 18:00',
                            'messages_sent' => 0, // Confirm no other messages on days before actual booking.
                        ],
                    ],
                ],
            ],
            'Reminder 2 days after a selflearning course has ended (selflearningcourseenddate)' => [
                [
                    'rulessettings' => [
                        0 => [
                            'name' => 'Reminder 2 days after a selflearning course has ended (selflearningcourseenddate)',
                            'useastemplate' => 0,
                            'conditionname' => 'select_student_in_bo',
                            'conditiondata' => '{"borole":"0"}',
                            'actionname' => 'send_mail',
                            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                                "subject":"A session {Title} was 2 days ago",
                                "template":"Hi {firstname}. The session of \\"{title}\\" was 2 days ago:<br>{bookingdetails}",
                                "templateformat":"1"}',
                            'rulename' => 'rule_specifictime',
                            'ruledata' => '{"seconds":-168800,"datefield":"selflearningcourseenddate"}', // 2 days after.
                        ],
                    ],
                    'useoption' => 0,
                    'usecourse' => 1,
                    'mock_timestamp' => 1,
                    'optionsettings' => [
                        [
                            'selflearningcourse' => 1,
                            'duration' => 84400 * 4, // 4 days.
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 2,
                    'tasksperoptiondates' => [
                        [
                            'mock_time' => strtotime('+5 days', time()),
                            'messages_sent' => 0, // Less than 2 days after selflearningcourseenddate.
                        ],
                        [
                            'mock_time' => strtotime('+7 days', time()),
                            'messages_sent' => 2, // More than 2 days after selflearningcourseenddate.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                    ],
                ],
            ],
            'Reminder one year after a booking option has ended (courseendtime)' => [
                [
                    'rulessettings' => [
                        0 => [
                            'name' => 'Session reminder: one year after a booking option has ended (courseendtime)',
                            'useastemplate' => 0,
                            'conditionname' => 'select_teacher_in_bo',
                            'conditiondata' => '',
                            'actionname' => 'send_mail',
                            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                                "subject":"A session {Title} was year ago",
                                "template":"Hi {firstname}. The session of \\"{title}\\" was a year ago:<br>{bookingdetails}",
                                "templateformat":"1"}',
                            'rulename' => 'rule_specifictime',
                            'ruledata' => '{"seconds":-31536000,"datefield":"courseendtime"}',
                        ],
                    ],
                    'useoption' => 2,
                    'usecourse' => 0,
                    'optionsettings' => [
                    ],
                ],
                [
                    'initialnumberoftasks' => 2,
                    'tasksperoptiondates' => [
                        [
                            'mock_time' => '14 June 2051 14:00',
                            'messages_sent' => 0, // Less than 1 year after courseendtime.
                        ],
                        [
                            'mock_time' => '15 June 2051 16:30',
                            'messages_sent' => 2, // More than 1 year after courseendtime.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                    ],
                ],
            ],
            'Reminder one week before start of booking option (coursestarttime)' => [
                [
                    'rulessettings' => [
                        0 => [
                            'name' => 'Session reminder: one week before start of booking option (coursestarttime)',
                            'useastemplate' => 0,
                            'conditionname' => 'select_users',
                            'conditiondata' => '{"userids":["2"]}',
                            'actionname' => 'send_mail',
                            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                                "subject":"A new session of {Title} will start in a week",
                                "template":"Hi {firstname}. The session of \\"{title}\\" will start soon:<br>{bookingdetails}",
                                "templateformat":"1"}',
                            'rulename' => 'rule_specifictime',
                            'ruledata' => '{"seconds":604800,"datefield":"coursestarttime"}',
                        ],
                    ],
                    'useoption' => 2,
                    'usecourse' => 0,
                    'optionsettings' => [
                    ],
                ],
                [
                    'initialnumberoftasks' => 1,
                    'tasksperoptiondates' => [
                        [
                            'mock_time' => '26 May 2050 14:00',
                            'messages_sent' => 0, // More than a week before.
                        ],
                        [
                            'mock_time' => '26 May 2050 15:30',
                            'messages_sent' => 1, // Less than a week before.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                        [
                            'mock_time' => '1 June 2050 15:30',
                            'messages_sent' => 0, // Confirm no other messages on days before.
                        ],
                    ],
                ],
            ],
            'Session reminders: Remind users before every session 1st in 2 days, 2nd and 3rd - in 10 minutes' => [
                [
                    'rulessettings' => [
                        0 => [
                            'name' => 'Session reminder: 1st in 2 days, 2nd and 3rd - in 10 minutes',
                            'useastemplate' => 0,
                            'conditionname' => 'select_student_in_bo',
                            'conditiondata' => '{"borole":"0"}',
                            'actionname' => 'send_mail',
                            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                                "subject":"A new session of {Title} will start soon",
                                "template":"Hi {firstname}. The session of \\"{title}\\" will start soon:<br>{bookingdetails}",
                                "templateformat":"1"}',
                            'rulename' => 'rule_specifictime',
                            'ruledata' => '{"seconds":600,"datefield":"optiondatestarttime"}',
                        ],
                    ],
                    'useoption' => 2,
                    'usecourse' => 0,
                    'optionsettings' => [
                        [
                            'daystonotify_0' => "2", // Override 1st sessiodate - 2 days before.
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 6,
                    'tasksperoptiondates' => [
                        [
                            'mock_time' => '31 May 2050 14:00',
                            'messages_sent' => 0, // More than 2 days before.
                        ],
                        [
                            'mock_time' => '31 May 2050 15:30',
                            'messages_sent' => 2, // Override 1st sessiodate - less than 2 days before.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                        [
                            'mock_time' => '1 June 2050 14:55',
                            'messages_sent' => 0, // Override 1st sessiodate - confirm no messages on less than 10 min before.
                        ],
                        [
                            'mock_time' => '8 June 2050 14:45',
                            'messages_sent' => 0, // More than 10 minutes before.
                        ],
                        [
                            'mock_time' => '8 June 2050 14:55',
                            'messages_sent' => 2, // Less than 10 minutes before.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                        [
                            'mock_time' => '15 June 2050 14:45',
                            'messages_sent' => 0, // More than 10 minutes before.
                        ],
                        [
                            'mock_time' => '15 June 2050 14:55',
                            'messages_sent' => 2, // Less than 10 minutes before.
                            'contains_success' => self::MAIL_SUCCES_TRACE,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        return [
            'booking' => [
                'name' => 'Rule Specific Time Test',
                'eventtype' => 'Test rules',
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
            ],
            'options' => [
                // Option 1 with 1 session in 3 days.
                0 => [
                    'text' => 'Option: in 3 days',
                    'description' => 'Will start in 3 days',
                    'chooseorcreatecourse' => 1, // Required.
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('+3 days', time()),
                    'courseendtime_0' => strtotime('+4 days', time()),
                ],
                // Option 2 with 2 session started in the remote future.
                1 => [
                    'text' => 'Option-with-two-sessions',
                    'description' => 'This option has two optiondates',
                    'chooseorcreatecourse' => 1, // Required.
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('2 June 2050 15:00'),
                    'courseendtime_0' => strtotime('2 June 2050 16:00'),
                    'optiondateid_1' => "0",
                    'daystonotify_1' => "0",
                    'coursestarttime_1' => strtotime('8 June 2050 15:00'),
                    'courseendtime_1' => strtotime('8 June 2050 16:00'),
                ],
                // Option 3 with 3 session started in the remote future.
                2 => [
                    'text' => 'Option-with-three-sessions',
                    'description' => 'This option has three optiondates',
                    'chooseorcreatecourse' => 1, // Required.
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('2 June 2050 15:00'),
                    'courseendtime_0' => strtotime('2 June 2050 16:00'),
                    'optiondateid_1' => "0",
                    'daystonotify_1' => "0",
                    'coursestarttime_1' => strtotime('8 June 2050 15:00'),
                    'courseendtime_1' => strtotime('8 June 2050 16:00'),
                    'optiondateid_2' => "0",
                    'daystonotify_2' => "0",
                    'coursestarttime_2' => strtotime('15 June 2050 15:00'),
                    'courseendtime_2' => strtotime('15 June 2050 16:00'),
                ],
                // Option 1 with 1 session in 2050 with booking oprning time being set.
                3 => [
                    'text' => 'Option: in 2050',
                    'description' => 'Will start in 2050',
                    'chooseorcreatecourse' => 1, // Required.
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('6 June 2050 15:00'),
                    'courseendtime_0' => strtotime('6 June 2050 16:00'),
                ],
            ],
        ];
    }
}
