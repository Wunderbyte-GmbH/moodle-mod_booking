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

use mod_booking\booking_rules\rules\templates\ruletemplate_selectusersuseradded;
use mod_booking\local\templaterule;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Tests the rule template that mails a user newly allowed to book a booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\rules\templates\ruletemplate_selectusersuseradded
 * @covers \mod_booking\event\selectusers_user_added
 * @covers \mod_booking\booking_rules\rules\rule_react_on_event
 * @covers \mod_booking\booking_rules\conditions\select_user_from_event
 */
final class rules_template_selectusers_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * The template is offered among the preconfigured rules and reacts on the right event.
     *
     * @return void
     */
    public function test_template_is_offered_and_well_formed(): void {
        set_config('bookingruletemplatesactive', 1, 'booking');

        $options = templaterule::get_template_rules();
        $this->assertArrayHasKey(-ruletemplate_selectusersuseradded::$templateid, $options);
        $this->assertSame(
            get_string('ruletemplateselectusersuseradded', 'mod_booking'),
            $options[-ruletemplate_selectusersuseradded::$templateid]
        );

        $record = templaterule::get_template_record_by_id(-ruletemplate_selectusersuseradded::$templateid);
        $this->assertSame('\\mod_booking\\event\\selectusers_user_added', $record->eventname);
        $rulejson = json_decode($record->rulejson);
        $this->assertSame('rule_react_on_event', $rulejson->rulename);
        $this->assertSame('\\mod_booking\\event\\selectusers_user_added', $rulejson->ruledata->boevent);
        $this->assertSame('select_user_from_event', $rulejson->conditionname);
        $this->assertSame('relateduserid', $rulejson->conditiondata->userfromeventtype);
        $this->assertSame('send_mail', $rulejson->actionname);
        $this->assertSame(get_string('ruletemplateselectusersuseraddedsubject', 'mod_booking'), $rulejson->actiondata->subject);
        $this->assertSame(get_string('ruletemplateselectusersuseraddedbody', 'mod_booking'), $rulejson->actiondata->template);

        // The template id must be unique among the built-in templates.
        $ids = array_filter(array_keys($options), fn($id) => $id < 0);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /**
     * A rule created from the template mails exactly the users newly allowed to book.
     *
     * @return void
     */
    public function test_rule_from_template_mails_newly_added_user(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Test booking select users template',
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
            'bookingmanager' => $manager->username,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create the rule from the template, as the rules form does.
        $template = ruletemplate_selectusersuseradded::return_template();
        $ruledata = json_decode($template->rulejson, true);
        $ruledata['conditiondata'] = json_encode($ruledata['conditiondata']);
        $ruledata['actiondata'] = json_encode($ruledata['actiondata']);
        $ruledata['ruledata'] = json_encode($ruledata['ruledata']);
        $ruledata['contextid'] = $template->contextid;
        $plugingenerator->create_rule($ruledata);

        // An option only user 1 may book.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Restricted option';
        $record->description = 'Description';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$user1->id];
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Creating the option already allowed user 1: that mail is queued, clear it.
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc'));
        $this->run_all_adhoc_tasks_silently();

        // Now user 2 is allowed to book as well (the cron run switched the user; save as admin again).
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        unset($update->importing);
        $update->bo_cond_selectusers_userids = [$user1->id, $user2->id];
        booking_option::update($update);

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'Only the newly added user gets a mail.');
        $customdata = reset($tasks)->get_custom_data();
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertSame(get_string('ruletemplateselectusersuseraddedsubject', 'mod_booking'), $customdata->customsubject);
        $this->assertSame(get_string('ruletemplateselectusersuseraddedbody', 'mod_booking'), $customdata->custommessage);
        $this->assertEquals($user2->id, json_decode($customdata->rulejson)->datafromevent->relateduserid);
        $this->assertEquals($option->id, $customdata->optionid);

        // The mail reaches user 2 with the option title in the subject.
        $sink = $this->redirectMessages();
        $this->run_all_adhoc_tasks_silently();
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertEquals($user2->id, $messages[0]->useridto);
        $this->assertStringContainsString('Restricted option', $messages[0]->subject);
        $this->assertStringNotContainsString('{', $messages[0]->fullmessagehtml);
    }

    /**
     * Runs all pending adhoc tasks without their output.
     *
     * @return void
     */
    private function run_all_adhoc_tasks_silently(): void {
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
    }
}
