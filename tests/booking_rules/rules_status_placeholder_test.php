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
 * Tests the {status} placeholder in a rule mail sent on bookingoption_booked.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\placeholders\placeholders_info;
use stdClass;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the {status} placeholder in a rule mail sent on bookingoption_booked.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class rules_status_placeholder_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * A rule on bookingoption_booked mailing the booked user must render {status} as "Booked".
     *
     * @covers \mod_booking\placeholders\placeholders\status
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\task\send_mail_by_rule_adhoc
     */
    public function test_status_placeholder_on_bookingoption_booked(): void {
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
            'cancancelbook' => 1,
        ];

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Rule: on bookingoption_booked mail the booked user (relateduserid) with the {status} placeholder.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"status-subj","template":"Your status: {status}","templateformat":"1"}';
        $plugingenerator->create_rule([
            'name' => 'status_on_booked',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","cancelrules":[],"condition":"0"}',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Status option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->useprice = 0;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Cron runs many adhoc tasks in one PHP process. A mail rendered earlier in that process
        // for the same user and option (e.g. a waiting list notification) already resolved {status}
        // while the user was not booked yet. The booking afterwards (waitlist promotion, booking by
        // a task, ...) must not leave that stale value behind for the bookingoption_booked mail.
        $rendered = placeholders_info::render_text('{status}', $settings->cmid, $settings->id, $student->id);
        $this->assertEquals(get_string('notbooked', 'mod_booking'), $rendered);

        // Book the student.
        $this->setUser($student);
        singleton_service::destroy_user($student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);

        $this->setAdminUser();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks);
        $customdata = reset($tasks)->get_custom_data();
        $this->assertEquals($student->id, $customdata->userid);
        $this->assertEquals('Your status: {status}', $customdata->custommessage);

        unset_config('noemailever');
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $trace = ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages, $trace);
        $message = reset($messages);
        $this->assertEquals($student->id, $message->useridto);
        $this->assertEquals('status-subj', $message->subject);
        $this->assertStringNotContainsString('{status}', $message->fullmessage);
        $this->assertStringContainsString(
            'Your status: ' . get_string('booked', 'mod_booking'),
            $message->fullmessage
        );
    }
}
