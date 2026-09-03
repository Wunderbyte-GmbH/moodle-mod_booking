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

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\placeholders\placeholders_info;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the {responsiblecontacts} placeholder.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class responsiblecontacts_placeholder_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        placeholders_info::$placeholders = [];
        singleton_service::destroy_instance();
    }

    /**
     * Tests tear down.
     */
    protected function tearDown(): void {
        placeholders_info::$placeholders = [];
        parent::tearDown();
    }

    /**
     * Create a booking option with the given responsible contacts.
     *
     * @param array $usernames usernames of the responsible contacts
     * @return array keys: option, settings
     */
    private function setup_option(array $usernames): array {
        $course = $this->getDataGenerator()->create_course();

        $bookingmanager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $bookingmodule = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->text = 'Test option responsible contacts';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;
        if (!empty($usernames)) {
            $record->responsiblecontact = implode(',', $usernames);
        }
        $option = $plugingenerator->create_option($record);

        return [
            'option' => $option,
            'settings' => singleton_service::get_instance_of_booking_option_settings($option->id),
        ];
    }

    /**
     * Several responsible contacts are rendered as "Firstname Lastname (email)", one below the other.
     *
     * @covers \mod_booking\placeholders\placeholders\responsiblecontacts::return_value
     * @return void
     */
    public function test_multiple_responsible_contacts_are_rendered_below_each_other(): void {
        $contact1 = $this->getDataGenerator()->create_user([
            'firstname' => 'Anna',
            'lastname' => 'Alpha',
            'email' => 'anna.alpha@example.com',
        ]);
        $contact2 = $this->getDataGenerator()->create_user([
            'firstname' => 'Bert',
            'lastname' => 'Beta',
            'email' => 'bert.beta@example.com',
        ]);

        $env = $this->setup_option([$contact1->username, $contact2->username]);

        $rendered = placeholders_info::render_text(
            '{responsiblecontacts}',
            $env['settings']->cmid,
            $env['option']->id,
            (int) $contact1->id
        );

        $this->assertSame(
            'Anna Alpha (anna.alpha@example.com)<br>Bert Beta (bert.beta@example.com)',
            $rendered
        );
    }

    /**
     * A single responsible contact is rendered without any separator.
     *
     * @covers \mod_booking\placeholders\placeholders\responsiblecontacts::return_value
     * @return void
     */
    public function test_single_responsible_contact_is_rendered(): void {
        $contact = $this->getDataGenerator()->create_user([
            'firstname' => 'Anna',
            'lastname' => 'Alpha',
            'email' => 'anna.alpha@example.com',
        ]);

        $env = $this->setup_option([$contact->username]);

        $rendered = placeholders_info::render_text(
            'Contact: {responsiblecontacts}',
            $env['settings']->cmid,
            $env['option']->id,
            (int) $contact->id
        );

        $this->assertSame('Contact: Anna Alpha (anna.alpha@example.com)', $rendered);
    }

    /**
     * Without responsible contacts, the placeholder is replaced by an empty string.
     *
     * @covers \mod_booking\placeholders\placeholders\responsiblecontacts::return_value
     * @return void
     */
    public function test_no_responsible_contacts_renders_empty_string(): void {
        $env = $this->setup_option([]);

        $rendered = placeholders_info::render_text(
            '{responsiblecontacts}',
            $env['settings']->cmid,
            $env['option']->id,
            (int) $this->getDataGenerator()->create_user()->id
        );

        $this->assertSame('', $rendered);
    }
    /**
     * A rule mail containing {responsiblecontacts} is sent without error when the option has no responsible contact.
     *
     * The placeholder simply resolves to an empty string, the rest of the message stays intact.
     *
     * @covers \mod_booking\placeholders\placeholders\responsiblecontacts::return_value
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @return void
     */
    public function test_rule_mail_with_placeholder_is_sent_without_responsible_contacts(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bookingmodule = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Responsible contacts placeholder test',
            'eventtype' => 'Test rules',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
            'enablecompletion' => 1,
            'completion' => 2,
        ]);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Rule which mails the booking user a template containing the placeholder.
        $actiondata = '{"sendical":0,"sendicalcreateorcancel":"","subject":"rcp-placeholder-subj",';
        $actiondata .= '"template":"Contacts:[{responsiblecontacts}]End","templateformat":"1"}';
        $ruledata = [
            'name' => 'mail_with_responsiblecontacts_placeholder',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked",'
                . '"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Booking option WITHOUT any responsible contact.
        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->text = 'Option without responsible contacts';
        $record->description = 'Will start in the future';
        $record->maxanswers = 5;
        $record->maxoverbooking = 10;
        $record->useprice = 0;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;

        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertEmpty($settings->responsiblecontact);

        // Book the student, which triggers the rule.
        $this->setUser($student);
        singleton_service::destroy_user($student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);

        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->setAdminUser();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks);

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        ob_get_clean();
        $sink->close();

        // The mail was sent, no error occurred while rendering the placeholder.
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame('rcp-placeholder-subj', $message->subject);
        $this->assertEquals($student->id, $message->useridto);
        // The placeholder was resolved to an empty string, the surrounding text is untouched.
        $this->assertStringNotContainsString('{responsiblecontacts}', $message->fullmessage);
        $this->assertStringContainsString('Contacts:[]End', $message->fullmessage);
    }
}
