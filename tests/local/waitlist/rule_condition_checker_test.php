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
 * Tests for rule_condition_checker (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3/§6, K11).
 * Rules are built via the mod_booking_generator's create_rule() (same fixture mechanism as the
 * Kategorie C migration tests), booking_answers state via direct booking_answers inserts (same
 * technique as capacity_calculator_test) - no booking_bookit() choreography needed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * K11 tests for rule_condition_checker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\rule_condition_checker::applicable_rules
 */
final class rule_condition_checker_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        // Avoid the booking_answers MUC cache masking direct DB inserts made by these tests.
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Creates a course + booking + one option with the given capacity settings.
     *
     * @param int $maxanswers
     * @param int $maxoverbooking
     * @return int the new option's id
     */
    private function create_option(int $maxanswers, int $maxoverbooking): int {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'Condition Checker Test',
            'eventtype' => 'Test',
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
        $record->text = 'condition-checker-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = $maxoverbooking;
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
     * Inserts one raw booking_answers row.
     *
     * @param int $optionid
     * @param int $waitinglist one of the MOD_BOOKING_STATUSPARAM_* constants
     * @return void
     */
    private function insert_answer(int $optionid, int $waitinglist): void {
        global $DB;
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'optionid' => $optionid,
            'timemodified' => 1000,
            'timecreated' => 1000,
            'waitinglist' => $waitinglist,
            'status' => 0,
            'places' => 1,
        ]);
    }

    /**
     * Creates one active rule_react_on_event + send_mail_interval rule with the given condition.
     *
     * @param string $name unique rule name
     * @param int $condition one of the rule_react_on_event::* constants
     * @return int the new rule's id
     */
    private function create_interval_rule(string $name, int $condition): int {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = $plugingenerator->create_rule([
            'name' => $name,
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => '{"interval":60,"subject":"s","template":"t","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) $condition,
        ]);
        return (int) $record->id;
    }

    /**
     * K11 ALWAYS: applies regardless of capacity state.
     */
    public function test_always_condition_applies_regardless_of_capacity(): void {
        $optionid = $this->create_option(5, 5);
        $ruleid = $this->create_interval_rule('always', rule_react_on_event::ALWAYS);

        $checker = new rule_condition_checker();
        $this->assertEquals([$ruleid], $checker->applicable_rules($optionid));
    }

    /**
     * K11 FULLYBOOKED: applies only when the option is fully booked.
     */
    public function test_fullybooked_condition(): void {
        $ruleid = $this->create_interval_rule('fullybooked', rule_react_on_event::FULLYBOOKED);
        $checker = new rule_condition_checker();

        $fulloptionid = $this->create_option(1, 5);
        $this->insert_answer($fulloptionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertEquals(
            [$ruleid],
            $checker->applicable_rules($fulloptionid),
            'FULLYBOOKED must apply once the option is full.'
        );

        $notfulloptionid = $this->create_option(5, 5);
        $this->assertEquals(
            [],
            $checker->applicable_rules($notfulloptionid),
            'FULLYBOOKED must not apply while the option still has free seats.'
        );
    }

    /**
     * K11 NOTFULLYBOOKED: the exact inverse of FULLYBOOKED.
     */
    public function test_notfullybooked_condition(): void {
        $ruleid = $this->create_interval_rule('notfullybooked', rule_react_on_event::NOTFULLYBOOKED);
        $checker = new rule_condition_checker();

        $notfulloptionid = $this->create_option(5, 5);
        $this->assertEquals(
            [$ruleid],
            $checker->applicable_rules($notfulloptionid),
            'NOTFULLYBOOKED must apply while the option still has free seats.'
        );

        $fulloptionid = $this->create_option(1, 5);
        $this->insert_answer($fulloptionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertEquals(
            [],
            $checker->applicable_rules($fulloptionid),
            'NOTFULLYBOOKED must not apply once the option is full.'
        );
    }

    /**
     * K11 FULLWAITINGLIST: applies only when the waiting list itself is full.
     */
    public function test_fullwaitinglist_condition(): void {
        $ruleid = $this->create_interval_rule('fullwl', rule_react_on_event::FULLWAITINGLIST);
        $checker = new rule_condition_checker();

        $fulloptionid = $this->create_option(5, 1);
        $this->insert_answer($fulloptionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertEquals(
            [$ruleid],
            $checker->applicable_rules($fulloptionid),
            'FULLWAITINGLIST must apply once the waiting list is full.'
        );

        $notfulloptionid = $this->create_option(5, 5);
        $this->insert_answer($notfulloptionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertEquals(
            [],
            $checker->applicable_rules($notfulloptionid),
            'FULLWAITINGLIST must not apply while the waiting list still has room.'
        );
    }

    /**
     * K11 NOTFULLWAITINGLIST: the exact inverse of FULLWAITINGLIST.
     */
    public function test_notfullwaitinglist_condition(): void {
        $ruleid = $this->create_interval_rule('notfullwl', rule_react_on_event::NOTFULLWAITINGLIST);
        $checker = new rule_condition_checker();

        $notfulloptionid = $this->create_option(5, 5);
        $this->insert_answer($notfulloptionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertEquals(
            [$ruleid],
            $checker->applicable_rules($notfulloptionid),
            'NOTFULLWAITINGLIST must apply while the waiting list still has room.'
        );

        $fulloptionid = $this->create_option(5, 1);
        $this->insert_answer($fulloptionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertEquals(
            [],
            $checker->applicable_rules($fulloptionid),
            'NOTFULLWAITINGLIST must not apply once the waiting list is full.'
        );
    }

    /**
     * K11: multiple active rules on the same option must all be returned, ordered by id
     * ascending regardless of creation order - progression() needs a deterministic processing
     * order across rules.
     */
    public function test_multiple_active_rules_returned_ascending_by_id(): void {
        $optionid = $this->create_option(5, 5);
        $second = $this->create_interval_rule('second', rule_react_on_event::ALWAYS);
        $first = $this->create_interval_rule('first', rule_react_on_event::ALWAYS);

        $checker = new rule_condition_checker();
        $expected = [$second, $first];
        sort($expected);
        $this->assertEquals($expected, $checker->applicable_rules($optionid));
    }

    /**
     * K11: an inactive rule must never be returned, even if its condition is met.
     */
    public function test_inactive_rule_is_excluded(): void {
        global $DB;
        $optionid = $this->create_option(5, 5);
        $ruleid = $this->create_interval_rule('inactive', rule_react_on_event::ALWAYS);
        $DB->set_field('booking_rules', 'isactive', 0, ['id' => $ruleid]);

        $checker = new rule_condition_checker();
        $this->assertEquals([], $checker->applicable_rules($optionid));
    }

    /**
     * K11: a rule_react_on_event rule whose action is NOT send_mail_interval (e.g. a plain
     * one-off "Send email") must be excluded - it is not a waitlist-progression rule.
     */
    public function test_non_interval_action_is_excluded(): void {
        $optionid = $this->create_option(5, 5);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_rule([
            'name' => 'plain-mail',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"s","template":"t","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) rule_react_on_event::ALWAYS,
        ]);

        $checker = new rule_condition_checker();
        $this->assertEquals([], $checker->applicable_rules($optionid));
    }

    /**
     * K11: no rules configured at all must yield an empty array, not an error.
     */
    public function test_no_matching_rules_returns_empty_array(): void {
        $optionid = $this->create_option(5, 5);

        $checker = new rule_condition_checker();
        $this->assertEquals([], $checker->applicable_rules($optionid));
    }
}
