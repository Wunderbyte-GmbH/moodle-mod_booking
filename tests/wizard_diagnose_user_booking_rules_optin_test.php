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
use stdClass;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;

/**
 * The user booking diagnosis names a switched-off confirmation rule as the cause of a missing confirmation,
 * since the selector picks it for that question as often as diagnose_booking_issue (Wunderbyte-GmbH#2672).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_user_booking_rules_optin_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * A booking instance with one option, a booked student and an active confirmation rule on the module.
     *
     * @return array{0:stdClass,1:stdClass,2:stdClass,3:stdClass} [booking, option, student, rule]
     */
    private function setup_booking(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['email' => 'optin.student@example.com']);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Rules Booking',
            'eventtype' => 'Test event',
            'bookingmanager' => 'admin',
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Erste Hilfe',
            'chooseorcreatecourse' => 0,
            'maxanswers' => 4,
            'optiondateid_0' => '0',
            'daystonotify_0' => '0',
            'coursestarttime_0' => strtotime('now + 3 days'),
            'courseendtime_0' => strtotime('now + 6 days'),
        ]);
        $rule = $gen->create_rule([
            'bookingid' => 0,
            'contextid' => (int)\context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => 'Booking confirmation',
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"confirmation","template":"confirmation","templateformat":"1"}',
            'eventname' => '\\mod_booking\\event\\bookingoption_booked',
        ]);
        $gen->create_answer(['optionid' => (int)$option->id, 'userid' => (int)$student->id]);
        singleton_service::destroy_booking_answers((int)$option->id);
        return [$booking, $option, $student, $rule];
    }

    /**
     * Restrict the option to selected rules.
     *
     * @param int $optionid
     * @param int $mode
     * @param array $ruleids
     */
    private function set_rule_restriction(int $optionid, int $mode, array $ruleids): void {
        global $DB;
        $json = json_decode((string)$DB->get_field('booking_options', 'json', ['id' => $optionid])) ?: new stdClass();
        $json->skipbookingrulesmode = $mode;
        $json->skipbookingrules = array_map('strval', $ruleids);
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Run the user booking diagnosis as admin for the student on the option.
     *
     * @param stdClass $booking
     * @param stdClass $option
     * @return array
     */
    private function diagnose(stdClass $booking, stdClass $option): array {
        global $USER;
        $this->setAdminUser();
        $result = (new diagnose_user_booking_skill())->execute(
            ['optionid' => (int)$option->id, 'userquery' => 'optin.student@example.com', 'outputlang' => 'en'],
            (int)\context_module::instance($booking->cmid)->id,
            (int)$USER->id
        );
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        return $result;
    }

    /**
     * Only selected rules apply and none is selected: the confirmation rule is named as switched off.
     */
    public function test_optin_with_empty_list_names_the_skipped_confirmation_rule(): void {
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option);

        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('"skipped_confirmation_rules": "Booking confirmation"', $observation);
        $this->assertStringContainsString('"mode": "only_selected_rules"', $observation);
        $this->assertStringContainsString('Booking confirmation', (string)$result['detail']);
        $this->assertStringContainsString(
            get_string('skipbookingrulesoptin', 'booking'),
            (string)$result['detail'],
            'the detail names the setting that switched the rule off'
        );
    }

    /**
     * An unrestricted option reports the applied confirmation rule and no finding.
     */
    public function test_unrestricted_option_reports_no_skipped_rule(): void {
        [$booking, $option] = $this->setup_booking();

        $result = $this->diagnose($booking, $option);

        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('"skipped_confirmation_rules": ""', $observation);
        $this->assertStringContainsString('"applied_confirmation_rules": 1', $observation);
        $this->assertStringContainsString('"finding": ""', $observation);
    }
}
