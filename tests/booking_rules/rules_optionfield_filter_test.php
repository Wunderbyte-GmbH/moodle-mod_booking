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
use mod_booking\booking_rules\optionfield_filter;
use mod_booking\booking_rules\rules_info;
use stdClass;

/**
 * Tests for the filter on booking option fields in booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\optionfield_filter
 */
final class rules_optionfield_filter_test extends advanced_testcase {
    /**
     * Set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Create a booking instance with two booking options, one of them "far away".
     *
     * @return array
     */
    private function create_options(): array {

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $manager = $this->getDataGenerator()->create_user();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create a customfield for booking options.
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'Test category',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => \context_system::instance()->id,
        ]);
        $category->save();

        $field = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Anreise',
            'shortname' => 'anreise',
            'type' => 'text',
            'configdata' => "",
        ]);
        $field->save();

        $bookingdata = (object) [
            'name' => 'Filter test booking',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'course' => $course->id,
            'bookingmanager' => $manager->username,
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
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bookingdata);

        $record = (object) [
            'text' => 'Option far away',
            'description' => 'Test option far away',
            'chooseorcreatecourse' => 1,
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'customfield_anreise' => 'weit weg',
            'coursestarttime_0' => strtotime('+10 days', time()),
            'courseendtime_0' => strtotime('+11 days', time()),
        ];
        $far = $plugingenerator->create_option($record);

        $record = (object) [
            'text' => 'Option nearby',
            'description' => 'Test option nearby',
            'chooseorcreatecourse' => 1,
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'customfield_anreise' => 'ganz nah',
            'coursestarttime_0' => strtotime('+10 days', time()),
            'courseendtime_0' => strtotime('+11 days', time()),
        ];
        $near = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($far->id);
        singleton_service::destroy_booking_option_singleton($near->id);

        return [(int) $far->id, (int) $near->id];
    }

    /**
     * Build a ruledata object holding a filter.
     *
     * @param string $fieldname
     * @param string $operator
     * @param string $value
     * @return stdClass
     */
    private function get_ruledata(string $fieldname, string $operator, string $value): stdClass {
        $filter = new stdClass();
        $filter->fieldname = $fieldname;
        $filter->operator = $operator;
        $filter->value = $value;

        $ruledata = new stdClass();
        $ruledata->optionfieldfilter = $filter;
        return $ruledata;
    }

    /**
     * Run the filter sql and return the matching optionids.
     *
     * @param ?stdClass $ruledata
     * @return array
     */
    private function get_matching_optionids(?stdClass $ruledata): array {
        global $DB;

        $sql = new stdClass();
        $sql->select = "bo.id optionid";
        $sql->from = "{booking_options} bo";
        $sql->where = " bo.status < 1 ";
        $params = [];

        optionfield_filter::apply_to_sql($sql, $params, $ruledata);

        return array_keys($DB->get_records_sql("SELECT $sql->select FROM $sql->from WHERE $sql->where", $params));
    }

    /**
     * Without a filter, all booking options are returned, exactly as before.
     */
    public function test_no_filter_returns_all_options(): void {
        [$far, $near] = $this->create_options();

        $ids = $this->get_matching_optionids(new stdClass());
        $this->assertContains($far, $ids);
        $this->assertContains($near, $ids);

        // Every option matches when there is no filter.
        $this->assertTrue(optionfield_filter::option_matches($far, new stdClass()));
        $this->assertTrue(optionfield_filter::option_matches($near, new stdClass()));
        $this->assertTrue(optionfield_filter::option_matches($far, null));
    }

    /**
     * The filter on a standard field restricts the options, in sql and in the check before the action.
     *
     * @param string $operator
     * @param string $value
     * @param bool $expectfar
     * @param bool $expectnear
     * @dataProvider operator_provider
     */
    public function test_filter_on_standard_field(
        string $operator,
        string $value,
        bool $expectfar,
        bool $expectnear
    ): void {
        [$far, $near] = $this->create_options();

        $ruledata = $this->get_ruledata('text', $operator, $value);
        $ids = $this->get_matching_optionids($ruledata);

        $this->assertEquals($expectfar, in_array($far, $ids), "sql, option far away, operator $operator");
        $this->assertEquals($expectnear, in_array($near, $ids), "sql, option nearby, operator $operator");

        // The check before the action has to come to the very same conclusion.
        $this->assertEquals($expectfar, optionfield_filter::option_matches($far, $ruledata), "check, far, $operator");
        $this->assertEquals($expectnear, optionfield_filter::option_matches($near, $ruledata), "check, near, $operator");
    }

    /**
     * Data provider for test_filter_on_standard_field.
     *
     * @return array
     */
    public static function operator_provider(): array {
        return [
            'equals' => [optionfield_filter::OPERATOR_EQUALS, 'Option far away', true, false],
            'equals is not case sensitive' => [optionfield_filter::OPERATOR_EQUALS, 'OPTION FAR AWAY', true, false],
            'not equals' => [optionfield_filter::OPERATOR_NOTEQUALS, 'Option far away', false, true],
            'contains' => [optionfield_filter::OPERATOR_CONTAINS, 'far', true, false],
            'does not contain' => [optionfield_filter::OPERATOR_NOTCONTAINS, 'far', false, true],
            'is not empty' => [optionfield_filter::OPERATOR_NOTEMPTY, '', true, true],
            'is empty' => [optionfield_filter::OPERATOR_EMPTY, '', false, false],
            'no option has this value' => [optionfield_filter::OPERATOR_EQUALS, 'nowhere', false, false],
        ];
    }

    /**
     * A filter on a customfield of the booking option.
     *
     * @param string $operator
     * @param string $value
     * @param bool $expectfar
     * @param bool $expectnear
     * @dataProvider customfield_operator_provider
     */
    public function test_filter_on_customfield(
        string $operator,
        string $value,
        bool $expectfar,
        bool $expectnear
    ): void {
        [$far, $near] = $this->create_options();

        $ruledata = $this->get_ruledata('cf_anreise', $operator, $value);
        $ids = $this->get_matching_optionids($ruledata);

        $this->assertEquals($expectfar, in_array($far, $ids), "sql, option far away, operator $operator");
        $this->assertEquals($expectnear, in_array($near, $ids), "sql, option nearby, operator $operator");

        // The check before the action has to come to the very same conclusion.
        $this->assertEquals($expectfar, optionfield_filter::option_matches($far, $ruledata), "check, far, $operator");
        $this->assertEquals($expectnear, optionfield_filter::option_matches($near, $ruledata), "check, near, $operator");
    }

    /**
     * Data provider for test_filter_on_customfield.
     *
     * @return array
     */
    public static function customfield_operator_provider(): array {
        return [
            'equals' => [optionfield_filter::OPERATOR_EQUALS, 'weit weg', true, false],
            'equals is not case sensitive' => [optionfield_filter::OPERATOR_EQUALS, 'Weit Weg', true, false],
            'not equals' => [optionfield_filter::OPERATOR_NOTEQUALS, 'weit weg', false, true],
            'contains' => [optionfield_filter::OPERATOR_CONTAINS, 'weit', true, false],
            'does not contain' => [optionfield_filter::OPERATOR_NOTCONTAINS, 'weit', false, true],
            'is not empty' => [optionfield_filter::OPERATOR_NOTEMPTY, '', true, true],
            'is empty' => [optionfield_filter::OPERATOR_EMPTY, '', false, false],
            'no option has this value' => [optionfield_filter::OPERATOR_EQUALS, 'nowhere', false, false],
        ];
    }

    /**
     * A filter on a field which does not exist anymore must not let the rule apply.
     */
    public function test_filter_on_deleted_field(): void {
        [$far, $near] = $this->create_options();

        $ruledata = $this->get_ruledata('cf_doesnotexist', optionfield_filter::OPERATOR_EQUALS, 'weit weg');
        $this->assertEmpty($this->get_matching_optionids($ruledata));
        $this->assertFalse(optionfield_filter::option_matches($far, $ruledata));
        $this->assertFalse(optionfield_filter::option_matches($near, $ruledata));
    }

    /**
     * End to end: a days before rule with a filter creates a task only for the matching booking option.
     */
    public function test_daysbefore_rule_only_runs_for_matching_option(): void {

        [$far, $near] = $this->create_options();

        $user = $this->getDataGenerator()->create_user();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"traveldescription","template":"how to get there","templateformat":"1"}';
        $ruledata = '{"days":"3","datefield":"coursestarttime","cancelrules":[],';
        $ruledata .= '"optionfieldfilter":{"fieldname":"cf_anreise","operator":"=","value":"weit weg"}}';

        $plugingenerator->create_rule([
            'name' => 'traveldescription',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["' . $user->id . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => $ruledata,
        ]);

        rules_info::execute_booking_rules();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Only the booking option which is "weit weg" may create a task.
        $optionids = [];
        foreach ($tasks as $task) {
            $optionids[] = (int) $task->get_custom_data()->optionid;
        }

        $this->assertContains($far, $optionids, 'The far away option must get a mail.');
        $this->assertNotContains($near, $optionids, 'The nearby option must NOT get a mail.');
    }

    /**
     * End to end: the rule is checked again before the mail is really sent.
     * When the field of the booking option changes in the meantime, the mail must not be sent.
     */
    public function test_rule_is_checked_again_before_sending(): void {
        global $DB;

        [$far, $near] = $this->create_options();

        $rule = new \mod_booking\booking_rules\rules\rule_react_on_event();
        $rulejson = '{"name":"traveldescription","rulename":"rule_react_on_event",';
        $rulejson .= '"ruledata":{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked","condition":"0",';
        $rulejson .= '"aftercompletion":"","cancelrules":[],';
        $rulejson .= '"optionfieldfilter":{"fieldname":"cf_anreise","operator":"=","value":"weit weg"}}}';

        $record = (object) [
            'rulename' => 'rule_react_on_event',
            'rulejson' => $rulejson,
            'eventname' => '\\mod_booking\\event\\bookingoption_booked',
            'contextid' => 1,
            'isactive' => 1,
        ];
        $record->id = $DB->insert_record('booking_rules', $record);
        $rule->set_ruledata($record);

        // As long as the option matches, the rule applies.
        $this->assertTrue($rule->check_if_rule_still_applies($far, 0, time()));
        // The other option never matches.
        $this->assertFalse($rule->check_if_rule_still_applies($near, 0, time()));

        // Now the booking option is changed and does not match anymore.
        $settings = singleton_service::get_instance_of_booking_option_settings($far);
        $optionrecord = (object) [
            'id' => $far,
            'cmid' => $settings->cmid,
            'bookingid' => $settings->bookingid,
            'text' => $settings->text,
            'description' => 'Test option far away',
            'chooseorcreatecourse' => 1,
            'customfield_anreise' => 'ganz nah',
        ];
        \mod_booking\booking_option::update($optionrecord);
        singleton_service::destroy_booking_option_singleton($far);

        // The mail must not be sent anymore.
        $this->assertFalse($rule->check_if_rule_still_applies($far, 0, time()));
    }
}
