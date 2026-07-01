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

use mod_booking\booking_advanced_testcase;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use cache_helper;
use context_module;
use context_system;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../booking_advanced_testcase.php');

/**
 * Tests that booking rules are duplicated together with the booking instance.
 *
 * When a booking instance is duplicated, the "Include booking rules"
 * (duplicationrestorerules) setting controls whether the instance level rules
 * (rules whose contextid is the module context) are copied to the new instance.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_booking_activity_structure_step
 * @covers \restore_booking_activity_structure_step
 *
 * @runTestsInSeparateProcesses
 */
final class duplicate_rules_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Create a minimal booking instance in the given course.
     *
     * @param stdClass $course
     * @param string $name
     * @return stdClass the booking module instance
     */
    private function create_booking_instance(stdClass $course, string $name): stdClass {
        $bdata = [
            'name' => $name,
            'course' => $course->id,
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
        ];
        return $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * Create an instance level booking rule attached to the given context.
     *
     * @param mod_booking_generator $plugingenerator
     * @param int $contextid the context the rule should belong to
     * @param string $name a unique name for the rule
     * @return stdClass the created booking_rules record
     */
    private function create_instance_rule(mod_booking_generator $plugingenerator, int $contextid, string $name): stdClass {
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"' . $name . '","template":"starts in 3 days","templateformat":"1"}';
        $ruledata = [
            'name' => $name,
            'conditionname' => 'select_users',
            'contextid' => $contextid,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"3","datefield":"coursestarttime","cancelrules":[]}',
        ];
        return $plugingenerator->create_rule($ruledata);
    }

    /**
     * Purge caches and singletons after a module duplication so subsequent reads hit the DB.
     *
     * @param stdClass $course
     * @return void
     */
    private function reset_after_duplication(stdClass $course): void {
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cache_helper::purge_all();
        singleton_service::destroy_instance();
        get_fast_modinfo($course, 0, true);
    }

    /**
     * When the setting is enabled, instance level rules are copied to the duplicated
     * instance and attached to the new instance's module context. Global rules
     * (contextid = 1) are not copied into the new context.
     */
    public function test_rules_are_duplicated_when_setting_enabled(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicationrestorerules', 1, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $sourcectxid = context_module::instance($booking->cmid)->id;

        // Create one instance level rule and one global rule.
        $created = $this->create_instance_rule($plugingenerator, $sourcectxid, 'instancerule');
        $this->create_instance_rule($plugingenerator, context_system::instance()->id, 'globalrule');
        // Re-read the source rule from DB so all columns (incl. DB defaults) are populated.
        $sourcerule = $DB->get_record('booking_rules', ['id' => $created->id], '*', MUST_EXIST);

        // Baseline: exactly one rule in the source instance context.
        $this->assertEquals(1, $DB->count_records('booking_rules', ['contextid' => $sourcectxid]));
        $globalcountbefore = $DB->count_records('booking_rules', ['contextid' => context_system::instance()->id]);

        // Duplicate the booking instance.
        $cm = get_fast_modinfo($course)->get_cm($booking->cmid);
        $newcm = duplicate_module($course, $cm);
        $this->assertNotNull($newcm, 'Module duplication must return a valid cm_info object.');
        $this->reset_after_duplication($course);

        $newctxid = context_module::instance($newcm->id)->id;

        // Exactly one rule must now be attached to the new instance context.
        $newrules = $DB->get_records('booking_rules', ['contextid' => $newctxid]);
        $this->assertCount(1, $newrules, 'The duplicated instance must have exactly one rule.');
        $newrule = reset($newrules);

        // The copied rule must be a new row attached to the new context, not the original.
        $this->assertNotEquals($sourcerule->id, $newrule->id, 'The duplicated rule must be a new record.');
        $this->assertNotEquals($sourcerule->contextid, $newrule->contextid, 'The duplicated rule must use the new context.');
        $this->assertEquals($newctxid, $newrule->contextid);

        // The rule content must be identical to the source rule.
        $this->assertEquals($sourcerule->rulename, $newrule->rulename);
        $this->assertEquals($sourcerule->rulejson, $newrule->rulejson);
        $this->assertEquals($sourcerule->eventname, $newrule->eventname);
        $this->assertEquals($sourcerule->isactive, $newrule->isactive);
        $this->assertEquals($sourcerule->useastemplate, $newrule->useastemplate);

        // The original instance rule must be untouched and global rules must not be duplicated.
        $this->assertEquals(1, $DB->count_records('booking_rules', ['contextid' => $sourcectxid]));
        $this->assertEquals(
            $globalcountbefore,
            $DB->count_records('booking_rules', ['contextid' => context_system::instance()->id]),
            'Global rules must not be duplicated into the new instance.'
        );
    }

    /**
     * When the setting is disabled, no rules are copied to the duplicated instance.
     */
    public function test_rules_are_not_duplicated_when_setting_disabled(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicationrestorerules', 0, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $sourcectxid = context_module::instance($booking->cmid)->id;

        $this->create_instance_rule($plugingenerator, $sourcectxid, 'instancerule');
        $this->assertEquals(1, $DB->count_records('booking_rules', ['contextid' => $sourcectxid]));

        // Duplicate the booking instance.
        $cm = get_fast_modinfo($course)->get_cm($booking->cmid);
        $newcm = duplicate_module($course, $cm);
        $this->assertNotNull($newcm, 'Module duplication must return a valid cm_info object.');
        $this->reset_after_duplication($course);

        $newctxid = context_module::instance($newcm->id)->id;

        // No rules must be attached to the new instance context.
        $this->assertEquals(
            0,
            $DB->count_records('booking_rules', ['contextid' => $newctxid]),
            'No rules must be duplicated when the setting is disabled.'
        );
    }
}
