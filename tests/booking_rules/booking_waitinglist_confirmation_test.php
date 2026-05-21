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
 * Isolated test for Bug-A fix: free-user auto-booking re-triggers adhoc task for
 * late-joining waitinglist users.
 *
 * This test is kept in its own class so it is never affected by static-state
 * side-effects from other tests in the booking-rules test suite.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Isolated test for Bug-A fix: free-user auto-booking re-triggers adhoc task for
 * late-joining waitinglist users.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_waitinglist_confirmation_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        // Reset static arrays not covered by destroy_singletons().
        rules_info::$rulestocancel = [];
        booking_rules::$rules = [];
        // Purge MUC price caches so stale price-category data from a previous test
        // (e.g. student price=0) does not bleed into the next test's price lookups.
        \cache_helper::purge_by_event('setbackprices');
        \cache::make('mod_booking', 'cachedprices')->purge();
        \cache::make('mod_booking', 'cachedpricecategories')->purge();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Helper: common booking-module settings used across tests.
     *
     * @return array
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
        ];
        return ['bdata' => [$bdata]];
    }

    /**
     * Regression test for the one-at-a-time WL confirmation chain:
     * when a paid WL user's confirm-task runs (paid path), the chain architecture
     * (confirm_bookinganswer creates tasks one by one, like send_mail_interval)
     * ensures that late joiners (s8, s9) are naturally picked up on each fresh WL
     * query without any explicit re-trigger.
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3–s7 (all price=100/default) join WL.
     * s1 cancels → freetobookagain fires → chain creates:
     *   - 1 direct confirm task for s3 (first WL user)
     *   - 1 repeat-trigger task for s4 (second WL user, repeat=1)
     *   All other WL users (s5–s7) are skipped in the initial event.
     * s8+s9 join WL AFTER the cancel (late joiners).
     * Batches are run until no tasks remain:
     *   - Each batch processes one direct confirm + one repeat-trigger.
     *   - The repeat-trigger re-executes the rule with a fresh WL query (usersalreadytreated
     *     is stored in rulejson) so late joiners are picked up when their turn comes.
     * After all batches:
     *   - s3–s9: WL=1, confirmationcount=1 in booking_answer JSON (all 7 WL users confirmed).
     *   - Total booked (WL=0) = 1 (s2 only; s1 cancelled, all WL users stay on WL in paid path).
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_all_paid_waitinglist_users_get_tasks_and_late_joiners_retriggered(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // All 9 users use the default price category (price=100). No student-pricecat users.
        for ($i = 1; $i <= 9; $i++) {
            $student[$i] = $this->getDataGenerator()->create_user();
        }
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        for ($i = 1; $i <= 9; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default=100 (all users in this test), student=0 (unused).
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Create booking rule: react on freetobookagain, select all WL users (borole=1),
        // action = confirm_bookinganswer.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2 (fully booked by s1+s2), useprice=1,
        // waitforconfirmation=2, confirmationonnotification=1 (non-exclusive, all WL users
        // can be confirmed in parallel; value 2 would cause an infinite loop with the re-trigger).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'bugabfixtest';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Phase 1: Book s1 and s2 via shopping_cart to fill the option (maxanswers=2 → fully booked).
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                "student{$i} should be booked after shopping_cart payment"
            );
        }

        // Phase 2: s3–s7 join WL (all have price=100 → paid path when their tasks run).
        for ($i = 3; $i <= 7; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Phase 3: s1 cancels → freetobookagain fires → tasks for ALL 5 WL users (s3–s7).
        // select_student_in_bo has no seat-count limit: it selects every WL user.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $tasksaftercancel = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $this->assertCount(
            2,
            $tasksaftercancel,
            'Chain architecture: expected exactly 2 tasks after s1 cancels: '
            . '1 direct confirm for s3 (first WL user) + 1 repeat-trigger for s4 (second WL user). '
            . 'select_student_in_bo returns all 5 WL users but the action only creates 2 tasks per event.'
        );

        // Phase 4: s8 and s9 join WL AFTER the tasks for s3–s7 are already queued (late joiners).
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        for ($i = 8; $i <= 9; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Confirm s8 and s9 still have no confirm tasks (joined after the initial freetobookagain).
        $alltasksbefore = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors8before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[8]->id);
        $taskfors9before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[9]->id);
        $this->assertEmpty($taskfors8before, 's8 must have no confirm task before batch 1 runs (late joiner)');
        $this->assertEmpty($taskfors9before, 's9 must have no confirm task before batch 1 runs (late joiner)');

        // Phase 5: Run all batches until no tasks remain.
        // Each batch processes one direct confirm (paid path → confirmationcount=1) and one
        // repeat-trigger (re-executes rule with fresh WL query → picks up late joiners and
        // creates the next pair of tasks). The chain terminates when all WL users have been
        // processed (everyone is in usersalreadytreated, rule returns empty records).
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        $maxbatches = 20; // Safety cap: 7 WL users × 1 batch each + buffer.
        $batchcount = 0;
        do {
            ob_start();
            $plugingenerator->runtaskswithintime($mocktime);
            ob_end_clean();
            $batchcount++;
            $remaining = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        } while (!empty($remaining) && $batchcount < $maxbatches);

        $this->assertLessThan(
            $maxbatches,
            $batchcount,
            'Chain must terminate within ' . $maxbatches . ' batches (possible infinite loop)'
        );

        // Refresh singletons after all task runs.
        for ($i = 1; $i <= 9; $i++) {
            singleton_service::destroy_user($student[$i]->id);
        }
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Final assertions: all 7 WL users (s3–s9) must have confirmationcount=1.
        for ($i = 3; $i <= 9; $i++) {
            $answer = $DB->get_record('booking_answers', [
                'optionid' => $option->id,
                'userid' => $student[$i]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $this->assertNotEmpty($answer, "student{$i} must have a booking_answers record on WL");
            $answerjson = empty($answer->json) ? (object)[] : json_decode($answer->json);
            $this->assertEquals(
                1,
                $answerjson->confirmationcount ?? 0,
                "Chain: student{$i} must have confirmationcount=1 after all chain batches ran"
            );
        }

        // All 7 WL users must be at PRICEISSET (confirmed by admin, awaiting payment).
        for ($i = 3; $i <= 9; $i++) {
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_PRICEISSET,
                $id,
                "student{$i}: after paid-path confirm task, user should be at PRICEISSET"
            );
        }

        // Sanity: only s2 remains booked (s1 cancelled; all WL users stay on WL in paid path).
        $bookedcount = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(
            1,
            $bookedcount,
            'Only s2 should remain booked after s1 cancelled and all WL users stayed on WL (paid path)'
        );
    }

    /**
     * Regression test for Bug A: after a free-user's confirm-task auto-books them, the fix
     * must purge the option cache and call check_if_free_to_book_again so that any remaining
     * free seat triggers a new freetobookagain event, which in turn re-queues confirm tasks
     * for waitinglist users who joined AFTER the original event (e.g. student5).
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3 (price=100) + s4 (price=0/student) join WL (s3 first, s4 second).
     * Admin raises maxanswers to 4 → freetobookagain fires → confirm tasks created for s3+s4.
     * s5 joins WL AFTER the tasks are already queued (so s5 has no task yet).
     * runtaskswithintime:
     *   - s3 task → paid path → stays WL=1 (2 seats remain free after s3 stays on WL).
     *   - s4 task → free path → auto-booked → Bug-A fix fires freetobookagain → new tasks for s3+s5.
     * Expected state after two runtaskswithintime rounds:
     *   - After batch 1: s3 confirmed (paid, stays WL), s4's repeat-trigger re-executes the rule
     *     → new confirm task for s4 + repeat-trigger for s5 created; s4 NOT yet auto-booked.
     *   - s5 has a pending task after batch 1 (chain: repeat-trigger).
     *   - After batch 2: s4 auto-booked (free path), s5's repeat-trigger creates a direct confirm task.
     *   - Booked (WL=0): s1, s2, s4  → exactly 3 records after batch 2.
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_free_user_autobooking_retriggers_task_for_late_joining_waitinglist_user(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Student4 has the student price category (price = 0); all others use the default (price = 100).
        $student[1] = $this->getDataGenerator()->create_user();
        $student[2] = $this->getDataGenerator()->create_user();
        $student[3] = $this->getDataGenerator()->create_user();
        $student[4] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[5] = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default = 100, student = 0.
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Create booking rule: react on freetobookagain, select waitinglist users,
        // action = confirm_bookinganswer (no mail, no counter limit, no repeat mechanism).
        // This ensures the ONLY path for student5 to receive a task is the re-trigger fix.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2 (will be fully booked by student1+2),
        // useprice=1, waitforconfirmation=2, confirmationonnotification=1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'bugafixtest';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Phase 1: Book student1 and student2 via shopping_cart to fill the option.
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            $res = shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                "student{$i} should be fully booked after shopping_cart payment"
            );
        }

        // Phase 2: student3 (paid) and student4 (free) join the waitinglist.
        // student3 joins first (earlier timemodified -> first in WL queue).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[3]);
        singleton_service::destroy_user($student[3]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Student4 joins second (later timemodified -> second in WL queue).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[4]);
        singleton_service::destroy_user($student[4]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Phase 3: Admin increases maxanswers to 4 -> freetobookagain fires.
        // With maxanswers=4 and 2 booked users, 2 seats are free.
        // After student4 (free) is auto-booked, 1 seat will still remain -> re-trigger fires.
        $this->setAdminUser();
        $updaterecord = new stdClass();
        $updaterecord->id = $option->id;
        $updaterecord->bookingid = $booking1->id;
        $updaterecord->text = $record->text;
        $updaterecord->maxanswers = 4;
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course->id;
        $updaterecord->maxoverbooking = 10;
        $updaterecord->waitforconfirmation = 2;
        $updaterecord->confirmationonnotification = 1;
        $updaterecord->useprice = 1;
        $updaterecord->importing = 1;
        $updaterecord->optiondateid_0 = "0";
        $updaterecord->daystonotify_0 = "0";
        $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
        $updaterecord->courseendtime_0 = $record->courseendtime_0;
        $updaterecord->teachersforoption = $teacher->username;
        booking_option::update($updaterecord);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // The rule must have created confirm tasks for student3 and student4 (the two WL users).
        $tasksafter = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $this->assertCount(
            2,
            $tasksafter,
            'Expected exactly 2 confirm tasks (for student3 and student4) after maxanswers increase'
        );

        // Phase 4: student5 joins AFTER the tasks are already queued.
        // student5 will NOT have a confirm task at this point.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm student5 has no confirm task yet (joined after the initial event fired).
        $alltasks = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5before = array_filter($alltasks, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertEmpty(
            $taskfors5before,
            'student5 must have no confirm task before tasks run (joined after the initial freetobookagain event)'
        );

        /* Phase 5: Run batch 1.
        student3 task (direct confirm, repeat=0): paid (price=100) -> confirmwaitinglist JSON set, stays on WL.
        student4 task (repeat-trigger, repeat=1): re-executes the rule with fresh WL query.
        -> rule creates a new direct confirm task for s4 + a repeat-trigger for s5.
        -> s4 is NOT auto-booked yet in this batch.
        */
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        // Assertions after batch 1.

        // KEY ASSERTION: student5 must now have a pending task created by the chain.
        // s4's repeat-trigger re-executed the rule (fresh WL query included s5), which created
        // a repeat-trigger task for s5. Without the chain architecture this would never happen.
        $tasksafterb1 = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5 = array_filter($tasksafterb1, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5,
            'Chain: a confirm_bookinganswer_by_rule_adhoc task for student5 must exist after batch 1 '
            . '(created when s4\'s repeat-trigger re-executed the rule and the fresh WL query included s5)'
        );

        // Phase 6: Run batch 2.
        // student4 task (direct confirm, repeat=0): free (price=0) -> user_submit_response auto-books s4.
        // student5 task (repeat-trigger, repeat=1): re-executes rule -> creates direct confirm task for s5.
        $this->setAdminUser();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        // Assertions after batch 2.

        // Student4 must now be fully booked (auto-booked by free path in batch 2).
        singleton_service::destroy_user($student[4]->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'student4 (free user) should have been auto-booked in batch 2'
        );

        // The option must still have a free seat (maxanswers=4, booked: s1+s2+s4=3).
        // Use a direct DB count to avoid any Moodle-cache or singleton contamination.
        $bookedrecords = $DB->get_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $bookedcount = count($bookedrecords);
        // Diagnostic: list the booked user IDs vs expected.
        $expecteduserids = [$student[1]->id, $student[2]->id, $student[4]->id];
        $actualuserids = array_column(array_values($bookedrecords), 'userid');
        sort($expecteduserids);
        sort($actualuserids);
        $this->assertEquals(
            3,
            $bookedcount,
            sprintf(
                'Expected exactly 3 booked (s1=%d,s2=%d,s4=%d) after batch 2 but got %d: actual booked userids=[%s] '
                    . '(s3=%d s5=%d)',
                $student[1]->id,
                $student[2]->id,
                $student[4]->id,
                $bookedcount,
                implode(',', $actualuserids),
                $student[3]->id,
                $student[5]->id
            )
        );

        // Student5 must have a direct confirm task (created when s5's repeat-trigger re-ran the rule in batch 2).
        $tasksafterb2 = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5b2 = array_filter($tasksafterb2, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b2,
            'Chain: student5 must have a pending confirm task after batch 2 '
            . '(created when s5\'s repeat-trigger re-executed the rule in batch 2)'
        );
    }

    /**
     * Regression for confirmationonnotification=2 (exclusive mode):
     *
     * Requirement (a): if two free seats are available and one WL user gets booked (free path),
     * other WL users must still get notified one-at-a-time via the chain.
     *
     * Requirement (b): users added to WL after freetobookagain was created must still get notified
     * if a place is still free, but must not be notified if all places have already been taken.
     *
     * Scenario
     * --------
     * maxanswers=2 -> s1+s2 are fully booked.
     * s3 (paid) + s4 (free) join WL.
     * maxanswers increased to 4 -> two free seats available, initial chain tasks created.
     * s5 (free) joins WL AFTER initial tasks are queued -> no task yet.
     * batch1: s3 direct-confirm (paid, stays WL), s4 repeat-trigger reruns rule -> s5 gets task.
     * batch2: s4 direct-confirm (free, booked), s5 repeat-trigger reruns rule -> s5 direct task exists.
     *   -> proves (a): one seat consumed, other WL user still notified.
     * batch3: s5 direct-confirm (free, booked) -> all seats consumed.
     * s6 joins WL AFTER seats consumed -> must never receive task.
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_confirmationmode2_late_joiner_notified_while_seat_free_but_not_after_full(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course and users.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // S4 and s5 are free users; others default paid.
        $student[1] = $this->getDataGenerator()->create_user();
        $student[2] = $this->getDataGenerator()->create_user();
        $student[3] = $this->getDataGenerator()->create_user();
        $student[4] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[5] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[6] = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        for ($i = 1; $i <= 6; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default=100, student=0.
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Rule reacts on freetobookagain and confirms WL users.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Confirmationonnotification=2 (exclusive mode).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'confirmationmode2-latejoiners';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 2;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Fully book s1+s2.
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        // S3 (paid) and s4 (free) join waiting list.
        for ($i = 3; $i <= 4; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Open exactly two free seats (maxanswers 2 -> 4) and trigger freetobookagain.
        $this->setAdminUser();
        $updaterecord = new stdClass();
        $updaterecord->id = $option->id;
        $updaterecord->bookingid = $booking1->id;
        $updaterecord->text = $record->text;
        $updaterecord->maxanswers = 4;
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course->id;
        $updaterecord->maxoverbooking = 10;
        $updaterecord->waitforconfirmation = 2;
        $updaterecord->confirmationonnotification = 2;
        $updaterecord->useprice = 1;
        $updaterecord->importing = 1;
        $updaterecord->optiondateid_0 = "0";
        $updaterecord->daystonotify_0 = "0";
        $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
        $updaterecord->courseendtime_0 = $record->courseendtime_0;
        $updaterecord->teachersforoption = $teacher->username;
        booking_option::update($updaterecord);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;
        $tasksafterupdate = \core\task\manager::get_adhoc_tasks($taskclass);
        $this->assertCount(
            2,
            $tasksafterupdate,
            'Mode 2 chain setup: expected exactly 2 initial tasks (direct + repeat-trigger).'
        );

        // Late joiner s5 joins after event/tasks were already created.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $alltasksbefore = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertEmpty(
            $taskfors5before,
            'Requirement (b, positive precondition): s5 must have no task immediately after late join (event already queued).'
        );

        // Run batch 1: s3 direct confirm + s4 repeat trigger reruns rule and includes s5.
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $tasksafterb1 = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5b1 = array_filter($tasksafterb1, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b1,
            'Requirement (b, positive): late joiner s5 must be notified while a seat is still free.'
        );

        // Run batch 2: s4 direct free-booking consumes one seat, s5 repeat creates direct s5 task.
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $bookedcountafterb2 = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(3, $bookedcountafterb2, 'After batch 2 exactly one of two free seats must be consumed (booked=3).');

        $tasksafterb2 = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5b2 = array_filter($tasksafterb2, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b2,
            'Requirement (a): after one user is booked with two seats available, other WL users (s5) must still be notified.'
        );

        // Run batch 3: s5 direct free-booking consumes the last free seat.
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $finalbookedcount = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(4, $finalbookedcount, 'All seats must be consumed after s4 and s5 free-booking runs (booked=4).');

        // Late joiner s6 joins only after option is full.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[6]);
        singleton_service::destroy_user($student[6]->id);
        booking_bookit::bookit('option', $settings->id, $student[6]->id);
        [$id] = $boinfo->is_available($settings->id, $student[6]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student[6]->id);
        [$id] = $boinfo->is_available($settings->id, $student[6]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $tasksbefores6run = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors6 = array_filter($tasksbefores6run, fn($t) => $t->get_custom_data()->userid == $student[6]->id);
        $this->assertEmpty(
            $taskfors6,
            'Requirement (b, negative): late joiner s6 must not be notified after all seats are already taken.'
        );

        // Even running tasks again must not create a task for s6.
        $this->setAdminUser();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $tasksafters6run = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors6after = array_filter($tasksafters6run, fn($t) => $t->get_custom_data()->userid == $student[6]->id);
        $this->assertEmpty(
            $taskfors6after,
            'Requirement (b, negative): s6 should remain without notification task after subsequent task runs.'
        );
    }
}
