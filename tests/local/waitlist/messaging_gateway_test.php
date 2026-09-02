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
 * Tests for moodle_messaging_gateway (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.4). Uses
 * $this->redirectMessages() against real message_controller sends - same technique as the
 * existing rules_waitinglist_notification_test.php - rather than mocking message_controller.
 * Both notification methods send via MOD_BOOKING_MSGCONTRPARAM_SEND_NOW (progression::reconcile()
 * already runs inside an adhoc task itself, so no further queueing is needed) - message_send()
 * happens synchronously, no runAdhocTasks() required.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * §3.4 tests for moodle_messaging_gateway.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\moodle_messaging_gateway::notify_offer
 * @covers \mod_booking\local\waitlist\moodle_messaging_gateway::notify_autobooked
 */
final class messaging_gateway_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Creates a course + booking + one option.
     *
     * @return int the new option's id
     */
    private function create_option(): int {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'Messaging Gateway Test',
            'eventtype' => 'Test',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'statuschangebody'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'messaging-gateway-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->maxoverbooking = 5;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * Creates one active rule_react_on_event + send_mail_interval rule with the given
     * subject/template.
     *
     * @param string $subject
     * @param string $template
     * @return int the new rule's id
     */
    private function create_interval_rule(string $subject, string $template): int {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $actstr = json_encode(['interval' => 60, 'subject' => $subject, 'template' => $template, 'templateformat' => '1']);
        $record = $plugingenerator->create_rule([
            'name' => 'messaging-gateway-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0',
        ]);
        return (int) $record->id;
    }

    /**
     * Builds one waitlist_offer value object with the given optionid/userid/ruleid - the other
     * fields are irrelevant to messaging_gateway, which only reads optionid/userid/ruleid.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $ruleid
     * @return waitlist_offer
     */
    private function build_offer(int $optionid, int $userid, int $ruleid): waitlist_offer {
        return new waitlist_offer(1, $optionid, $userid, 0, 1, new offer_statuses\offered(), 1, 0, 0, $ruleid, 1, 0, 0);
    }

    /**
     * K4: notify_offer() must send the rule's own subject/template to the offer's recipient.
     */
    public function test_notify_offer_sends_rule_subject_and_template(): void {
        $optionid = $this->create_option();
        $ruleid = $this->create_interval_rule('gwoffersubj', 'gwoffermsg');
        $user = $this->getDataGenerator()->create_user();
        $offer = $this->build_offer($optionid, (int) $user->id, $ruleid);

        $sink = $this->redirectMessages();
        (new moodle_messaging_gateway())->notify_offer($offer, $ruleid);
        $messages = $sink->get_messages();
        $sink->close();

        $matching = array_filter(
            $messages,
            fn($m) => (int) $m->useridto === (int) $user->id && $m->subject === 'gwoffersubj'
        );
        $this->assertNotEmpty($matching, 'notify_offer() must send the rule-configured subject to the offer recipient.');
    }

    /**
     * notify_offer() must send nothing if $ruleid no longer resolves to a rule - defensive, no
     * empty-subject mail.
     */
    public function test_notify_offer_sends_nothing_for_unresolvable_ruleid(): void {
        $optionid = $this->create_option();
        $user = $this->getDataGenerator()->create_user();
        $offer = $this->build_offer($optionid, (int) $user->id, 999999);

        $sink = $this->redirectMessages();
        (new moodle_messaging_gateway())->notify_offer($offer, 999999);
        $messages = $sink->get_messages();
        $sink->close();

        $matching = array_filter($messages, fn($m) => (int) $m->useridto === (int) $user->id);
        $this->assertEmpty($matching, 'An unresolvable ruleid must not produce an empty-subject mail.');
    }

    /**
     * K3: notify_autobooked() must send a status-changed notification to the candidate.
     */
    public function test_notify_autobooked_sends_status_changed_message(): void {
        $optionid = $this->create_option();
        $user = $this->getDataGenerator()->create_user();
        $candidate = new booking_waitlist_candidate($optionid, (int) $user->id, 0, (object) ['id' => $user->id]);

        $sink = $this->redirectMessages();
        (new moodle_messaging_gateway())->notify_autobooked($candidate, 0);
        $messages = $sink->get_messages();
        $sink->close();

        $matching = array_filter($messages, fn($m) => (int) $m->useridto === (int) $user->id);
        $this->assertNotEmpty($matching, 'notify_autobooked() must send a message to the candidate.');
    }
}
