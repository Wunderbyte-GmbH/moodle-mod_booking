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
 * Tests for progression_factory (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6), the
 * composition root. Verifies both that get() returns a correctly-typed, independent instance
 * each call, and that the wiring itself is actually correct end-to-end (a small real K3 scenario
 * driven entirely through the factory, not manually-wired collaborators like progression_test.php
 * uses - if get() ever wires the wrong collaborator, this is the test that would catch it).
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
 * §6 tests for progression_factory::get().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression_factory::get
 */
final class progression_factory_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * get() must return a progression instance, and two separate calls must return two distinct
     * instances (no static caching, per the class's own docblock).
     */
    public function test_get_returns_distinct_progression_instances(): void {
        $first = progression_factory::get();
        $second = progression_factory::get();

        $this->assertInstanceOf(progression::class, $first);
        $this->assertInstanceOf(progression::class, $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * End-to-end: a free-price candidate driven entirely through progression_factory::get() must
     * actually be autobooked - proves the wiring itself (not just the type) is correct.
     */
    public function test_get_produces_a_correctly_wired_progression(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => 'Factory Test',
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
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'freecat',
            'identifier' => 'freecat',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'factory-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->maxoverbooking = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        $optionid = (int) $option->id;
        singleton_service::destroy_booking_option_singleton($optionid);

        $actstr = json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']);
        $plugingenerator->create_rule([
            'name' => 'factory-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $candidate = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'freecat']);
        $this->getDataGenerator()->enrol_user($candidate->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $candidate->id,
            'optionid' => $optionid,
            'timemodified' => 100,
            'timecreated' => 100,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);

        $this->setAdminUser();
        progression_factory::get()->reconcile($optionid, 'factory_test');

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $answer->waitinglist,
            'A correctly-wired progression from the factory must actually autobook a free candidate.'
        );
    }
}
