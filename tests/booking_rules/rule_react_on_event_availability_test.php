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
use mod_booking\event\bookingoption_updated;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\availability_condition_cases_trait;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/availability_condition_cases_trait.php');

/**
 * Tests that rules reacting on option updates never react on changes of the availability.
 *
 * Changes of the availability conditions are recorded in the changes of the option, but no rule mail is
 * sent because of them and no rule mail lists them. Changes of the booking period still trigger the rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
 */
final class rule_react_on_event_availability_test extends advanced_testcase {
    use availability_condition_cases_trait;

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
        singleton_service::destroy_instance();
        // The values of placeholders are cached by user id, and the ids are the same in every test.
        placeholders_info::$placeholders = [];
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

        [, $update, $student] = $this->create_booked_option();
        $other = $this->getDataGenerator()->create_user();
        $this->create_template_rule();
        $this->clear_rule_mails();

        // Only the user list changes: no rule mail.
        $update->bo_cond_selectusers_restrict = 1;
        $update->bo_cond_selectusers_userids = [$student->id, $other->id];
        booking_option::update($update);
        $this->assertSame(0, $this->count_rule_mails(), 'A changed user list must not trigger the rule.');

        // The user list changes together with the description: the rule mails, without the user list.
        $update->bo_cond_selectusers_userids = [$student->id];
        $update->description = 'Description changed';
        booking_option::update($update);
        $fieldnames = $this->fieldnames_of_single_rule_mail('A changed description still triggers the rule.');
        $this->assertContains('description', $fieldnames);
        $this->assertNotContains('availability', $fieldnames);
    }

    /**
     * Adding, changing or removing any availability condition queues no rule mail, whatever the setting says.
     *
     * @param string $condition
     * @param int $limitchangestracking value of the setting limiting rules to chosen fields
     * @return void
     *
     * @dataProvider condition_and_setting_provider
     */
    public function test_no_condition_change_queues_a_rule_mail(string $condition, int $limitchangestracking): void {
        $this->setAdminUser();
        set_config('limitchangestrackinginrules', $limitchangestracking, 'booking');

        [, $update] = $this->create_booked_option();
        $this->create_template_rule();
        $steps = $this->condition_steps($condition, (int) $update->bookingid, (int) $update->courseid);
        $this->clear_rule_mails();

        foreach (['add', 'change', 'remove'] as $step) {
            foreach ($steps[$step] as $key => $value) {
                $update->{$key} = $value;
            }
            booking_option::update($update);
            $this->assertSame(0, $this->count_rule_mails(), "Step '$step' of $condition must not trigger the rule.");
        }
    }

    /**
     * Data provider: every condition, with the setting limiting rules to chosen fields off and on.
     *
     * @return array
     */
    public static function condition_and_setting_provider(): array {
        $cases = [];
        foreach (self::condition_names() as $condition) {
            $cases["$condition, all fields trigger rules"] = [$condition, 0];
            $cases["$condition, chosen fields trigger rules"] = [$condition, 1];
        }
        return $cases;
    }

    /**
     * A mail sent because of another change lists this change only, not the one of the availability.
     *
     * @param string $condition
     * @return void
     *
     * @covers \mod_booking\placeholders\placeholders\changes::return_value
     * @covers \mod_booking\placeholders\placeholders\eventdescription::return_value
     * @covers \mod_booking\task\send_mail_by_rule_adhoc
     *
     * @dataProvider mailed_condition_provider
     */
    public function test_mail_lists_only_other_changes(string $condition): void {
        $this->setAdminUser();
        set_config('limitchangestrackinginrules', 0, 'booking');

        [$option, $update, $student] = $this->create_booked_option();
        $this->create_mail_rule('OptionChangedSilent', 'Changes:{changes} Event:{eventdescription}');
        $steps = $this->condition_steps($condition, (int) $update->bookingid, (int) $update->courseid);

        // The condition is there already, so the mail is about a changed condition.
        foreach ($steps['add'] as $key => $value) {
            $update->{$key} = $value;
        }
        booking_option::update($update);
        $this->assertSame(0, $this->count_rule_mails());

        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();

        foreach ($steps['change'] as $key => $value) {
            $update->{$key} = $value;
        }
        $update->description = 'Description changed';
        booking_option::update($update);
        $this->runAdhocTasks();

        $messages = $messagesink->get_messages();
        $output = ob_get_clean();
        $messagesink->close();

        $this->assertStringContainsString(
            "mail successfully sent for option {$option->id} to user {$student->id}",
            $output
        );
        $mails = array_values(array_filter(
            $messages,
            fn($message) => strpos($message->subject, 'OptionChangedSilent') !== false
        ));
        $this->assertCount(1, $mails, 'The booked student gets exactly one mail.');
        $mail = reset($mails);
        $this->assertEquals($student->id, $mail->useridto);

        $text = $mail->fullmessage . ' ' . $mail->fullmessagehtml;
        $this->assertStringContainsString('Description changed', $text);
        $this->assertStringNotContainsString(get_string('availability', 'mod_booking'), $text);
        $this->assertStringNotContainsString($condition, $text);
    }

    /**
     * Data provider: conditions with a list of ids, a list of users and nested settings.
     *
     * @return array
     */
    public static function mailed_condition_provider(): array {
        return [
            'selectusers' => ['selectusers'],
            'enrolledincohorts' => ['enrolledincohorts'],
            'customform' => ['customform'],
        ];
    }

    /**
     * The change that triggers no mail is still part of the recorded changes of the option.
     *
     * @return void
     *
     * @covers \mod_booking\event\bookingoption_updated
     */
    public function test_history_still_shows_condition_changes(): void {
        $this->setAdminUser();
        set_config('limitchangestrackinginrules', 0, 'booking');

        [$option, $update] = $this->create_booked_option();
        $this->create_template_rule();
        $steps = $this->condition_steps('selectusers', (int) $update->bookingid, (int) $update->courseid);
        $this->clear_rule_mails();

        // Without the events being redirected, the rules see the update: no mail.
        foreach ($steps['add'] as $key => $value) {
            $update->{$key} = $value;
        }
        booking_option::update($update);
        $this->assertSame(0, $this->count_rule_mails());

        // The event of the next update is what the list of recent updates of the option renders.
        foreach ($steps['change'] as $key => $value) {
            $update->{$key} = $value;
        }
        $sink = $this->redirectEvents();
        booking_option::update($update);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof bookingoption_updated && (int) $event->objectid === (int) $option->id
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $fieldnames = array_map(fn($change) => $change['fieldname'] ?? '', reset($events)->get_data()['other']['changes']);
        $this->assertContains('availability', $fieldnames);
        $description = reset($events)->get_description();
        $this->assertStringContainsString(get_string('availability', 'mod_booking'), $description);
        $this->assertStringContainsString(get_string('bocondselectusers', 'mod_booking'), $description);
    }

    /**
     * A changed booking period is no silent change: it still triggers the rule and is listed for the mail.
     *
     * @param string $restrictkey form key switching the restriction on
     * @param string $timekey form key and field name of the time
     * @return void
     *
     * @dataProvider booking_period_provider
     */
    public function test_booking_period_change_still_mails(string $restrictkey, string $timekey): void {
        $this->setAdminUser();
        set_config('limitchangestrackinginrules', 0, 'booking');

        [, $update] = $this->create_booked_option();
        $this->create_template_rule();
        $this->clear_rule_mails();

        // The booking period is restricted.
        $update->{$restrictkey} = 1;
        $update->{$timekey} = strtotime('1 May 2050');
        booking_option::update($update);
        $fieldnames = $this->fieldnames_of_single_rule_mail('A restricted booking period triggers the rule.');
        $this->assertContains($timekey, $fieldnames);
        $this->assertNotContains('availability', $fieldnames);
        $this->clear_rule_mails();

        // The booking period is moved.
        $update->{$timekey} = strtotime('2 May 2050');
        booking_option::update($update);
        $fieldnames = $this->fieldnames_of_single_rule_mail('A moved booking period triggers the rule.');
        $this->assertContains($timekey, $fieldnames);
        $this->assertNotContains('availability', $fieldnames);
    }

    /**
     * Data provider: start and end of the booking period.
     *
     * @return array
     */
    public static function booking_period_provider(): array {
        return [
            'bookable from' => ['restrictanswerperiodopening', 'bookingopeningtime'],
            'bookable until' => ['restrictanswerperiodclosing', 'bookingclosingtime'],
        ];
    }

    /**
     * Creates a booking option with one booked student.
     *
     * @return array the option, an update record carrying its stored values and the booked student
     */
    private function create_booked_option(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');
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
            'bookingmanager' => $manager->username,
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

        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

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

        return [$option, $update, $student];
    }

    /**
     * Creates the "Updates" rule from its template: it mails booked students about changes of the option.
     *
     * @return void
     */
    private function create_template_rule(): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $template = ruletemplate_courseupdate::return_template();
        $ruledata = json_decode($template->rulejson, true);
        $ruledata['conditiondata'] = json_encode($ruledata['conditiondata']);
        $ruledata['actiondata'] = json_encode($ruledata['actiondata']);
        $ruledata['ruledata'] = json_encode($ruledata['ruledata']);
        $ruledata['contextid'] = $template->contextid;
        $plugingenerator->create_rule($ruledata);
    }

    /**
     * Creates a rule mailing the booked students of an updated option with the given subject and text.
     *
     * @param string $subject
     * @param string $template
     * @return void
     */
    private function create_mail_rule(string $subject, string $template): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_rule([
            'name' => 'emailchanges',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => json_encode(['borole' => '0']),
            'actionname' => 'send_mail',
            'actiondata' => json_encode([
                'sendical' => 0,
                'sendicalcreateorcancel' => '',
                'subject' => $subject,
                'template' => $template,
                'templateformat' => '1',
            ]),
            'rulename' => 'rule_react_on_event',
            'ruledata' => json_encode([
                'boevent' => '\\mod_booking\\event\\bookingoption_updated',
                'condition' => '0',
                'aftercompletion' => '',
            ]),
        ]);
    }

    /**
     * Asserts that exactly one rule mail is queued and returns the names of the changed fields it is about.
     *
     * @param string $message
     * @return array
     */
    private function fieldnames_of_single_rule_mail(string $message): array {
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, $message);
        $rulejson = json_decode(reset($tasks)->get_custom_data()->rulejson);
        return array_map(fn($change) => $change->fieldname ?? '', (array) $rulejson->datafromevent->other->changes);
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
