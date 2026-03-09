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
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rule on specific time.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_cancellation_test extends advanced_testcase {
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
     * Testing of a cancellation of booking answer and entire booking option.
     * option has invisibily MOD_BOOKING_OPTION_VISIBLEWITHLINK, message is send not matter the setting
     * option has invisibily MOD_BOOKING_OPTION_INVISIBLE and setting 'sendmessagesforinvisibleoptions' 0, no message is send
     * option has invisibily MOD_BOOKING_OPTION_INVISIBLE and setting 'sendmessagesforinvisibleoptions' 1, message is send
     * option has invisibily MOD_BOOKING_OPTION_VISIBLE, message is send not matter the setting
     *
     * @covers \mod_booking\event\bookinganswer_cancelled
     * @covers \mod_booking\event\bookingoption_cancelled
     * @covers \mod_booking\booking_option::user_delete_response
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @dataProvider rule_answer_and_option_cancelled_provider
     *
     * @param array $data describes the type of change to the option
     * @param array $expected expected traces for messages sent vs prevented
     * @throws \coding_exception
     */
    public function test_rule_on_answer_and_option_cancelled(array $data, array $expected): void {
        global $DB;

        $this->resetAfterTest(true);

        $this->setAdminUser();
        $bdata = self::booking_common_settings_provider();

        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

        if (isset($data['config'])) {
            foreach ($data['config'] as $key => $value) {
                set_config($key, $value, 'booking');
            }
        }
        // Setup course, users, booking instance.
        $courses = [];
        for ($i = 0; $i < $data['coursenumber']; $i++) {
            $courses[$i] = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        }
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($teacher1->id, $courses[$data['usecourse']]->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher2->id, $courses[$data['usecourse']]->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $courses[$data['usecourse']]->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $courses[$data['usecourse']]->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $courses[$data['usecourse']]->id, 'student');

        $bdata['booking']['course'] = $courses[$data['usecourse']]->id;
        $bdata['booking']['bookingmanager'] = $teacher1->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

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

        // Create booking option.
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

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $result);
        singleton_service::destroy_booking_answers($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Initial number of scheduled tasks.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['initialnumberoftasks'], $tasks, 'wrong number of tasks');

        // Run tasks by events and check messages sent.
        if (isset($expected['tasksbyevents'])) {
            unset_config('noemailever');
            // Cancel booking answer or entire option.
            if ($expected['tasksbyevents']['bookinganswer_cancelled']) {
                $optionobj->user_delete_response($student1->id);
            }
            if ($expected['tasksbyevents']['bookingoption_cancelled']) {
                booking_option::cancelbookingoption($option->id);
            }
            // Get actual messages.
            ob_start();
            $messagesink = $this->redirectMessages();
            $plugingenerator->runtaskswithintime(time_mock::get_mock_time());
            $messages = $messagesink->get_messages();
            $trace = ob_get_clean();
            $messagesink->close();

            // Assertions.
            $this->assertCount($expected['tasksbyevents']['messages_sent'], $messages);
            // Validate scheduled adhoc tasks. Validate messages - order might be free.
            foreach ($messages as $key => $message) {
                if (strpos($message->subject, "answcancsubj") !== false) {
                    // Validate message on the option's answer cancellation.
                    $this->assertEquals("answcancsubj", $message->subject);
                    $this->assertEquals("answcancmsg", $message->fullmessage);
                    $this->assertSame(true, in_array($message->useridto, [$student2->id, $student3->id]));
                } else {
                    // Validate message on the entire option cancellation.
                    $this->assertEquals("optcancsubj", $message->subject);
                    $this->assertEquals("optcancmsg", $message->fullmessage);
                    $this->assertSame(true, in_array($message->useridto, [$teacher1->id, $teacher2->id]));
                }
            }
        }
    }

    /**
     * Data provider for test_rule_on_answer_and_option_cancelled.
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function rule_answer_and_option_cancelled_provider(): array {
        $actstr0 = '"subject":"answcancsubj","template":"answcancmsg"';
        $actstr1 = '"subject":"optcancsubj","template":"optcancmsg"';
        $standardrules = [
            0 => [
                'name' => 'Notify students (Cancel booking ansewer and booking option)',
                'useastemplate' => 0,
                'conditionname' => 'select_student_in_bo',
                'contextid' => 1,
                'conditiondata' => '{"borole":"0"}',
                'actionname' => 'send_mail',
                'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",' . $actstr0 . ',"templateformat":"1"}',
                'rulename' => 'rule_react_on_event',
                'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled",
                    "aftercompletion":"","condition":"0"}',
            ],
            1 => [
                'name' => 'Notify teachers (Cancel booking ansewer and booking option)',
                'useastemplate' => 0,
                'conditionname' => 'select_teacher_in_bo',
                'contextid' => 1,
                'conditiondata' => '',
                'actionname' => 'send_mail',
                'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",' . $actstr1 . ',"templateformat":"1"}',
                'rulename' => 'rule_react_on_event',
                'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_cancelled",
                    "aftercompletion":"","condition":"0"}',
            ],
        ];
        return [
            'Cancel booking ansewer and entire option (invisible=2, sendmessagesforinvisibleoptions=0, 4 messages)' => [
                [
                    'rulessettings' => [
                        0 => $standardrules[0],
                        1 => $standardrules[1],
                    ],
                    'coursenumber' => 1,
                    'useoption' => 0,
                    'usecourse' => 0,
                    'mock_timestamp' => 1,
                    'config' => [
                        'sendmessagesforinvisibleoptions' => 0,
                    ],
                    'optionsettings' => [
                        [
                            'invisible' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 0,
                    'tasksbyevents' => [
                        'bookinganswer_cancelled' => 1,
                        'bookingoption_cancelled' => 1,
                        'messages_sent' => 4,
                    ],
                ],
            ],
            'Cancel booking ansewer and entire option (invisible=2, sendmessagesforinvisibleoptions=1, 4 messages)' => [
                [
                    'rulessettings' => [
                        0 => $standardrules[0],
                        1 => $standardrules[1],
                    ],
                    'coursenumber' => 1,
                    'useoption' => 0,
                    'usecourse' => 0,
                    'mock_timestamp' => 1,
                    'config' => [
                        'sendmessagesforinvisibleoptions' => 1,
                    ],
                    'optionsettings' => [
                        [
                            'invisible' => MOD_BOOKING_OPTION_VISIBLEWITHLINK,
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 0,
                    'tasksbyevents' => [
                        'bookinganswer_cancelled' => 1,
                        'bookingoption_cancelled' => 1,
                        'messages_sent' => 4,
                    ],
                ],
            ],
            'Cancel booking ansewer and entire option (invisible=1, sendmessagesforinvisibleoptions=1, 4 messages)' => [
                [
                    'rulessettings' => [
                        0 => $standardrules[0],
                        1 => $standardrules[1],
                    ],
                    'coursenumber' => 1,
                    'useoption' => 0,
                    'usecourse' => 0,
                    'mock_timestamp' => 1,
                    'config' => [
                        'sendmessagesforinvisibleoptions' => 1,
                    ],
                    'optionsettings' => [
                        [
                            'invisible' => MOD_BOOKING_OPTION_INVISIBLE,
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 0,
                    'tasksbyevents' => [
                        'bookinganswer_cancelled' => 1,
                        'bookingoption_cancelled' => 1,
                        'messages_sent' => 4,
                    ],
                ],
            ],
            'Cancel booking ansewer and entire option (invisible=1, sendmessagesforinvisibleoptions=0, 0 messages)' => [
                [
                    'rulessettings' => [
                        0 => $standardrules[0],
                        1 => $standardrules[1],
                    ],
                    'coursenumber' => 1,
                    'useoption' => 0,
                    'usecourse' => 0,
                    'mock_timestamp' => 1,
                    'config' => [
                        'sendmessagesforinvisibleoptions' => 0,
                    ],
                    'optionsettings' => [
                        [
                            'invisible' => MOD_BOOKING_OPTION_INVISIBLE,
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 0,
                    'tasksbyevents' => [
                        'bookinganswer_cancelled' => 1,
                        'bookingoption_cancelled' => 1,
                        'messages_sent' => 0,
                    ],
                ],
            ],
            'Cancel booking ansewer and entire option (invisible=0, 4 messages)' => [
                [
                    'rulessettings' => [
                        0 => $standardrules[0],
                        1 => $standardrules[1],
                    ],
                    'coursenumber' => 1,
                    'useoption' => 0,
                    'usecourse' => 0,
                    'mock_timestamp' => 1,
                    'optionsettings' => [
                        [
                            'invisible' => MOD_BOOKING_OPTION_VISIBLE,
                        ],
                    ],
                ],
                [
                    'initialnumberoftasks' => 0,
                    'tasksbyevents' => [
                        'bookinganswer_cancelled' => 1,
                        'bookingoption_cancelled' => 1,
                        'messages_sent' => 4,
                    ],
                ],
            ],
        ];
    }

    /**
     * Common data provider for booking settings.
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
            ],
        ];
    }
}
