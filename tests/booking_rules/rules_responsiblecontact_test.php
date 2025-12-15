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
 * Tests for booking rules.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class rules_responsiblecontact_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * Test rules for "select_responsible_contact_in_bo" when option is booked.
     *
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_responsible_contact_in_bo
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider different_rule_conditions_provider
     */
    public function test_rule_on_email_responsiblecontact_on_booking(array $data, array $expected): void {
        global $DB, $CFG;

        $bdata = self::booking_common_settings_provider();

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();
        $rcps = [
            [
                'username' => 'rcp1',
                'firstname' => 'RCP',
                'lastname' => '1',
                'email' => 'rcp1@example.com',
            ],
            [
                'username' => 'rcp2',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp2@example.com',
            ],
            [
                'username' => 'rcp3',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp3@example.com',
            ],
        ];
        $rcp1 = $this->getDataGenerator()->create_user($rcps[0]);
        $rcp2 = $this->getDataGenerator()->create_user($rcps[1]);
        $rcp3 = $this->getDataGenerator()->create_user($rcps[2]);
        $allrcpuserids = [$rcp1->id, $rcp2->id, $rcp3->id];
        $allrcpusernames = [$rcp1->username, $rcp2->username, $rcp3->username];

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "select_responsible_contact_in_bo" on "bookingoption_booked".
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"rcp-booked-subj","template":"rcp-booked-msg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'email_rcp_on_booking',
            'conditionname' => 'select_responsible_contact_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":1,"cancelrules":[],"condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 5;

        if (isset($data['fullwaitinglist']) && !empty($data['fullwaitinglist'])) {
            $record->maxoverbooking = 4;
        } else {
            $record->maxoverbooking = 10;
        }

        $record->description = 'Will start in a future';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->useprice = 0;
        $record->importing = 1;
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        if (isset($data['responsiblecontactsnumber']) && !empty($data['responsiblecontactsnumber'])) {
            $rcpuserids = array_slice($allrcpuserids, 0, $data['responsiblecontactsnumber']);
            $rcpusernames = array_slice($allrcpusernames, 0, $data['responsiblecontactsnumber']);
            $record->responsiblecontact = implode(',', $rcpusernames);
        }

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Execute tasks, get messages and validate it.
        $this->setAdminUser();
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        if (isset($data['responsiblecontactsnumber']) && !empty($data['responsiblecontactsnumber'])) {
            $actualids = [];
            foreach ($tasks as $task) {
                $taskdata = $task->get_custom_data();
                $actualids[] = $taskdata->userid;
            }
            $this->assertEmpty(array_diff($rcpuserids, $actualids));
        }

        // Check if tasks were found.
        $this->assertCount($expected['messagefound'], $tasks);
        // There are only two mails scheduled by the logic of send_mail_interval class.

        // Run tasks.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        // Check if message was send.
        $this->assertCount($expected['messagefound'], $messages);
        if (isset($data['responsiblecontactsnumber']) && !empty($data['responsiblecontactsnumber'])) {
            $actualids = [];
            foreach ($messages as $message) {
                if (strpos($message->subject, "rcp-booked-subj") !== false) {
                    $actualids[] = $message->useridto;
                }
            }
            $this->assertEmpty(array_diff($rcpuserids, $actualids));
        }
    }

    /**
     * Data Provider for different rule conditions.
     *
     * @return array
     *
     */
    public static function different_rule_conditions_provider(): array {
        return [
            'Rule responsiblecontact - no RCPs' => [
                'data' => [
                    'responsiblecontactsnumber' => 0,
                ],
                'expected' => [
                    'messagefound' => 0,
                ],
            ],
            'Rule responsiblecontact - 1 RCP' => [
                'data' => [
                    'responsiblecontactsnumber' => 1,
                ],
                'expected' => [
                    'messagefound' => 1,
                ],
            ],
            'Rule responsiblecontact - 3 RCPs' => [
                'data' => [
                    'responsiblecontactsnumber' => 3,
                ],
                'expected' => [
                    'messagefound' => 3,
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
        $bdata = [
            'name' => 'Rule Booking Test',
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
            'pricecategories' => [
                0 => (object)[
                    'ordernum' => 1,
                    'name' => 'default',
                    'identifier' => 'default',
                    'defaultvalue' => 99,
                    'pricecatsortorder' => 1,
                ],
                1 => (object)[
                    'ordernum' => 2,
                    'name' => 'discount1',
                    'identifier' => 'discount1',
                    'defaultvalue' => 89,
                    'pricecatsortorder' => 2,
                ],
                2 => (object)[
                    'ordernum' => 3,
                    'name' => 'discount2',
                    'identifier' => 'discount2',
                    'defaultvalue' => 79,
                    'pricecatsortorder' => 3,
                ],
            ],
        ];
        return ['bdata' => [$bdata]];
    }
}
