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
 * Tests for booking rules.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class rules_waitinglist_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events when waitinglist is forced.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_waitinglist_forced(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, $now);

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"smallerthan1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 3; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student1 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now remove booking of student 2, for a place to free up.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        $option->user_delete_response($student2->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        // Execute tasks, get messages and validate it.
        $this->setAdminUser();

        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(6, $tasks);
        // Validate task messages. Might be free order.
        foreach ($tasks as $key => $task) {
            $customdata = $task->get_custom_data();
            if (strpos($customdata->customsubject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $customdata->customsubject);
                $this->assertEquals("freeplacemsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student2->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student2->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertNotEmpty($rulejson->datafromevent->eventname ?? '');
            } else {
                // Validate 3 task messages on the bookingoption_freetobookagain with delay event.
                $this->assertEquals("freeplacedelaysubj", $customdata->customsubject);
                $this->assertEquals("freeplacedelaymsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student2->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student2->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertNotEmpty($rulejson->datafromevent->eventname ?? '');
            }
        }

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(5, $messages);
        // Validate ACTUAL task messages. Might be free order.
        $messagekeys = [];
        foreach ($messages as $key => $message) {
            $messagekey = $message->useridto . ':' . $message->subject;
            $this->assertArrayNotHasKey($messagekey, $messagekeys);
            $messagekeys[$messagekey] = true;
            if (strpos($message->subject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $message->subject);
                $this->assertEquals("freeplacemsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student1->id, $student3->id, $student4->id]);
            } else {
                // Validate delay task messages on the bookingoption_freetobookagain event chain.
                $this->assertEquals("freeplacedelaysubj", $message->subject);
                $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student1->id, $student2->id, $student3->id, $student4->id]);
            }
        }
    }

    /**
     * A2 (O2, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): tie-break for the
     * waiting-list promotion order.
     *
     * booking_option::sync_waiting_list() re-sorts the waiting list with a bare usort() that
     * only compares timemodified (booking_option.php, no id tie-break in the comparator
     * itself). Since PHP 8's usort() is stable, ties keep the relative order they had when
     * they entered the array - which comes from
     * booking_answers::return_sql_to_get_answers()'s "ORDER BY ba.timemodified ASC, ba.id
     * ASC". This test locks in that the whole chain (SQL tie-break -> stable usort) still
     * produces a deterministic id-ascending promotion order for genuinely identical
     * timestamps, repeated several times to catch DB/cache-layer nondeterminism (relevant for
     * MariaDB per the coverage doc; fix 1df299206 shipped without a test).
     *
     * @covers \mod_booking\booking_option::sync_waiting_list
     * @covers \mod_booking\booking_answers\booking_answers::get_usersonwaitinglist
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_o2_tiebreak_promotion_order_deterministic_with_identical_timemodified(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users. Students B, C, D join the waiting list in this order (ascending
        // answer id), but will all be forced to the exact same timemodified below.
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $studentc = $this->getDataGenerator()->create_user();
        $studentd = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentc->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentd->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Booking option: 1 free seat, waiting list on, NOT forced (waitforconfirmation = 0)
        // so sync_waiting_list() auto-promotes without a confirmation-count gate.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 0;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill the single seat with student A. The exact number of bookit()/confirm steps
        // depends on waitforconfirmation=0's flow, so drive it defensively to the booked state.
        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings->id, $studenta->id);
        [$id] = $boinfo->is_available($settings->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $studenta->id);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        $this->setAdminUser();

        // Students B, C, D join the waiting list, in this order -> ascending answer id.
        foreach ([$studentb, $studentc, $studentd] as $student) {
            $this->setUser($student);
            singleton_service::destroy_user($student->id);
            booking_bookit::bookit('option', $settings->id, $student->id);
            booking_bookit::bookit('option', $settings->id, $student->id);
            [$id] = $boinfo->is_available($settings->id, $student->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }
        $this->setAdminUser();

        // Force B, C and D onto the EXACT same timemodified so ORDER BY timemodified alone can
        // no longer disambiguate them - only the id tie-break can.
        $sametime = time();
        $waitinglistanswers = $DB->get_records(
            'booking_answers',
            ['optionid' => $option1->id, 'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST],
            'id ASC'
        );
        $this->assertCount(3, $waitinglistanswers);
        $orderedids = array_values(array_map(fn($a) => (int) $a->userid, $waitinglistanswers));
        // Sanity-check the fixture: ids/creation order really are B, C, D ascending.
        $this->assertEquals([$studentb->id, $studentc->id, $studentd->id], $orderedids);
        foreach ($waitinglistanswers as $answer) {
            $DB->set_field('booking_answers', 'timemodified', $sametime, ['id' => $answer->id]);
        }
        singleton_service::destroy_booking_answers($option1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Repeated, independent re-fetches of the waiting list must return the SAME
        // id-ascending order every time - this is the actual O2 guarantee.
        for ($i = 0; $i < 5; $i++) {
            \cache::make('mod_booking', 'bookingoptionsanswers')->purge();
            singleton_service::destroy_booking_answers($option1->id);
            $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $waitinglistkeys = array_map('intval', array_keys($ba->get_usersonwaitinglist()));
            $this->assertEquals(
                [$studentb->id, $studentc->id, $studentd->id],
                $waitinglistkeys,
                "Waiting-list order must stay id-ascending on repeated fetch #$i despite identical timemodified."
            );
        }

        // Now exercise the real O2/T2 trigger: increase maxanswers by exactly ONE free seat.
        // With three fully tied candidates, sync_waiting_list() must promote the LOWEST id
        // (student B) and leave C and D waiting - never C or D ahead of B.
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->maxanswers = 2;
        $record->teachersforoption = [$teacher1->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookedids = array_map('intval', array_keys($ba->get_usersonlist()));
        $waitingids = array_map('intval', array_keys($ba->get_usersonwaitinglist()));

        $this->assertContains((int) $studenta->id, $bookedids);
        $this->assertContains(
            (int) $studentb->id,
            $bookedids,
            'O2: with identical timemodified, the lowest-id waiting-list user (B) must be promoted first.'
        );
        $this->assertContains((int) $studentc->id, $waitingids);
        $this->assertContains((int) $studentd->id, $waitingids);
        $this->assertCount(2, $bookedids);
        $this->assertCount(2, $waitingids);
    }

    /**
     * A3 (O4, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): duplicate
     * booking_answers rows for the same person must not cause duplicate treatment.
     *
     * select_student_in_bo::execute() selects rows via "ba.waitinglist = :borole" with no
     * general per-user GROUP BY (only its special "additionalusers"/late-joiner branch
     * deduplicates, via "GROUP BY sub.userid", and only for deleted rows of forced users).
     * If a person ends up with two live booking_answers rows in the same waitinglist status
     * for the same option (fix b2b2fb2b9 / #1146, shipped without a dedicated test per the
     * coverage doc), this test locks in that they are still notified exactly once - not once
     * per duplicate row.
     *
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_o4_duplicate_answer_rows_cause_only_one_treatment(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Single, simple "notify waiting-list users" rule on bookingoption_freetobookagain.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'notifywl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Booking option: 1 seat, waiting list on, FORCED (waitforconfirmation = 1) - with
        // waitforconfirmation = 0, sync_waiting_list() would auto-promote student2 the
        // instant student1 cancels (K3), immediately refilling the seat before
        // check_if_free_to_book_again() ever observes it as free, so the mail rule would
        // never fire. Forcing confirmation keeps the seat genuinely free so we can test the
        // mail path, matching the pattern of the existing tests above.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill the single seat with student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id] = $boinfo->is_available($settings->id, $student1->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $student1->id);
            [$id] = $boinfo->is_available($settings->id, $student1->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Student2 joins the waiting list.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Manufacture the duplicate: clone student2's waiting-list answer row so there are
        // TWO live rows with waitinglist=WAITINGLIST for the same (user, option) pair.
        $original = $DB->get_record('booking_answers', ['optionid' => $option1->id, 'userid' => $student2->id]);
        $this->assertNotEmpty($original);
        $duplicate = clone $original;
        unset($duplicate->id);
        $duplicateid = $DB->insert_record('booking_answers', $duplicate);
        $this->assertNotEquals($original->id, $duplicateid);

        $allanswers = $DB->get_records('booking_answers', ['optionid' => $option1->id, 'userid' => $student2->id]);
        $this->assertCount(2, $allanswers, 'Fixture must have exactly 2 live rows for student2 before triggering.');

        singleton_service::destroy_booking_answers($option1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Free the seat: cancel student1 -> triggers bookingoption_freetobookagain.
        $this->setUser($student1);
        $option->user_delete_response($student1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        // Exactly ONE adhoc mail task for student2, not two (one per duplicate row).
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'Duplicate answer rows must not create duplicate tasks (O4).');
        $customdata = reset($tasks)->get_custom_data();
        $this->assertEquals($student2->id, $customdata->userid);

        // Run it and confirm exactly one message is actually sent.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        ob_get_clean();
        $sink->close();

        $this->assertCount(1, $messages, 'Duplicate answer rows must not cause duplicate mail delivery (O4).');
        $this->assertEquals($student2->id, $messages[0]->useridto);
    }

    /**
     * A4 (K9, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a rule that is
     * changed or deleted after a mail step has already been scheduled must not send a mail
     * with stale content, and must leave a plain-text abort reason in the task log.
     *
     * send_mail_by_rule_adhoc::execute() snapshots the rule's rulejson into the task's
     * custom data at scheduling time and re-reads the live \booking_rules row at run time
     * (fix 1ea74eed0 / #1165, shipped without a dedicated test). Two cases are locked in
     * here: (1) the rule's actiondata/ruledata is edited after scheduling -> the task
     * compares the snapshot against the live row, finds a mismatch and aborts without
     * sending; (2) the rule is deleted outright after scheduling -> the task can't find the
     * rule at all and aborts. Both must mtrace a human-readable reason instead of failing
     * silently or throwing.
     *
     * @covers \mod_booking\task\send_mail_by_rule_adhoc::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_k9_rule_changed_or_deleted_after_scheduling_aborts_send(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';

        // Case 1: rule content changed after the mail step was scheduled.

        $actstr1 = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr1 .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifywl1',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr1,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Booking option 1: 1 seat, waiting list on, FORCED confirmation (see A3: with
        // waitforconfirmation = 0, sync_waiting_list() would auto-promote the waiting-list
        // user before the freetobookagain event can be observed as "still free").
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football1';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        // Fill the single seat with student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id] = $boinfo1->is_available($settings1->id, $student1->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings1->id, $student1->id);
            [$id] = $boinfo1->is_available($settings1->id, $student1->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option1obj->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Student2 joins the waiting list.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Free the seat: cancel student1 -> triggers bookingoption_freetobookagain, schedules
        // the mail step for student2 with a snapshot of rule1's rulejson as it is right now.
        $this->setUser($student1);
        $option1obj->user_delete_response($student1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks, 'Precondition: exactly one mail step must be scheduled for student2.');

        // Now edit the rule "in the admin UI" - change the mail subject - after the step was
        // already scheduled. This updates the live booking_rules row via the same generator
        // path the settings form uses, without touching the already-scheduled task's
        // snapshot.
        $actstr1changed = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr1changed .= '"subject":"changedsubj","template":"changedmsg","templateformat":"1"}';
        $ruledata1changed = $ruledata1;
        $ruledata1changed['id'] = $rule1->id;
        $ruledata1changed['actiondata'] = $actstr1changed;
        $plugingenerator->create_rule($ruledata1changed);

        $sink1 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $trace1 = ob_get_clean();
        $messages1 = $sink1->get_messages();
        $sink1->close();
        // Note: runAdhocTasks() switches $USER to the executed task's assigned user
        // (\core\cron::setup_user()) and never restores it - reset to admin before
        // setting up case 2, or create_option() below silently loses capability-gated
        // field classes and writes a broken option row.
        $this->setAdminUser();

        $this->assertCount(
            0,
            $messages1,
            'K9: a rule edited after scheduling must not send the stale mail step.'
        );
        $this->assertStringContainsString(
            'Rule or Option has changed. Mail was NOT SENT',
            $trace1,
            'K9: the abort must leave a plain-text reason in the task log (mtrace).'
        );

        // Rules are global (contextid 1): rule1 would otherwise also react to option2's
        // freetobookagain event below and - since its content is no longer changing after
        // this point - send a (legitimate) mail of its own, muddying case 2's assertions.
        // Case 1 is fully verified, so retire it now.
        $DB->delete_records('booking_rules', ['id' => $rule1->id]);

        // Case 2: rule deleted outright after the mail step was scheduled.

        $actstr2 = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr2 .= '"subject":"freeplacesubj2","template":"freeplacemsg2","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'notifywl2',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr2,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        $record2 = new stdClass();
        $record2->bookingid = $booking1->id;
        $record2->text = 'football2';
        $record2->chooseorcreatecourse = 1;
        $record2->courseid = $course1->id;
        $record2->maxanswers = 1;
        $record2->maxoverbooking = 10;
        $record2->waitforconfirmation = 1;
        $record2->description = 'Will start in 2050';
        $record2->optiondateid_0 = "0";
        $record2->daystonotify_0 = "0";
        $record2->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record2->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record2->teachersforoption = $teacher1->username;
        $option2 = $plugingenerator->create_option($record2);
        singleton_service::destroy_booking_option_singleton($option2->id);

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);

        // Fill the single seat with student3.
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        booking_bookit::bookit('option', $settings2->id, $student3->id);
        [$id] = $boinfo2->is_available($settings2->id, $student3->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings2->id, $student3->id);
            [$id] = $boinfo2->is_available($settings2->id, $student3->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option2obj->user_submit_response($student3, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Student4 joins the waiting list.
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        booking_bookit::bookit('option', $settings2->id, $student4->id);
        booking_bookit::bookit('option', $settings2->id, $student4->id);
        [$id] = $boinfo2->is_available($settings2->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Free the seat: cancel student3 -> schedules the mail step for student4 with a
        // snapshot of rule2's rulejson as it is right now.
        $this->setUser($student3);
        $option2obj->user_delete_response($student3->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        singleton_service::destroy_booking_answers($option2->id);
        $this->setAdminUser();

        $tasks2 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        // Rules are global (contextid 1), so rule1 (still active, just edited in case 1) also
        // reacts to option2's freetobookagain event alongside rule2 - filter to rule2's own
        // task for student4.
        $tasks2 = array_filter(
            $tasks2,
            fn($t) => $t->get_custom_data()->userid == $student4->id && $t->get_custom_data()->ruleid == $rule2->id
        );
        $this->assertCount(1, $tasks2, 'Precondition: exactly one mail step must be scheduled for student4 by rule2.');

        // Now delete the rule outright - "in the admin UI" - after the step was scheduled.
        $DB->delete_records('booking_rules', ['id' => $rule2->id]);

        $sink2 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $trace2 = ob_get_clean();
        $messages2 = $sink2->get_messages();
        $sink2->close();

        $this->assertCount(
            0,
            $messages2,
            'K9: a rule deleted after scheduling must not send the stale mail step.'
        );
        $this->assertStringContainsString(
            'Rule does not exist anymore. Mail was NOT SENT',
            $trace2,
            'K9: the abort must leave a plain-text reason in the task log (mtrace).'
        );
    }

    /**
     * A5 (K10, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a booking option
     * deleted, or its course module moved (cmid changed), after a step was already scheduled.
     *
     * Three cases, characterizing today's actual (imperfect) behaviour rather than the desired
     * target behaviour - the target is B-level work for Phase 3, once the new architecture's
     * capacity/context checks happen fresh at send-time instead of relying on a stale task
     * snapshot:
     *
     * 1. send_mail_by_rule_adhoc, option deleted: no exception, no mail - but ALSO no explicit
     *    "why" reason (unlike K9's "Rule or Option has changed"), just a bare "mail could not
     *    be sent". The option's settings singleton falls back to defaults (cmid = null) for a
     *    deleted id, which never even reaches send_or_queue() successfully.
     * 2. send_mail_by_rule_adhoc, cmid changed but the OPTION ITSELF still exists: this is a
     *    genuine current gap, not a safe abort. execute()'s "has anything changed" gate only
     *    aborts on a cmid mismatch for rule_daysbefore/rule_specifictime rules (see the
     *    $abort = true branch) - for rule_react_on_event (this whole feature's rule type), a
     *    cmid-only mismatch is silently ignored because only actiondata/ruledata equality is
     *    checked once inside the gate. The mail is sent anyway, with the stale/wrong cmid.
     * 3. confirm_bookinganswer_by_rule_adhoc, option deleted: unlike its mail counterpart, this
     *    task has NO cmid comparison anywhere (confirmed by inspection - the only rulejson
     *    compare is gated to rule_daysbefore/rule_specifictime, same as case 2, and cmid is
     *    never read at all). It happens to fail safe for the deleted-option sub-case, but only
     *    by accident: booking_option_settings::$confirmationonnotification defaults to 0 for a
     *    row that can't be loaded, which the task's own "is confirmation required at all" check
     *    (== 0) then treats as "feature disabled" and returns early. This is the Schritt-1
     *    asymmetry finding: the confirm task has strictly less protection than the mail task,
     *    which itself is already incomplete (case 2).
     *
     * @covers \mod_booking\task\send_mail_by_rule_adhoc::execute
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_k10_option_deleted_or_cmid_changed_after_scheduling(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'notifywl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football1';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;

        // Case 1: option deleted after the mail step was scheduled.

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id] = $boinfo1->is_available($settings1->id, $student1->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings1->id, $student1->id);
            [$id] = $boinfo1->is_available($settings1->id, $student1->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option1obj->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Free the seat -> schedules the mail step for student2.
        $this->setUser($student1);
        $option1obj->user_delete_response($student1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $tasks1 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks1, 'Precondition: exactly one mail step must be scheduled for student2.');

        // Delete the option outright - "in the admin UI" - after the step was scheduled.
        $DB->delete_records('booking_options', ['id' => $option1->id]);
        booking_option::purge_cache_for_option($option1->id);

        $sink1 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $trace1 = ob_get_clean();
        $messages1 = $sink1->get_messages();
        $sink1->close();
        // Note: runAdhocTasks() switches $USER to the executed task's assigned user and never
        // restores it (see A4) - reset before building the next option below.
        $this->setAdminUser();

        $this->assertCount(
            0,
            $messages1,
            'K10: a mail step for a since-deleted option must not actually send.'
        );
        $this->assertStringContainsString(
            'mail could not be sent',
            $trace1,
            'K10 characterization: unlike K9, a deleted option leaves no explicit "why" reason ' .
            'in the task log - the task just silently fails to build/queue the message.'
        );

        // Case 2: cmid mismatch, the OPTION ITSELF still exists (simulates its course
        // module having been moved to another course after scheduling). Mutate the
        // already-scheduled task's stored cmid directly - that is exactly what
        // send_mail_by_rule_adhoc::execute() compares against the live option's cmid, so this
        // reproduces the real-world effect without needing full course-module-move machinery.

        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student3 = $this->getDataGenerator()->create_user();
        // Booking capability is checked against the booking activity's own course (course1),
        // not the connected course (course2) - enrol in both.
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course2->id, 'student');

        $record2 = clone $record;
        $record2->id = 0;
        $record2->courseid = $course2->id;
        $record2->text = 'football2';
        $option2 = $plugingenerator->create_option($record2);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);

        $this->setUser($student2);
        booking_bookit::bookit('option', $settings2->id, $student2->id);
        [$id] = $boinfo2->is_available($settings2->id, $student2->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings2->id, $student2->id);
            [$id] = $boinfo2->is_available($settings2->id, $student2->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option2obj->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($student3);
        booking_bookit::bookit('option', $settings2->id, $student3->id);
        booking_bookit::bookit('option', $settings2->id, $student3->id);
        [$id] = $boinfo2->is_available($settings2->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        $this->setUser($student2);
        $option2obj->user_delete_response($student2->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        singleton_service::destroy_booking_answers($option2->id);
        $this->setAdminUser();

        $tasks2 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $tasks2 = array_filter($tasks2, fn($t) => $t->get_custom_data()->userid == $student3->id);
        $this->assertCount(1, $tasks2, 'Precondition: exactly one mail step must be scheduled for student3.');
        $task2 = reset($tasks2);
        $taskrecord2 = $DB->get_record('task_adhoc', ['id' => $task2->get_id()]);
        $customdata2 = json_decode($taskrecord2->customdata);
        $customdata2->cmid = 999999;
        $taskrecord2->customdata = json_encode($customdata2);
        $DB->update_record('task_adhoc', $taskrecord2);

        $sink2 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $messages2 = $sink2->get_messages();
        $sink2->close();
        $this->setAdminUser();

        $this->assertCount(
            1,
            $messages2,
            'K10 characterization (current gap, not desired behaviour): a cmid mismatch alone ' .
            'is NOT enough to abort a rule_react_on_event mail step - only actiondata/ruledata ' .
            'equality is checked, so the stale-cmid mail is sent anyway. Must be closed by the ' .
            'new architecture (fresh context/capacity check at send-time), tracked as part of K10.'
        );

        // Case 3: confirm_bookinganswer_by_rule_adhoc, option deleted after scheduling -
        // documents the Schritt-1 asymmetry: this task never compares cmid at all.

        $ruledataconfirm = [
            'name' => 'confirmwl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledataconfirm);

        $student4 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');

        $record3 = clone $record;
        $record3->id = 0;
        $record3->text = 'football3';
        $record3->confirmationonnotification = 1;
        $option3 = $plugingenerator->create_option($record3);
        singleton_service::destroy_booking_option_singleton($option3->id);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $this->assertEquals(
            1,
            $settings3->confirmationonnotification,
            'Precondition: confirmationonnotification must actually be enabled on option3.'
        );
        $boinfo3 = new bo_info($settings3);
        $option3obj = singleton_service::get_instance_of_booking_option($settings3->cmid, $settings3->id);

        $this->setUser($student2);
        booking_bookit::bookit('option', $settings3->id, $student2->id);
        [$id] = $boinfo3->is_available($settings3->id, $student2->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings3->id, $student2->id);
            [$id] = $boinfo3->is_available($settings3->id, $student2->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option3obj->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($student4);
        booking_bookit::bookit('option', $settings3->id, $student4->id);
        booking_bookit::bookit('option', $settings3->id, $student4->id);
        [$id] = $boinfo3->is_available($settings3->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        $this->setUser($student2);
        $option3obj->user_delete_response($student2->id);
        singleton_service::destroy_booking_option_singleton($option3->id);
        singleton_service::destroy_booking_answers($option3->id);
        $this->setAdminUser();

        $tasks3 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\confirm_bookinganswer_by_rule_adhoc');
        $tasks3 = array_filter($tasks3, fn($t) => $t->get_custom_data()->userid == $student4->id);
        $this->assertCount(1, $tasks3, 'Precondition: exactly one confirm step must be scheduled for student4.');

        $answerbefore = $DB->get_record('booking_answers', [
            'optionid' => $option3->id,
            'userid' => $student4->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertNotEmpty($answerbefore, 'Precondition: student4 must still be on the waiting list.');

        $DB->delete_records('booking_options', ['id' => $option3->id]);
        booking_option::purge_cache_for_option($option3->id);

        ob_start();
        $this->runAdhocTasks();
        $trace3 = ob_get_clean();
        $this->setAdminUser();

        $this->assertStringContainsString(
            'no confirmation is required',
            $trace3,
            'K10 asymmetry: with no cmid check of its own, the confirm task only fails safe ' .
            'for a deleted option by accident - confirmationonnotification defaults to 0 when ' .
            'the option row cannot be loaded, which the task reads as "feature disabled".'
        );
        $answerafter = $DB->get_record('booking_answers', ['id' => $answerbefore->id]);
        $answerjsonafter = empty($answerafter->json) ? null : json_decode($answerafter->json);
        $this->assertEmpty(
            $answerjsonafter->confirmwaitinglist ?? null,
            'K10: the (accidental) safe-fail must mean no confirmation was actually recorded.'
        );
    }

    /**
     * A6 (K3, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): overbooking
     * protection when two free/immediately-bookable waiting-list candidates compete for a
     * single freed seat.
     *
     * booking_option::sync_waiting_list() computes $noofuserstobook ONCE from the option's
     * capacity before entering its promotion loop, and decrements it on every iteration
     * (booking_option.php, "1. Update, enrol and inform users..."). This locks in that with
     * exactly one free seat and two equally eligible (free, unforced) waiting-list candidates,
     * exactly one gets auto-booked - never both - and that re-running sync_waiting_list()
     * afterwards (e.g. a second, redundant trigger - see also A10/K5) does not promote a
     * second person into a seat that no longer exists.
     *
     * @covers \mod_booking\booking_option::sync_waiting_list
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_k3_overbooking_protection_with_two_free_candidates_one_free_seat(array $bdata): void {
        $bdata['cancancelbook'] = 1;

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $studentc = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentc->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Booking option: 1 seat, waiting list on, NOT forced (waitforconfirmation = 0) and no
        // price - both waiting-list candidates are equally, immediately eligible for K3's
        // automatic promotion (a priced option would make sync_waiting_list() skip them
        // instead, per paid_option_skips_user()).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 0;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill the single seat with student A.
        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings->id, $studenta->id);
        [$id] = $boinfo->is_available($settings->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $studenta->id);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        $this->setAdminUser();

        // Students B and C both join the waiting list - two equally eligible candidates for
        // the single seat that is about to free up.
        foreach ([$studentb, $studentc] as $student) {
            $this->setUser($student);
            singleton_service::destroy_user($student->id);
            booking_bookit::bookit('option', $settings->id, $student->id);
            booking_bookit::bookit('option', $settings->id, $student->id);
            [$id] = $boinfo->is_available($settings->id, $student->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }
        $this->setAdminUser();

        // Free the seat: cancel student A. user_delete_response() calls sync_waiting_list()
        // synchronously (waitforconfirmation = 0), so the K3 promotion loop runs right here -
        // exactly one free seat must go to exactly one of B/C, never both.
        $this->setUser($studenta);
        $option->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookedids = array_map('intval', array_keys($ba->get_usersonlist()));
        $waitingids = array_map('intval', array_keys($ba->get_usersonwaitinglist()));

        $this->assertCount(1, $bookedids, 'K3: exactly one seat is free, so exactly one person must be booked.');
        $this->assertCount(1, $waitingids, 'K3: the other equally eligible candidate must stay on the waiting list.');
        $this->assertContains(
            (int) $studentb->id,
            $bookedids,
            'K3: the earlier-joined candidate (B) must be the one promoted (tie-break, see O2).'
        );
        $this->assertContains((int) $studentc->id, $waitingids);

        // Idempotency: a second, redundant sync_waiting_list() call (e.g. a duplicate trigger,
        // see A10/K5) must not promote C into a seat that no longer exists.
        $option->sync_waiting_list();
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookedidsafter = array_map('intval', array_keys($ba->get_usersonlist()));
        $waitingidsafter = array_map('intval', array_keys($ba->get_usersonwaitinglist()));

        $this->assertEquals($bookedids, $bookedidsafter, 'K3: a redundant sync must not change who is booked.');
        $this->assertEquals($waitingids, $waitingidsafter, 'K3: a redundant sync must not promote a second person.');
    }

    /**
     * A7 (W1/W2, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): the
     * confirmationonnotification setting's mode 0 (off) and mode 2 (exclusive) behaviour.
     *
     * confirm_bookinganswer_by_rule_adhoc::execute() is otherwise well covered for mode 1/2
     * (booking_waitinglist_confirmation_test.php), but two things are not: mode 0 is never
     * exercised anywhere, and no existing test asserts the DB state of an ALREADY-confirmed
     * OTHER waiting-list user across an exclusive-mode (mode 2) confirmation - only who gets
     * notified/booked next, not that a prior confirmation is actively revoked.
     *
     * Case 1 (W1, mode 0): confirmationonnotification = 0 must be fully inert - the task
     * aborts immediately (confirmed via the same "no confirmation is required" trace as the
     * K10 test above) and never writes a confirmwaitinglist key.
     * Case 2 (W2, mode 2): with a second waiting-list user pre-seeded as already confirmed
     * (simulating a stale confirmation from an earlier round), confirming a NEW user must
     * actively strip the confirmwaitinglist key from that other user's answer - not just leave
     * it alone. user_already_confirmed() also means the pre-confirmed user never gets a task
     * of their own for this round (skipped at the rule-execute level, not just at task level).
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_w1w2_confirmationonnotification_modes(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // A single default price category is enough - every user here shares the same price,
        // which only needs to be > 0 so confirm_bookinganswer_by_rule_adhoc takes the
        // JSON-confirmation branch instead of the free-user direct-booking branch.
        $pricecategorydata = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata);

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;

        // Case 1 (W1): confirmationonnotification = 0 -> must be a no-op.

        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->setAdminUser();

        $record1 = clone $record;
        $record1->text = 'football1';
        $record1->confirmationonnotification = 0;
        $option1 = $plugingenerator->create_option($record1);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        // Fill the single (priced) seat with student A via admin override - waitforconfirmation
        // = 1 forces every first-time booking onto the waiting list regardless of price, so A
        // ends up there too and must be force-submitted to actually hold the seat.
        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings1->id, $studenta->id);
        [$id] = $boinfo1->is_available($settings1->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings1->id, $studenta->id);
            [$id] = $boinfo1->is_available($settings1->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option1obj->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($studentb);
        singleton_service::destroy_user($studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        [$id] = $boinfo1->is_available($settings1->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        $answerbefore = $DB->get_record('booking_answers', ['optionid' => $option1->id, 'userid' => $studentb->id]);
        $this->assertEmpty($answerbefore->json, 'Precondition: student B must start unconfirmed.');

        // Free the seat -> schedules a confirm task for student B (plus a harmless task for
        // the cancelling student A, whose own answer is DELETED by now and matched only by
        // select_student_in_bo's forced-late-joiner branch - it aborts with "No booking answer
        // found" and does not affect these assertions).
        $this->setUser($studenta);
        $option1obj->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $sink1 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $trace1 = ob_get_clean();
        $sink1->close();
        $this->setAdminUser();

        $this->assertStringContainsString(
            'setting in the booking option is set to 0, so no confirmation is required',
            $trace1,
            'W1: mode 0 must abort the confirm task with an explicit "disabled" reason.'
        );
        $answerafter = $DB->get_record('booking_answers', ['optionid' => $option1->id, 'userid' => $studentb->id]);
        $answerjsonafter = empty($answerafter->json) ? null : json_decode($answerafter->json);
        $this->assertEmpty(
            $answerjsonafter->confirmwaitinglist ?? null,
            'W1: mode 0 must never grant a confirmation, however long the waiting-list user waits.'
        );

        // Case 2 (W2): confirmationonnotification = 2 (exclusive) -> confirming one user must
        // actively revoke an already-confirmed OTHER user, not just leave them alone.

        $studentc = $this->getDataGenerator()->create_user();
        $studentd = $this->getDataGenerator()->create_user();
        $studente = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentc->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentd->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studente->id, $course1->id, 'student');
        $this->setAdminUser();

        $record2 = clone $record;
        $record2->text = 'football2';
        $record2->confirmationonnotification = 2;
        $option2 = $plugingenerator->create_option($record2);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);

        $this->setUser($studentc);
        booking_bookit::bookit('option', $settings2->id, $studentc->id);
        [$id] = $boinfo2->is_available($settings2->id, $studentc->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings2->id, $studentc->id);
            [$id] = $boinfo2->is_available($settings2->id, $studentc->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option2obj->user_submit_response($studentc, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Student D joins first (gets the direct confirm task below), student E second.
        foreach ([$studentd, $studente] as $s) {
            $this->setUser($s);
            booking_bookit::bookit('option', $settings2->id, $s->id);
            booking_bookit::bookit('option', $settings2->id, $s->id);
            [$id] = $boinfo2->is_available($settings2->id, $s->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }
        $this->setAdminUser();

        // Pre-seed student E as already confirmed from an earlier round (e.g. a since-expired
        // offer) - this is the prior state whose active revocation W2 is about.
        $eanswer = $DB->get_record('booking_answers', ['optionid' => $option2->id, 'userid' => $studente->id]);
        $DB->set_field(
            'booking_answers',
            'json',
            json_encode(['confirmwaitinglist' => 1, 'confirmationcount' => 1]),
            ['id' => $eanswer->id]
        );

        $this->setUser($studentc);
        $option2obj->user_delete_response($studentc->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        singleton_service::destroy_booking_answers($option2->id);
        $this->setAdminUser();

        // Student E must not even receive a task of her own this round - she is already
        // confirmed, so confirm_bookinganswer::execute()'s user_already_confirmed() guard
        // skips her before the counter/task-queueing logic ever runs.
        $tasks2 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\confirm_bookinganswer_by_rule_adhoc');
        $tasksforstudente = array_filter($tasks2, fn($t) => $t->get_custom_data()->userid == $studente->id);
        $this->assertCount(0, $tasksforstudente, 'W2: an already-confirmed user must not get a fresh confirm task.');

        $sink2 = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $sink2->close();
        $this->setAdminUser();

        $danswer = $DB->get_record('booking_answers', ['optionid' => $option2->id, 'userid' => $studentd->id]);
        $djson = empty($danswer->json) ? null : json_decode($danswer->json);
        $this->assertNotEmpty(
            $djson->confirmwaitinglist ?? null,
            'W2: the newly processed user (D) must end up confirmed.'
        );

        $eanswerafter = $DB->get_record('booking_answers', ['id' => $eanswer->id]);
        $eanswerjsonafter = empty($eanswerafter->json) ? null : json_decode($eanswerafter->json);
        $this->assertEmpty(
            $eanswerjsonafter->confirmwaitinglist ?? null,
            'W2: exclusive mode must ACTIVELY revoke the other waiting-list user\'s prior ' .
            'confirmation, not merely leave it alone while a new one is granted.'
        );
    }

    /**
     * A8 (P2, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a waiting-list
     * candidate whose price cannot be resolved at all must be treated like price 0 (K3
     * autobooking still applies), without triggering a PHP warning/notice.
     *
     * price::get_price() returns a plain [] (no 'price' key whatsoever, not even price=0) when
     * the user's price category matches nothing AND the 'pricecategoryfallback' admin setting
     * is 2 (no default fallback) - a real, reachable admin misconfiguration, not a contrived
     * edge case. waitinglist_sync_status::paid_option_skips_user() already guards this
     * correctly with isset($price['price']) rather than a bare array access, but that guard
     * itself was never exercised by a test with a genuinely key-less price array (fix
     * associated with the coverage doc's P2 gap). This locks in both halves: the guard's
     * behaviour (treat as free -> autobook) AND its absence of side effects (no warning).
     *
     * @covers \mod_booking\local\waitinglist\waitinglist_sync_status::paid_option_skips_user
     * @covers \mod_booking\booking_option::sync_waiting_list
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_p2_missing_price_key_treated_as_free_no_warning(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // No default fallback -> a user matching no price category gets price::get_price()
        // == [] (no 'price' key at all), not even a resolved price of 0.
        set_config('pricecategoryfallback', 2, 'booking');

        $pricecategorydata = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        // Not forced: K3's automatic sync_waiting_list() promotion is exactly what P2 protects.
        $record->waitforconfirmation = 0;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $price = price::get_price('option', $settings->id, $studentb);
        $this->assertArrayNotHasKey(
            'price',
            $price,
            'Precondition: student B\'s price must be genuinely unresolvable (no "price" key), not just 0.'
        );

        // Fill the single seat with student A via admin override - a useprice=1 option with an
        // unresolvable price also blocks the normal booking_bookit() flow at
        // MOD_BOOKING_BO_COND_PRICEISSET, so both students need the admin-override path here.
        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings->id, $studenta->id);
        [$id] = $boinfo->is_available($settings->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $studenta->id);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Note: booking_bookit() would hit MOD_BOOKING_BO_COND_PRICEISSET and never create a
        // real waiting-list row for student B either (verified: the normal flow blocks
        // entirely when the price is unresolvable) - manufacture the waiting-list fixture
        // directly, like A2/A3, since K3's promotion logic is what's under test, not the form.
        singleton_service::destroy_user($studentb->id);
        booking_option::write_user_answer_to_db(
            $booking1->id,
            0,
            $studentb->id,
            $option1->id,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST
        );
        singleton_service::destroy_booking_answers($option1->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        [$id] = $boinfo->is_available($settings->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id, 'Precondition: student B must be on the waiting list.');
        $this->setAdminUser();

        // Free the seat under a temporary error handler: sync_waiting_list()'s K3 promotion
        // runs synchronously inside user_delete_response() (waitforconfirmation=0), so any
        // "Undefined array key" warning from a bare $price['price'] access would fire here.
        $caughtwarnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$caughtwarnings): bool {
            $caughtwarnings[] = "$errno: $errstr";
            return false;
        });
        $this->setUser($studenta);
        $option->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        restore_error_handler();
        $this->setAdminUser();

        $this->assertEmpty(
            $caughtwarnings,
            'P2: an unresolvable price must not trigger any PHP warning/notice during the ' .
            'K3 promotion path. Caught: ' . implode('; ', $caughtwarnings)
        );

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $studentb->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'P2: with no resolvable price, K3 must autobook student B exactly as it would for price 0.'
        );
    }

    /**
     * A9 (P1, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a price-category
     * (affiliation) change WHILE a person is on the waiting list must be evaluated fresh at
     * decision time (when the seat actually frees up), not with the category that was current
     * when they joined. Directly motivated by Felix' u:rise test protocol v3 (affiliation
     * switch causing a stuck waiting list).
     *
     * Case A (free direction): joins as paid ('employee'), switches to free ('student') before
     * the seat frees -> K3 must autobook using the NEW category.
     * Case B (paid direction): joins as free ('student'), switches to paid ('employee') before
     * the seat frees -> must NOT be silently autobooked (paid_option_skips_user() must see the
     * new category too), and must instead get a proper confirm/offer step using the new price
     * - not silence, and not the old free-category direct-booking branch.
     *
     * Reusable finding: singleton_service::get_pricecategory_for_user() caches the resolved
     * category per userid on the singleton instance - destroy_user() alone does NOT invalidate
     * it, only a full singleton_service::destroy_instance() does. In production this is a
     * non-issue (the cron task that runs sync_waiting_list() starts in its own fresh process),
     * but it means any test - or any hypothetical same-request code path - that reads a user's
     * price both before and after an affiliation change must force a full instance reset to
     * see the change, exactly like a fresh process would.
     *
     * @covers \mod_booking\local\waitinglist\waitinglist_sync_status::paid_option_skips_user
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\singleton_service::get_pricecategory_for_user
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_p1_affiliation_change_while_waiting_uses_fresh_category(array $bdata): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'employee',
            'identifier' => 'employee',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 2,
            'name' => 'student',
            'identifier' => 'student',
            'defaultvalue' => 0,
            'pricecatsortorder' => 2,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        // Not forced: Case A relies on K3's automatic sync_waiting_list() promotion.
        $record->waitforconfirmation = 0;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;

        // Case A: joins paid, switches to free before the seat frees -> must be autobooked.

        $studenta = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'employee']);
        $studentb = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'employee']);
        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->setAdminUser();

        $record1 = clone $record;
        $record1->text = 'football1';
        $option1 = $plugingenerator->create_option($record1);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        // Fill the single seat with student A via admin override (useprice=1 blocks the normal
        // booking_bookit() flow at MOD_BOOKING_BO_COND_PRICEISSET until actually paid).
        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings1->id, $studenta->id);
        [$id] = $boinfo1->is_available($settings1->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings1->id, $studenta->id);
            [$id] = $boinfo1->is_available($settings1->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option1obj->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Student B joins the waiting list as 'employee' (paid) - joining itself does not
        // require payment, only taking the seat does, so the normal flow works here.
        $this->setUser($studentb);
        singleton_service::destroy_user($studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        [$id] = $boinfo1->is_available($settings1->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Switch student B's affiliation to 'student' (free) WHILE on the waiting list.
        $updateuserb = new stdClass();
        $updateuserb->id = $studentb->id;
        $updateuserb->profile_field_pricecat = 'student';
        profile_save_data($updateuserb);
        // See class docblock: only a full instance reset picks up the DB change here.
        singleton_service::destroy_instance();

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $freshuserb = singleton_service::get_instance_of_user($studentb->id);
        $freshpriceb = price::get_price('option', $settings1->id, $freshuserb);
        $this->assertEquals(
            '0.00',
            $freshpriceb['price'] ?? null,
            'Precondition: student B\'s price must have actually switched to free (0.00).'
        );

        // Free the seat -> K3's automatic sync_waiting_list() promotion runs synchronously.
        $this->setUser($studenta);
        $option1obj->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        [$id] = $boinfo1->is_available($settings1->id, $studentb->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'P1: switching to a free category while waiting must get K3-autobooked using the ' .
            'NEW category, not silently stuck because of the old (paid) one at join time.'
        );

        // Case B: joins free, switches to paid before the seat frees -> must NOT be silently
        // autobooked, and must instead get a proper confirm/offer step with the new price.

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $studentc = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'employee']);
        $studentd = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $this->getDataGenerator()->enrol_user($studentc->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentd->id, $course1->id, 'student');
        $this->setAdminUser();

        $record2 = clone $record;
        $record2->text = 'football2';
        $record2->confirmationonnotification = 1;
        $option2 = $plugingenerator->create_option($record2);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);

        $this->setUser($studentc);
        booking_bookit::bookit('option', $settings2->id, $studentc->id);
        [$id] = $boinfo2->is_available($settings2->id, $studentc->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings2->id, $studentc->id);
            [$id] = $boinfo2->is_available($settings2->id, $studentc->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option2obj->user_submit_response($studentc, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($studentd);
        booking_bookit::bookit('option', $settings2->id, $studentd->id);
        booking_bookit::bookit('option', $settings2->id, $studentd->id);
        [$id] = $boinfo2->is_available($settings2->id, $studentd->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Switch student D's affiliation to 'employee' (paid) WHILE on the waiting list.
        $updateuserd = new stdClass();
        $updateuserd->id = $studentd->id;
        $updateuserd->profile_field_pricecat = 'employee';
        profile_save_data($updateuserd);
        singleton_service::destroy_instance();

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $freshuserd = singleton_service::get_instance_of_user($studentd->id);
        $freshpriced = price::get_price('option', $settings2->id, $freshuserd);
        $this->assertEquals(
            '80.00',
            $freshpriced['price'] ?? null,
            'Precondition: student D\'s price must have actually switched to paid (80.00).'
        );

        // Free the seat.
        $this->setUser($studentc);
        $option2obj->user_delete_response($studentc->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        singleton_service::destroy_booking_answers($option2->id);
        $this->setAdminUser();

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        [$id] = $boinfo2->is_available($settings2->id, $studentd->id, true);
        $this->assertNotEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'P1: switching to a paid category while waiting must NOT get K3-autobooked for ' .
            'free using the OLD category.'
        );

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $sink->close();
        $this->setAdminUser();

        $danswer = $DB->get_record('booking_answers', ['optionid' => $option2->id, 'userid' => $studentd->id]);
        $djson = empty($danswer->json) ? null : json_decode($danswer->json);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $danswer->waitinglist,
            'P1: student D must still be on the waiting list, not silently dropped or booked.'
        );
        $this->assertNotEmpty(
            $djson->confirmwaitinglist ?? null,
            'P1: switching to a paid category while waiting must produce a proper confirm/' .
            'offer step (using the new price), not silence.'
        );
    }

    /**
     * A10 (K5, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a second,
     * independent trigger of the same event for the same option/user must not cause double
     * treatment (no second mail/task).
     *
     * This test found and locks in a real bugfix. \core\task\manager::
     * reschedule_or_queue_adhoc_task() (used by send_mail/send_mail_interval/
     * confirm_bookinganswer to queue their adhoc tasks) deduplicates by an EXACT string
     * comparison of the task's customdata. rules_info::collect_rules_for_execution() embeds
     * the raw event payload (\core\event\base::get_data()) into that customdata as
     * "datafromevent" without normalizing scalar types - and userid in particular reaches
     * there as either int or string depending on whether the triggering call site went
     * through a type-hinted function (e.g. booking_option::check_if_free_to_book_again(int
     * $userid, ...), which coerces to int) or passed a raw value straight through (e.g. from a
     * DB record, or a caller not enforcing the type). A second, independently triggered event
     * for the exact same option/user/rule therefore serialized to a DIFFERENT customdata
     * string whenever the two call sites disagreed on the userid type, silently defeating the
     * dedup and producing a second task and a second mail to the same person - reproduced here
     * before the fix (2 tasks, 2 mails).
     *
     * Fix: normalize userid/objectid/relateduserid to int in rules_info::
     * collect_rules_for_execution() right before embedding them into datafromevent, so two
     * triggers of the same underlying event always serialize identically.
     *
     * @covers \mod_booking\booking_rules\rules_info::collect_rules_for_execution
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_k5_double_trigger_of_same_event_does_not_double_treat(array $bdata): void {
        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'notifywl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        // Forced: the seat must stay genuinely free across both triggers (see A3).
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings->id, $studenta->id);
        [$id] = $boinfo->is_available($settings->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $studenta->id);
            [$id] = $boinfo->is_available($settings->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        $this->setUser($studentb);
        singleton_service::destroy_user($studentb->id);
        booking_bookit::bookit('option', $settings->id, $studentb->id);
        booking_bookit::bookit('option', $settings->id, $studentb->id);
        [$id] = $boinfo->is_available($settings->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Cancel student A -> natural, single trigger of bookingoption_freetobookagain
        // (goes through the type-hinted booking_option::check_if_free_to_book_again(int
        // $userid, ...), so its embedded userid is an int).
        $this->setUser($studenta);
        $option->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $tasks1 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks1, 'Precondition: exactly one mail step must be scheduled after the first trigger.');

        // Manually re-fire the SAME event a second time - simulating a genuine, independent
        // double-trigger (e.g. two call sites both reacting to the same cancellation; student
        // A's id passed here is whatever raw type mod_booking_generator produced, deliberately
        // NOT going through a type-hinted function this time).
        $event = \mod_booking\event\bookingoption_freetobookagain::create([
            'objectid' => $option1->id,
            'context' => \context_module::instance($settings->cmid),
            'userid' => $studenta->id,
        ]);
        $event->trigger();

        $tasks2 = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(
            1,
            $tasks2,
            'K5: a second, independent trigger of the same event for the same option/user ' .
            'must not create a second mail task (deduplicated via reschedule_or_queue_adhoc_task()).'
        );

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(
            1,
            $messages,
            'K5: student B must receive exactly one mail, not one per trigger.'
        );
        $this->assertEquals($studentb->id, $messages[0]->useridto);
    }

    /**
     * A11 (W4, coverage doc WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): waiting-list
     * autobooking, confirm and un-confirm must all be logged to booking_history.
     *
     * All three write through booking_option::write_user_answer_to_db(), which
     * unconditionally calls booking_history_insert() - K3 autobooking via
     * user_submit_response() (historystatus defaults to MOD_BOOKING_STATUSPARAM_BOOKED),
     * confirm via confirm_bookinganswer_by_rule_adhoc's direct write (historystatus =
     * MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED), and the exclusive-mode un-confirm loop
     * (historystatus = MOD_BOOKING_STATUSPARAM_CONFIRMATION_DELETED). This locks in that all
     * three actually produce a booking_history row with the right status/user/option, not just
     * that the answer JSON changes.
     *
     * @covers \mod_booking\booking_option::write_user_answer_to_db
     * @covers \mod_booking\booking_option::booking_history_insert
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_w4_history_logs_autobooking_confirm_and_unconfirm(array $bdata): void {
        global $DB;

        $bdata['cancancelbook'] = 1;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;

        // Case 1 (K3 autobooking): free option, no rule needed - sync_waiting_list() promotes
        // synchronously on cancellation (waitforconfirmation = 0).

        $studenta = $this->getDataGenerator()->create_user();
        $studentb = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenta->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentb->id, $course1->id, 'student');
        $this->setAdminUser();

        $record1 = clone $record;
        $record1->text = 'football1';
        $record1->waitforconfirmation = 0;
        $option1 = $plugingenerator->create_option($record1);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option1obj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        $this->setUser($studenta);
        singleton_service::destroy_user($studenta->id);
        booking_bookit::bookit('option', $settings1->id, $studenta->id);
        [$id] = $boinfo1->is_available($settings1->id, $studenta->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings1->id, $studenta->id);
            [$id] = $boinfo1->is_available($settings1->id, $studenta->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option1obj->user_submit_response($studenta, 0, 0, 0, MOD_BOOKING_VERIFIED);
            [$id] = $boinfo1->is_available($settings1->id, $studenta->id, true);
        }
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        $this->setAdminUser();

        $this->setUser($studentb);
        singleton_service::destroy_user($studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        booking_bookit::bookit('option', $settings1->id, $studentb->id);
        [$id] = $boinfo1->is_available($settings1->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        $this->setUser($studenta);
        $option1obj->user_delete_response($studenta->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $this->setAdminUser();

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        [$id] = $boinfo1->is_available($settings1->id, $studentb->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id, 'Precondition: K3 must have autobooked student B.');

        $autobookinghistory = $DB->get_records(
            'booking_history',
            ['optionid' => $option1->id, 'userid' => $studentb->id, 'status' => MOD_BOOKING_STATUSPARAM_BOOKED]
        );
        $this->assertNotEmpty(
            $autobookinghistory,
            'W4: K3 autobooking must be logged to booking_history with status BOOKED.'
        );

        // Case 2 (confirm) + Case 3 (un-confirm): priced, exclusive mode, one candidate
        // pre-seeded as already confirmed, a second one gets freshly confirmed -> the first
        // one's confirmation must be actively revoked (see A7/W2) AND both transitions logged.

        $pricecategorydata = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata);

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwl',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $studentc = $this->getDataGenerator()->create_user();
        $studentd = $this->getDataGenerator()->create_user();
        $studente = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentc->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studentd->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($studente->id, $course1->id, 'student');
        $this->setAdminUser();

        $record2 = clone $record;
        $record2->text = 'football2';
        $record2->waitforconfirmation = 1;
        $record2->confirmationonnotification = 2;
        $record2->useprice = 1;
        $record2->importing = 1;
        $option2 = $plugingenerator->create_option($record2);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);
        $option2obj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);

        $this->setUser($studentc);
        booking_bookit::bookit('option', $settings2->id, $studentc->id);
        [$id] = $boinfo2->is_available($settings2->id, $studentc->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings2->id, $studentc->id);
            [$id] = $boinfo2->is_available($settings2->id, $studentc->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $option2obj->user_submit_response($studentc, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        foreach ([$studentd, $studente] as $s) {
            $this->setUser($s);
            booking_bookit::bookit('option', $settings2->id, $s->id);
            booking_bookit::bookit('option', $settings2->id, $s->id);
            [$id] = $boinfo2->is_available($settings2->id, $s->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }
        $this->setAdminUser();

        // Pre-seed student E as already confirmed from an earlier round (see A7/W2).
        $eanswer = $DB->get_record('booking_answers', ['optionid' => $option2->id, 'userid' => $studente->id]);
        $DB->set_field(
            'booking_answers',
            'json',
            json_encode(['confirmwaitinglist' => 1, 'confirmationcount' => 1]),
            ['id' => $eanswer->id]
        );

        $this->setUser($studentc);
        $option2obj->user_delete_response($studentc->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        singleton_service::destroy_booking_answers($option2->id);
        $this->setAdminUser();

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $sink->close();
        $this->setAdminUser();

        // Confirm (student D): must be logged with WAITINGLIST_CONFIRMED.
        $danswer = $DB->get_record('booking_answers', ['optionid' => $option2->id, 'userid' => $studentd->id]);
        $djson = empty($danswer->json) ? null : json_decode($danswer->json);
        $this->assertNotEmpty($djson->confirmwaitinglist ?? null, 'Precondition: student D must be confirmed.');

        $confirmhistory = $DB->get_records('booking_history', [
            'optionid' => $option2->id,
            'userid' => $studentd->id,
            'status' => MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED,
        ]);
        $this->assertNotEmpty(
            $confirmhistory,
            'W4: confirming a waiting-list user must be logged to booking_history with status WAITINGLIST_CONFIRMED.'
        );

        // Un-confirm (student E): must be logged with CONFIRMATION_DELETED.
        $eanswerafter = $DB->get_record('booking_answers', ['id' => $eanswer->id]);
        $eanswerjsonafter = empty($eanswerafter->json) ? null : json_decode($eanswerafter->json);
        $this->assertEmpty(
            $eanswerjsonafter->confirmwaitinglist ?? null,
            'Precondition: student E\'s prior confirmation must have been revoked (see A7/W2).'
        );

        $unconfirmhistory = $DB->get_records('booking_history', [
            'optionid' => $option2->id,
            'userid' => $studente->id,
            'status' => MOD_BOOKING_STATUSPARAM_CONFIRMATION_DELETED,
        ]);
        $this->assertNotEmpty(
            $unconfirmhistory,
            'W4: revoking another waiting-list user\'s confirmation (exclusive mode) must be ' .
            'logged to booking_history with status CONFIRMATION_DELETED.'
        );
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is forced and maxanswers has been increased.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_maxanswer_increased_and_waitinglist_forced(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, time());

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('-3 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Continue as admin.
        $this->setAdminUser();
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        // Update booking.
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->maxanswers = 2;
        $record->teachersforoption = [$teacher1->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->get_usersonlist());
        $this->assertCount(1, $ba->get_usersonlist());
        $this->assertIsArray($ba->get_usersonwaitinglist());
        $this->assertCount(3, $ba->get_usersonwaitinglist());
        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(6, $tasks);
        // Validate task messages. Might be free order.
        foreach ($tasks as $key => $task) {
            $customdata = $task->get_custom_data();
            if (strpos($customdata->customsubject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $customdata->customsubject);
                $this->assertEquals("freeplacemsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student2->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student2->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertEmpty($rulejson->datafromevent->relateduserid);
                $this->assertEquals(2, $rulejson->datafromevent->userid);
            } else {
                // Validate 3 task messages on the bookingoption_freetobookagain with delay event.
                $this->assertEquals("freeplacedelaysubj", $customdata->customsubject);
                $this->assertEquals("freeplacedelaymsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student2->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student2->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertNotEmpty($rulejson->datafromevent->eventname ?? '');
            }
        }

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(5, $messages);
        // Validate ACTUAL task messages. Might be free order.
        $messagekeys = [];
        foreach ($messages as $key => $message) {
            $messagekey = $message->useridto . ':' . $message->subject;
            $this->assertArrayNotHasKey($messagekey, $messagekeys);
            $messagekeys[$messagekey] = true;
            if (strpos($message->subject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $message->subject);
                $this->assertEquals("freeplacemsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student2->id, $student3->id, $student4->id]);
            } else {
                // Validate delay task messages on the bookingoption_freetobookagain event chain.
                $this->assertEquals("freeplacedelaysubj", $message->subject);
                $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student1->id, $student2->id, $student3->id, $student4->id]);
            }
        }

        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(1, $messages);
        // Validate ACTUAL task messages. Might be free order.
        foreach ($messages as $key => $message) {
            // Validate 1 task messages on the bookingoption_freetobookagain with delay event.
            $this->assertEquals("freeplacedelaysubj", $message->subject);
            $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
            $this->assertEquals($student3->id, $message->useridto);
        }
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reorderd and booking is cancelled.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_reorder_waitinglist_when_booking_cancelled_and_rule_not_executed(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');
        // Config settings to reorder waitinaglist.
        set_config('waitinglistshowplaceonwaitinglist', 1, 'booking');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();

        // User can cancel booking at any time.
        $bdata['cancancelbook'] = 1;
        $bdata['cancelrelativedate'] = 2;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 0; // No confirmation necessary.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        // Book the student2 via waitinglist with intervals.
        // Move mock time strictly forward to guarantee deterministic waitinglist ordering.
        $time = time_mock::get_mock_time();
        time_mock::set_mock_time($time + DAYSECS);
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        $time = time_mock::get_mock_time();
        time_mock::set_mock_time($time + DAYSECS);
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        $time = time_mock::get_mock_time();
        time_mock::set_mock_time($time + DAYSECS);
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $answers = singleton_service::get_instance_of_booking_answers($settings);

        $this->assertCount(4, $answers->get_users());
        $this->assertCount(1, $answers->get_usersonlist());
        $this->assertCount(3, $answers->get_usersonwaitinglist());

        // Enrolled user cancels.
        $this->setUser($student1);
        $buttons = booking_bookit::render_bookit_button($settings, $student1->id);
        $this->assertStringContainsString('Undo my booking', $buttons);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result1 = booking_bookit::bookit('option', $settings->id, $student1->id);
        // After cancellation, student1 can book again only on waitinglist -.
        // Because next user (student2) from waitinglist had been booked.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $this->assertStringContainsString('Book it - on waitinglist', $description);

        // Continue as admin.
        $this->setAdminUser();
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        // Validate cancelled user.
        $this->assertIsArray($ba->get_usersonlist());
        $this->assertCount(1, $ba->get_usersonlist());
        $this->assertEquals($student2->id, array_key_first($ba->get_usersonlist()));
        // Validate 1st user on waitinglist.
        $this->assertIsArray($ba->get_usersonwaitinglist());
        $this->assertCount(2, $ba->get_usersonwaitinglist());
        $this->assertEquals($student3->id, array_key_first($ba->get_usersonwaitinglist()));
        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.

        // Don't expect tasks, since users are directly enrolled and no free places to book.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(0, $tasks);

        $time = time_mock::get_mock_time();
        $maxwaitingtimemodified = (int)$DB->get_field_sql(
            'SELECT MAX(timemodified)
               FROM {booking_answers}
              WHERE waitinglist = :waitinglist
                AND optionid = :optionid',
            [
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'optionid' => $option->id,
            ]
        );
        // Keep mock time monotonic and ensure this update becomes the latest waitinglist entry.
        time_mock::set_mock_time(max($time + 60, $maxwaitingtimemodified + 60));
        $time = time_mock::get_mock_time();
        // Reorder the waitinglist - set student 3 as last on waitinglist by updating timemodified to actual time.
        $student3answer = $DB->get_record('booking_answers', [
            'userid' => $student3->id,
            'waitinglist' => 1,
            'optionid' => $option->id,
        ]);
        $this->assertNotFalse($student3answer);
        $student3answer->timemodified = $time;
        // Update directly in the DB to avoid mocking table data (like timemodified).
        $DB->update_record('booking_answers', $student3answer);

        // Check that now the updated record is really the one with the highest timemodified.
        $waitinglistentries = $DB->get_records('booking_answers', [
            'waitinglist' => 1,
            'optionid' => $option->id,
        ], 'timemodified DESC');
        $this->assertEquals($student3answer->id, array_key_first($waitinglistentries));

        // Now put student1 back on the list.
        // And then cancel for student2.
        // Since the waitinglist was reordered, student4 should be on list.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $this->setUser($student2);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        // After cancellation, student2 can book again only on waitinglist -.
        // Because next user (student3) from waitinglist had been booked.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $this->assertStringContainsString('Book it - on waitinglist', $description);

        $this->setAdminUser();
        // Since the waitinglist was reordered, student4 should be on list.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba2 = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertEquals($student4->id, array_key_first($ba2->get_usersonlist()));
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reordered forced and bookingoption cancelled.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_waitinglist_reordered_and_user_cancelled(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $student6 = $this->getDataGenerator()->create_user();
        $student7 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":1,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in a couple of days';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+5 days', time());
        $record->courseendtime_0 = strtotime('+25 days', time());
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student5 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Reorder waitinglist, student4 is now top on the list.
        $student4answer = $DB->get_record(
            'booking_answers',
            ['userid' => $student4->id, 'waitinglist' => 1, 'optionid' => $settings->id]
        );
        $this->assertNotFalse($student4answer);
        $student4answer->timemodified = strtotime('-6 days', time());
        $updateconfirmation = $DB->update_record('booking_answers', $student4answer);
        $this->assertTrue($updateconfirmation);
        booking_option::purge_cache_for_answers($settings->id);
        $waitinglist = $DB->get_records('booking_answers', ['waitinglist' => 1, 'optionid' => $settings->id], 'timemodified ASC');
        $firstonlist = reset($waitinglist);
        $this->assertEquals($student4->id, $firstonlist->userid);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // Asserting that the spot is free to book and 4 users remaining on waitinglist.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->get_usersonlist());
        $this->assertCount(0, $ba->get_usersonlist());
        $this->assertIsArray($ba->get_usersonwaitinglist());
        $this->assertCount(4, $ba->get_usersonwaitinglist());

        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(3, $tasks);
        // There are only two mails scheduled by the logic of send_mail_interval class.

        $taskdata = [];
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            $data->nextruntime = $task->get_next_run_time();
            $taskdata[] = $data;
        }

        // Sort the array by nextruntime ascending.
        usort($taskdata, function ($a, $b) {
            return $a->nextruntime <=> $b->nextruntime;
        });

        // Find runtimes for the event chain users that prove waitinglist order is respected.
        $runtimebyuserid = [];
        foreach ($taskdata as $entry) {
            if (!array_key_exists($entry->userid, $runtimebyuserid)) {
                $runtimebyuserid[$entry->userid] = (int)$entry->nextruntime;
            }
        }

        $this->assertArrayHasKey($student4->id, $runtimebyuserid);
        $this->assertArrayHasKey($student2->id, $runtimebyuserid);
        $this->assertLessThan($runtimebyuserid[$student2->id], $runtimebyuserid[$student4->id]);

        // Check the interval.
        $runtimedifference = $runtimebyuserid[$student2->id] - $runtimebyuserid[$student4->id];
        // The interval defined in the rules json is in minutes, so multiplied by 60 for the timestamp.
        $this->assertEquals(1440 * 60, $runtimedifference);

        // Ok now we add a user to the waitinglist, reorder the waitinglist to make him first...
        // ... set the time later, so that both of the tasks are running.
        // And see if the second task created a new reminder mail task for the right user.
        $this->setUser($student6);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings->id, $student6->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student6->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student6->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student6->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $s6a = $DB->get_record('booking_answers', ['userid' => $student6->id, 'waitinglist' => 1, 'optionid' => $settings->id]);
        $this->assertNotFalse($s6a);
        $s6a->timemodified = strtotime('-20 days', time());
        $DB->update_record('booking_answers', $s6a);
        booking_option::purge_cache_for_answers($settings->id);

        time_mock::set_mock_time(time() + 10);
        $time = time_mock::get_mock_time();

        // Two tasks. One with runtime in the past for user student4.
        // And one for the next user on the list: student2.

        // Run tasks.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $this->assertCount(2, $messages);
        $messagekeys = [];
        $recipientids = [];
        foreach ($messages as $message) {
            $messagekey = $message->useridto . ':' . $message->subject;
            $this->assertArrayNotHasKey($messagekey, $messagekeys);
            $messagekeys[$messagekey] = true;
            $recipientids[] = $message->useridto;
        }
        $this->assertContains($student4->id, $recipientids);

        // So now we expect two tasks.
        // First one for student6 who is now the first on the list who hasn't been informed yet.
        // Second for student2 who remains third (second non-informed).
        $newtasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $newtasks);

        $taskdata = [];
        foreach ($newtasks as $task) {
            $data = $task->get_custom_data();
            $data->nextruntime = $task->get_next_run_time();
            $taskdata[] = $data;
        }
        // Sort the array by nextruntime ascending.
        usort($taskdata, function ($a, $b) {
            return $a->nextruntime <=> $b->nextruntime;
        });

        $this->assertEquals($student6->id, $taskdata[0]->userid);
        $this->assertEquals($student2->id, $taskdata[1]->userid);
        $runtimedifference = (int)$taskdata[1]->nextruntime - (int)$taskdata[0]->nextruntime;
        $this->assertEquals(1440 * 60, $runtimedifference);

        time_mock::set_mock_time(strtotime('+20 days', time()) + 10);
        $time = time_mock::get_mock_time();
        // We are now 24 days ahead of real current time.

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $firsttaskdata = reset($tasks)->get_custom_data();
        // Finally student2 is next to recieve the message.
        $this->assertEquals($student2->id, $firsttaskdata->userid);

        time_mock::set_mock_time(strtotime('+5 days', time()) + 10);
        $time = time_mock::get_mock_time();
        // We are now 29 days ahead of real current time, so bookingclosingtime is passed.

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        // Finally student2 is next to recieve the message.
        $this->assertEmpty($tasks);
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reordered forced and bookingoption cancelled.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     * @return void
     *
     * @dataProvider different_rule_conditions_provider
     */
    public function test_rule_on_freeplace_on_intervals_with_different_rule_conditions(array $data, array $expected): void {
        global $DB, $CFG;

        $bdata = self::booking_common_settings_provider();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $student2 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $student3 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $student4 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $student5 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $student6 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $student7 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $teacher1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $condition = $data['rulecondition'];
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":1,"cancelrules":[],"condition":"' . $condition . '"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create set of price categories.
        $plugingenerator->create_pricecategory($bdata['bdata'][0]['pricecategories'][0]);
        $plugingenerator->create_pricecategory($bdata['bdata'][0]['pricecategories'][1]);
        $plugingenerator->create_pricecategory($bdata['bdata'][0]['pricecategories'][2]);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;

        if (isset($data['fullwaitinglist']) && !empty($data['fullwaitinglist'])) {
            $record->maxoverbooking = 4;
        } else {
            $record->maxoverbooking = 10;
        }

        $record->description = 'Will start in a couple of days';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->useprice = 1;
        $record->importing = 1;
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);
        $option = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        // Create a booking option answer - book student1.
        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);

        // Book the student.
        $this->setAdminUser();
        // Purchase item in behalf of student1 to having history item.
        shopping_cart::delete_all_items_from_cart($student1->id);
        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);
        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);
        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings1->id, -1);
        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);

        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student5 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student5->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Render to see if "cancel purchase" present.
        $buttons = booking_bookit::render_bookit_button($settings1, $student1->id);
        $this->assertStringContainsString('Cancel purchase', $buttons);
        // Actual cancellation of purcahse and verify.
        $res = shopping_cart::cancel_purchase($settings1->id, 'option', $student1->id, 'mod_booking', $item->id, 0);
        $this->assertEquals(1, $res['success']);
        $this->assertEmpty($res['error']);

        booking_option::purge_cache_for_answers($settings1->id);
        // Asserting that the spot is free to book and 4 users remaining on waitinglist.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->get_usersonlist());
        $this->assertCount(0, $ba->get_usersonlist());
        $this->assertIsArray($ba->get_usersonwaitinglist());
        $this->assertCount(4, $ba->get_usersonwaitinglist());

        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Option was fully booked and is not fully booked anymore.
        // Check if tasks were found.
        $this->assertCount(2, $tasks);
        // There are only two mails scheduled by the logic of send_mail_interval class.

        // Book user again. Option is now fully booked.
        $this->setAdminUser();
        // Purchase item in behalf of student1 to having history item.
        shopping_cart::delete_all_items_from_cart($student1->id);
        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);
        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);
        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings1->id, -1);
        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);

        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Run tasks.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        // Check if message was send.
        $this->assertCount($expected['messagefound'], $messages);
    }

    /**
     * Data Provider for different rule conditions.
     *
     * @return array
     *
     */
    public static function different_rule_conditions_provider(): array {
        return [
            'Rule condition apply always' => [
                'data' => [
                    'rulecondition' => 0,
                ],
                'expected' => [
                    'messagefound' => 1,
                ],
            ],
            'Rule condition applies when not fully booked' => [
                'data' => [
                    'rulecondition' => 2,
                ],
                'expected' => [
                    'messagefound' => 0, // No message expected, because option is fully booked.
                ],
            ],
            'Rule condition applies when fully booked' => [
                'data' => [
                    'rulecondition' => 1, // Fully booked.
                ],
                'expected' => [
                    'messagefound' => 1, // No message expected, because option is fully booked.
                ],
            ],
            'Rule condition applies when full waitinglist - waitinglist not full' => [
                'data' => [
                    'rulecondition' => 3, // Triggers when waitinglist is full.
                ],
                'expected' => [
                    'messagefound' => 0, // Waitinglist is not full.
                ],
            ],
            'Rule condition applies when waitinglist not full - waitinglist not full' => [
                'data' => [
                    'rulecondition' => 4, // Triggers when waitinglist not full.
                ],
                'expected' => [
                    'messagefound' => 1, // Waitinglist is not full.
                ],
            ],
            'Rule condition applies when waitinglist not full - with full waitinglist' => [
                'data' => [
                    'rulecondition' => 4, // Waitinglist is full.
                    'fullwaitinglist' => true, // Waitinglist is full.
                ],
                'expected' => [
                    'messagefound' => 0, // Waitinglist is not full. No message.
                ],
            ],
            'Rule condition applies when waitinglist full - with full waitinglist' => [
                'data' => [
                    'rulecondition' => 3, // Waitinglist is full.
                    'fullwaitinglist' => true, // Waitinglist is full.
                ],
                'expected' => [
                    'messagefound' => 1, // Waitinglist is full. Send message.
                ],
            ],
        ];
    }

    /**
     * Create booking with bookingoption that contains price for some users, depending on profilefield.
     * Option is fully booked with waitinglist enabled. Some users on waitinglist need to pay, others don't.
     * Create rule to send interval messages.
     * One booked user cancels, 1 seat is free again.
     * Check that mail is send.
     * Check that new user NOT on waitinglist can not book.
     * Make sure, only user next on waitinglist can book.
     * If this user has the right value in the field, he will be enrolled automatically.
     * In this case, freetobookagain message should not be send (or scheduled).
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $testdata
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider waitinglist_price_provider
     */
    public function test_waitinglist_with_price(array $testdata, array $expected): void {
        global $DB;

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $time = time_mock::get_mock_time();

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create users, some of them with second price category.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user($testdata['student2settings'] ?? []);
        $student3 = $this->getDataGenerator()->create_user($testdata['student3settings'] ?? []);
        $student4 = $this->getDataGenerator()->create_user($testdata['student4settings'] ?? []);
        $student5 = $this->getDataGenerator()->create_user($testdata['student5settings'] ?? []);
        $student6 = $this->getDataGenerator()->create_user($testdata['student6settings'] ?? []);
        $student7 = $this->getDataGenerator()->create_user($testdata['student7settings'] ?? []);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $pricecategorydata2 = (object) [
            'ordernum' => 2,
            'name' => 'SecondPrice',
            'identifier' => 'secondprice',
            'defaultvalue' => $testdata['secondprice'],
            'pricecatsortorder' => 2,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata2);

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = $testdata['waitforconfirmation'];
        $record->confirmationonnotification = $testdata['confirmationonnotification'];
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        $record->useprice = 1;
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid); // Require to avoid caching issues.
        $boinfo1 = new bo_info($settings1);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);

        // Book the student.
        $this->setAdminUser();
        $price = price::get_price('option', $settings1->id, $student1);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);
        // Purchase item in behalf of student1 to having history item.
        // Clean cart.
        shopping_cart::delete_all_items_from_cart($student1->id);
        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);
        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);
        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings1->id, -1);
        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);

        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 on waitinglist.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, false);
        // This time it is coming from MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION.
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Render to see if "cancel purchase" present.
        $buttons = booking_bookit::render_bookit_button($settings1, $student1->id);
        $this->assertStringContainsString('Cancel purchase', $buttons);
        // Cancellation of purcahse if shopping_cart installed.
        // Getting history of purchased item and verify.
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);
        shopping_cart::add_quota_consumed_to_item($item, $student1->id);
        shoppingcart_history_list::add_round_config($item);
        $this->assertEquals($settings1->id, $item->itemid);
        $this->assertEquals($student1->id, $item->userid);
        $this->assertEquals($pricecategorydata1->defaultvalue, (int) $item->price);
        $this->assertEquals(0, $item->quotaconsumed);
        // Actual cancellation of purcahse and verify.
        $res = shopping_cart::cancel_purchase($settings1->id, 'option', $student1->id, 'mod_booking', $item->id, 0);
        $this->assertEquals(1, $res['success']);
        $this->assertEquals($pricecategorydata1->defaultvalue, $res['credit']);
        $this->assertEmpty($res['error']);

        $ba = singleton_service::get_instance_of_booking_answers($settings1);

        // Try to book EXTERNAL user - not yet on waitinglist.
        // Result depends on waitforconfirmation setting.
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        if (!empty($expected['newuserconfirmation'])) {
            $result = booking_bookit::bookit('option', $settings1->id, $student5->id);
            [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student5->id, false);
            $this->assertEquals($expected['newuserconfirmation'], $id);
        }
        $result = booking_bookit::bookit('option', $settings1->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student5->id, true);
        $this->assertEquals($expected['newuserresponse'], $id);

        // Asserting that the spot is EITHER free to book OR booked by next user AND proper number of users remains on waitinglist.
        $ba = singleton_service::get_instance_of_booking_answers($settings1);
        $this->assertIsArray($ba->get_usersonlist());
        $this->assertCount($expected['usersonlist1'], $ba->get_usersonlist());
        $this->assertIsArray($ba->get_usersonwaitinglist());
        $this->assertCount($expected['usersonwaitinglist1'], $ba->get_usersonwaitinglist());

        // Check for proper number of tasks.
        // Tasks are tested in depth in other tests of this class.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['taskcount1'], $tasks);

        // In the future we run tasks.
        // No free seats available, so no messages should be send.
        time_mock::set_mock_time(strtotime('+3 day', time()));
        $time = time_mock::get_mock_time();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $this->assertCount($expected['messagecount'], $messages);
        if (isset($testdata['bookseconduser']) && !$testdata['bookseconduser']) {
            foreach ($messages as $key => $message) {
                if (strpos($message->subject, "freeplacedelaysubj")) {
                    // Validate email on option change.
                    $this->assertEquals($student2->id, $message->useridto);
                }
            }
        }

        // After the rule execution, we check the booking answer of student2 to
        // verify that the JSON column contains the expected value.
        $student2bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student2->id,
            'waitinglist' => $expected['student2waitinglistvalue'],
        ]);

        if (is_null($expected['student2bajsonvalue'])) {
            $this->assertTrue(
                is_null($student2bookinganswer->json) || $student2bookinganswer->json === '{}',
                'Expected null or empty JSON object for student2bookinganswer->json'
            );
        } else if ($expected['student2bajsonvalue'] === 'json') {
            // Check if first user on waiting list (student2) is confirmed by rule.
            $this->assertNotNull($student2bookinganswer->json);
        }

        // After the rule execution, we check the booking answer of student2 to
        // verify that the JSON column contains the expected value.
        [$id, $isavailable, $description] = $boinfo1->is_available($option1->id, $student2->id, true);
        $this->assertEquals($expected['student2condtionvalue'], $id);

        $runnedtask = [];
        // 1. Check the userids in the tasks
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            $useridintask = $data->userid;
            $this->assertContains($useridintask, [$student2->id, $student3->id]);
            $runnedtask[] = $task->get_id();
        }

        // 2. See if both tasks are executed
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        foreach ($tasks as $task) {
            $this->assertNotContains($task->get_id(), $runnedtask);
        }

        // 3. If both tasks are executed and new option is active, student2 should not have the confirm keys in the json.
        // And student3 should have confirm key in answer json.
        ob_start();
        $this->runAdhocTasks(); // Run task again.
        ob_get_clean();
        $student2bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student2->id,
            'waitinglist' => $expected['student2waitinglistvalue'],
        ]);
        if (is_null($expected['student2bajsonvalue2'])) {
            $this->assertTrue(
                is_null($student2bookinganswer->json) || $student2bookinganswer->json === '{}',
                'Expected null or empty JSON object for student2bookinganswer->json'
            );
        } else {
            $this->assertNotNull($student2bookinganswer->json);
        }

        $student3bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student3->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        if (is_null($expected['student3bajsonvalue2'])) {
            $this->assertTrue(
                is_null($student3bookinganswer->json) || $student3bookinganswer->json === '{}',
                'Expected null or empty JSON object for student3bookinganswer->json'
            );
        } else {
            $this->assertNotNull($student3bookinganswer->json);
        }
    }

    /**
     * Data provider for test waitinglist with price.
     *
     * @return array
     *
     */
    public static function waitinglist_price_provider(): array {
        return [
            'second_user_no_price_no_confirmationlist' => [
                [
                    'secondprice' => 0,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => true,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // After the first cancellation, with these settings, we expect...
                    // The student2 (next on waitinglist) to be on the list, because he doesn't need to pay.
                    'usersonlist1' => 1,
                    'usersonwaitinglist1' => 3,
                    // So no tasks expected.
                    'taskcount1' => 0,
                    // Such as waitinglist now reqire confirmation.
                    'newuserconfirmation' => MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                    // Therefore (student2 already took the place), the external user can only book on the list.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // So no tasks expected.
                    'messagecount' => 0,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_BOOKED,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_no_confirmationlist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 3,
                    'taskcount1' => 2, // Tasks expected.
                    // No waitinglist in this case.
                    'newuserconfirmation' => '',
                    // Therefore new user can book with price.
                    'newuserresponse' => MOD_BOOKING_BO_COND_PRICEISSET,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // Users will not be notified.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // Such as waitinglist now reqire confirmation.
                    'newuserconfirmation' => MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,

                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification1' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 1,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // Such as waitinglist now reqire confirmation.
                    'newuserconfirmation' => MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => 'json',
                    'student3bajsonvalue2' => 'json',
                ],

            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification2' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 2,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // Such as waitinglist now reqire confirmation.
                    'newuserconfirmation' => MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => 'json',
                ],
            ],
        ];
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
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
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            'pricecategories' => [
                0 => (object)[
                    'ordernum' => 1,
                    'name' => 'default',
                    'identifier' => 'default',
                    'defaultvalue' => 99,
                    'pricecatsortorder' => 1,
                ],
                1 => (object)[
                    'ordernum' => 2,
                    'name' => 'discount1',
                    'identifier' => 'discount1',
                    'defaultvalue' => 89,
                    'pricecatsortorder' => 2,
                ],
                2 => (object)[
                    'ordernum' => 3,
                    'name' => 'discount2',
                    'identifier' => 'discount2',
                    'defaultvalue' => 79,
                    'pricecatsortorder' => 3,
                ],
            ],
        ];
        return ['bdata' => [$bdata]];
    }
}
