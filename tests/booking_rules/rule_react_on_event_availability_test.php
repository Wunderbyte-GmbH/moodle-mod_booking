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

namespace mod_booking;

use advanced_testcase;
use mod_booking\booking_rules\rules\templates\ruletemplate_courseupdate;
use mod_booking_generator;
use stdClass;

/**
 * Tests that rules reacting on option updates never react on changes of the availability.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
 */
final class rule_react_on_event_availability_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Changing who may book queues no rule mail, even with the setting that limits rules to chosen fields off.
     * A real change of the option still does.
     *
     * @return void
     */
    public function test_availability_changes_trigger_no_rule_mail(): void {
        $this->setAdminUser();
        set_config('limitchangestrackinginrules', 0, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Test booking rule availability',
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
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option';
        $record->description = 'Description';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // The student books, the "Updates" rule mails booked students about changes of the option.
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $template = ruletemplate_courseupdate::return_template();
        $ruledata = json_decode($template->rulejson, true);
        $ruledata['conditiondata'] = json_encode($ruledata['conditiondata']);
        $ruledata['actiondata'] = json_encode($ruledata['actiondata']);
        $ruledata['ruledata'] = json_encode($ruledata['ruledata']);
        $ruledata['contextid'] = $template->contextid;
        $plugingenerator->create_rule($ruledata);
        $this->clear_rule_mails();

        // The update carries the stored values of the option, like a saved option form does.
        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        unset($update->importing);
        $update->identifier = $settings->identifier;
        $update->enrolmentstatus = $settings->enrolmentstatus ?? 0;
        $session = reset($settings->sessions);
        $update->optiondateid_0 = (string) $session->optiondateid;
        $update->coursestarttime_0 = $session->coursestarttime;
        $update->courseendtime_0 = $session->courseendtime;

        // Only the user list changes: no rule mail.
        $update->bo_cond_selectusers_restrict = 1;
        $update->bo_cond_selectusers_userids = [$student->id, $other->id];
        booking_option::update($update);
        $this->assertSame(0, $this->count_rule_mails(), 'A changed user list must not trigger the rule.');

        // The user list changes together with the description: the rule mails, without the user list.
        $update->bo_cond_selectusers_userids = [$student->id];
        $update->description = 'Description changed';
        booking_option::update($update);
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'A changed description still triggers the rule.');
        $rulejson = json_decode(reset($tasks)->get_custom_data()->rulejson);
        $fieldnames = array_map(fn($change) => $change->fieldname ?? '', $rulejson->datafromevent->other->changes);
        $this->assertContains('description', $fieldnames);
        $this->assertNotContains('availability', $fieldnames);
    }

    /**
     * The number of queued rule mails.
     *
     * @return int
     */
    private function count_rule_mails(): int {
        return count(\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc'));
    }

    /**
     * Removes the rule mails queued so far, so only the ones of the step under test are counted.
     *
     * @return void
     */
    private function clear_rule_mails(): void {
        global $DB;
        $DB->delete_records('task_adhoc', ['classname' => '\mod_booking\task\send_mail_by_rule_adhoc']);
    }
}
