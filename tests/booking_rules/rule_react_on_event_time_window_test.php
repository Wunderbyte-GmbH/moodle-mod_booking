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
use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\booking_rules\rules_info;

/**
 * Pins the time window of "react on event" rules (setting "Number of days after end of booking
 * option, where rule still applies", ruledata->aftercompletion).
 *
 * A rule whose window has closed is skipped silently when its mail task runs. The booking
 * diagnosis reuses the same window computation, so this behaviour must stay exactly as it is.
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\booking_rules\rules\rule_react_on_event
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_react_on_event_time_window_test extends advanced_testcase {
    /**
     * Create a booking instance with one option running between the two given times.
     *
     * @param int $start
     * @param int $end
     * @param bool $selflearning
     * @return array{0:\stdClass,1:\stdClass} [booking module, option]
     */
    private function create_option(int $start, int $end, bool $selflearning = false): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Time window option';
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = $start;
        $record->courseendtime_0 = $end;
        if ($selflearning) {
            $record->selflearningcourse = 1;
            $record->duration = DAYSECS * 4;
        }
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$booking, $option];
    }

    /**
     * Create a mail rule reacting to a booking and return it as loaded rule instance.
     *
     * @param \stdClass $booking
     * @param string|int $aftercompletion Stored value of the setting.
     * @return rule_react_on_event
     */
    private function create_rule(stdClass $booking, $aftercompletion): rule_react_on_event {
        global $DB;

        $created = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_rule([
            'contextid' => (int)\context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => 'Booking confirmation',
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"confirmation","template":"confirmation","templateformat":"1"}',
            'eventname' => '\\mod_booking\\event\\bookingoption_booked',
            'aftercompletion' => $aftercompletion,
        ]);

        $rule = rules_info::get_rule('rule_react_on_event');
        $rule->set_ruledata($DB->get_record('booking_rules', ['id' => $created->id], '*', MUST_EXIST));

        return $rule;
    }

    /**
     * Provider: stored setting, option end relative to now, whether the rule still applies.
     *
     * @return array
     */
    public static function window_provider(): array {
        return [
            'one day, ended three days ago' => [1, -3 * DAYSECS, false],
            'one day, ended twelve hours ago' => [1, -12 * HOURSECS, true],
            'empty keeps the rule forever' => ['', -30 * DAYSECS, true],
            'zero keeps the rule forever' => [0, -30 * DAYSECS, true],
            'string zero keeps the rule forever' => ['0', -30 * DAYSECS, true],
            'negative suspends before the end' => [-2, DAYSECS, false],
            'negative, end still far away' => [-2, 5 * DAYSECS, true],
            'seven days, ended six days ago' => [7, -6 * DAYSECS, true],
        ];
    }

    /**
     * The rule stops applying exactly when option end plus the configured days has passed.
     *
     * @dataProvider window_provider
     * @param string|int $aftercompletion
     * @param int $endoffset
     * @param bool $expected
     */
    public function test_rule_applies_only_within_window($aftercompletion, int $endoffset, bool $expected): void {
        $this->resetAfterTest();
        $end = time() + $endoffset;
        [$booking, $option] = $this->create_option($end - 2 * HOURSECS, $end);
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        // Guard against a vacuous pass: the option really carries the end time under test.
        $this->assertSame($end, (int)$settings->courseendtime);

        $rule = $this->create_rule($booking, $aftercompletion);
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame($expected, $rule->check_if_rule_still_applies((int)$option->id, (int)$user->id, 0));
    }

    /**
     * Self-learning courses have no meaningful end, so the window never closes for them.
     */
    public function test_selflearning_option_is_never_cut_off(): void {
        $this->resetAfterTest();
        $end = time() - 10 * DAYSECS;
        [$booking, $option] = $this->create_option($end - DAYSECS, $end, true);
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        $this->assertNotEmpty($settings->selflearningcourse);

        $rule = $this->create_rule($booking, 1);
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue($rule->check_if_rule_still_applies((int)$option->id, (int)$user->id, 0));
    }

    /**
     * An inactive rule never applies, independent of the window.
     */
    public function test_inactive_rule_never_applies(): void {
        global $DB;
        $this->resetAfterTest();
        [$booking, $option] = $this->create_option(time() + DAYSECS, time() + 2 * DAYSECS);
        $rule = $this->create_rule($booking, '');
        $record = $DB->get_record('booking_rules', ['contextid' => (int)\context_module::instance($booking->cmid)->id]);
        $record->isactive = 0;
        $rule->set_ruledata($record);
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse($rule->check_if_rule_still_applies((int)$option->id, (int)$user->id, 0));
    }
}
